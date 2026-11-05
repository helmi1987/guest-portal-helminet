#!/usr/bin/env python3
"""Minimal HTTPS stand-in for the portal's router download routes (tests only).

Serves, like RouterCertController / RouterLoginPageController:
  /router-cert/<id>/fingerprint | router.crt | router.key   (files from <cert-dir>, read on each request)
  /router-login/<id>/<page>.html, page login|status|logout|alogin
                                                             (from <login-dir>/<id>/<page>.html or <login-dir>/<page>.html)
Only with header "X-Portal-Token: <token>" (403 otherwise). The source IP is logged but not checked:
with qemu user networking every request arrives from the NAT address, not from the router's src-address.

Usage: stub_portal.py <port> <server.crt> <server.key> <cert-dir> <login-dir> <token> <request-log>
"""
import hashlib
import os
import re
import ssl
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT, CRT, KEY, CERT_DIR, LOGIN_DIR, TOKEN, LOG = sys.argv[1:8]


def fingerprint():
    pem = open(os.path.join(CERT_DIR, "router.crt")).read()
    first = re.search(r"-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----", pem, re.S).group(0)
    der = ssl.PEM_cert_to_DER_cert(first)
    return hashlib.sha256(der).hexdigest()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        with open(LOG, "a") as f:
            f.write("%s %s\n" % (self.client_address[0], fmt % args))

    def send(self, code, body=b"", ctype="text/plain"):
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.headers.get("X-Portal-Token", "") != TOKEN:
            return self.send(403, b"forbidden")
        m = re.fullmatch(r"/router-cert/([0-9A-Za-z]+)/(fingerprint|router\.crt|router\.key)", self.path)
        if m:
            name = m.group(2)
            if name == "fingerprint":
                return self.send(200, fingerprint().encode())
            return self.send(200, open(os.path.join(CERT_DIR, name), "rb").read(), "application/x-pem-file")
        m = re.fullmatch(r"/router-login/([0-9A-Za-z]+)/(login|status|logout|alogin)\.html", self.path)
        if m:
            page = m.group(2) + ".html"
            path = os.path.join(LOGIN_DIR, m.group(1), page)
            if not os.path.exists(path):
                path = os.path.join(LOGIN_DIR, page)
            if not os.path.exists(path):
                return self.send(404, b"not found")
            return self.send(200, open(path, "rb").read(), "text/html; charset=utf-8")
        return self.send(404, b"not found")


ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(CRT, KEY)
server = ThreadingHTTPServer(("0.0.0.0", int(PORT)), Handler)
server.socket = ctx.wrap_socket(server.socket, server_side=True)
server.serve_forever()
