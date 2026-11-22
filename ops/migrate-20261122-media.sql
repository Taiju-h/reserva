-- Apply once to an EXISTING Hanamaru MySQL database (the initial schema is in schema.mysql.sql).
CREATE TABLE IF NOT EXISTS site_media (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 menu_id BIGINT UNSIGNED NULL,
 kind VARCHAR(16) NOT NULL DEFAULT 'menu',
 file_name VARCHAR(100) NOT NULL,
 mime_type VARCHAR(32) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_site_media_menu (menu_id, id),
 CONSTRAINT fk_site_media_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inquiries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 email VARCHAR(254) NOT NULL,
 message TEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'new',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_inquiries_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waitlist (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 menu_id BIGINT UNSIGNED NOT NULL,
 preferred_date DATE NULL,
 email VARCHAR(254) NOT NULL,
 name VARCHAR(100) NOT NULL DEFAULT '',
 status VARCHAR(20) NOT NULL DEFAULT 'waiting',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_waitlist_menu_date (menu_id, preferred_date),
 CONSTRAINT fk_waitlist_menu FOREIGN KEY (menu_id) REFERENCES menus(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
