# Camera Portal — Setup

## What this is
- **Admin panel** (`/admin/`) — add/edit/delete cameras (IP, RTSP path, credentials), toggle
  detection flags per camera (used later by the detection worker, not built yet).
- **Grid viewer** (`/viewer/`) — 1/4/8/16/32 layout switcher, paginates through all enabled cameras.
- **go2rtc** does the actual RTSP→WebRTC/HLS restreaming; this app only manages config + UI.
- **Detection pipeline** (motion gate → CPU inference → notifications) is the next phase —
  the `events` table and `detect_object`/`detect_face` flags are already in place for it to plug into.

## 1. Install go2rtc
```bash
# on the Ubuntu server, e.g. 103.210.32.115
curl -L https://github.com/AlexxIT/go2rtc/releases/latest/download/go2rtc_linux_amd64 -o /usr/local/bin/go2rtc
chmod +x /usr/local/bin/go2rtc
mkdir -p /etc/go2rtc
```
Create a systemd unit `/etc/systemd/system/go2rtc.service`:
```ini
[Unit]
Description=go2rtc
After=network.target

[Service]
ExecStart=/usr/local/bin/go2rtc -config /etc/go2rtc/go2rtc.yaml
Restart=always
User=go2rtc

[Install]
WantedBy=multi-user.target
```
```bash
systemctl daemon-reload
systemctl enable --now go2rtc
```
Open TCP 1984 (API/player) and 8555 (WebRTC) on the firewall for whoever will view the grid.

