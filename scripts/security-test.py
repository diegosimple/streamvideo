"""Local tests: requires running services without a configured administrator."""
import html
import http.cookiejar
import os
import pathlib
import re
import secrets
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
MANAGER = 'http://127.0.0.1:8081/'
PUBLIC = 'http://127.0.0.1:' + os.environ.get('VIDEO_HTTP_PORT', '8080')

def docker(*args, data=None, check=True):
    return subprocess.run(['docker', *args], input=data, capture_output=True, check=check, cwd=ROOT)

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def fetch(browser, url, data=None, headers=None, expected=200):
    try:
        response = browser.open(urllib.request.Request(url, data, headers or {}), timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read()
    assert response.code == expected, f'Expected {expected}, got {response.code}: {body[:150]!r}'
    return body, response.headers

def csrf(page):
    return re.search(rb'name="csrf" value="([a-f0-9]+)"', page)[1].decode()

def post(browser, fields, expected=200):
    return fetch(browser, MANAGER, urllib.parse.urlencode(fields).encode(), expected=expected)

def upload(browser, token, name, data, expected=200):
    boundary = secrets.token_hex(16)
    body = b''
    for key, value in [('csrf', token), ('action', 'upload')]:
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
    body += f'--{boundary}\r\nContent-Disposition: form-data; name="video_file"; filename="{name}"\r\nContent-Type: video/mp4\r\n\r\n'.encode()
    body += data + f'\r\n--{boundary}--\r\n'.encode()
    return fetch(browser, MANAGER, body, {'Content-Type': f'multipart/form-data; boundary={boundary}'}, expected)

assert sys.argv[1:] == ['--bootstrap'], 'Use --bootstrap locally without a configured admin.'
exists = docker('compose', 'exec', '-T', 'video-manager', 'test', '-f', '/var/lib/streamvideo/admin.json', check=False)
assert exists.returncode == 1, 'Existing admin or unavailable container: refusing to change credentials.'
password = secrets.token_urlsafe(24)
browser = client()
created_ids = []
token = None
try:
    # First administrator must prove possession of the server's installation key.
    page, _ = fetch(browser, MANAGER + 'setup')
    assert b'name="setup_key"' in page
    fetch(browser, PUBLIC + '/setup')
    for asset in ['/img/imgLogo.png', '/img/icone-erp.ico', '/access.css']:
        content, _ = fetch(browser, PUBLIC + asset)
        assert len(content) > 100
    fields = {'action': 'setup', 'csrf': csrf(page), 'username': 'smoke-admin', 'password': password, 'password_confirmation': password, 'setup_key': 'invalid'}
    fetch(browser, MANAGER + 'setup', urllib.parse.urlencode(fields).encode(), expected=403)
    key = docker('compose', 'exec', '-T', '--user', 'www-data', 'video-manager', 'php', '/opt/streamvideo/setup-key.php').stdout.decode().strip()
    fields['setup_key'] = key
    fields['csrf'] = 'invalid'
    fetch(browser, MANAGER + 'setup', urllib.parse.urlencode(fields).encode(), expected=403)
    fields['csrf'] = csrf(page)
    page, _ = fetch(browser, MANAGER + 'setup', urllib.parse.urlencode(fields).encode())
    recovery = re.search(rb'id="recovery-code">([A-F0-9-]+)</code>', page)[1].decode()
    closed, _ = fetch(browser, MANAGER + 'setup', expected=409)
    assert recovery.encode() not in closed
    fetch(browser, MANAGER + 'setup', urllib.parse.urlencode(fields).encode(), expected=409)
    # Establish a session that must be invalidated by recovery.
    old_session = client()
    page, _ = fetch(old_session, MANAGER)
    post(old_session, {'action': 'login', 'username': 'smoke-admin', 'password': password, 'csrf': csrf(page)})
    page, _ = fetch(browser, MANAGER + 'recuperar')
    reset = {'action': 'recover', 'csrf': csrf(page), 'username': 'smoke-admin', 'password': secrets.token_urlsafe(24), 'recovery_key': 'invalid'}
    reset['password_confirmation'] = reset['password']
    fetch(browser, MANAGER + 'recuperar', urllib.parse.urlencode(reset).encode(), expected=403)
    reset['recovery_key'] = recovery
    page, _ = fetch(browser, MANAGER + 'recuperar', urllib.parse.urlencode(reset).encode())
    replacement_key = re.search(rb'id="recovery-code">([A-F0-9-]+)</code>', page)[1].decode()
    assert replacement_key != recovery
    page, _ = fetch(old_session, MANAGER)
    assert b'name="video_file"' not in page
    post(old_session, {'action': 'login', 'username': 'smoke-admin', 'password': password, 'csrf': csrf(page)}, expected=401)
    password = reset['password']
    page, _ = fetch(browser, MANAGER + 'recuperar')
    reset['csrf'] = csrf(page)
    fetch(browser, MANAGER + 'recuperar', urllib.parse.urlencode(reset).encode(), expected=403)
    # Public routes do not disclose internal PHP files or installation credentials.
    fetch(browser, PUBLIC + '/credentials.php', expected=404)
    fetch(browser, PUBLIC + '/setup-key.php', expected=404)
    anonymous = client()
    login, headers = fetch(anonymous, MANAGER)
    assert b'name="password"' in login and b'name="video_file"' not in login
    assert "script-src 'nonce-" in headers['Content-Security-Policy']
    post(anonymous, {'action': 'delete', 'id': 1, 'csrf': csrf(login)}, expected=403)
    post(anonymous, {'action': 'login', 'username': 'smoke-admin', 'password': 'wrong', 'csrf': csrf(login)}, expected=401)
    page, _ = fetch(browser, MANAGER)
    page, _ = post(browser, {'action': 'login', 'username': 'smoke-admin', 'password': password, 'csrf': csrf(page)})
    assert b'name="video_file"' in page
    token = csrf(page)
    post(browser, {'action': 'delete', 'id': 0, 'csrf': 'invalid'}, expected=400)
    with tempfile.TemporaryDirectory(prefix='streamvideo-test-') as directory:
        fixture_name = 'security-' + secrets.token_hex(8)
        for ext in ['mp4', 'mov']:
            container_path = f'/tmp/{fixture_name}.{ext}'
            docker('compose', 'exec', '-T', 'rtmp-server', 'ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=320x180:d=1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', container_path)
            fixture_path = pathlib.Path(directory) / ('fixture.' + ext)
            docker('cp', 'zeuspro-streaming:' + container_path, str(fixture_path))
            fixture = fixture_path.read_bytes()
            if ext == 'mp4':
                upload(browser, token, 'invalid.mp4', b'<html><body>This is HTML, not video.</body></html>', expected=400)
                upload(browser, token, 'invalid.mov', b'This is plain text, not video.', expected=400)
                upload(browser, token, 'invalid.html', fixture, expected=400)
                upload(browser, token, 'invalid.mp4.php', fixture, expected=400)
                upload(browser, token, 'fake.mp4', b'\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2' + b'\x00' * 256, expected=400)
            prefix = 'smoke-' + secrets.token_hex(5)
            page, _ = upload(browser, token, prefix + '<b>title.' + ext, fixture)
            assert (prefix + '<b>').encode() not in page
            row = next(row for row in page.decode().split('<tr>') if prefix in row)
            video_id = re.search(r'id="url_(\d+)"', row)[1]
            created_ids.append(video_id)
            signed_url = html.unescape(re.search(r'value="(https://streaming[^\"]+)"', row)[1])
            query = urllib.parse.urlsplit(signed_url).query
            embed, headers = fetch(anonymous, PUBLIC + '/embed.php?' + query)
            assert b'<video controls' in embed and headers.get('X-Frame-Options') is None
            media = PUBLIC + '/media.php?' + query
            body, headers = fetch(anonymous, media)
            assert body == fixture and headers['X-Content-Type-Options'] == 'nosniff'
            body, _ = fetch(anonymous, media, headers={'Range': 'bytes=0-15'}, expected=206)
            assert body == fixture[:16]
            fetch(anonymous, PUBLIC + '/media.php', expected=403)
            values = urllib.parse.parse_qs(query)
            values['signature'] = ['0' * 64]
            fetch(anonymous, PUBLIC + '/embed.php?' + urllib.parse.urlencode(values, doseq=True), expected=403)
            values = urllib.parse.parse_qs(query)
            values['expires'] = ['1']
            fetch(anonymous, PUBLIC + '/media.php?' + urllib.parse.urlencode(values, doseq=True), expected=403)
            filename = html.unescape(re.search(r'<td>(smoke-[^<]+)</td>', row)[1])
            fetch(anonymous, PUBLIC + '/videos/' + urllib.parse.quote(filename), expected=404)
            fetch(anonymous, PUBLIC + '/protected-videos/' + urllib.parse.quote(filename), expected=404)
            post(browser, {'action': 'revoke', 'id': video_id, 'csrf': token})
            fetch(anonymous, media, expected=403)
            post(browser, {'action': 'delete', 'id': video_id, 'csrf': token})
            created_ids.remove(video_id)
            docker('compose', 'exec', '-T', 'rtmp-server', 'rm', '-f', container_path)
    page, _ = post(browser, {'action': 'logout', 'csrf': token})
    assert b'name="password"' in page
    post(browser, {'action': 'upload', 'csrf': token}, expected=403)
    for index in range(11):
        page, _ = fetch(anonymous, MANAGER)
        post(anonymous, {'action': 'login', 'username': 'smoke-admin', 'password': 'wrong', 'csrf': csrf(page)}, expected=401 if index < 10 else 429)
    print('PASS: branded assets, protected setup, setup closes after creation, recovery key rotation, old session/password invalidation, login/logout, CSRF, rate limit, MP4/MOV validation, HTML rejection, signed playback, ranges, revocation, direct-file blocking, deletion')
finally:
    for video_id in created_ids:
        if token:
            try:
                post(browser, {'action': 'delete', 'id': video_id, 'csrf': token})
            except Exception:
                pass
    docker('compose', 'exec', '-T', '--user', 'www-data', 'video-manager', 'php', '-r', "unlink('/var/lib/streamvideo/admin.json'); $db = new PDO('sqlite:/var/lib/streamvideo/auth.sqlite'); $db->exec('DELETE FROM attempts');")
