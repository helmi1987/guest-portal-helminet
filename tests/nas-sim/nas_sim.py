#!/usr/bin/env python3
"""NAS simulator for the generic RADIUS captive-portal driver (decision 2026-11-04, v0.28.0).

Emulates the captive portal of one preset as documented by the vendor (redirect parameters, MAC notation,
login form) and acts as the NAS towards the portal's RADIUS: Access-Request (PAP), Accounting
Start/Interim/Stop and Disconnect-Request (RFC 5176) on the CoA port. Python 3 standard library only.

It proves that the driver follows the documented conventions; it cannot prove how a real device behaves.
Usage and the Docker run: README.md and run.sh in this directory.
"""

import argparse
import hashlib
import hmac
import json
import os
import re
import socket
import ssl
import struct
import sys
import threading
import time
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# --- RADIUS ---------------------------------------------------------------------------------------------

ACCESS_REQUEST, ACCESS_ACCEPT, ACCESS_REJECT = 1, 2, 3
ACCOUNTING_REQUEST, ACCOUNTING_RESPONSE = 4, 5
DISCONNECT_REQUEST, DISCONNECT_ACK, DISCONNECT_NAK = 40, 41, 42

USER_NAME, USER_PASSWORD, NAS_IP_ADDRESS, FRAMED_IP_ADDRESS = 1, 2, 4, 8
SESSION_TIMEOUT, VENDOR_SPECIFIC, CALLED_STATION_ID, CALLING_STATION_ID, NAS_IDENTIFIER = 27, 26, 30, 31, 32
ACCT_STATUS_TYPE, ACCT_INPUT_OCTETS, ACCT_OUTPUT_OCTETS, ACCT_SESSION_ID = 40, 42, 43, 44
ACCT_SESSION_TIME, ACCT_TERMINATE_CAUSE, NAS_PORT_TYPE, MESSAGE_AUTHENTICATOR = 46, 49, 61, 80
ACCT_INTERIM_INTERVAL, ERROR_CAUSE = 85, 101
SERVICE_TYPE, NAS_PORT, NAS_PORT_ID = 6, 5, 87
WISPR_VENDOR, WISPR_MAX_UP, WISPR_MAX_DOWN = 14122, 7, 8

NAMES = {USER_NAME: 'User-Name', FRAMED_IP_ADDRESS: 'Framed-IP-Address', CALLING_STATION_ID: 'Calling-Station-Id',
         ACCT_SESSION_ID: 'Acct-Session-Id', NAS_IP_ADDRESS: 'NAS-IP-Address', NAS_IDENTIFIER: 'NAS-Identifier',
         MESSAGE_AUTHENTICATOR: 'Message-Authenticator', 87: 'NAS-Port-Id', 5: 'NAS-Port', 101: 'Error-Cause'}


def attr(type_, value):
    if isinstance(value, str):
        value = value.encode()
    return struct.pack('!BB', type_, len(value) + 2) + value


def attr_int(type_, value):
    return attr(type_, struct.pack('!I', value))


def attr_ip(type_, ip):
    return attr(type_, socket.inet_aton(ip))


def parse_attrs(data):
    attrs, i = [], 0
    while i + 2 <= len(data):
        t, length = data[i], data[i + 1]
        if length < 2 or i + length > len(data):
            raise ValueError('malformed attribute')
        attrs.append((t, data[i + 2:i + length]))
        i += length
    return attrs


def pap(password, secret, authenticator):
    p = password.encode()
    p += b'\0' * ((16 - len(p) % 16) % 16 if p else 16)
    out, last = b'', authenticator
    for i in range(0, len(p), 16):
        key = hashlib.md5(secret + last).digest()
        block = bytes(a ^ b for a, b in zip(p[i:i + 16], key))
        out += block
        last = block
    return out


def mac_hex(value):
    return ''.join(c for c in (value or '').lower() if c in '0123456789abcdef')


