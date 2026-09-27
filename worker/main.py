"""
Detection worker — entry point.

Design notes (why it's shaped this way):
- SnapshotManager keeps one lightweight ffmpeg process per camera, decoding keyframes only
  (~1fps). This is what makes 32 cameras affordable with no hardware decode / no AVX2.
- MotionGate filters out static frames before anything touches the neural net — most of
  the savings on a CPU-only box come from here.
- Only ONE inference worker thread runs the object model, pulling from a queue. Never run
  concurrent inference across cameras — on this hardware that's what causes stalls.
  When the Coral TPU arrives, swap ObjectDetector for a Coral-backed implementation with
  the same .infer() signature; the queue and everything downstream stays the same.
- Face detection only runs on frames the object detector already flagged 'person' —
  never on every motion frame.
- Recording (continuous or motion-triggered) is a separate concern from detection —
  see recorder.py. Motion-mode recording reuses this same cheap snapshot/motion-gate
  poll, it just doesn't require detect_object/detect_face to be on.
"""

import os
import queue
import threading
import time
from datetime import datetime

import cv2

import config
import db
from snapshot_manager import SnapshotManager
from motion import MotionGate
from detector import ObjectDetector
from face import FaceDetector
from notifier import Notifier
from recorder import ContinuousRecorder, MotionClipRecorder

frame_queue: "queue.Queue[tuple[dict, str]]" = queue.Queue(maxsize=64)


def capture_loop(cameras: list[dict], snap_mgr: SnapshotManager, motion_gate: MotionGate,
                  motion_recorder: MotionClipRecorder | None, motion_record_names: set):
    """Polls each camera's rolling snapshot file; on motion, enqueues it for inference
    (only if detect_object/detect_face is on) and/or notifies the motion clip recorder
    (only if this camera is in 'motion' recording mode) — the two are independent."""
    last_mtime: dict[str, float] = {}
    while True:
        for cam in cameras:
            path = snap_mgr.snapshot_path(cam["name"])
            try:
                mtime = os.path.getmtime(path)
            except FileNotFoundError:
                continue
            if last_mtime.get(cam["name"]) == mtime:
                continue  # ffmpeg hasn't written a new frame yet
            last_mtime[cam["name"]] = mtime

            frame = cv2.imread(path)
            if frame is None:
                continue
            if motion_gate.check(cam["name"], frame):
                if cam["name"] in motion_record_names and motion_recorder is not None:
                    motion_recorder.notify_motion(cam["name"])
                if cam.get("detect_object") or cam.get("detect_face"):
                    try:
                        frame_queue.put_nowait((cam, path))
                    except queue.Full:
                        pass  # detector is backlogged; drop this frame rather than pile up latency
        time.sleep(config.POLL_INTERVAL_SEC)


def inference_loop(object_detector: ObjectDetector, face_detector: FaceDetector, notifier: Notifier):
    """Single sequential worker — the only thing on this box allowed to run the neural net."""
    os.makedirs(config.EVENT_SNAPSHOT_DIR, exist_ok=True)
    while True:
        cam, path = frame_queue.get()
        frame = cv2.imread(path)
        if frame is None:
            continue

        objects = object_detector.infer(frame) if cam.get("detect_object") else []

        for obj in objects:
            _save_event_and_notify(cam, notifier, "object", obj["label"], obj["confidence"], frame)

            if cam.get("detect_face") and obj["label"] == "person":
                x, y, w, h = obj["box"]
                x, y = max(0, x), max(0, y)
                crop = frame[y:y + h, x:x + w]
                if crop.size == 0:
                    continue
                faces = face_detector.infer(crop)
                for face in faces:
                    _save_event_and_notify(cam, notifier, "face", "unrecognized face",
                                            face["confidence"], frame)

        if not objects and cam.get("detect_object"):
            # motion happened but nothing of interest — still worth a low-priority log,
            # skip notification to avoid spamming
            db.insert_event(cam["id"], "motion", None, None, None)


def _save_event_and_notify(cam, notifier, event_type, label, confidence, frame):
    ts = datetime.now().strftime("%Y%m%d_%H%M%S_%f")
    filename = f"{cam['name']}_{ts}.jpg"
    out_path = os.path.join(config.EVENT_SNAPSHOT_DIR, filename)
    cv2.imwrite(out_path, frame)

    db.insert_event(cam["id"], event_type, label, confidence, f"{config.EVENT_SNAPSHOT_URL_PREFIX}/{filename}")
    notifier.notify(cam["label"] or cam["name"], cam["name"], label, confidence, out_path)


def main():
    detect_cams = db.fetch_active_cameras()
    recording_cams = db.fetch_recording_cameras()

    if not detect_cams and not recording_cams:
        print("No cameras with detection or recording enabled. Nothing to do.")
        return

    # Union of both lists (by id) — detection cameras need a live snapshot for
    # inference; motion-mode recording cameras need one too, just to decide when
    # to start/extend a clip. Continuous-mode cameras don't need this at all —
    # they record unconditionally via their own ffmpeg process.
    by_id: dict[int, dict] = {c["id"]: dict(c) for c in detect_cams}
    for c in recording_cams:
        by_id.setdefault(c["id"], {}).update(c)
    poll_cams = [c for c in by_id.values() if c.get("id") not in
                 {rc["id"] for rc in recording_cams if rc["recording_mode"] == "continuous"}
                 or c.get("detect_object") or c.get("detect_face")
                 or c.get("recording_mode") == "motion"]

    continuous_cams = [c for c in recording_cams if c["recording_mode"] == "continuous"]
    motion_record_cams = {c["name"]: c for c in recording_cams if c["recording_mode"] == "motion"}
    motion_record_names = set(motion_record_cams.keys())

    print(f"Detection: {len(detect_cams)} camera(s). "
          f"Recording: {len(continuous_cams)} continuous, {len(motion_record_cams)} motion-triggered.")

    motion_recorder = None
    if motion_record_cams:
        motion_recorder = MotionClipRecorder(motion_record_cams)
        motion_recorder.start_reaper_thread()

    if continuous_cams:
        continuous_recorder = ContinuousRecorder(continuous_cams)
        continuous_recorder.start_all()
        continuous_recorder.start_watchdog_thread()

    if poll_cams:
        snap_mgr = SnapshotManager(poll_cams)
        snap_mgr.start_all()
        snap_mgr.start_watchdog_thread()

        motion_gate = MotionGate()
        capture_thread = threading.Thread(
            target=capture_loop,
            args=(poll_cams, snap_mgr, motion_gate, motion_recorder, motion_record_names),
            daemon=True,
        )
        capture_thread.start()

    if not detect_cams:
        # Nothing needs the inference loop — recording-only setup. Just idle.
        while True:
            time.sleep(3600)

    object_detector = ObjectDetector()
    face_detector = FaceDetector()
    notifier = Notifier()

    # Inference runs on the main thread — deliberately the only place doing model inference
    inference_loop(object_detector, face_detector, notifier)


if __name__ == "__main__":
    main()
