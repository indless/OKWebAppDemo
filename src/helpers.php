<?php

declare(strict_types=1);

function app_config(?string $dotKey = null): mixed
{
    global $config;
    if ($dotKey === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $dotKey) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function base_url(): string
{
    $configured = trim((string) (app_config('app.base_url') ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = dirname($script);
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        return '';
    }
    return rtrim($dir, '/');
}

function url(string $path = '', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    if ($path === '/') {
        $path = '';
    }
    $q = $query ? ('?' . http_build_query($query)) : '';
    return base_url() . $path . $q;
}

function request_route(): string
{
    if (isset($_GET['r'])) {
        return trim((string) $_GET['r'], '/');
    }
    $uri = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $base = base_url();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    $uri = preg_replace('#/index\.php$#', '', $uri) ?? $uri;
    return trim($uri, '/');
}

function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query));
    exit;
}

function flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null && $message !== null) {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
        return null;
    }
    $item = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($item) ? $item : null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_verify(): void
{
    $token = (string) ($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        echo 'Invalid request token. Please go back and try again.';
        exit;
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y g:i A', $ts) : $value;
}

function format_age_days(?int $days): string
{
    if ($days === null) {
        return '—';
    }
    return $days === 1 ? '1 day' : $days . ' days';
}

function submission_age_days(?string $status, ?string $submittedAt, ?string $returnedAt = null): ?int
{
    if ($status === 'submitted' && $submittedAt) {
        return days_since($submittedAt);
    }
    if ($status === 'needs_info') {
        $from = $returnedAt ?: $submittedAt;
        return $from ? days_since($from) : null;
    }
    return null;
}

function days_since(string $datetime): ?int
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return null;
    }
    return max(0, (int) floor((time() - $ts) / 86400));
}

function status_label(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'needs_info' => 'Needs info',
        'reviewed' => 'Reviewed',
        default => $status,
    };
}

function option_label(array $field, string $value): string
{
    foreach ($field['options'] ?? [] as $option) {
        if ((string) $option['value'] === $value) {
            return (string) $option['label'];
        }
    }
    return $value;
}

function storage_path(string $relative = ''): string
{
    $base = ROOT . '/storage';
    return $relative === '' ? $base : $base . '/' . ltrim(str_replace('\\', '/', $relative), '/');
}

function ensure_dir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}
