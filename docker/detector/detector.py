# SPDX-FileCopyrightText: 2026 Florian Schneider
# SPDX-License-Identifier: AGPL-3.0-or-later
"""
Nudity detector for Glimpse's sensitive pictures: NudeNet behind a tiny HTTP API.

  GET  /ping    -> "pong"
  POST /detect  (body: a JPEG or PNG) -> {"detections": [{"class": "FEMALE_BREAST_EXPOSED", "score": 0.83}, ...]}

No state, no files kept. One request at a time per worker thread; NudeNet's small model
needs about 40 ms per picture on a CPU.
"""
import json
import os
import tempfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from nudenet import NudeDetector

MAX_BYTES = 20 * 1024 * 1024
detector = NudeDetector()


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == '/ping':
            self._send(200, b'pong', 'text/plain')
        else:
            self._send(404, b'{"error": "not found"}')

    def do_POST(self):
        if self.path != '/detect':
            return self._send(404, b'{"error": "not found"}')
        length = int(self.headers.get('Content-Length') or 0)
        if length <= 0 or length > MAX_BYTES:
            return self._send(400, b'{"error": "send one picture as the body"}')
        body = self.rfile.read(length)
        # NudeNet reads from a path
        with tempfile.NamedTemporaryFile(suffix='.img') as f:
            f.write(body)
            f.flush()
            try:
                found = detector.detect(f.name)
            except Exception as e:  # not a picture
                return self._send(400, json.dumps({'error': str(e)}).encode())
        out = [{'class': d['class'], 'score': round(float(d['score']), 3)} for d in found]
        self._send(200, json.dumps({'detections': out}).encode())

    def _send(self, code, body, kind='application/json'):
        self.send_response(code)
        self.send_header('Content-Type', kind)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass  # no access log: nothing about the pictures is kept


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', int(os.environ.get('PORT', '3005'))), Handler).serve_forever()