class Radius:
    def __init__(self, host, auth_port, acct_port, secret, log):
        self.host, self.auth_port, self.acct_port, self.secret, self.log = host, auth_port, acct_port, secret.encode(), log
        self.ident = 0

    def _exchange(self, port, packet, auth):
        """Send with retries; returns (code, attributes) of the answer after checking its authenticator."""
        ident = packet[1]
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.settimeout(5)
        try:
            for _ in range(3):
                sock.sendto(packet, (self.host, port))
                try:
                    reply, _ = sock.recvfrom(4096)
                except socket.timeout:
                    continue
                r_code, r_id, r_len = struct.unpack('!BBH', reply[:4])
                if r_id != ident:
                    continue
                if hashlib.md5(reply[:4] + auth + reply[20:r_len] + self.secret).digest() != reply[4:20]:
                    raise RuntimeError('response authenticator mismatch (wrong secret?)')
                return r_code, parse_attrs(reply[20:r_len])
            raise RuntimeError('no answer from RADIUS %s:%d' % (self.host, port))
        finally:
            sock.close()

    def _next_id(self):
        self.ident = (self.ident + 1) % 256
        return self.ident

    def access(self, user, password, attrs):
        """Access-Request with PAP and Message-Authenticator (FreeRADIUS may require it)."""
        auth = os.urandom(16)
        body = attr(USER_NAME, user) + attr(USER_PASSWORD, pap(password, self.secret, auth)) + b''.join(attrs)
        body += attr(MESSAGE_AUTHENTICATOR, b'\0' * 16)
        header = struct.pack('!BBH', ACCESS_REQUEST, self._next_id(), 20 + len(body))
        body = body[:-16] + hmac.new(self.secret, header + auth + body, hashlib.md5).digest()
        return self._exchange(self.auth_port, header + auth + body, auth)

    def accounting(self, attrs):
        body = b''.join(attrs)
        header = struct.pack('!BBH', ACCOUNTING_REQUEST, self._next_id(), 20 + len(body))
        auth = hashlib.md5(header + b'\0' * 16 + body + self.secret).digest()
        code, _ = self._exchange(self.acct_port, header + auth + body, auth)
        if code != ACCOUNTING_RESPONSE:
            raise RuntimeError('unexpected accounting answer %d' % code)


# --- Presets: what the vendor's captive portal sends and expects ------------------------------------------

