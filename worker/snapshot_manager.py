import os
import subprocess
import threading
import time

import config


class SnapshotManager:
    """
    Runs one persistent ffmpeg process per camera that continuously overwrites a single
    JPEG file at ~SNAPSHOT_FPS. Uses -skip_frame nokey so ffmpeg decodes keyframes only —
    this is what keeps 32 cameras affordable on a CPU with no hardware decode / no AVX2.
    """

    def __init__(self, cameras: list[dict]):
        self.cameras = cameras
        self.procs: dict[str, subprocess.Popen] = {}
        self._stop = False
        os.makedirs(config.LIVE_SNAPSHOT_DIR, exist_ok=True)

    def snapshot_path(self, cam_name: str) -> str:
        return os.path.join(config.LIVE_SNAPSHOT_DIR, f"{cam_name}.jpg")

    def _spawn(self, cam: dict, url: str):
        out_path = self.snapshot_path(cam["name"])
        cmd = [
            "ffmpeg",
            "-rtsp_transport", "tcp",
            "-skip_frame", "nokey",     # decode keyframes only — big CPU saving
            "-i", url,
            "-vf", f"fps={config.SNAPSHOT_FPS},scale={config.SNAPSHOT_SCALE_WIDTH}:-1",
            "-update", "1",
            "-q:v", "5",
            "-y", out_path,
            "-loglevel", "error",
        ]
        proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.procs[cam["name"]] = proc

    def start_all(self):
        from db import rtsp_url
        for cam in self.cameras:
            self._spawn(cam, rtsp_url(cam))

    def watchdog_loop(self, poll_sec: int = 15):
        """Restart any ffmpeg process that has died (bad stream, camera reboot, etc.)."""
        from db import rtsp_url
        while not self._stop:
            for cam in self.cameras:
                proc = self.procs.get(cam["name"])
                if proc is None or proc.poll() is not None:
                    self._spawn(cam, rtsp_url(cam))
            time.sleep(poll_sec)

    def stop(self):
        self._stop = True
        for proc in self.procs.values():
            proc.terminate()

    def start_watchdog_thread(self):
        t = threading.Thread(target=self.watchdog_loop, daemon=True)
        t.start()
        return t
