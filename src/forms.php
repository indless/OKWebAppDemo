<?php

declare(strict_types=1);

function form_definitions(): array
{
    static $forms = null;
    if ($forms !== null) {
        return $forms;
    }
    $forms = [];
    foreach (glob(ROOT . '/forms/*.json') ?: [] as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || empty($data['key'])) {
            continue;
        }
        $forms[$data['key']] = $data;
    }
    return $forms;
}

function form_definition(string $key): ?array
{
    return form_definitions()[$key] ?? null;
}

function form_fields(array $form): array
{
    $fields = [];
    foreach ($form['sections'] ?? [] as $section) {
        foreach ($section['fields'] ?? [] as $field) {
            $fields[$field['key']] = $field;
        }
    }
    return $fields;
}

function allowed_answer_keys(array $form): array
{
    $keys = [];
    foreach (form_fields($form) as $field) {
        $keys[] = $field['key'];
        if (!empty($field['notes'])) {
            $keys[] = $field['key'] . '_notes';
        }
    }
    return $keys;
}

function collect_answers_from_post(array $form): array
{
    $answers = [];
    foreach (allowed_answer_keys($form) as $key) {
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        $value = $_POST[$key];
        if (is_array($value)) {
            continue;
        }
        $answers[$key] = trim((string) $value);
    }
    return $answers;
}

function validate_required_answers(array $form, array $answers): array
{
    $errors = [];
    foreach (form_fields($form) as $field) {
        if (empty($field['required'])) {
            continue;
        }
        $value = trim((string) ($answers[$field['key']] ?? ''));
        if ($value === '') {
            $errors[] = $field['label'] . ' is required.';
        }
    }
    return $errors;
}

function default_answers_for_user(array $form, array $user): array
{
    $answers = [
        'inspection_date' => date('Y-m-d'),
        'inspector_name' => $user['display_name'],
    ];
    return $answers;
}
