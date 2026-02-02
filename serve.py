#!/usr/bin/env python3
"""
Simple HTTP server for testing OPC Weather Map
Run this script and open http://localhost:8000 in your browser
"""

import http.server
import socketserver
import os

PORT = 8000
DIRECTORY = os.path.dirname(os.path.abspath(__file__))

class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=DIRECTORY, **kwargs)

    def end_headers(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Cache-Control', 'no-store, no-cache, must-revalidate')
        super().end_headers()

if __name__ == "__main__":
    print("OPC Weather Map - Local Server")
    print("=" * 40)
    print("Server: http://localhost:{}".format(PORT))
    print("Directory: {}".format(DIRECTORY))
    print("Press Ctrl+C to stop")
    print()

    with socketserver.TCPServer(("", PORT), Handler) as httpd:
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            print("\nServer stopped.")
