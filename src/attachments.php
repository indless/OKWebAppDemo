<?php

declare(strict_types=1);

const ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024;
const ATTACHMENT_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function inspection_attachments(int $inspectionId): array
{
    $stmt = db()->prepare('SELECT * FROM attachments WHERE inspection_id = ? ORDER BY id');
    $stmt->execute([$inspectionId]);
    return $stmt->fetchAll();
}

function attachment_dir(int $inspectionId): string
{
    return storage_path('uploads/' . $inspectionId);
}

function attachment_abs_path(array $attachment): string
{
    return attachment_dir((int) $attachment['inspection_id']) . '/' . $attachment['stored_name'];
}

function find_attachment(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM attachments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function delete_attachment_record(array $attachment): void
{
    $path = attachment_abs_path($attachment);
    if (is_file($path)) {
        @unlink($path);
    }
    $stmt = db()->prepare('DELETE FROM attachments WHERE id = ?');
    $stmt->execute([(int) $attachment['id']]);
}

function process_attachment_deletes(int $inspectionId, array $ids): void
{
    if (!$ids) {
        return;
    }
    foreach ($ids as $id) {
        $attachment = find_attachment((int) $id);
        if (!$attachment || (int) $attachment['inspection_id'] !== $inspectionId) {
            continue;
        }
        delete_attachment_record($attachment);
    }
}

function process_attachment_uploads(int $inspectionId, int $maxTotal): array
{
    $errors = [];
    $files = normalize_files_array($_FILES['photos'] ?? null);
    if (!$files) {
        return $errors;
    }

    $existing = inspection_attachments($inspectionId);
    $remaining = $maxTotal - count($existing);
    if ($remaining <= 0) {
        $errors[] = 'Maximum of ' . $maxTotal . ' photos already attached.';
        return $errors;
    }

    ensure_dir(attachment_dir($inspectionId));
    $saved = 0;

    foreach ($files as $file) {
        if ($saved >= $remaining) {
            $errors[] = 'Only ' . $maxTotal . ' photos are allowed. Extra files were skipped.';
            break;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'Could not upload ' . ($file['name'] ?? 'file') . '.';
            continue;
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'Invalid upload for ' . ($file['name'] ?? 'file') . '.';
            continue;
        }
        if (($file['size'] ?? 0) > ATTACHMENT_MAX_BYTES) {
            $errors[] = ($file['name'] ?? 'File') . ' is larger than 5 MB.';
            continue;
        }
        $mime = detect_upload_mime($file);
        if (!isset(ATTACHMENT_MIMES[$mime])) {
            $errors[] = ($file['name'] ?? 'File') . ' must be a JPEG, PNG, or WebP image.';
            continue;
        }
        $stored = bin2hex(random_bytes(8)) . '.' . ATTACHMENT_MIMES[$mime];
        $dest = attachment_dir($inspectionId) . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $errors[] = 'Could not store ' . ($file['name'] ?? 'file') . '.';
            continue;
        }
        $stmt = db()->prepare(
            'INSERT INTO attachments (inspection_id, stored_name, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $inspectionId,
            $stored,
            (string) ($file['name'] ?? $stored),
            $mime,
            (int) $file['size'],
        ]);
        $saved++;
    }

    return $errors;
}

function normalize_files_array(mixed $files): array
{
    if (!is_array($files) || !isset($files['name'])) {
        return [];
    }
    if (!is_array($files['name'])) {
        return [$files];
    }
    $out = [];
    foreach ($files['name'] as $i => $name) {
        $out[] = [
            'name' => $name,
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
    }
    return $out;
}

function detect_upload_mime(array $file): string
{
    if (class_exists('finfo')) {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }
    }
    return (string) ($file['type'] ?? '');
}

function attachment_data_uri(array $attachment, int $maxWidth = 900): ?string
{
    $path = attachment_abs_path($attachment);
    if (!is_file($path)) {
        return null;
    }
    $bytes = (string) file_get_contents($path);
    if ($bytes !== '' && function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring($bytes);
        if ($img) {
            $width = imagesx($img);
            $height = imagesy($img);
            if ($width > $maxWidth) {
                $newWidth = $maxWidth;
                $newHeight = (int) round($height * $maxWidth / $width);
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                ob_start();
                imagejpeg($resized, null, 82);
                $bytes = (string) ob_get_clean();
                imagedestroy($resized);
                imagedestroy($img);
                return 'data:image/jpeg;base64,' . base64_encode($bytes);
            }
            imagedestroy($img);
        }
    }
    return 'data:' . $attachment['mime_type'] . ';base64,' . base64_encode($bytes);
}
