import cv2
import numpy as np

import config

# COCO class names, index-matched to standard YOLOv8 export
COCO_CLASSES = [
    "person","bicycle","car","motorcycle","airplane","bus","train","truck","boat",
    "traffic light","fire hydrant","stop sign","parking meter","bench","bird","cat","dog",
    "horse","sheep","cow","elephant","bear","zebra","giraffe","backpack","umbrella","handbag",
    "tie","suitcase","frisbee","skis","snowboard","sports ball","kite","baseball bat",
    "baseball glove","skateboard","surfboard","tennis racket","bottle","wine glass","cup",
    "fork","knife","spoon","bowl","banana","apple","sandwich","orange","broccoli","carrot",
    "hot dog","pizza","donut","cake","chair","couch","potted plant","bed","dining table",
    "toilet","tv","laptop","mouse","remote","keyboard","cell phone","microwave","oven",
    "toaster","sink","refrigerator","book","clock","vase","scissors","teddy bear",
    "hair drier","toothbrush",
]


class ObjectDetector:
    """
    Thin wrapper around a YOLOv8-family ONNX model. This is the CPU backend — when the
    Coral TPU arrives, write a CoralObjectDetector with the same `.infer(frame) -> list[dict]`
    signature and swap it in main.py; nothing else in the pipeline needs to change.
    """

    def __init__(self, model_path: str = config.OBJECT_MODEL_PATH,
                 input_size: int = config.OBJECT_INPUT_SIZE):
        self.input_size = input_size
        self.net = cv2.dnn.readNetFromONNX(model_path)
        self.net.setPreferableBackend(cv2.dnn.DNN_BACKEND_OPENCV)
        self.net.setPreferableTarget(cv2.dnn.DNN_TARGET_CPU)

    def infer(self, frame_bgr: np.ndarray) -> list[dict]:
        h0, w0 = frame_bgr.shape[:2]
        blob = cv2.dnn.blobFromImage(
            frame_bgr, scalefactor=1 / 255.0,
            size=(self.input_size, self.input_size),
            swapRB=True, crop=False,
        )
        self.net.setInput(blob)
        output = self.net.forward()  # shape: (1, 84, N) for YOLOv8 export

        preds = np.squeeze(output).T  # -> (N, 84)
        boxes, scores, class_ids = [], [], []

        for row in preds:
            class_scores = row[4:]
            class_id = int(np.argmax(class_scores))
            conf = float(class_scores[class_id])
            if conf < config.OBJECT_CONF_THRESHOLD:
                continue
            cx, cy, w, h = row[0], row[1], row[2], row[3]
            x = (cx - w / 2) / self.input_size * w0
            y = (cy - h / 2) / self.input_size * h0
            bw = w / self.input_size * w0
            bh = h / self.input_size * h0
            boxes.append([int(x), int(y), int(bw), int(bh)])
            scores.append(conf)
            class_ids.append(class_id)

        results = []
        if boxes:
            idxs = cv2.dnn.NMSBoxes(boxes, scores, config.OBJECT_CONF_THRESHOLD, 0.45)
            for i in np.array(idxs).flatten() if len(idxs) else []:
                label = COCO_CLASSES[class_ids[i]] if class_ids[i] < len(COCO_CLASSES) else str(class_ids[i])
                if label in config.OBJECT_CLASSES_OF_INTEREST:
                    results.append({
                        "label": label,
                        "confidence": scores[i],
                        "box": boxes[i],  # x, y, w, h in original frame coords
                    })
        return results
