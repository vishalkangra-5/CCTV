import cv2
import numpy as np

import config


class FaceDetector:
    """
    OpenCV's YuNet — a very light face detector, cheap enough to run on CPU without AVX2.
    Only call this on crops/frames already flagged 'person' by ObjectDetector; don't run
    it on every motion frame.
    """

    def __init__(self, model_path: str = config.FACE_MODEL_PATH):
        self.detector = cv2.FaceDetectorYN.create(
            model_path, "", (320, 320),
            score_threshold=config.FACE_CONF_THRESHOLD,
        )

    def infer(self, frame_bgr: np.ndarray) -> list[dict]:
        h, w = frame_bgr.shape[:2]
        self.detector.setInputSize((w, h))
        _, faces = self.detector.detect(frame_bgr)
        results = []
        if faces is not None:
            for f in faces:
                x, y, bw, bh = f[:4].astype(int)
                conf = float(f[-1])
                results.append({"box": [int(x), int(y), int(bw), int(bh)], "confidence": conf})
        return results
