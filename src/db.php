<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = app_config('db');
    $driver = $cfg['driver'] ?? 'mysql';

    if ($driver === 'sqlite') {
        $path = $cfg['path'] ?? (ROOT . '/storage/app.sqlite');
        ensure_dir(dirname($path));
        $fresh = !is_file($path);
        $pdo = new PDO('sqlite:' . $path, null, null, pdo_options(false));
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($fresh) {
            init_sqlite($pdo);
        }
    } else {
        $pdo = mysql_pdo($cfg);
    }

    return $pdo;
}

function mysql_pdo(array $cfg): PDO
{
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException(
            'PHP PDO MySQL driver is missing. In hPanel → PHP Configuration, enable nd_pdo_mysql (or pdo_mysql).'
        );
    }

    $name = (string) ($cfg['name'] ?? 'inspections');
    $charset = (string) ($cfg['charset'] ?? 'utf8mb4');
    $user = (string) ($cfg['user'] ?? '');
    $pass = (string) ($cfg['pass'] ?? '');
    $host = (string) ($cfg['host'] ?? 'localhost');
    $hosts = [$host];
    if ($host === 'localhost') {
        $hosts[] = '127.0.0.1';
    } elseif ($host === '127.0.0.1') {
        $hosts[] = 'localhost';
    }

    $last = null;
    foreach (array_unique($hosts) as $tryHost) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $tryHost, $name, $charset);
        try {
            return new PDO($dsn, $user, $pass, pdo_options(true));
        } catch (PDOException $e) {
            $last = $e;
            if (!str_contains($e->getMessage(), '2002')) {
                throw $e;
            }
        }
    }

    throw $last ?? new RuntimeException('Unable to connect to MySQL.');
}

function pdo_options(bool $emulatePrepares): array
{
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => $emulatePrepares,
    ];
}

function init_sqlite(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        display_name TEXT NOT NULL,
        role TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE inspections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        form_key TEXT NOT NULL,
        inspector_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        submitted_at TEXT NULL,
        reviewed_at TEXT NULL,
        reviewed_by INTEGER NULL,
        pdf_path TEXT NULL,
        admin_comment TEXT NULL,
        admin_comment_at TEXT NULL,
        admin_comment_by INTEGER NULL,
        returned_at TEXT NULL,
        FOREIGN KEY (inspector_id) REFERENCES users (id),
        FOREIGN KEY (reviewed_by) REFERENCES users (id)
    )");

    $pdo->exec("CREATE TABLE inspection_answers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        inspection_id INTEGER NOT NULL,
        field_key TEXT NOT NULL,
        field_value TEXT NULL,
        UNIQUE (inspection_id, field_key),
        FOREIGN KEY (inspection_id) REFERENCES inspections (id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        inspection_id INTEGER NOT NULL,
        stored_name TEXT NOT NULL,
        original_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        file_size INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (inspection_id) REFERENCES inspections (id) ON DELETE CASCADE
    )");

    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, display_name, role) VALUES (?, ?, ?, ?)');
    $stmt->execute(['inspector', password_hash('inspector123', PASSWORD_DEFAULT), 'Alex Inspector', 'inspector']);
    $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Jordan Admin', 'admin']);
}
