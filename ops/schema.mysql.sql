SET NAMES utf8mb4;
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

-- Individual staff admin accounts (master password stays in .env).
CREATE TABLE IF NOT EXISTS admin_users (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 login_id VARCHAR(50) NOT NULL UNIQUE,
 display_name VARCHAR(80) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_login_at DATETIME NULL
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

-- Schedule layers for fresh deployments.
CREATE TABLE IF NOT EXISTS teachers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS schedule_rules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 scope ENUM('store','teacher','menu') NOT NULL,
 target_id BIGINT UNSIGNED NULL,
 decision ENUM('allow','deny') NOT NULL,
 label VARCHAR(120) NOT NULL DEFAULT '',
 start_date DATE NULL,
 end_date DATE NULL,
 weekdays VARCHAR(30) NOT NULL DEFAULT '0,1,2,3,4,5,6',
 start_time TIME NOT NULL,
 end_time TIME NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_schedule_rules_scope(scope,target_id,enabled),
 INDEX idx_schedule_rules_dates(start_date,end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instructor profiles / qualification master (new installations).
CREATE TABLE IF NOT EXISTS teacher_profiles (
 teacher_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 summary TEXT NULL, career MEDIUMTEXT NULL,
 qualifications_text MEDIUMTEXT NULL,
 training MEDIUMTEXT NULL, activity_area TEXT NULL, message MEDIUMTEXT NULL,
 photo_path VARCHAR(255) NULL, photo_source_url TEXT NULL,
 source_url TEXT NULL, source_checked_at DATETIME NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_teacher_profiles_teacher FOREIGN KEY(teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS qualification_master (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(250) NOT NULL,
 category VARCHAR(80) NOT NULL DEFAULT '未分類',
 UNIQUE KEY uq_qualification_name(name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS teacher_qualifications (
 teacher_id BIGINT UNSIGNED NOT NULL,
 qualification_id BIGINT UNSIGNED NOT NULL,
 note VARCHAR(255) NOT NULL DEFAULT '',
 PRIMARY KEY(teacher_id,qualification_id),
 CONSTRAINT fk_tq_teacher FOREIGN KEY(teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
 CONSTRAINT fk_tq_qualification FOREIGN KEY(qualification_id) REFERENCES qualification_master(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Portrait assets retained in MySQL rather than ephemeral container file storage.
CREATE TABLE IF NOT EXISTS teacher_photo_assets (
 teacher_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 mime VARCHAR(32) NOT NULL,
 image_bytes MEDIUMBLOB NOT NULL,
 original_url TEXT NULL,
 imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_teacher_photo_asset FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Company-funded appointments (new installs); existing sites use
-- ops/migrate-corporate-bookings.php for additive migration.
CREATE TABLE IF NOT EXISTS corporate_campaigns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,code VARCHAR(40) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,access_code_hash VARCHAR(255) NOT NULL,
 starts_on DATE NOT NULL,ends_on DATE NOT NULL,unit_price INT NOT NULL DEFAULT 8000,
 published TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS corporate_teachers (
 campaign_id BIGINT UNSIGNED NOT NULL,teacher_id BIGINT UNSIGNED NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,PRIMARY KEY(campaign_id,teacher_id),
 FOREIGN KEY(campaign_id) REFERENCES corporate_campaigns(id),
 FOREIGN KEY(teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS corporate_slots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,
 teacher_id BIGINT UNSIGNED NOT NULL,starts_at DATETIME NOT NULL,ends_at DATETIME NOT NULL,
 booked_id BIGINT UNSIGNED NULL, UNIQUE KEY uq_corporate_slot(campaign_id,teacher_id,starts_at),
 KEY idx_corporate_time(teacher_id,starts_at,ends_at),
 FOREIGN KEY(campaign_id) REFERENCES corporate_campaigns(id),
 FOREIGN KEY(teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS corporate_customers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(254) NOT NULL,name VARCHAR(100) NOT NULL,
 phone VARCHAR(40) NOT NULL DEFAULT '',employee_reference VARCHAR(80) NULL,
 email_verified_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_corporate_customer_email(campaign_id,email),
 FOREIGN KEY(campaign_id) REFERENCES corporate_campaigns(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS corporate_bookings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,
 slot_id BIGINT UNSIGNED NOT NULL,teacher_id BIGINT UNSIGNED NOT NULL,
 customer_id BIGINT UNSIGNED NULL,customer_name VARCHAR(100) NOT NULL,
 customer_email VARCHAR(254) NOT NULL,customer_phone VARCHAR(40) NOT NULL DEFAULT '',
 employee_reference VARCHAR(80) NULL,
 status ENUM('confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'confirmed',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL, KEY idx_corporate_booking_campaign_status(campaign_id,status),
 KEY idx_corporate_booking_email(customer_email),
 FOREIGN KEY(campaign_id) REFERENCES corporate_campaigns(id),
 FOREIGN KEY(slot_id) REFERENCES corporate_slots(id),
 FOREIGN KEY(teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS teacher_logins (
 teacher_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,login_id VARCHAR(100) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,
 version INT UNSIGNED NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(teacher_id) REFERENCES teachers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional, protected pre-registration list for a company campaign.
-- Never present the full employee roster to unauthenticated visitors.
CREATE TABLE IF NOT EXISTS corporate_invitees (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 campaign_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(254) NOT NULL,
 display_name VARCHAR(100) NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_corporate_invitee(campaign_id,email),
 FOREIGN KEY(campaign_id) REFERENCES corporate_campaigns(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- On existing installations: ops/migrate-corporate-bookings.php adds
-- corporate_campaigns.roster_required DEFAULT 0.
