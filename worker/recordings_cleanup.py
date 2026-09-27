"""
Deletes old recordings. Run periodically (see systemd/camera-recordings-cleanup.timer).

Two passes:
1. Age-based: delete any camera/date folder older than RETENTION_DAYS.
2. Disk-space safety valve: if free space on the recordings volume is still
   below RECORDING_MIN_FREE_GB after pass 1, delete the oldest remaining
   date-folders (across all cameras) until it isn't — so a burst of motion
   clips or a bigger-than-usual day can't fill the disk and crash ffmpeg/MySQL.
"""

import os
import shutil
import time
from datetime import datetime, timedelta

import config


def _date_folders():
    """Yields (path, camera_name, date_str, mtime) for every camera/date folder."""
    if not os.path.isdir(config.RECORDING_DIR):
        return
    for cam_name in os.listdir(config.RECORDING_DIR):
        cam_dir = os.path.join(config.RECORDING_DIR, cam_name)
        if not os.path.isdir(cam_dir):
            continue
        for date_str in os.listdir(cam_dir):
            date_dir = os.path.join(cam_dir, date_str)
            if not os.path.isdir(date_dir):
                continue
            try:
                mtime = os.path.getmtime(date_dir)
            except OSError:
                continue
            yield date_dir, cam_name, date_str, mtime


def _free_gb(path: str) -> float:
    usage = shutil.disk_usage(path)
    return usage.free / (1024 ** 3)


def run():
    cutoff = datetime.now() - timedelta(days=config.RETENTION_DAYS)
    cutoff_ts = cutoff.timestamp()

    deleted = 0
    for date_dir, cam_name, date_str, mtime in list(_date_folders()):
        if mtime < cutoff_ts:
            print(f"[age] deleting {cam_name}/{date_str} (older than {config.RETENTION_DAYS}d)")
            shutil.rmtree(date_dir, ignore_errors=True)
            deleted += 1

    if os.path.isdir(config.RECORDING_DIR):
        free = _free_gb(config.RECORDING_DIR)
        if free < config.RECORDING_MIN_FREE_GB:
            print(f"[space] {free:.1f} GB free, below {config.RECORDING_MIN_FREE_GB} GB — "
                  f"deleting oldest folders regardless of age")
            remaining = sorted(_date_folders(), key=lambda t: t[3])  # oldest first
            for date_dir, cam_name, date_str, _ in remaining:
                if _free_gb(config.RECORDING_DIR) >= config.RECORDING_MIN_FREE_GB:
                    break
                print(f"[space] deleting {cam_name}/{date_str}")
                shutil.rmtree(date_dir, ignore_errors=True)
                deleted += 1

    print(f"Done. {deleted} folder(s) deleted.")


if __name__ == "__main__":
    run()
