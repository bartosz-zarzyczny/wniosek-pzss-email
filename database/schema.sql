CREATE TABLE IF NOT EXISTS competitions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    event_date DATE NOT NULL,
    location VARCHAR(60) NOT NULL,
    calendar ENUM('PZSS', 'WZSS', 'MZSS', 'other') NOT NULL,
    source_key VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_source (source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS competition_results (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    competition_id INT UNSIGNED NOT NULL,
    discipline ENUM('pistol', 'rifle', 'shotgun') NOT NULL,
    license_number VARCHAR(10) NOT NULL,
    name_key VARCHAR(160) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_start (competition_id, discipline, license_number),
    KEY idx_license (license_number),
    CONSTRAINT fk_results_competition FOREIGN KEY (competition_id)
        REFERENCES competitions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lookup_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(40) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pdf_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    url VARCHAR(255) NOT NULL,
    file_name VARCHAR(200) NOT NULL,
    etag VARCHAR(200) NULL,
    last_modified VARCHAR(60) NULL,
    size BIGINT UNSIGNED NULL,
    sha256 CHAR(64) NULL,
    downloaded_at DATETIME NULL,
    imported_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    message VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_url (url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;