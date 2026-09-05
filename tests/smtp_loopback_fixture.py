"""Optional loopback-only SMTP/TLS benchmark fixture; requires cryptography."""

import argparse
import datetime
import json
import os
import pathlib
import socket
import socketserver
import ssl
import subprocess
import tempfile
import threading

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', default='php')
parser.add_argument('--baseline', type=pathlib.Path)
parser.add_argument('--candidate', type=pathlib.Path, default=pathlib.Path(__file__).resolve().parents[1])
parser.add_argument('--messages', type=int, default=200)
parser.add_argument('--rounds', type=int, default=3)
parser.add_argument('--output', type=pathlib.Path)
arguments = parser.parse_args()
if not 1 <= arguments.messages <= 5000 or not 1 <= arguments.rounds <= 10:
    parser.error('Use 1-5000 messages and 1-10 rounds')
candidate = arguments.candidate.resolve()
roots = {}
if arguments.baseline:
    roots['baseline'] = arguments.baseline.resolve()
roots['candidate'] = candidate
for root in roots.values():
    if not (root / 'vendor/autoload.php').is_file():
        parser.error('Each checkout needs its locked Composer dependencies')

class SmtpServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

    def handle_error(self, request, address):
        with self.guard:
            self.stats['errors'] += 1

class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        sock = self.request
        sock.settimeout(15)
        sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        stream = sock.makefile('rb')
        tls = False
        auth = None
        recipients = []
        message = None
        with self.server.guard:
            self.server.stats['connections'] += 1

        def reply(data):
            sock.sendall(data)

        try:
            reply(b'220 localhost loopdeck fixture\r\n')
            while True:
                line = stream.readline(65536)
                if not line:
                    break
                if message is not None:
                    if line == b'.\r\n':
                        with self.server.guard:
                            index = self.server.stats['messages']
                            expected = ('recipient%d@example.com' % index).encode()
                            body = ('LoopDeck loopback body %d' % index).encode()
                            if recipients != [expected] or body not in b''.join(message) or not tls:
                                self.server.stats['errors'] += 1
                            self.server.stats['messages'] += 1
                        message = None
                        reply(b'250 2.0.0 accepted locally\r\n')
                    else:
                        message.append(line)
                    continue
                if auth is not None:
                    if auth == 'username':
                        auth = 'password'
                        reply(b'334 UGFzc3dvcmQ6\r\n')
                    else:
                        auth = None
                        with self.server.guard:
                            self.server.stats['authentications'] += 1
                        reply(b'235 2.7.0 authenticated\r\n')
                    continue
                command = line.strip().split(b' ', 1)[0].upper()
                if command in (b'EHLO', b'HELO'):
                    reply(b'250-localhost\r\n' + (b'' if tls else b'250-STARTTLS\r\n')
                          + b'250-AUTH LOGIN\r\n250 8BITMIME\r\n')
                elif command == b'STARTTLS':
                    reply(b'220 2.0.0 ready for TLS\r\n')
                    stream.close()
                    sock = self.server.tls_context.wrap_socket(sock, server_side=True)
                    sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
                    stream = sock.makefile('rb')
                    tls = True
                    with self.server.guard:
                        self.server.stats['tls_handshakes'] += 1
                elif command == b'AUTH':
                    if not tls:
                        reply(b'530 TLS required\r\n')
                        continue
                    auth = 'username'
                    reply(b'334 VXNlcm5hbWU6\r\n')
                elif command == b'MAIL':
                    recipients = []
                    reply(b'250 2.1.0 sender accepted\r\n')
                elif command == b'RCPT':
                    recipients.append(line.split(b'<', 1)[1].split(b'>', 1)[0])
                    reply(b'250 2.1.5 recipient accepted\r\n')
                elif command == b'DATA':
                    message = []
                    reply(b'354 end with dot\r\n')
                elif command in (b'RSET', b'NOOP'):
                    recipients = []
                    reply(b'250 2.0.0 reset\r\n')
                elif command == b'QUIT':
                    reply(b'221 2.0.0 goodbye\r\n')
                    break
                else:
                    reply(b'502 5.5.1 unsupported\r\n')
        finally:
            stream.close()
            sock.close()

with tempfile.TemporaryDirectory(prefix='loopdeck-smtp-fixture-') as temporary:
    folder = pathlib.Path(temporary)
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'localhost')])
    now = datetime.datetime.now(datetime.timezone.utc)
    certificate = (x509.CertificateBuilder().subject_name(name).issuer_name(name)
        .public_key(key.public_key()).serial_number(x509.random_serial_number())
        .not_valid_before(now - datetime.timedelta(minutes=5))
        .not_valid_after(now + datetime.timedelta(days=1))
        .add_extension(x509.SubjectAlternativeName([x509.DNSName('localhost')]), critical=False)
        .add_extension(x509.BasicConstraints(ca=True, path_length=None), critical=True)
        .sign(key, hashes.SHA256()))
    cert_path = folder / 'fixture-ca.pem'
    key_path = folder / 'fixture-key.pem'
    cert_path.write_bytes(certificate.public_bytes(serialization.Encoding.PEM))
    key_path.write_bytes(key.private_bytes(serialization.Encoding.PEM,
        serialization.PrivateFormat.TraditionalOpenSSL, serialization.NoEncryption()))
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert_path, key_path)
    results = {name: [] for name in roots}
    for repeat in range(arguments.rounds):
        for name in (list(roots) if repeat % 2 == 0 else list(reversed(roots))):
            with SmtpServer(('127.0.0.1', 0), Handler) as server:
                server.tls_context = context
                server.guard = threading.Lock()
                server.stats = dict(connections=0, tls_handshakes=0, authentications=0, messages=0, errors=0)
                worker = threading.Thread(target=server.serve_forever, daemon=True)
                worker.start()
                try:
                    run = subprocess.run([arguments.php, str(candidate / 'tests/SmtpLoopbackBenchmark.php'), str(arguments.messages)],
                        env={**os.environ, 'LOOPDECK_BENCHMARK_ROOT': str(roots[name]),
                             'LOOPDECK_BENCHMARK_SMTP_CA': str(cert_path),
                             'LOOPDECK_BENCHMARK_SMTP_PORT': str(server.server_address[1])},
                        capture_output=True, text=True, encoding='utf-8', cwd=roots[name], timeout=300)
                    if run.returncode:
                        raise RuntimeError(run.stdout + run.stderr)
                    output = json.loads(run.stdout)
                    output.update(server.stats)
                    if output['errors'] != 0 or output['messages'] != arguments.messages:
                        raise RuntimeError('SMTP fixture delivery validation failed: ' + json.dumps(output))
                    results[name].append(output)
                    print(json.dumps({'revision': name, 'round': repeat + 1, **output}), flush=True)
                finally:
                    server.shutdown()
                    worker.join()
    if arguments.output:
        arguments.output.write_text(json.dumps(results, indent=2), encoding='utf-8')