def presets(a):
    """Redirect query and login form per preset, after the vendor documentation cited in
    app/Nas/Generic/Presets.php. host/port: where the browser reaches this simulator."""
    login = '%s://%s:%d' % ('http' if a.preset in PLAIN_HTTP else 'https', a.host, a.port)
    mac = a.client_mac.lower()
    colon = ':'.join(mac_hex(mac)[i:i + 2] for i in range(0, 12, 2))
    dash_upper = colon.replace(':', '-').upper()
    portal = a.portal.rstrip('/') + '/portal/g/' + urllib.parse.quote(a.site)
    # CoovaChilli session id: unique per run like chilli's (start time + counter). The portal finds accounting rows
    # by (NAS, Acct-Session-Id): a reused id would attach a new login to an old session.
    sid = '%d%08d' % (a.started, 1)
    q = urllib.parse.urlencode
    return {
        'cisco_wlc': dict(
            entry=portal + '?' + q({'switch_url': login + '/login.html', 'ap_mac': 'aa:bb:cc:00:00:99', 'client_mac': colon,
                                    'wlan': 'Guest', 'redirect': 'http://example.com/'}),
            path='/login.html', method='POST', user='username', password='password', success='redirect_url',
            fixed={'buttonClicked': '4', 'err_flag': '0'}, calling=colon),
        'aruba': dict(
            entry=portal + '?' + q({'cmd': 'login', 'mac': colon, 'essid': 'Guest', 'ip': a.client_ip, 'apname': 'ap-1',
                                    'apmac': 'aa:bb:cc:00:00:99', 'url': 'http://example.com/'}),
            path='/cgi-bin/login', method='POST', user='user', password='password', success='url',
            fixed={'cmd': 'authenticate'}, calling=mac_hex(mac)),
        # pfSense replaces the variables of the uploaded page as raw text (no URL encoding).
        'pfsense': dict(
            entry=a.portal.rstrip('/') + '/portal?site=' + urllib.parse.quote(a.site) + '&login_url=' + login
            + '/index.php?zone=guest&zone=guest&redirurl=http://example.com/&mac=' + colon + '&ip=' + a.client_ip,
            path='/index.php', method='POST', user='auth_user', password='auth_pass', success='redirurl',
            fixed={'accept': 'Continue', 'zone': 'guest'}, calling=colon),
        # OPNsense 26.7 (observed): the zone redirects to its own template, which (docs/opnsense-captive-template)
        # asks the status API and forwards to the portal; the guest's browser logs in through the logon API
        # (POST from the portal's handoff, or from the template with method "fragment"). JSON answers, no CoA,
        # RADIUS without Framed-IP-Address.
        'opnsense': dict(
            entry=login + '/index.html?' + q({'redirurl': 'http://example.com/'}),
            path='/api/captiveportal/access/logon/0/', method='POST', user='user', password='password', success=None,
            fixed={}, calling=colon, coa=False, json=True,
            radius=[attr_int(SERVICE_TYPE, 1), attr_int(NAS_PORT, 0), attr_int(NAS_PORT_TYPE, 15)]),
        'coovachilli': dict(
            entry=portal + '?' + q({'res': 'notyet', 'uamip': a.host, 'uamport': str(a.port), 'challenge': a.challenge,
                                    'called': 'AA-BB-CC-00-00-99', 'mac': dash_upper, 'ip': a.client_ip, 'nasid': a.nas_id,
                                    'sessionid': sid, 'userurl': 'http://example.com/'}),
            # /logon reads userurl (not redirurl); afterwards chilli redirects to the UAM server (the portal) with res=.
            path='/logon', method='GET', user='username', password='password', success='userurl', fixed={},
            uam_return=portal,
            calling=dash_upper, session_id=sid,
            # CoovaChilli 1.9 (observed): Service-Type, NAS-Port(-Id), dashed MACs, Framed-IP-Address.
            radius=[attr_int(SERVICE_TYPE, 1), attr_int(NAS_PORT, 1), attr(NAS_PORT_ID, '00000001'), attr_int(NAS_PORT_TYPE, 19),
                    attr(CALLED_STATION_ID, 'AA-BB-CC-00-00-99'),
                    attr(ACCT_SESSION_ID, sid), attr_ip(FRAMED_IP_ADDRESS, a.client_ip)]),
        'ruckus': dict(
            entry=portal + '?' + q({'client_mac': colon, 'uip': a.client_ip, 'sip': a.host, 'nbiIP': a.host,
                                    'url': 'http://example.com/', 'ssid': 'Guest'}),
            path='/login', method='POST', user='username', password='password', success='url',
            fixed={'client_mac': colon, 'uip': a.client_ip}, calling=colon),
        'custom': dict(
            entry=portal + '?' + q({'mac': colon, 'ip': a.client_ip, 'login': login + '/login'}),
            path='/login', method='POST', user='username', password='password', success='dst', fixed={},
            calling=colon.upper()),
    }[a.preset]


PLAIN_HTTP = ('coovachilli', 'opnsense')


def chilli_decode(hex_password, challenge, uamsecret):
    """CoovaChilli redir.c: hex-decoded password XOR MD5(challenge + uamsecret) (or the challenge)."""
    data = bytes.fromhex(hex_password)
    chal = bytes.fromhex(challenge)
    key = hashlib.md5(chal + uamsecret.encode()).digest() if uamsecret else chal
    return bytes(b ^ key[i % 16] for i, b in enumerate(data)).rstrip(b'\0').decode()


# --- Simulator ---------------------------------------------------------------------------------------------

