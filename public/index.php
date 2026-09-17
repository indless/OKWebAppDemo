<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$path = request_route();
$method = request_method();

if ($path === '' || $path === 'index.php') {
    $user = current_user();
    if (!$user) {
        redirect('login');
    }
    redirect($user['role'] === 'admin' ? 'admin' : 'home');
}

if ($path === 'login' && $method === 'GET') {
    if (current_user()) {
        redirect(current_user()['role'] === 'admin' ? 'admin' : 'home');
    }
    render('login.twig');
}

if ($path === 'login' && $method === 'POST') {
    csrf_verify();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $password === '' || !attempt_login($username, $password)) {
        flash('error', 'Invalid username or password.');
        redirect('login');
    }
    $user = current_user();
    flash('success', 'Signed in as ' . $user['display_name'] . '.');
    redirect($user['role'] === 'admin' ? 'admin' : 'home');
}

if ($path === 'logout' && $method === 'POST') {
    csrf_verify();
    logout_user();
    session_name((string) (app_config('app.session_name') ?: 'inspect_demo'));
    session_start();
    flash('success', 'You have been signed out.');
    redirect('login');
}

if ($path === 'home') {
    $user = require_role('inspector');
    $forms = form_definitions();
    render('home.twig', [
        'form_list' => array_values($forms),
        'forms' => $forms,
        'inspections' => list_inspections_for_user((int) $user['id']),
    ]);
}

if ($path === 'inspections' && $method === 'GET') {
    $user = require_role('inspector');
    render('my_inspections.twig', [
        'inspections' => list_inspections_for_user((int) $user['id']),
        'forms' => form_definitions(),
    ]);
}

if ($path === 'inspections/new' && $method === 'POST') {
    $user = require_role('inspector');
    csrf_verify();
    $formKey = (string) ($_POST['form_key'] ?? '');
    $form = form_definition($formKey);
    if (!$form) {
        flash('error', 'Unknown inspection form.');
        redirect('home');
    }
    $id = create_inspection($formKey, (int) $user['id']);
    save_answers($id, default_answers_for_user($form, $user));
    redirect('inspections/' . $id . '/edit');
}

if (preg_match('#^inspections/(\d+)/edit$#', $path, $m)) {
    $user = require_role('inspector');
    $inspection = find_inspection((int) $m[1]);
    if (!$inspection || !can_edit_inspection($user, $inspection)) {
        flash('error', 'You cannot edit that inspection.');
        redirect('home');
    }
    $form = form_definition($inspection['form_key']);
    if (!$form) {
        not_found();
    }

    if ($method === 'POST') {
        csrf_verify();
        $action = (string) ($_POST['action'] ?? 'draft');
        $answers = collect_answers_from_post($form);
        save_answers((int) $inspection['id'], $answers);

        $deleteIds = array_map('intval', (array) ($_POST['delete_attachments'] ?? []));
        process_attachment_deletes((int) $inspection['id'], $deleteIds);

        $max = (int) ($form['attachments']['max'] ?? 3);
        $uploadErrors = process_attachment_uploads((int) $inspection['id'], $max);

        if ($uploadErrors) {
            flash('error', implode(' ', $uploadErrors));
            redirect('inspections/' . $inspection['id'] . '/edit');
        }

        if ($action === 'submit') {
            $answers = inspection_answers((int) $inspection['id']);
            $errors = validate_required_answers($form, $answers);
            if ($errors) {
                flash('error', implode(' ', $errors));
                redirect('inspections/' . $inspection['id'] . '/edit');
            }
            submit_inspection((int) $inspection['id']);
            $inspection = find_inspection((int) $inspection['id']);
            $attachments = inspection_attachments((int) $inspection['id']);
            try {
                generate_inspection_pdf($inspection, $form, $answers, $attachments);
            } catch (Throwable $e) {
                flash('error', 'Changes saved, but PDF generation failed: ' . $e->getMessage());
                redirect('inspections/' . $inspection['id']);
            }
            flash('success', 'Inspection submitted and PDF updated.');
            redirect('inspections/' . $inspection['id']);
        }

        if ($inspection['status'] !== 'needs_info') {
            mark_inspection_draft((int) $inspection['id']);
        }
        flash('success', 'Draft saved.');
        redirect('inspections/' . $inspection['id'] . '/edit');
    }

    render('form.twig', [
        'form' => $form,
        'inspection' => $inspection,
        'answers' => inspection_answers((int) $inspection['id']),
        'attachments' => inspection_attachments((int) $inspection['id']),
        'cancel_url' => (!empty($inspection['submitted_at']) || $inspection['status'] !== 'draft')
            ? 'inspections/' . $inspection['id']
            : 'inspections',
    ]);
}

