<?php

declare(strict_types=1);

function create_inspection(string $formKey, int $inspectorId): int
{
    $stmt = db()->prepare('INSERT INTO inspections (form_key, inspector_id, status, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$formKey, $inspectorId, 'draft', now()]);
    return (int) db()->lastInsertId();
}

function find_inspection(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT i.*, u.display_name AS inspector_name, u.username AS inspector_username,
                r.display_name AS reviewer_name,
                c.display_name AS commenter_name
         FROM inspections i
         JOIN users u ON u.id = i.inspector_id
         LEFT JOIN users r ON r.id = i.reviewed_by
         LEFT JOIN users c ON c.id = i.admin_comment_by
         WHERE i.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function can_view_inspection(array $user, array $inspection): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    return (int) $inspection['inspector_id'] === (int) $user['id'];
}

function can_edit_inspection(array $user, array $inspection): bool
{
    return $user['role'] === 'inspector'
        && (int) $inspection['inspector_id'] === (int) $user['id'];
}

function inspection_answers(int $inspectionId): array
{
    $stmt = db()->prepare('SELECT field_key, field_value FROM inspection_answers WHERE inspection_id = ?');
    $stmt->execute([$inspectionId]);
    $out = [];
    foreach ($stmt as $row) {
        $out[$row['field_key']] = $row['field_value'];
    }
    return $out;
}

function save_answers(int $inspectionId, array $answers): void
{
    $sql = 'INSERT INTO inspection_answers (inspection_id, field_key, field_value) VALUES (?, ?, ?)
            ON CONFLICT(inspection_id, field_key) DO UPDATE SET field_value = excluded.field_value';
    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $sql = 'INSERT INTO inspection_answers (inspection_id, field_key, field_value) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)';
    }
    $stmt = db()->prepare($sql);
    foreach ($answers as $key => $value) {
        $stmt->execute([$inspectionId, $key, $value]);
    }
}

function list_inspections_for_user(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT i.*, u.display_name AS inspector_name
         FROM inspections i
         JOIN users u ON u.id = i.inspector_id
         WHERE i.inspector_id = ?
         ORDER BY i.created_at DESC, i.id DESC'
    );
    $stmt->execute([$userId]);
    return attach_summaries($stmt->fetchAll());
}

function list_submitted_inspections(): array
{
    $stmt = db()->query(
        "SELECT i.*, u.display_name AS inspector_name
         FROM inspections i
         JOIN users u ON u.id = i.inspector_id
         WHERE i.status IN ('submitted', 'reviewed', 'needs_info')
         ORDER BY CASE i.status
                    WHEN 'submitted' THEN 0
                    WHEN 'needs_info' THEN 1
                    ELSE 2
                  END,
                  CASE WHEN i.status = 'needs_info'
                       THEN COALESCE(i.returned_at, i.submitted_at, i.created_at)
                       ELSE COALESCE(i.submitted_at, i.created_at)
                  END ASC,
                  i.id ASC"
    );
    return attach_summaries($stmt->fetchAll());
}

function queue_status_stats(array $inspections): array
{
    $counts = ['submitted' => 0, 'needs_info' => 0, 'reviewed' => 0];
    foreach ($inspections as $row) {
        $status = $row['status'] ?? '';
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }
    $total = array_sum($counts);
    $pcts = ['submitted' => 0, 'needs_info' => 0, 'reviewed' => 0];
    $assigned = 0;
    $lastKey = null;
    foreach ($counts as $key => $count) {
        if ($count > 0) {
            $lastKey = $key;
        }
    }
    foreach ($counts as $key => $count) {
        if ($total === 0) {
            continue;
        }
        if ($key === $lastKey) {
            $pcts[$key] = 100 - $assigned;
            continue;
        }
        $pcts[$key] = (int) round(100 * $count / $total);
        $assigned += $pcts[$key];
    }

    $cursor = 0.0;
    $paths = [];
    $full = null;
    foreach ($counts as $key => $count) {
        $sweep = $total ? 360.0 * $count / $total : 0.0;
        $paths[$key] = pie_slice_path(80, 80, 72, $cursor, $sweep);
        if ($total > 0 && $count === $total) {
            $full = $key;
        }
        $cursor += $sweep;
    }

    return [
        'submitted' => $counts['submitted'],
        'needs_info' => $counts['needs_info'],
        'reviewed' => $counts['reviewed'],
        'total' => $total,
        'submitted_pct' => $pcts['submitted'],
        'needs_info_pct' => $pcts['needs_info'],
        'reviewed_pct' => $pcts['reviewed'],
        'submitted_path' => $paths['submitted'],
        'needs_info_path' => $paths['needs_info'],
        'reviewed_path' => $paths['reviewed'],
        'full' => $full,
        'is_empty' => $total === 0,
    ];
}