class Sim:
    def __init__(self, a):
        self.a = a
        self.p = presets(a)
        self.lock = threading.Lock()
        self.state = {'preset': a.preset, 'radius': not a.no_radius, 'steps': [], 'handoff': None, 'access': None,
                      'session': None, 'accounting': [], 'disconnect': [], 'errors': []}
        self.radius = None if a.no_radius else Radius(a.radius_host, a.auth_port, a.acct_port, a.secret, self.log)

    def log(self, step, message, **data):
        line = '[%s] %s: %s' % (self.a.preset, step, message)
        if data:
            line += ' ' + json.dumps(data, sort_keys=True)
        print(line, flush=True)
        with self.lock:
            self.state['steps'].append({'step': step, 'message': message, **data})
            if step == 'error':
                self.state['errors'].append(message)

    # Handoff ------------------------------------------------------------------------------------------

    def login(self, fields):
        p = self.p
        missing = [k for k in (p['user'], p['password']) if not fields.get(k)]
        wrong = {k: fields.get(k) for k, v in p['fixed'].items() if fields.get(k) != v}
        success = fields.get(p['success']) if p['success'] else ''
        if missing or wrong or (p['success'] and not success):
            self.log('error', 'handoff fields do not match the preset', missing=missing, wrong=wrong, success=success)
            return None, 'handoff fields do not match the preset'
        password = fields[p['password']]
        if self.a.preset == 'coovachilli':
            try:
                password = chilli_decode(password, self.a.challenge, self.a.uamsecret)
            except ValueError:
                self.log('error', 'CoovaChilli password is not hex encoded')
                return None, 'password not encoded'
        user = fields[p['user']]
        with self.lock:
            self.state['handoff'] = {'fields': sorted(fields), 'user': user, 'success': success}
        self.log('handoff', 'login form accepted', fields=sorted(fields))
        if self.radius is None:
            self.log('radius', 'skipped (--no-radius): no Access-Request, the status page will keep waiting')
            return success, None
        attrs = [attr(CALLING_STATION_ID, p['calling']), attr_ip(NAS_IP_ADDRESS, self.a.nas_ip), attr(NAS_IDENTIFIER, self.a.nas_id)]
        attrs += p.get('radius', [attr(CALLED_STATION_ID, 'AA-BB-CC-00-00-99:Guest'), attr_ip(FRAMED_IP_ADDRESS, self.a.client_ip),
                                  attr_int(NAS_PORT_TYPE, 19)])
        try:
            code, reply = self.radius.access(user, password, attrs)
        except Exception as e:  # noqa: BLE001 - logged for the test script
            self.log('error', 'Access-Request failed: %s' % e)
            return None, str(e)
        if code != ACCESS_ACCEPT:
            self.log('access', 'Access-Reject', reply=[NAMES.get(t, t) for t, _ in reply])
            with self.lock:
                self.state['access'] = {'result': 'reject'}
            return None, 'rejected'
        info = {'result': 'accept'}
        for t, v in reply:
            if t == SESSION_TIMEOUT:
                info['session_timeout'] = struct.unpack('!I', v)[0]
            elif t == ACCT_INTERIM_INTERVAL:
                info['interim'] = struct.unpack('!I', v)[0]
            elif t == VENDOR_SPECIFIC and len(v) >= 6 and struct.unpack('!I', v[:4])[0] == WISPR_VENDOR:
                for st, sv in parse_attrs(v[4:]):
                    if st in (WISPR_MAX_UP, WISPR_MAX_DOWN):
                        info['wispr_up' if st == WISPR_MAX_UP else 'wispr_down'] = struct.unpack('!I', sv)[0]
        self.log('access', 'Access-Accept', **info)
        session = {'user': user, 'acct_session_id': p.get('session_id', '%08X' % int(time.time())), 'start': time.time(),
                   'ip': self.a.client_ip, 'mac': p['calling'], 'open': True}
        with self.lock:
            self.state['access'] = info
            self.state['session'] = session
        self.acct(1)
        interval = min(info.get('interim', 300), self.a.interim)
        threading.Thread(target=self.interim_loop, args=(interval,), daemon=True).start()
        return success, None

    def acct(self, status, cause=None):
        s = self.state['session']
        names = {1: 'Start', 3: 'Interim-Update', 2: 'Stop'}
        elapsed = int(time.time() - s['start'])
        framed = [] if self.a.preset == 'opnsense' else [attr_ip(FRAMED_IP_ADDRESS, s['ip'])]
        attrs = [attr_int(ACCT_STATUS_TYPE, status), attr(ACCT_SESSION_ID, s['acct_session_id']), attr(USER_NAME, s['user']),
                 attr(CALLING_STATION_ID, s['mac'])] + framed + [attr_ip(NAS_IP_ADDRESS, self.a.nas_ip),
                 attr(NAS_IDENTIFIER, self.a.nas_id), attr_int(ACCT_SESSION_TIME, elapsed),
                 attr_int(ACCT_INPUT_OCTETS, 1000 * elapsed), attr_int(ACCT_OUTPUT_OCTETS, 5000 * elapsed)]
        if cause is not None:
            attrs.append(attr_int(ACCT_TERMINATE_CAUSE, cause))
        try:
            self.radius.accounting(attrs)
            self.log('accounting', names[status] + ' acknowledged', acct_session_id=s['acct_session_id'])
            with self.lock:
                self.state['accounting'].append(names[status])
        except Exception as e:  # noqa: BLE001
            self.log('error', 'Accounting-%s failed: %s' % (names[status], e))

    def interim_loop(self, interval):
        while True:
            time.sleep(max(5, interval))
            if not self.state['session'] or not self.state['session']['open']:
                return
            self.acct(3)

    # CoA ----------------------------------------------------------------------------------------------

    def coa_loop(self):
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.bind((self.a.listen, self.a.coa_port))
        self.log('coa', 'listening for Disconnect-Request on UDP %d' % self.a.coa_port)
        secret = self.a.secret.encode()
        while True:
            data, peer = sock.recvfrom(4096)
            try:
                code, ident, length = struct.unpack('!BBH', data[:4])
                req_auth, body = data[4:20], data[20:length]
                if code != DISCONNECT_REQUEST:
                    self.log('coa', 'ignored packet code %d' % code)
                    continue
                if hashlib.md5(data[:4] + b'\0' * 16 + body + secret).digest() != req_auth:
                    self.log('error', 'Disconnect-Request with a wrong authenticator (secret?) ignored', peer=peer[0])
                    continue
                attrs = parse_attrs(body)
            except (ValueError, struct.error):
                self.log('error', 'malformed CoA packet ignored')
                continue
            got = {}
            for t, v in attrs:
                if t == FRAMED_IP_ADDRESS:
                    got['Framed-IP-Address'] = socket.inet_ntoa(v)
                elif t in NAMES and t != MESSAGE_AUTHENTICATOR:
                    got[NAMES[t]] = v.decode(errors='replace')
            s = self.state['session']
            match = s is not None and s['open'] and bool(got) and all(
                {'User-Name': lambda v: v == s['user'], 'Acct-Session-Id': lambda v: v == s['acct_session_id'],
                 'Framed-IP-Address': lambda v: v == s['ip'],
                 'Calling-Station-Id': lambda v: mac_hex(v) == mac_hex(s['mac'])}.get(k, lambda v: False)(v)
                for k, v in got.items())
            reply_code = DISCONNECT_ACK if match else DISCONNECT_NAK
            reply_attrs = b'' if match else attr_int(ERROR_CAUSE, 503)
            reply_attrs += attr(MESSAGE_AUTHENTICATOR, b'\0' * 16)
            header = struct.pack('!BBH', reply_code, ident, 20 + len(reply_attrs))
            mac = hmac.new(secret, header + req_auth + reply_attrs, hashlib.md5).digest()
            reply_attrs = reply_attrs[:-16] + mac
            resp_auth = hashlib.md5(header + req_auth + reply_attrs + secret).digest()
            sock.sendto(header + resp_auth + reply_attrs, peer)
            result = 'ack' if match else 'nak'
            self.log('coa', 'Disconnect-%s' % ('ACK' if match else 'NAK'), attributes=got, peer=peer[0])
            with self.lock:
                self.state['disconnect'].append({'result': result, 'attributes': got})
            if match:
                s['open'] = False
                self.acct(2, cause=6)  # Admin-Reset