## 2. Database
```bash
mysql -u root -p < schema.sql
```
If you're updating an existing install rather than starting fresh, also run the migrations
in order (schema.sql alone won't add columns to a database that already exists):
```bash
mysql -u root -p < migrations/001_add_recording_mode.sql
mysql -u root -p < migrations/002_add_sub_rtsp_path.sql
mysql -u root -p < migrations/003_add_user_roles_and_permissions.sql
```
Create the app's DB user:
```sql
CREATE USER 'camera_portal_user'@'localhost' IDENTIFIED BY 'pick-a-strong-password';
GRANT ALL PRIVILEGES ON camera_portal.* TO 'camera_portal_user'@'localhost';
FLUSH PRIVILEGES;
```
Create an admin login:
```bash
php -r "echo password_hash('your-admin-password', PASSWORD_DEFAULT), \"\n\";"
```
```sql
INSERT INTO admin_users (username, password_hash) VALUES ('vishal', 'PASTE_HASH_HERE');
```

## 3. Configure the app
Edit `config/config.php`:
- `DB_PASS` — the password you set above.
- `ENC_KEY_HEX` — generate with `php -r "echo bin2hex(random_bytes(32));"`. This encrypts
  stored camera passwords; back it up somewhere safe, separate from the DB backup.
- `GO2RTC_PUBLIC_URL` — the URL **browsers** will use to reach go2rtc (server's public/LAN IP + port 1984).
- `GO2RTC_CONFIG_PATH` — should match the systemd unit's `-config` path (`/etc/go2rtc/go2rtc.yaml`).

## 4. Web server
Point your vhost's document root at this project folder. Example nginx snippet:
```nginx
server {
    listen 80;
    server_name camportal.internal;
    root /var/www/camera-portal;
    index index.php index.html;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # The viewer's real markup lives in app.html, gated by index.php in front
    # of it (require_login()). Block direct requests to app.html itself, or
    # anyone who knows the filename can skip the login check entirely.
    location = /viewer/app.html { deny all; return 404; }

    # Recordings are served only via admin/recording_file.php, which checks
    # login then hands off to nginx with X-Accel-Redirect. `internal;` means
    # nginx refuses to serve this path directly — only that PHP handoff works.
    location /recordings/ {
        internal;
        alias /var/camera-portal/recordings/;
    }
}
```
Deny direct web access to `config/` and `includes/` (they're .php so they'll execute rather than
leak source, but block them anyway):
```nginx
location ~ ^/(config|includes)/ { deny all; }
```

## 5. Let the admin panel restart go2rtc after config changes
`admin/go2rtc_sync.php` calls `sudo systemctl restart go2rtc`. Give the web server user
passwordless sudo for just that command — add to `/etc/sudoers.d/camera-portal`:
```
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart go2rtc
```
(replace `www-data` with your actual PHP-FPM user)

## 6. First run
1. Go to `/admin/login.php`, sign in.
2. Add your 32 cameras (IP, RTSP path per your camera model, credentials). If a camera's
   main stream is H.265 (most DVRs' default), also fill in the sub-stream path if it has
   one — fixes the "codecs not matched: video:H265" WebRTC error and gives a lower-bandwidth
   option for slow networks, at no CPU cost (no transcoding, go2rtc just offers both and
   the viewer picks whichever it can play).
3. Click "Regenerate go2rtc config" — this writes `go2rtc.yaml` and restarts the service.
4. Open `/viewer/`, sign in (same login as the admin panel — the viewer now requires it),
   pick a layout, confirm streams load.

## RTSP path cheat sheet (adjust to your actual camera models)
- Hikvision: `/Streaming/Channels/101` (channel 1, main stream) or `/102` for sub-stream.
- CP Plus / Dahua-style: `/cam/realmonitor?channel=1&subtype=0`
- Generic ONVIF: check the camera's ONVIF device page or vendor manual — path varies.

## 7. Detection worker (Python, CPU-only for now)

```bash
apt install ffmpeg python3-venv
mkdir -p /opt/camera-portal/worker /opt/camera-portal/models
cp -r worker/* /opt/camera-portal/worker/
cd /opt/camera-portal/worker
python3 -m venv venv
./venv/bin/pip install -r requirements.txt
```

**Get the object detection model** (exported yourself, so it matches your OpenCV/onnxruntime version):
```bash
pip install ultralytics
yolo export model=yolov8n.pt format=onnx imgsz=320
mv yolov8n.onnx /opt/camera-portal/models/
```

**Get the face detection model** (small, from the official OpenCV Zoo):
```bash
curl -L -o /opt/camera-portal/models/face_detection_yunet_2023mar.onnx \
  https://github.com/opencv/opencv_zoo/raw/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx
```

Edit `worker/config.py`:
- `DB_PASSWORD` and `ENC_KEY_HEX` — **`ENC_KEY_HEX` must be the exact same value** as
  `config/config.php`'s `ENC_KEY_HEX`, or the worker won't be able to decrypt camera passwords.
- `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID` — create a bot via @BotFather, message it once,
  then hit `https://api.telegram.org/bot<token>/getUpdates` to read your chat_id.
- `OBJECT_CLASSES_OF_INTEREST` — trim to what you actually care about (default includes
  person/vehicles).

Create the runtime user and directories:
```bash
useradd -r -s /usr/sbin/nologin camportal
mkdir -p /var/camera-portal/live /var/camera-portal/events
chown -R camportal:camportal /var/camera-portal /opt/camera-portal
```

Install and start the service:
```bash
cp systemd/camera-detect-worker.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now camera-detect-worker
journalctl -u camera-detect-worker -f   # watch logs
```

Serve `/var/camera-portal/events` at the `EVENT_SNAPSHOT_URL_PREFIX` path (`/events`) in your
nginx vhost if you want event snapshots viewable from the admin panel later:
```nginx
location /events/ { alias /var/camera-portal/events/; }
```

### What to expect on this hardware
- With 32 cameras and no AVX2/hardware decode, motion-gating plus keyframe-only snapshotting
  is what keeps this running at all. Expect a real delay (a second or more) between motion
  and a Telegram alert — that's normal here, not a bug.
- If CPU load is still too high with many cameras flagged for detection, trim
  `detect_object`/`detect_face` down to the cameras that actually matter (gate, entrance)
  rather than all 32, until the Coral TPU is in.
- Once the Coral TPU arrives: write a `CoralObjectDetector` class in `worker/detector.py`
  with the same `.infer(frame) -> list[dict]` method (using `pycoral`), and swap it in
  `main.py`'s `main()`. Nothing else in the pipeline changes.

## 8. Recording

Per-camera, set in the admin panel (`camera_form.php`):
- **Off** — default, no recording.
- **Continuous** — records 24/7 in `CONTINUOUS_SEGMENT_SEC`-length files (10 min default).
- **Motion-triggered** — only records while the existing motion gate is firing, extends the
  clip while motion continues, cuts after `MOTION_CLIP_QUIET_SEC` of quiet.

Both modes are plain stream-copy (`-c copy`), no re-encoding — CPU cost is close to zero,
the real cost is disk space. This runs inside the same `camera-detect-worker` service as
detection (see section 7) — no separate service needed for recording itself, just:

```bash
mkdir -p /var/camera-portal/recordings
chown -R camportal:camportal /var/camera-portal/recordings
```

Retention is enforced by a separate daily job — install its systemd timer:
```bash
cp systemd/camera-recordings-cleanup.service systemd/camera-recordings-cleanup.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now camera-recordings-cleanup.timer
systemctl list-timers | grep recordings   # confirm it's scheduled
```
Adjust `RETENTION_DAYS` and `RECORDING_MIN_FREE_GB` in `worker/config.py` to taste — the
latter is a safety valve that deletes the oldest recordings regardless of age if free disk
space gets critically low, so a busy day of motion clips can't crash the box.

Browse recordings at `/admin/recordings.php` (requires admin login). Playback in-browser
depends on the camera's codec — a camera with only an H.265 main stream may not play back in
Chrome even though it recorded fine; download and open with VLC in that case, or configure a
sub-stream (section 6) for cameras you'll want to review often.

## 9. Users & permissions

Every existing account (created via the `INSERT INTO admin_users` step in section 2) becomes
role `admin` after migration 003 — unchanged behavior, full access, nothing to redo.

To give someone view-only access to just a subset of cameras: `/admin/users.php` → Add user →
role **Viewer** → tick the cameras they should see → save. That account can then log into
`/viewer/` (and the Flutter app) and will only ever see those cameras — `api/cameras.php` now
filters by permission, not just by `enabled`. Viewer-role accounts can't reach `/admin/index.php`,
`camera_form.php`, `users.php`, `recordings.php`, etc. at all (403) — only the viewer page.

Admin-role accounts always see every enabled camera regardless of what's in `camera_permissions`
— that table only ever restricts a Viewer account, never grants anything extra to an Admin one.

## 10. Reordering cameras

`/admin/reorder.php` — up/down arrows per camera, no more hand-editing the "Sort order" number
in each camera's edit form. This is the order both the web viewer and the Flutter app page
through (1/4/8/16/32 at a time).

## Not built yet
- Viewing saved event snapshots / history in the admin panel (the `events` table already
  has everything needed — just needs a listing page).
- Known-face matching (right now "face" events just mean "a face was seen," not identified).
  Adding named-person recognition means storing face embeddings for known people and
  comparing new detections against them — a reasonable next step once basic detection is
  running reliably.
