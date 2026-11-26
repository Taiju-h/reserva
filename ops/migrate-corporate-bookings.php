<?php
declare(strict_types=1);
// Additive, idempotent schema for a company-funded second-career pilot.
if(PHP_SAPI!=='cli')exit(1);
$vars=[];
foreach(file(dirname(__DIR__).'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){$line=trim($line);if(!$line||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$k,$v]=explode('=',$line,2);$vars[trim($k)]=trim($v," \t\n\r\0\x0B\"'");}
if(($vars['DB_CONNECTION']??'')!=='mysql'){fwrite(STDERR,"DB_CONNECTION=mysql required\n");exit(1);}
try{
  $pdo=new PDO('mysql:host='.($vars['DB_HOST']??'mysql').';port='.($vars['DB_PORT']??'3306').';dbname='.($vars['DB_DATABASE']??'hanamaru').';charset=utf8mb4',$vars['DB_USERNAME']??'hanamaru',$vars['DB_PASSWORD']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $pdo->exec('CREATE TABLE IF NOT EXISTS corporate_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    access_code_hash VARCHAR(255) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    unit_price INT NOT NULL DEFAULT 8000,
    published TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $pdo->exec('CREATE TABLE IF NOT EXISTS corporate_teachers (
    campaign_id BIGINT UNSIGNED NOT NULL,
    teacher_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY(campaign_id,teacher_id),
    CONSTRAINT fk_corporate_teacher_campaign FOREIGN KEY (campaign_id) REFERENCES corporate_campaigns(id),
    CONSTRAINT fk_corporate_teacher_id FOREIGN KEY (teacher_id) REFERENCES teachers(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $pdo->exec('CREATE TABLE IF NOT EXISTS corporate_slots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    teacher_id BIGINT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    booked_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_corporate_slot(campaign_id,teacher_id,starts_at),
    KEY idx_corporate_time (teacher_id,starts_at,ends_at),
    CONSTRAINT fk_corporate_slot_campaign FOREIGN KEY (campaign_id) REFERENCES corporate_campaigns(id),
    CONSTRAINT fk_corporate_slot_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $pdo->exec('CREATE TABLE IF NOT EXISTS corporate_bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    slot_id BIGINT UNSIGNED NOT NULL,
    teacher_id BIGINT UNSIGNED NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_email VARCHAR(254) NOT NULL,
    customer_phone VARCHAR(40) NOT NULL DEFAULT "",
    employee_reference VARCHAR(80) NULL,
    status ENUM("confirmed","completed","cancelled","no_show") NOT NULL DEFAULT "confirmed",
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    KEY idx_corporate_booking_campaign_status(campaign_id,status),
    KEY idx_corporate_booking_email(customer_email),
    CONSTRAINT fk_corporate_booking_campaign FOREIGN KEY(campaign_id) REFERENCES corporate_campaigns(id),
    CONSTRAINT fk_corporate_booking_slot FOREIGN KEY(slot_id) REFERENCES corporate_slots(id),
    CONSTRAINT fk_corporate_booking_teacher FOREIGN KEY(teacher_id) REFERENCES teachers(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $pdo->exec('CREATE TABLE IF NOT EXISTS teacher_logins (
    teacher_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    login_id VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_teacher_login FOREIGN KEY(teacher_id) REFERENCES teachers(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $pdo->exec('CREATE TABLE IF NOT EXISTS corporate_customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(254) NOT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(40) NOT NULL DEFAULT "",
    employee_reference VARCHAR(80) NULL,
    email_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_corporate_customer_email(campaign_id,email),
    CONSTRAINT fk_corporate_customer_campaign FOREIGN KEY(campaign_id) REFERENCES corporate_campaigns(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  $hasCustomer=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='corporate_bookings' AND COLUMN_NAME='customer_id'")->fetchColumn();
  if(!$hasCustomer)$pdo->exec('ALTER TABLE corporate_bookings ADD COLUMN customer_id BIGINT UNSIGNED NULL, ADD INDEX idx_corporate_customer_id(customer_id)');
  echo "Corporate pilot schema ready; no public campaigns were enabled.\n";
}catch(Throwable $e){fwrite(STDERR,"Migration failed: ".$e->getMessage()."\n");exit(1);}
