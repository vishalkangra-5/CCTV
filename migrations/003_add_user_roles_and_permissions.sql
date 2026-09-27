-- Adds roles (admin/viewer) and per-camera view permissions for viewer-role
-- users. Existing users all become 'admin' (unchanged behavior for them).

USE camera_portal;

ALTER TABLE admin_users
    ADD COLUMN role ENUM('admin','viewer') NOT NULL DEFAULT 'admin' AFTER password_hash;

CREATE TABLE IF NOT EXISTS camera_permissions (
    user_id   INT NOT NULL,
    camera_id INT NOT NULL,
    PRIMARY KEY (user_id, camera_id),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE
) ENGINE=InnoDB;
