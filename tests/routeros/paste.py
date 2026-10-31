#!/usr/bin/env python3
"""Types a script into an interactive RouterOS terminal session, line by line (like a terminal paste).

usage: python3 -I paste.py <file> -- <ssh command ...>
Prints the session output (CR removed). Answers the license question of a first login with "n", skips the
password change offer (Ctrl-C) and answers the terminal's cursor position request; waits for the prompt before the first line.
"""
import fcntl, os, pty, struct, termios, re, select, sys, time

f = sys.argv[1]
cmd = sys.argv[sys.argv.index('--') + 1:]
lines = open(f, encoding='utf-8').read().split('\n')
if lines and lines[-1] == '':
    lines.pop()

pid, fd = pty.fork()
if pid == 0:
    os.execvp(cmd[0], cmd)

# a pty starts with size 0x0 (RouterOS then wraps after every character)
fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack('HHHH', 50, 250, 0, 0))
buf = b''
out = b''


def pump(timeout):
    global buf, out
    r, _, _ = select.select([fd], [], [], timeout)
    if not r:
        return False
    try:
        d = os.read(fd, 65536)
    except OSError:
        return False
    if not d:
        return False
    buf += d
    out += d
    if os.environ.get("PASTE_DEBUG"): open(os.environ["PASTE_DEBUG"], "ab").write(d)
    # RouterOS measures the terminal by moving the cursor far away and asking for its position
    for m in re.finditer(rb'(\x1b\[9999([BC]))?(?:\x1b\[H)?\x1b\[6n', d):
        os.write(fd, b'\x1b[%d;%dR' % ((50, 1) if m.group(2) == b'B' else (1, 250) if m.group(2) == b'C' else (1, 1)))
    return True


def plain():
    # terminal escape sequences and CR removed (RouterOS redraws the prompt line character by character)
    return re.sub(rb'\x1b\[[0-9;?]*[A-Za-z]|\x1b.|\r', b'', buf)


def wait_for(pattern, limit):
    global buf
    end = time.time() + limit
    while time.time() < end:
        if re.search(pattern, plain()):
            return True
        pump(0.5)
    return False


# login: license question (first login), password change offer (Ctrl-C skips it), then the prompt
end = time.time() + 400
while time.time() < end:
    wait_for(rb'(license\? \[Y/n\]|ew password>|\] > )', 60)
    p = plain()
    if b'license?' in p:
        os.write(fd, b'n\r')
    elif b'ew password>' in p or b'Ctrl-C to skip' in p:
        os.write(fd, b'\x03')
    elif re.search(rb'\] > ', p):
        break
    buf = b''
buf = b''
time.sleep(1)
for line in lines:
    buf = b''
    os.write(fd, line.encode() + b'\r')
    # wait for the echo of the end of the line (the router may be slow), then until the output is quiet
    tail = line.encode()[-12:]
    ok = wait_for(re.escape(tail) if tail else rb'', 60)
    if os.environ.get('PASTE_DEBUG'): sys.stderr.write('%s %s %r\n' % (time.strftime('%X'), ok, line[:40]))
    while pump(0.7):
        pass
# let the router finish, then leave
wait_for(rb'\] > ?$', 20)
time.sleep(2)
while pump(0.5):
    pass
os.write(fd, b'\x03/quit\r')
end = time.time() + 15
while time.time() < end and pump(0.5):
    pass
try:
    os.waitpid(pid, 0)
except ChildProcessError:
    pass
sys.stdout.write(out.decode(errors='replace').replace('\r', ''))
