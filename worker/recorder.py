"""
Recording — two independent modes, both plain stream-copy (no re-encode, so
CPU cost is ~free; the real cost is disk space, see recordings_cleanup.py).

- ContinuousRecorder: one long-running ffmpeg per camera using the segment
  muxer, auto-splitting into fixed-length files. Supervised like
  SnapshotManager — restarted if it dies (camera reboot, network blip, etc).

- MotionClipRecorder: no process runs until motion fires. notify_motion() is
  called from main.py's existing motion-gate check (the same cheap ~1fps
  snapshot-diff already used for detection) for any camera in 'motion'
  recording mode. On the first call for a camera it starts an ffmpeg clip;
  each subsequent call while already recording just pushes back the "quiet
  deadline". A reaper thread stops (SIGINT, so the mp4 finalizes cleanly)
  any clip whose quiet deadline has passed, or that's hit MOTION_CLIP_MAX_SEC.
"""

import os
import signal
import subprocess
import threading
import time
from datetime import datetime

import config
from db import rtsp_url


def _day_dir(cam_name: str, when: datetime | None = None) -> str:
    when = when or datetime.now()
    d = os.path.join(config.RECORDING_DIR, cam_name, when.strftime("%Y-%m-%d"))
    os.makedirs(d, exist_ok=True)
    return d


class ContinuousRecorder:
    def __init__(self, cameras: list[dict]):
        self.cameras = cameras
        self.procs: dict[str, subprocess.Popen] = {}
        self._stop = False

    def _spawn(self, cam: dict):
        name = cam["name"]
        out_dir = _day_dir(name)
        # %Y%m%d_%H%M%S via -strftime; segment rolls into a fresh file every
        # CONTINUOUS_SEGMENT_SEC without ever holding a giant growing file open.
        pattern = os.path.join(out_dir, f"{name}_%Y%m%d_%H%M%S.mp4")
        cmd = [
            "ffmpeg",
            "-rtsp_transport", "tcp",
            "-i", rtsp_url(cam),
            "-c", "copy",
            "-f", "segment",
            "-segment_time", str(config.CONTINUOUS_SEGMENT_SEC),
            "-segment_atomic_writes", "1",
            "-reset_timestamps", "1",
            "-strftime", "1",
            pattern,
            "-loglevel", "error",
        ]
        proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.procs[name] = proc

    def start_all(self):
        for cam in self.cameras:
            self._spawn(cam)

    def watchdog_loop(self, poll_sec: int = 15):
        while not self._stop:
            for cam in self.cameras:
                proc = self.procs.get(cam["name"])
                if proc is None or proc.poll() is not None:
                    self._spawn(cam)
            time.sleep(poll_sec)

    def start_watchdog_thread(self):
        t = threading.Thread(target=self.watchdog_loop, daemon=True)
        t.start()
        return t

    def stop(self):
        self._stop = True
        for proc in self.procs.values():
            proc.send_signal(signal.SIGINT)  # let the segment muxer finalize the current file


class _MotionClip:
    __slots__ = ("proc", "started_at", "quiet_deadline")

    def __init__(self, proc: subprocess.Popen, started_at: float, quiet_deadline: float):
        self.proc = proc
        self.started_at = started_at
        self.quiet_deadline = quiet_deadline


class MotionClipRecorder:
    def __init__(self, cameras_by_name: dict[str, dict]):
        self.cameras_by_name = cameras_by_name  # name -> camera row (for rtsp_url)
        self._active: dict[str, _MotionClip] = {}
        self._lock = threading.Lock()
        self._stop = False

    def _spawn(self, cam_name: str) -> subprocess.Popen:
        cam = self.cameras_by_name[cam_name]
        out_dir = _day_dir(cam_name)
        ts = datetime.now().strftime("%Y%m%d_%H%M%S")
        out_path = os.path.join(out_dir, f"{cam_name}_motion_{ts}.mp4")
        cmd = [
            "ffmpeg",
            "-rtsp_transport", "tcp",
            "-i", rtsp_url(cam),
            "-c", "copy",
            "-t", str(config.MOTION_CLIP_MAX_SEC),  # hard cap; we usually stop it sooner
            "-y", out_path,
            "-loglevel", "error",
        ]
        return subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def notify_motion(self, cam_name: str):
        """Call this whenever the motion gate fires for a 'motion'-mode camera."""
        now = time.time()
        with self._lock:
            clip = self._active.get(cam_name)
            if clip is None or clip.proc.poll() is not None:
                proc = self._spawn(cam_name)
                self._active[cam_name] = _MotionClip(
                    proc=proc, started_at=now, quiet_deadline=now + config.MOTION_CLIP_QUIET_SEC
                )
            else:
                clip.quiet_deadline = now + config.MOTION_CLIP_QUIET_SEC

    def reaper_loop(self, poll_sec: int = 5):
        """Stops clips that have gone quiet or hit the max-duration cap."""
        while not self._stop:
            now = time.time()
            with self._lock:
                for cam_name, clip in list(self._active.items()):
                    over_quiet = now >= clip.quiet_deadline
                    over_max = (now - clip.started_at) >= config.MOTION_CLIP_MAX_SEC
                    if over_quiet or over_max:
                        if clip.proc.poll() is None:
                            clip.proc.send_signal(signal.SIGINT)  # finalize the mp4 cleanly
                        del self._active[cam_name]
            time.sleep(poll_sec)

    def start_reaper_thread(self):
        t = threading.Thread(target=self.reaper_loop, daemon=True)
        t.start()
        return t

    def stop(self):
        self._stop = True
        with self._lock:
            for clip in self._active.values():
                if clip.proc.poll() is None:
                    clip.proc.send_signal(signal.SIGINT)
