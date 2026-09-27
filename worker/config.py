"""
Config for the detection worker.
Edit the values below for your environment. Keep DB_PASSWORD and TELEGRAM_BOT_TOKEN
out of version control (use a separate untracked config_local.py that overrides these,
or environment variables — your choice).
"""

# --- Database (same DB the PHP admin panel uses) ---
DB_HOST = "127.0.0.1"
DB_NAME = "camera_portal"
DB_USER = "camera_portal_user"
DB_PASSWORD = "CHANGE_ME"

# --- Must match includes/functions.php's ENC_KEY_HEX exactly, so the worker can
#     decrypt camera passwords the admin panel stored. ---
ENC_KEY_HEX = "CHANGE_ME_64_HEX_CHARS_00000000000000000000000000000000000000"

# --- Where snapshot JPEGs (live, rolling) and event snapshots (saved) live ---
LIVE_SNAPSHOT_DIR = "/var/camera-portal/live"      # overwritten ~1x/sec per camera by ffmpeg
EVENT_SNAPSHOT_DIR = "/var/camera-portal/events"   # saved copy when an event fires
EVENT_SNAPSHOT_URL_PREFIX = "/events"              # how the web server serves EVENT_SNAPSHOT_DIR

# --- Capture ---
SNAPSHOT_FPS = 1                 # frames/sec pulled from each RTSP stream for detection
SNAPSHOT_SCALE_WIDTH = 640        # downscale width for snapshots (height auto)
POLL_INTERVAL_SEC = 1.0           # how often the main loop checks each camera's snapshot for motion

# --- Motion gate ---
MOTION_DIFF_THRESHOLD = 25        # pixel-intensity diff threshold (0-255)
MOTION_MIN_CHANGED_PCT = 0.5      # % of pixels that must change to call it "motion"

# --- Object detection model ---
# Export your own with: pip install ultralytics && yolo export model=yolov8n.pt format=onnx imgsz=320
OBJECT_MODEL_PATH = "/opt/camera-portal/models/yolov8n.onnx"
OBJECT_INPUT_SIZE = 320
OBJECT_CONF_THRESHOLD = 0.45
# Only these COCO class names trigger an "object" event (edit to taste)
OBJECT_CLASSES_OF_INTEREST = {"person", "car", "truck", "motorcycle", "bicycle", "dog"}

# --- Face detection model (only runs on frames already flagged "person") ---
# Download from the OpenCV Zoo: https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet
FACE_MODEL_PATH = "/opt/camera-portal/models/face_detection_yunet_2023mar.onnx"
FACE_CONF_THRESHOLD = 0.7

# --- Recording ---
# Where recordings are written: RECORDING_DIR/<camera_name>/<YYYY-MM-DD>/<file>.mp4
# Continuous and motion clips are both plain stream-copy remuxes (no re-encode),
# so CPU cost is close to zero — the load is disk I/O and space, not CPU.
RECORDING_DIR = "/var/camera-portal/recordings"
RECORDING_URL_PREFIX = "/recordings"          # how the web server serves RECORDING_DIR (internal/auth-gated, see README)

# Continuous mode: one long-running ffmpeg per camera, auto-splitting into
# fixed-length segment files so nothing grows unbounded and old segments can
# be deleted while recording keeps going.
CONTINUOUS_SEGMENT_SEC = 600                  # 10-minute segment files

# Motion mode: a clip starts on the first motion event and keeps extending
# while motion continues; it's cut once motion has been quiet for this long,
# and hard-capped at MOTION_CLIP_MAX_SEC so one long event can't run forever.
MOTION_CLIP_QUIET_SEC = 30
MOTION_CLIP_MAX_SEC = 20 * 60                 # 20 minutes hard cap per clip

# Retention: how long recordings are kept before recordings_cleanup.py deletes
# them. Applies to both continuous and motion recordings, per-camera folders.
RETENTION_DAYS = 15

# Safety valve: if free space on the recordings volume drops below this,
# recordings_cleanup.py deletes the oldest day-folders first, even if they're
# within RETENTION_DAYS, rather than let the disk fill up and crash ffmpeg/DB.
RECORDING_MIN_FREE_GB = 10

# --- Notifications (Telegram bot — simplest push option) ---
TELEGRAM_BOT_TOKEN = "CHANGE_ME"
TELEGRAM_CHAT_ID = "CHANGE_ME"     # your chat id or a group chat id
NOTIFY_COOLDOWN_SEC = 60           # don't re-notify for the same camera within this window