def handler(sim):
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, fmt, *args):
            pass

        def send(self, status, body, ctype='text/html; charset=utf-8', location=None):
            data = body.encode()
            self.send_response(status)
            if location:
                self.send_header('Location', location)
            self.send_header('Content-Type', ctype)
            self.send_header('Content-Length', str(len(data)))
            self.send_header('Cache-Control', 'no-store')
            self.end_headers()
            self.wfile.write(data)

        def handle_login(self, fields):
            success, error = sim.login(fields)
            if sim.p.get('uam_return'):
                # CoovaChilli (redir.c, observed on 1.9): back to the UAM server with res=success / res=failed&reason=..
                query = {'res': 'failed', 'reason': 'reject'} if error else {'res': 'success'}
                query.update({'uamip': sim.a.host, 'uamport': str(sim.a.port), 'called': 'AA-BB-CC-00-00-99',
                              'uid': fields.get(sim.p['user'], ''), 'mac': sim.p['calling'], 'ip': sim.a.client_ip,
                              'nasid': sim.a.nas_id, 'sessionid': sim.p['session_id'], 'userurl': fields.get('userurl', '')})
                if not error:
                    query['timeleft'] = str((sim.state.get('access') or {}).get('session_timeout', ''))
                return self.send(302, '', location=sim.p['uam_return'] + '?' + urllib.parse.urlencode(query))
            if sim.p.get('json'):
                state = 'NOT_AUTHORIZED' if error else 'AUTHORIZED'
                return self.send(200, json.dumps({'clientState': state, 'ipAddress': sim.a.client_ip}), 'application/json')
            if error:
                self.send(403, '<h1>Login failed</h1><p>%s</p>' % error)
            else:
                self.send(302, '', location=success)

        def template(self):
            # The documented OPNsense template with this run's portal URL (docs/opnsense-captive-template).
            if not sim.a.template:
                return self.send(404, 'no --template given')
            with open(sim.a.template, encoding='utf-8') as f:
                page = f.read()
            portal = sim.a.portal.rstrip('/') + '/portal/g/' + urllib.parse.quote(sim.a.site)
            page = re.sub(r"var PORTAL = '[^']*';", "var PORTAL = '%s';" % portal, page, count=1)
            sim.log('template', 'OPNsense template served')
            self.send(200, page)

        def do_GET(self):
            url = urllib.parse.urlsplit(self.path)
            if url.path == '/state':
                with sim.lock:
                    body = json.dumps(sim.state, indent=1)
                return self.send(200, body, 'application/json')
            if url.path == '/index.html' and sim.a.preset == 'opnsense':
                return self.template()
            if url.path in ('/', '/generate_204', '/hotspot-detect.html'):
                sim.log('redirect', 'guest captured, redirect to the portal', location=sim.p['entry'])
                return self.send(302, '', location=sim.p['entry'])
            if url.path == sim.p['path'] and sim.p['method'] == 'GET':
                return self.handle_login({k: v[-1] for k, v in urllib.parse.parse_qs(url.query).items()})
            self.send(404, 'not found')

        def do_POST(self):
            url = urllib.parse.urlsplit(self.path)
            if url.path == '/api/captiveportal/access/status/0/' and sim.a.preset == 'opnsense':
                s = sim.state['session']
                body = {'clientState': 'AUTHORIZED' if s and s['open'] else 'NOT_AUTHORIZED', 'ipAddress': sim.a.client_ip,
                        'macAddress': sim.p['calling'], 'authType': 'normal'}
                sim.log('status', 'status API asked', clientState=body['clientState'])
                return self.send(200, json.dumps(body), 'application/json')
            if url.path != sim.p['path'] or sim.p['method'] != 'POST':
                sim.log('error', 'unexpected %s %s' % ('POST', url.path))
                return self.send(405, 'method or path not expected for this preset')
            size = min(int(self.headers.get('Content-Length') or 0), 65536)
            fields = {k: v[-1] for k, v in urllib.parse.parse_qs(self.rfile.read(size).decode()).items()}
            if sim.a.preset == 'pfsense':
                zone = urllib.parse.parse_qs(url.query).get('zone', [''])[-1]
                if zone != 'guest':
                    sim.log('error', 'login action without ?zone=guest')
            self.handle_login(fields)

    return Handler


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument('--preset', required=True, choices=['cisco_wlc', 'aruba', 'pfsense', 'opnsense', 'coovachilli', 'ruckus', 'custom'])
    ap.add_argument('--template', help='OPNsense: docs/opnsense-captive-template/index.html (served as /index.html)')
    ap.add_argument('--portal', required=True, help='guest portal base URL as the browser reaches it, e.g. https://127.0.0.1:443')
    ap.add_argument('--site', required=True, help='slug of the portal site (driver generic_radius)')
    ap.add_argument('--host', default='127.0.0.1', help='host/IP under which the browser reaches this simulator')
    ap.add_argument('--port', type=int, default=8099)
    ap.add_argument('--listen', default='0.0.0.0')
    ap.add_argument('--cert', help='TLS certificate (all presets except coovachilli and opnsense)')
    ap.add_argument('--key', help='TLS key')
    ap.add_argument('--radius-host', default='127.0.0.1')
    ap.add_argument('--auth-port', type=int, default=1812)
    ap.add_argument('--acct-port', type=int, default=1813)
    ap.add_argument('--coa-port', type=int, default=3799)
    ap.add_argument('--secret', default='', help='RADIUS secret of the router entry')
    ap.add_argument('--nas-ip', default='127.0.0.1', help='NAS-IP-Address (the router entry\'s NAS IP)')
    ap.add_argument('--nas-id', default='nas-sim')
    ap.add_argument('--client-mac', default='aa:bb:cc:00:00:01')
    ap.add_argument('--client-ip', default='10.0.0.20')
    ap.add_argument('--challenge', default='0123456789abcdef0123456789abcdef', help='CoovaChilli challenge (hex)')
    ap.add_argument('--uamsecret', default='', help='CoovaChilli uamsecret (= handoff secret of the site)')
    ap.add_argument('--interim', type=int, default=60, help='upper bound of the interim interval in seconds')
    ap.add_argument('--no-radius', action='store_true', help='without FreeRADIUS: only check the redirect and the handoff')
    a = ap.parse_args()
    a.started = int(time.time())
    if not a.no_radius and not a.secret:
        ap.error('--secret is required unless --no-radius')

    sim = Sim(a)
    server = ThreadingHTTPServer((a.listen, a.port), handler(sim))
    scheme = 'http'
    if a.preset not in PLAIN_HTTP:
        if not (a.cert and a.key):
            ap.error('--cert and --key are required for preset %s (HTTPS login form)' % a.preset)
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(a.cert, a.key)
        server.socket = ctx.wrap_socket(server.socket, server_side=True)
        scheme = 'https'
    if not a.no_radius and sim.p.get('coa', True):
        threading.Thread(target=sim.coa_loop, daemon=True).start()
    elif not a.no_radius:
        sim.log('coa', 'no CoA listener (the device does not support Disconnect-Request)')
    sim.log('start', 'captive portal on %s://%s:%d/ (state: /state)' % (scheme, a.host, a.port), radius=not a.no_radius)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        sys.exit(0)


if __name__ == '__main__':
    main()
