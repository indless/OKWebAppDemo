# Field Inspections Demo

Interactive HTML inspection forms, SQL storage, photo attachments, and generated PDF reports. Built to run on **Hostinger Web/Cloud shared hosting** (PHP 8 + MySQL/MariaDB).

Twig templates are used for both the web UI and PDF layouts — the same “template + stored data → document” pattern as Jinja in a Python stack.

## Demo accounts

| Role | Username | Password |
| --- | --- | --- |
| Inspector | `inspector` | `inspector123` |
| Admin | `admin` | `admin123` |

Inspectors start a **Field Safety Inspection** or **Equipment Inspection**, save drafts, attach up to 3 photos, sign, and submit. Submit stores answers in SQL and writes a PDF. Admins review submitted reports and download PDFs.

## Local development

You need PHP 8.1+ with the `pdo_mysql` or `pdo_sqlite`, `mbstring`, `fileinfo`, and `gd` extensions, plus [Composer](https://getcomposer.org/).

```powershell
Copy-Item config.example.php config.php
composer install
php -S localhost:8080 -t public public/router.php
```

On macOS/Linux, use `cp config.example.php config.php` instead of `Copy-Item`.

Open http://localhost:8080

For a MySQL-free local tryout, set `"driver" => "sqlite"` in `config.php` (see the `path` key in `config.example.php`). A new SQLite file is created with tables and demo users; later requests do not inspect or rebuild the schema.

To use MySQL locally instead:

1. Create a database and import [`sql/schema.sql`](sql/schema.sql).
2. Set `driver` to `mysql` and fill in `host`, `name`, `user`, and `pass` in `config.php`.

## Hostinger deployment

Hostinger shared hosting includes **MySQL/MariaDB**, not SQL Server. Python/Flask/Docker are VPS-only; this demo is PHP so it runs on Web/Cloud plans.

1. In hPanel, open **Databases** and create a MySQL database + user. Note host (`localhost`), database name, username, and password.
2. Import [`sql/schema.sql`](sql/schema.sql) in phpMyAdmin. The app does not create or check tables on login, save, or logout.
3. Copy `config.example.php` to `config.php` and set:

```php
'db' => [
    'driver' => 'mysql',
    'host' => 'localhost',
    'name' => 'YOUR_DB_NAME',
    'user' => 'YOUR_DB_USER',
    'pass' => 'YOUR_DB_PASSWORD',
    'charset' => 'utf8mb4',
],
```

4. Upload the project (FTP, File Manager, or Git). Prefer pointing the domain or subdomain **document root** at the `public/` folder. Keep `src/`, `templates/`, `forms/`, `vendor/`, `storage/`, and `config.php` **outside** the document root.
5. If you cannot change the document root, upload the whole project into `public_html`. The root `.htaccess` serves the app at `/login` (not `/public/login`) and blocks `src`, `vendor`, `storage`, and `config.php`.
6. Make `storage/uploads` and `storage/pdfs` writable (755 or 775).
7. In hPanel, set PHP to **8.2 or 8.3**, and raise `upload_max_filesize` / `post_max_size` to at least **8 MB**.
8. Enable HTTPS (Hostinger SSL).

`vendor/` is intended to be uploaded with the project so you do not need Composer on the server. If you deploy from a machine that already ran `composer install`, include that `vendor` folder.

## Adding more forms later

Each existing fillable PDF becomes:

1. A JSON definition in `forms/` (field keys, labels, types, required flags).
2. A matching Twig PDF template in `templates/pdf/`.

No new database tables. Answers stay in `inspection_answers` as field key/value rows.

## Layout

- `public/` — web root (front controller, CSS, JS)
- `src/` — PDO, auth, forms, attachments, PDF
- `templates/` — Twig pages and PDF layouts
- `forms/` — JSON form registry
- `sql/schema.sql` — Hostinger MySQL import
- `storage/` — uploaded photos and generated PDFs (not served directly)
