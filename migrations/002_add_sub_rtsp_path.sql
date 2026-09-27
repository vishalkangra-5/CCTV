-- Adds an optional lower-resolution "sub-stream" RTSP path per camera,
-- used for WebRTC codec compatibility (most DVRs' sub streams are H264
-- even when the main stream is H265) and as a low-bandwidth fallback.

USE camera_portal;

ALTER TABLE cameras
    ADD COLUMN sub_rtsp_path VARCHAR(255) NULL
    AFTER rtsp_path;
