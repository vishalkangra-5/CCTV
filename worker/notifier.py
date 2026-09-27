import time

import requests

import config


class Notifier:
    def __init__(self):
        self._last_sent: dict[str, float] = {}

    def _cooldown_ok(self, cam_name: str) -> bool:
        last = self._last_sent.get(cam_name, 0)
        return (time.time() - last) >= config.NOTIFY_COOLDOWN_SEC

    def notify(self, cam_label: str, cam_name: str, label: str, confidence: float, snapshot_path: str):
        if not self._cooldown_ok(cam_name):
            return False
        text = f"📷 {cam_label}\nDetected: {label} ({confidence:.0%})"
        try:
            with open(snapshot_path, "rb") as f:
                requests.post(
                    f"https://api.telegram.org/bot{config.TELEGRAM_BOT_TOKEN}/sendPhoto",
                    data={"chat_id": config.TELEGRAM_CHAT_ID, "caption": text},
                    files={"photo": f},
                    timeout=10,
                )
            self._last_sent[cam_name] = time.time()
            return True
        except Exception as e:
            print(f"[notifier] failed to send for {cam_name}: {e}")
            return False
