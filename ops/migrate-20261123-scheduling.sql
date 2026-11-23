-- Additive layer-based scheduling (run once via ops/migrate-scheduling.php).
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
 INDEX idx_schedule_rules_scope (scope,target_id,enabled),
 INDEX idx_schedule_rules_dates (start_date,end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
