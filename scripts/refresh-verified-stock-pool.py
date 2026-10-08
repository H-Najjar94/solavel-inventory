#!/usr/bin/env python3
"""Root-only invocation of the temporary Stock opcode identity probe."""
import argparse
import os
from pathlib import Path
import re
import socket
import struct

p = argparse.ArgumentParser()
p.add_argument('--release', required=True)
p.add_argument('--sha', required=True)
p.add_argument('--probe', required=True)
a = p.parse_args()
assert os.geteuid() == 0
assert re.fullmatch(r'\d{8}T\d{6}Z-[a-f0-9]{8}', a.release)
assert re.fullmatch(r'[a-f0-9]{40}', a.sha)
assert re.fullmatch(r'_qa32_opcache_[a-f0-9]{24}\.php', a.probe)
root = Path('/var/www/solavel-stock')
release = root / 'releases' / a.release
assert (root / 'current').resolve() == release
assert (release / 'RELEASE_SHA').read_text().strip() == a.sha
script = release / 'public' / a.probe
assert script.is_file() and not script.is_symlink()

def record(kind, content=b''):
    return struct.pack('!BBHHBB', 1, kind, 1, len(content), 0, 0) + content

def length(n):
    return bytes([n]) if n < 128 else struct.pack('!I', n | 0x80000000)

params = {'SCRIPT_FILENAME': str(script), 'SCRIPT_NAME': '/local-stock-release',
          'REQUEST_URI': '/local-stock-release', 'REQUEST_METHOD': 'POST',
          'SERVER_PROTOCOL': 'HTTP/1.1', 'SERVER_NAME': 'localhost',
          'SERVER_PORT': '0', 'REMOTE_ADDR': '127.0.0.1', 'CONTENT_LENGTH': '0'}
payload = b''.join(length(len(k)) + length(len(v)) + k.encode() + v.encode()
                   for k, v in params.items())
with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as s:
    s.settimeout(20)
    s.connect('/run/php/php8.4-solavel-stock.sock')
    s.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, payload) + record(4) + record(5))

    def exact(n):
        out = b''
        while len(out) < n:
            part = s.recv(n - len(out))
            if not part:
                raise RuntimeError('Incomplete Stock FastCGI response')
            out += part
        return out

    output = b''
    while True:
        _, kind, _, size, padding, _ = struct.unpack('!BBHHBB', exact(8))
        body = exact(size)
        exact(padding)
        if kind == 6:
            output += body
        if kind == 3:
            break
    identity = output.split(b'\r\n\r\n', 1)[1].decode().strip()
    assert identity == str(release), 'Unexpected Stock pool identity'
    print(identity)
