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
        $pdo = new PDO('sqlite:' . $path, null, null, pdo_options());
        $pdo->exec('PRAGMA foreign_keys = ON');
        migrate_sqlite($pdo);
    } else {
        $pdo = mysql_pdo($cfg);
        migrate_mysql($pdo);
    }

    seed_demo_users($pdo);
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
            return new PDO($dsn, $user, $pass, pdo_options());
        } catch (PDOException $e) {
            $last = $e;
            if (!str_contains($e->getMessage(), '2002')) {
                throw $e;
            }
        }
    }

    throw $last ?? new RuntimeException('Unable to connect to MySQL.');
}

function pdo_options(): array
{
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
}

function migrate_mysql(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        display_name VARCHAR(120) NOT NULL,
        role ENUM('inspector', 'admin') NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS inspections (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        form_key VARCHAR(64) NOT NULL,
        inspector_id INT UNSIGNED NOT NULL,
        status ENUM('draft', 'submitted', 'reviewed', 'needs_info') NOT NULL DEFAULT 'draft',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        submitted_at DATETIME NULL,
        reviewed_at DATETIME NULL,
        reviewed_by INT UNSIGNED NULL,
        pdf_path VARCHAR(255) NULL,
        admin_comment MEDIUMTEXT NULL,
        admin_comment_at DATETIME NULL,
        admin_comment_by INT UNSIGNED NULL,
        returned_at DATETIME NULL,
        CONSTRAINT fk_inspections_inspector FOREIGN KEY (inspector_id) REFERENCES users (id),
        CONSTRAINT fk_inspections_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_answers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        inspection_id INT UNSIGNED NOT NULL,
        field_key VARCHAR(128) NOT NULL,
        field_value MEDIUMTEXT NULL,
        UNIQUE KEY uniq_inspection_field (inspection_id, field_key),
        CONSTRAINT fk_answers_inspection FOREIGN KEY (inspection_id) REFERENCES inspections (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attachments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        inspection_id INT UNSIGNED NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        file_size INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_attachments_inspection FOREIGN KEY (inspection_id) REFERENCES inspections (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migrate_inspections_columns($pdo, 'mysql');
}

function migrate_sqlite(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        display_name TEXT NOT NULL,
        role TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS inspections (
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_answers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        inspection_id INTEGER NOT NULL,
        field_key TEXT NOT NULL,
        field_value TEXT NULL,
        UNIQUE (inspection_id, field_key),
        FOREIGN KEY (inspection_id) REFERENCES inspections (id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        inspection_id INTEGER NOT NULL,
        stored_name TEXT NOT NULL,
        original_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        file_size INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (inspection_id) REFERENCES inspections (id) ON DELETE CASCADE
    )");

    migrate_inspections_columns($pdo, 'sqlite');
}

function migrate_inspections_columns(PDO $pdo, string $driver): void
{
    $types = $driver === 'mysql'
        ? [
            'admin_comment' => 'MEDIUMTEXT NULL',
            'admin_comment_at' => 'DATETIME NULL',
            'admin_comment_by' => 'INT UNSIGNED NULL',
            'returned_at' => 'DATETIME NULL',
        ]
        : [
            'admin_comment' => 'TEXT NULL',
            'admin_comment_at' => 'TEXT NULL',
            'admin_comment_by' => 'INTEGER NULL',
            'returned_at' => 'TEXT NULL',
        ];

    foreach ($types as $column => $type) {
        if (!inspection_has_column($pdo, $driver, $column)) {
            try {
                $pdo->exec("ALTER TABLE inspections ADD COLUMN {$column} {$type}");
            } catch (PDOException) {
                // Column may already exist under a different information_schema view.
            }
        }
    }

    if ($driver === 'mysql') {
        try {
            $pdo->exec("ALTER TABLE inspections MODIFY COLUMN status ENUM('draft', 'submitted', 'reviewed', 'needs_info') NOT NULL DEFAULT 'draft'");
        } catch (PDOException) {
            // Hostinger accounts sometimes cannot ALTER an existing ENUM; CREATE TABLE already has needs_info.
        }
    }
}

function inspection_has_column(PDO $pdo, string $driver, string $column): bool
{
    if ($driver === 'mysql') {
        $quoted = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $column);
        $stmt = $pdo->query('SHOW COLUMNS FROM inspections LIKE ' . $pdo->quote($quoted));
        return (bool) $stmt->fetch();
    }
    foreach ($pdo->query('PRAGMA table_info(inspections)') as $row) {
        if (strcasecmp((string) $row['name'], $column) === 0) {
            return true;
        }
    }
    return false;
}

function seed_demo_users(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, display_name, role) VALUES (?, ?, ?, ?)');
    $stmt->execute(['inspector', password_hash('inspector123', PASSWORD_DEFAULT), 'Alex Inspector', 'inspector']);
    $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Jordan Admin', 'admin']);
}
