-- Camera Portal — schema
-- Run once: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS camera_portal CHARACTER SET utf8mb4;
USE camera_portal;

CREATE TABLE IF NOT EXISTS cameras (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(64)  NOT NULL,          -- short id used in go2rtc / grid (e.g. gate_01)
    label         VARCHAR(128) NOT NULL,          -- friendly display name
    ip            VARCHAR(64)  NOT NULL,
    port          INT          NOT NULL DEFAULT 554,
    rtsp_path     VARCHAR(255) NOT NULL DEFAULT '/',
    sub_rtsp_path VARCHAR(255) NULL, -- e.g. /Streaming/Channels/101
    username      VARCHAR(64)  NULL,
    password_enc  VARBINARY(512) NULL,            -- AES-encrypted, see includes/functions.php
    detect_object TINYINT(1)   NOT NULL DEFAULT 0,
    detect_face   TINYINT(1)   NOT NULL DEFAULT 0,
    recording_mode ENUM('off','continuous','motion') NOT NULL DEFAULT 'off',
    enabled       TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order    INT          NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB;

-- Detection events (populated later by the detection worker — table is ready now)
CREATE TABLE IF NOT EXISTS events (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    camera_id     INT NOT NULL,
    event_type    ENUM('motion','object','face') NOT NULL,
    label         VARCHAR(64)  NULL,              -- e.g. 'person', 'car', or matched face name
    confidence    FLOAT NULL,
    snapshot_path VARCHAR(255) NULL,
    notified      TINYINT(1) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE,
    INDEX idx_camera_time (camera_id, created_at)
) ENGINE=InnoDB;

-- Admin users for the panel itself
CREATE TABLE IF NOT EXISTS admin_users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','viewer') NOT NULL DEFAULT 'admin',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Which cameras a 'viewer'-role user can see. Irrelevant for 'admin' users,
-- who always see everything — a row here only ever restricts, never grants
-- beyond what an admin already has.
CREATE TABLE IF NOT EXISTS camera_permissions (
    user_id   INT NOT NULL,
    camera_id INT NOT NULL,
    PRIMARY KEY (user_id, camera_id),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB;
