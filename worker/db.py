import hashlib
import pymysql
from Crypto.Cipher import AES  # pycryptodome

import config


def get_conn():
    return pymysql.connect(
        host=config.DB_HOST,
        user=config.DB_USER,
        password=config.DB_PASSWORD,
        database=config.DB_NAME,
        autocommit=True,
        cursorclass=pymysql.cursors.DictCursor,
    )


def _key_bytes() -> bytes:
    return bytes.fromhex(config.ENC_KEY_HEX)


def decrypt_secret(blob: bytes) -> str:
    """Mirrors includes/functions.php's encrypt_secret(): iv(12) + tag(16) + ciphertext, AES-256-GCM."""
    if not blob:
        return ""
    iv, tag, ct = blob[:12], blob[12:28], blob[28:]
    cipher = AES.new(_key_bytes(), AES.MODE_GCM, nonce=iv)
    try:
        return cipher.decrypt_and_verify(ct, tag).decode("utf-8")
    except ValueError:
        return ""  # bad tag / tampered / wrong key


def fetch_active_cameras():
    """Cameras enabled AND flagged for at least one kind of detection."""
    sql = """
        SELECT id, name, label, ip, port, rtsp_path, username, password_enc,
               detect_object, detect_face
        FROM cameras
        WHERE enabled = 1 AND (detect_object = 1 OR detect_face = 1)
    """
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(sql)
            rows = cur.fetchall()
    for r in rows:
        r["password"] = decrypt_secret(r.pop("password_enc"))
    return rows


def fetch_recording_cameras():
    """Cameras enabled AND with recording_mode != 'off' — independent of the
    detect_object/detect_face flags used by fetch_active_cameras()."""
    sql = """
        SELECT id, name, label, ip, port, rtsp_path, username, password_enc, recording_mode
        FROM cameras
        WHERE enabled = 1 AND recording_mode != 'off'
    """
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(sql)
            rows = cur.fetchall()
    for r in rows:
        r["password"] = decrypt_secret(r.pop("password_enc"))
    return rows


def rtsp_url(cam: dict) -> str:
    auth = ""
    if cam.get("username"):
        from urllib.parse import quote
        auth = f"{quote(cam['username'])}:{quote(cam.get('password', ''))}@"
    path = cam["rtsp_path"] or "/"
    if not path.startswith("/"):
        path = "/" + path
    return f"rtsp://{auth}{cam['ip']}:{cam['port']}{path}"


def insert_event(camera_id: int, event_type: str, label: str, confidence: float, snapshot_path: str):
    sql = """
        INSERT INTO events (camera_id, event_type, label, confidence, snapshot_path)
        VALUES (%s, %s, %s, %s, %s)
    """
    with get_conn() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (camera_id, event_type, label, confidence, snapshot_path))
