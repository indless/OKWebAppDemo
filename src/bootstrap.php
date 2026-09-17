<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$configPath = ROOT . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Missing config.php. Copy config.example.php to config.php and set database credentials.';
    exit;
}

$config = require $configPath;

$autoload = ROOT . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo 'Missing vendor/autoload.php. Run composer install before launching the app.';
    exit;
}

require $autoload;
require ROOT . '/src/helpers.php';
require ROOT . '/src/db.php';
require ROOT . '/src/auth.php';
require ROOT . '/src/forms.php';
require ROOT . '/src/inspections.php';
require ROOT . '/src/attachments.php';
require ROOT . '/src/pdf.php';

$sessionOptions = [
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
];
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $sessionOptions['cookie_secure'] = true;
}
session_name((string) (app_config('app.session_name') ?: 'inspect_demo'));
session_start($sessionOptions);

$twigLoader = new Twig\Loader\FilesystemLoader(ROOT . '/templates');
$twig = new Twig\Environment($twigLoader, [
    'cache' => false,
    'autoescape' => 'html',
    'debug' => (bool) app_config('app.debug'),
]);
$twig->addGlobal('app_name', app_config('app.name') ?: 'Field Inspections');
$twig->addGlobal('current_user', current_user());
$twig->addGlobal('csrf_token', csrf_token());
$twig->addGlobal('flash', flash());
$twig->addFunction(new Twig\TwigFunction('url', 'url'));
$twig->addFunction(new Twig\TwigFunction('status_label', 'status_label'));
$twig->addFunction(new Twig\TwigFunction('format_datetime', 'format_datetime'));
$twig->addFunction(new Twig\TwigFunction('option_label', 'option_label'));
$twig->addFilter(new Twig\TwigFilter('nl2br_e', static function (?string $value): string {
    return nl2br(htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
}, ['is_safe' => ['html']]));

function render(string $template, array $data = []): never
{
    global $twig;
    echo $twig->render($template, $data);
    exit;
}

function not_found(): never
{
    http_response_code(404);
    render('error.twig', [
        'title' => 'Not found',
        'message' => 'That page does not exist.',
    ]);
}

function send_file(string $absPath, string $downloadName, string $mime, bool $inline = false): never
{
    if (!is_file($absPath)) {
        not_found();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($absPath));
    $disposition = $inline ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $downloadName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($absPath);
    exit;
}