if (preg_match('#^inspections/(\d+)$#', $path, $m)) {
    $user = require_login();
    $inspection = find_inspection((int) $m[1]);
    if (!$inspection || !can_view_inspection($user, $inspection)) {
        http_response_code(404);
        render('error.twig', ['title' => 'Not found', 'message' => 'Inspection not found.']);
    }
    $form = form_definition($inspection['form_key']);
    if (!$form) {
        not_found();
    }
    $answers = inspection_answers((int) $inspection['id']);
    render('inspection_view.twig', [
        'form' => $form,
        'inspection' => $inspection,
        'answers' => $answers,
        'attachments' => inspection_attachments((int) $inspection['id']),
        'title_text' => inspection_title($inspection, $answers, $form),
        'is_admin' => $user['role'] === 'admin',
        'can_edit' => can_edit_inspection($user, $inspection),
    ]);
}

if (preg_match('#^inspections/(\d+)/pdf$#', $path, $m)) {
    $user = require_login();
    $inspection = find_inspection((int) $m[1]);
    if (!$inspection || !can_view_inspection($user, $inspection) || empty($inspection['pdf_path'])) {
        not_found();
    }
    $abs = storage_path($inspection['pdf_path']);
    send_file($abs, 'inspection-' . $inspection['id'] . '.pdf', 'application/pdf', true);
}

if (preg_match('#^attachments/(\d+)$#', $path, $m)) {
    $user = require_login();
    $attachment = find_attachment((int) $m[1]);
    if (!$attachment) {
        not_found();
    }
    $inspection = find_inspection((int) $attachment['inspection_id']);
    if (!$inspection || !can_view_inspection($user, $inspection)) {
        not_found();
    }
    send_file(
        attachment_abs_path($attachment),
        $attachment['original_name'],
        $attachment['mime_type'],
        true
    );
}

if ($path === 'admin') {
    require_role('admin');
    $forms = form_definitions();
    $inspections = list_submitted_inspections();
    render('admin/dashboard.twig', [
        'inspections' => $inspections,
        'forms' => $forms,
        'stats' => queue_status_stats($inspections),
    ]);
}

if (preg_match('#^admin/inspections/(\d+)/notes$#', $path, $m) && $method === 'POST') {
    $user = require_role('admin');
    csrf_verify();
    $inspection = find_inspection((int) $m[1]);
    if (!$inspection) {
        flash('error', 'Inspection not found.');
        redirect('admin');
    }
    $action = (string) ($_POST['action'] ?? 'save');
    $comment = trim((string) ($_POST['admin_comment'] ?? ''));
    if ($action === 'needs_info' && $comment === '') {
        flash('error', 'Add an admin comment before requesting more information.');
        redirect('inspections/' . $inspection['id']);
    }
    save_admin_comment((int) $inspection['id'], $comment, (int) $user['id']);
    if ($action === 'needs_info') {
        mark_inspection_needs_info((int) $inspection['id']);
        flash('success', 'Inspection returned to the inspector. The comment is not included in the PDF.');
        redirect('inspections/' . $inspection['id']);
    }
    if ($action === 'review') {
        if (!in_array($inspection['status'], ['submitted', 'needs_info'], true)) {
            flash('error', 'Only submitted or returned inspections can be marked reviewed.');
            redirect('inspections/' . $inspection['id']);
        }
        mark_inspection_reviewed((int) $inspection['id'], (int) $user['id']);
        flash('success', 'Inspection marked as reviewed.');
        redirect('inspections/' . $inspection['id']);
    }
    flash('success', 'Admin comment saved. It is not included in the PDF.');
    redirect('inspections/' . $inspection['id']);
}

if (preg_match('#^admin/inspections/(\d+)/review$#', $path, $m) && $method === 'POST') {
    $user = require_role('admin');
    csrf_verify();
    $inspection = find_inspection((int) $m[1]);
    if (!$inspection || !in_array($inspection['status'], ['submitted', 'needs_info'], true)) {
        flash('error', 'Only submitted or returned inspections can be marked reviewed.');
        redirect('admin');
    }
    $comment = trim((string) ($_POST['admin_comment'] ?? ''));
    if ($comment !== '' || has_admin_comment($inspection)) {
        save_admin_comment((int) $inspection['id'], $comment !== '' ? $comment : (string) $inspection['admin_comment'], (int) $user['id']);
    }
    mark_inspection_reviewed((int) $inspection['id'], (int) $user['id']);
    flash('success', 'Inspection marked as reviewed.');
    redirect('inspections/' . $inspection['id']);
}

not_found();
