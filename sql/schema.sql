-- Hostinger MySQL / MariaDB schema for the inspections demo.
-- Import this in hPanel → Databases → phpMyAdmin (or the MySQL import tool).

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    role ENUM('inspector', 'admin') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inspections (
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
    CONSTRAINT fk_inspections_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id),
    INDEX idx_inspections_status (status),
    INDEX idx_inspections_inspector (inspector_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inspection_answers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id INT UNSIGNED NOT NULL,
    field_key VARCHAR(128) NOT NULL,
    field_value MEDIUMTEXT NULL,
    UNIQUE KEY uniq_inspection_field (inspection_id, field_key),
    CONSTRAINT fk_answers_inspection FOREIGN KEY (inspection_id) REFERENCES inspections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspection_id INT UNSIGNED NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attachments_inspection FOREIGN KEY (inspection_id) REFERENCES inspections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demo users are created automatically on first app load (inspector / inspector123, admin / admin123).
