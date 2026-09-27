-- Run this once on an existing database (you already ran the original schema.sql).
-- Safe to run even if schema.sql already has the column, MySQL will error harmlessly
-- with "duplicate column" if so — just skip it in that case.

USE camera_portal;

ALTER TABLE cameras
    ADD COLUMN recording_mode ENUM('off','continuous','motion') NOT NULL DEFAULT 'off'
    AFTER detect_face;
