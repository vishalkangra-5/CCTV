import cv2
import numpy as np

import config


class MotionGate:
    """Per-camera frame-diff motion detector. Cheap: grayscale, downsized, no AVX2 needed."""

    def __init__(self):
        self._prev: dict[str, np.ndarray] = {}

    def check(self, cam_name: str, frame_bgr: np.ndarray) -> bool:
        gray = cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2GRAY)
        gray = cv2.resize(gray, (160, 90))  # tiny — motion detection doesn't need resolution
        gray = cv2.GaussianBlur(gray, (5, 5), 0)

        prev = self._prev.get(cam_name)
        self._prev[cam_name] = gray
        if prev is None:
            return False  # first frame for this camera — nothing to compare yet

        diff = cv2.absdiff(prev, gray)
        changed = np.count_nonzero(diff > config.MOTION_DIFF_THRESHOLD)
        pct = 100.0 * changed / diff.size
        return pct >= config.MOTION_MIN_CHANGED_PCT
