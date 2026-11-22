-- Hanamaru / MySQL 8.0 schema. Initial creation only.
-- The database is created by the MySQL image with DB_DATABASE from .env.
CREATE TABLE IF NOT EXISTS menus (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 description TEXT NOT NULL,
 duration INT NOT NULL,
 price INT NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 menu_id BIGINT UNSIGNED NOT NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 customer_name VARCHAR(100) NOT NULL,
 email VARCHAR(254) NOT NULL,
 phone VARCHAR(40) NOT NULL DEFAULT '',
 note TEXT NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'confirmed',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_bookings_times (starts_at, ends_at, status),
 KEY idx_bookings_email (email),
 CONSTRAINT fk_bookings_menu FOREIGN KEY (menu_id) REFERENCES menus(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
 `key` VARCHAR(100) PRIMARY KEY,
 `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_payments (
 booking_id BIGINT UNSIGNED PRIMARY KEY,
 session_id VARCHAR(255) UNIQUE,
 amount_jpy INT NOT NULL,
 expires_at DATETIME NOT NULL,
 state VARCHAR(30) NOT NULL DEFAULT 'initiating',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_stripe_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_events (
 event_id VARCHAR(255) PRIMARY KEY,
 event_type VARCHAR(100) NOT NULL,
 processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO menus(name,description,duration,price,active)
 SELECT '起業・経営相談','50分の個別相談',50,5500,1
 WHERE NOT EXISTS (SELECT 1 FROM menus);

INSERT IGNORE INTO settings (`key`,`value`) VALUES
 ('site_name','はなキャリ'),('open_time','07:00'),('close_time','22:00'),
 ('slot_minutes','30'),('closed_weekdays',''),('closed_dates',''),
 ('lead_minutes','60'),('max_advance_days','90');