function pie_point(float $cx, float $cy, float $r, float $deg): array
{
    $rad = deg2rad($deg - 90);
    return [$cx + $r * cos($rad), $cy + $r * sin($rad)];
}

function pie_slice_path(float $cx, float $cy, float $r, float $startDeg, float $sweepDeg): string
{
    if ($sweepDeg <= 0.01 || $sweepDeg >= 359.99) {
        return '';
    }
    $start = pie_point($cx, $cy, $r, $startDeg);
    $end = pie_point($cx, $cy, $r, $startDeg + $sweepDeg);
    $large = $sweepDeg > 180 ? 1 : 0;
    return sprintf(
        'M %.3f %.3f L %.3f %.3f A %.3f %.3f 0 %d 1 %.3f %.3f Z',
        $cx,
        $cy,
        $start[0],
        $start[1],
        $r,
        $r,
        $large,
        $end[0],
        $end[1]
    );
}

function attach_summaries(array $inspections): array
{
    foreach ($inspections as &$row) {
        $form = form_definition($row['form_key']) ?? ['title' => $row['form_key']];
        $row['summary'] = inspection_title($row, inspection_answers((int) $row['id']), $form);
        $row['age_days'] = submission_age_days(
            $row['status'] ?? null,
            $row['submitted_at'] ?? null,
            $row['returned_at'] ?? null
        );
        $row['age_label'] = format_age_days($row['age_days']);
    }
    unset($row);
    return $inspections;
}

function submit_inspection(int $inspectionId): void
{
    $stmt = db()->prepare(
        "UPDATE inspections
         SET status = 'submitted', submitted_at = ?, reviewed_at = NULL, reviewed_by = NULL
         WHERE id = ?"
    );
    $stmt->execute([now(), $inspectionId]);
}

function mark_inspection_draft(int $inspectionId): void
{
    $stmt = db()->prepare(
        "UPDATE inspections
         SET status = 'draft', reviewed_at = NULL, reviewed_by = NULL
         WHERE id = ?"
    );
    $stmt->execute([$inspectionId]);
}

function mark_inspection_reviewed(int $inspectionId, int $adminId): void
{
    $stmt = db()->prepare(
        "UPDATE inspections
         SET status = 'reviewed', reviewed_at = ?, reviewed_by = ?, returned_at = NULL
         WHERE id = ? AND status IN ('submitted', 'needs_info')"
    );
    $stmt->execute([now(), $adminId, $inspectionId]);
}

function save_admin_comment(int $inspectionId, string $comment, int $adminId): void
{
    $stmt = db()->prepare(
        'UPDATE inspections SET admin_comment = ?, admin_comment_at = ?, admin_comment_by = ? WHERE id = ?'
    );
    $stmt->execute([$comment === '' ? null : $comment, now(), $adminId, $inspectionId]);
}

function mark_inspection_needs_info(int $inspectionId): void
{
    $stmt = db()->prepare(
        "UPDATE inspections
         SET status = 'needs_info', returned_at = ?, reviewed_at = NULL, reviewed_by = NULL
         WHERE id = ?"
    );
    $stmt->execute([now(), $inspectionId]);
}

function has_admin_comment(array $inspection): bool
{
    return trim((string) ($inspection['admin_comment'] ?? '')) !== '';
}

function set_inspection_pdf_path(int $inspectionId, string $relativePath): void
{
    $stmt = db()->prepare('UPDATE inspections SET pdf_path = ? WHERE id = ?');
    $stmt->execute([$relativePath, $inspectionId]);
}

function inspection_title(array $inspection, array $answers, array $form): string
{
    if ($inspection['form_key'] === 'safety') {
        return $answers['site_name'] ?? $form['title'];
    }
    if ($inspection['form_key'] === 'equipment') {
        $name = $answers['equipment_name'] ?? '';
        $id = $answers['equipment_id'] ?? '';
        $bits = array_filter([$name, $id !== '' ? '#' . $id : '']);
        return $bits ? implode(' ', $bits) : $form['title'];
    }
    return $form['title'] ?? 'Inspection';
}
