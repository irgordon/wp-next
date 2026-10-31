#!/usr/bin/env python3
"""Production-mode workflows through nginx/Apache, PHP-FPM and verified HTTPS."""
import base64
import hashlib
import io
import json
import re
import ssl
import sqlite3
import subprocess
import tarfile
import urllib.error
import urllib.parse
import zipfile

import http.client
import http_helpers as h
import portability_helpers as portability
from deployment_support import TLSClient, arguments, deployed_site, raw_request


def main():
    options = arguments()
    h.PHP = options.php
    stacks = ('nginx', 'apache') if options.stack == 'both' else (options.stack,)
    modes = ('direct', 'terminated') if options.mode == 'both' else (options.mode,)
    for stack in stacks:
        for mode in modes:
            run_stack(options, stack, mode)
    print(f'{h.COUNT} real PHP-FPM/HTTPS assertions passed.', flush=True)


def run_stack(options, stack, mode):
    print(f'BEGIN: {stack} / {mode}', flush=True)
    with deployed_site(options, stack, mode) as fixture:
        verify_transport(fixture)
        verify_application(fixture)
    verify_restoration(options, stack, mode, fixture)
    print(f'COMPLETE: {stack} / {mode}', flush=True)


def verify_application(fixture):
    fixture.update(admin=TLSClient(fixture), guest=TLSClient(fixture))
    verify_installation(fixture)
    verify_author_isolation(fixture)
    verify_content(fixture)
    verify_assets(fixture)
    verify_design(fixture)
    verify_portability(fixture)
    verify_discovery(fixture)
    verify_operations(fixture)
    verify_api(fixture)
    verify_logout(fixture)


def verify_api(fixture):
    # The editorial API through the real stack: disabled by default, and —
    # once an operator enables it — proof that the Authorization header
    # actually reaches PHP (Apache needs CGIPassAuth; a dropped header is
    # distinguishable because it yields authentication-required, not
    # invalid-token).
    admin = fixture['admin']
    label = fixture['stack'] + '/' + fixture['mode']
    status, body, _ = admin.request('/wp-next/v1/posts')
    h.check(status == 503 and '"credentials-unavailable"' in body, 'Editorial API is disabled by default through ' + label)
    if fixture['mode'] == 'direct':
        plain = h.Client(fixture['http'])
        status, body, _ = plain.request('/wp-next/v1/posts')
        h.check(status == 503 and '"https-required"' in body, 'Plain HTTP origin returns https-required through ' + fixture['stack'])
    for command in (('api:init',), ('api:enable',)):
        subprocess.run([fixture['options'].php, str(fixture['site']/'bin/wp'), *command],
                       env=fixture['env'], check=True, capture_output=True, timeout=60)
    issued = subprocess.run([fixture['options'].php, str(fixture['site']/'bin/wp'), 'api:issue', 'admin', 'Deployment probe', 'posts:read', '30'],
                            env=fixture['env'], check=True, capture_output=True, text=True, timeout=60)
    match = re.search(r'wpn1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}', issued.stdout)
    h.check(match is not None, 'CLI issues an API credential in the deployed fixture')
    status, body, _ = admin.request('/wp-next/v1/posts', headers={'Authorization': 'Bearer ' + match.group(0)})
    h.check(status == 200, 'The Authorization header reaches PHP through ' + label)
    status, body, _ = admin.request('/wp-next/v1/posts', headers={'Authorization': 'Bearer wpn1.' + 'f' * 32 + '.' + 'A' * 43})
    h.check(status == 401 and '"invalid-token"' in body, 'A bogus credential is distinguished from a missing one through ' + label)
    status, body, _ = admin.request('/wp-next/v1/posts')
    h.check(status == 401 and '"authentication-required"' in body, 'A missing credential returns authentication-required through ' + label)
    verify_api_mutation(fixture, label)


def verify_api_mutation(fixture, label):
    issued = subprocess.run([fixture['options'].php, str(fixture['site']/'bin/wp'), 'api:issue', 'admin', 'Deployment mutation', 'posts:read,posts:write,posts:publish', '30'],
                            env=fixture['env'], check=True, capture_output=True, text=True, timeout=60)
    match = re.search(r'wpn1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}', issued.stdout)
    h.check(match is not None, 'CLI issues a write-scoped API credential in the deployed fixture')
    client = TLSClient(fixture)
    headers = {'Authorization': 'Bearer ' + match.group(0), 'Content-Type': 'application/json', 'Idempotency-Key': 'deployment-create-0001'}
    payload = json.dumps({'title': 'API Mutation', 'content': {'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'DEPLOYED-API-BODY'}]}}).encode()
    status, body, _ = client.request('/wp-next/v1/posts', payload, headers)
    created = json.loads(body) if status == 201 else {}
    h.check(status == 201 and created.get('status') == 'draft', 'An Authorization-authenticated API mutation creates a post through ' + label)
    h.check(h.dbrow(fixture['database'], "SELECT COUNT(*) AS n FROM posts WHERE title='API Mutation'")['n'] == 1, 'The API mutation commits exactly one post through ' + label)
    status, body, response_headers = client.request('/wp-next/v1/posts', payload, headers)
    replayed = json.loads(body) if status == 201 else {}
    h.check(status == 201 and response_headers.get('Idempotency-Replayed') == 'true' and replayed.get('mutation_id') == created.get('mutation_id'), 'The replayed API mutation returns the stored acknowledgement through ' + label)
    h.check(h.dbrow(fixture['database'], "SELECT COUNT(*) AS n FROM posts WHERE title='API Mutation'")['n'] == 1, 'The API mutation replay creates no duplicate post through ' + label)
    publish_headers = dict(headers, **{'Idempotency-Key': 'deployment-publish-001'})
    status, body, _ = client.request('/wp-next/v1/posts/' + str(created['id']) + '/publish', json.dumps({'expected_revision': created['revision']}).encode(), publish_headers)
    published = json.loads(body) if status == 200 else {}
    h.check(status == 200 and published.get('status') == 'published', 'An Authorization-authenticated API publish succeeds through ' + label)


def verify_transport(fixture):
    check_tls_rejection(fixture, False, fixture['base'], 'Untrusted temporary CA is rejected')
    check_tls_rejection(fixture, True, fixture['base'].replace('localhost', '127.0.0.1'), 'Certificate hostname mismatch is rejected')
    if fixture['mode'] == 'direct':
        verify_insecure_origin(fixture)


def check_tls_rejection(fixture, trusted, base, label):
    candidate = dict(fixture, base=base)
    client = TLSClient(candidate) if trusted else h.Client(base)
    try:
        client.request('/')
    except urllib.error.URLError as error:
        h.check(isinstance(error.reason, ssl.SSLCertVerificationError), label)
        return
    raise AssertionError(label)


def verify_insecure_origin(fixture):
    client = h.Client(fixture['http'])
    for headers in ({}, {'X-Forwarded-Proto': 'https', 'X-Forwarded-Host': 'attacker.invalid', 'Forwarded': 'proto=https;host=attacker.invalid'}):
        status, _, _ = client.request('/wp-admin/install.php', headers=headers)
        h.check(status == 503, 'Production HTTP refuses insecure origin even with spoofed HTTPS headers')


def verify_installation(fixture):
    admin = fixture['admin']
    status, body, _ = admin.request('/wp-admin/install.php')
    h.check(status == 200 and 'Create your site' in body, 'Blank installer executes through verified TLS and FPM')
    invalid = install_form(body, email='bad', password='short')
    status, _, _ = admin.request('/wp-admin/install.php', invalid)
    h.check(status == 200 and installed_users(fixture) == 0, 'Invalid installation creates no administrator')
    _, body, _ = admin.request('/wp-admin/install.php')
    status, body, _ = admin.request('/wp-admin/install.php', install_form(body))
    h.check(status == 200 and 'Installation complete' in body, 'Blank production installation completes')
    status, _, headers = admin.login('admin')
    h.check(status == 302 and len(admin.cookie()) == 64, 'Administrator signs in through FPM')
    h.check('secure' in headers.get('Set-Cookie', '').lower() and 'httponly' in headers.get('Set-Cookie', '').lower(), 'Session cookie is Secure and HttpOnly')
    verify_authenticated_headers(fixture)


def verify_authenticated_headers(fixture):
    status, _, headers = fixture['admin'].request('/wp-admin/index.php', headers={'X-Forwarded-Proto': 'http', 'X-Forwarded-Host': 'attacker.invalid'})
    h.check(status == 200 and 'max-age=' in headers.get('Strict-Transport-Security', ''), 'Trusted TLS state survives spoofed forwarding headers')
    h.check(headers.get('X-Frame-Options') == 'DENY' and "script-src 'self'" in headers.get('Content-Security-Policy', ''), 'Authenticated shell retains security policy')
    h.check(fixture['guest'].request('/wp-admin/users.php')[0] == 302, 'Anonymous account administration is refused')
    h.check(fixture['admin'].request('/wp-admin/post-new.php', {'post_title': 'No CSRF'})[0] == 403, 'Content write requires action-bound CSRF')


def install_form(body, email='admin@example.test', password='disposable-test-password'):
    return {'_wpnonce': h.token(body), 'site_title': 'HTTPS Deployment', 'admin_user': 'admin',
            'admin_email': email, 'admin_password': password}


def verify_author_isolation(fixture):
    _, body, _ = fixture['admin'].request('/wp-admin/users.php')
    fields = {'_wpnonce': h.token(body), 'username': 'writer', 'email': 'writer@example.test',
              'password': 'disposable-test-password', 'role': 'author'}
    status, body, _ = fixture['admin'].request('/wp-admin/users.php', fields)
    h.check(status == 200 and 'Account saved.' in body, 'Administrator creates a restricted author')
    writer = TLSClient(fixture)
    h.check(writer.login('writer')[0] == 302, 'Restricted author signs in through FPM')
    h.check(writer.request('/wp-admin/options-general.php')[0] == 403, 'Author cannot change site settings')
    fixture['writer'] = writer


def installed_users(fixture):
    with sqlite3.connect(fixture['database']) as database:
        exists = database.execute("SELECT name FROM sqlite_master WHERE name='users'").fetchone()
        return database.execute('SELECT COUNT(*) FROM users').fetchone()[0] if exists else 0


def verify_content(fixture):
    h.check(create_content(fixture, 'FPM Post', 'post', 'published')[0] == 302, 'Published Post is created')
    h.check(create_content(fixture, 'FPM Page', 'page', 'published')[0] == 302, 'Published Page is created')
    h.check(create_content(fixture, 'Private FPM Draft', 'page', 'draft')[0] == 302, 'Private Page draft is created')
    guest = fixture['guest']
    h.check('BODY-FPM Post' in guest.request('/fpm-post/')[1], 'Pretty Post route renders saved content')
    h.check('BODY-FPM Page' in guest.request('/fpm-page/')[1], 'Pretty Page route renders saved content')
    h.check(guest.request('/private-fpm-draft/')[0] == 404, 'Draft remains unavailable anonymously')
    verify_content_updates(fixture)
    verify_visual_preview(fixture)


def create_content(fixture, title, kind, state):
    path = '/wp-admin/post-new.php?post_type=' + kind
    _, form, _ = fixture['admin'].request(path)
    return fixture['admin'].request(path, {'_wpnonce': h.token(form, 'post-editor'), 'post_type': kind,
           'post_title': title, 'post_status': state, 'post_content': '<p>BODY-' + title + '</p>'})


def verify_content_updates(fixture):
    record = h.dbrow(fixture['database'], "SELECT * FROM posts WHERE slug='fpm-post'")
    fixture['post_id'] = record['id']
    path = '/wp-admin/post.php?post=' + str(record['id'])
    _, form, _ = fixture['admin'].request(path)
    data = {'_wpnonce': h.token(form, 'post-editor'), 'ID': record['id'], 'expected_revision': record['revision'],
            'post_title': 'FPM Post', 'post_name': 'fpm-post', 'post_status': 'published', 'post_content': '<p>EDITED-FPM-BODY</p>'}
    h.check(fixture['admin'].request(path, data)[0] == 302, 'Content update commits')
    h.check(fixture['admin'].request(path, dict(data, post_content='<p>STALE-FPM</p>'))[0] == 200, 'Stale update receives conflict form')
    h.check('EDITED-FPM-BODY' in fixture['guest'].request('/fpm-post/')[1], 'Stale edit cannot overwrite current content')
    status, _, headers = fixture['guest'].request('/?p=' + str(record['id']), headers={'Host': 'attacker.invalid', 'X-Forwarded-Host': 'attacker.invalid'})
    h.check(status == 301 and headers.get('Location') == fixture['base'] + '/fpm-post/', 'Canonical redirect ignores spoofed forwarding host')


def verify_visual_preview(fixture):
    _, body, _ = fixture['admin'].request('/wp-admin/post-new.php?editor=visual')
    nonce = re.search(r'id="block-preview-nonce" value="([^"]+)"', body)[1]
    data = {'_wpnonce': nonce, 'content': json.dumps({'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'FPM-CANVAS-PREVIEW'}]})}
    status, body, _ = fixture['admin'].request('/wp-admin/block-preview.php', data)
    h.check(status == 200 and 'FPM-CANVAS-PREVIEW' in body, 'Authenticated visual-editor preview POST reaches FPM')
    status, body, _ = fixture['guest'].request('/wp-admin/block-preview.php', data)
    h.check(status in (302, 403) and 'FPM-CANVAS-PREVIEW' not in body, 'Anonymous visual-editor POST is refused')


def verify_assets(fixture):
    verify_document_delivery(fixture)
    verify_upload_boundaries(fixture)
    verify_image_delivery(fixture)
    verify_sensitive_paths(fixture)


def verify_document_delivery(fixture):
    public = upload_document(fixture, 'public.txt', b'PUBLIC-FPM-DOCUMENT')
    private = upload_document(fixture, 'private.txt', b'PRIVATE-FPM-DOCUMENT', 'private')
    fixture.update(public_document=public, private_document=private)
    h.check(fixture['guest'].request('/documents/' + str(public))[1] == 'PUBLIC-FPM-DOCUMENT', 'Public document downloads anonymously')
    status, body, headers = fixture['guest'].request('/documents/' + str(private))
    h.check(status == 404 and 'PRIVATE-FPM' not in body and 'no-store' in headers.get('Cache-Control', ''), 'Private document denial is uniform and uncacheable')
    h.check(fixture['admin'].request('/documents/' + str(private))[1] == 'PRIVATE-FPM-DOCUMENT', 'Owner can download private bytes')
    h.check(fixture['writer'].request('/documents/' + str(private))[0] == 404, 'Other author cannot download private bytes')
    status, body, headers = raw_request(fixture['guest'], '/documents/' + str(public), method='HEAD')
    h.check(status == 200 and body == b'' and headers.get('X-Content-Type-Options') == 'nosniff', 'Document HEAD has no body and fixed delivery headers')


def upload_document(fixture, name, contents, visibility='public'):
    status, body, _ = upload(fixture['admin'], name, contents, visibility)
    h.check(status == 200 and json.loads(body)['ok'], 'Document intake succeeds: ' + name)
    return json.loads(body)['id']


def upload(client, name, contents, visibility='public', image=False):
    action, field = ('upload', 'image[]') if image else ('upload_document', 'document[]')
    _, body, _ = client.request('/wp-admin/media.php?kind=' + ('images' if image else 'documents'))
    fields = {'_wpnonce': h.token(body, action_field=action), 'action': action, 'visibility': visibility, 'ajax': '1'}
    return send_multipart(client, '/wp-admin/media.php', fields, {field: (name, contents)})


def send_multipart(client, path, fields, files):
    boundary = 'wp-fpm-https-fixture'
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key, value in fields.items()]
    for key, (name, contents) in files.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + contents + b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    return client.request(path, b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + boundary})


def verify_upload_boundaries(fixture):
    exact = b'x' * (25 * 1024 * 1024)
    upload_document(fixture, 'exact-limit.txt', exact)
    status, body, _ = upload(fixture['admin'], 'over-limit.txt', exact + b'x')
    refused = json.loads(body) if status == 422 else {}
    h.check(status == 422 and refused.get('ok') is False and '25 MB' in refused.get('error', ''), 'Document one byte above 25 MiB is refused by the application ceiling after multipart admission')
    h.check(h.dbrow(fixture['database'], "SELECT id FROM media WHERE original_name='over-limit.txt'") is None, 'The application-refused document is not registered')
    for name, contents in (('bad.txt', b'bad\xff'), ('bad.pdf', b'not a pdf'), ('bad.php', b'<?php echo 1;')):
        h.check(upload(fixture['admin'], name, contents)[0] == 422, 'Malformed or executable upload is refused: ' + name)
    verify_native_batch(fixture)
    verify_server_body_refusal(fixture)


def verify_server_body_refusal(fixture):
    label = fixture['stack'] + '/' + fixture['mode']
    before = media_snapshot(fixture)
    _, page, _ = fixture['admin'].request('/wp-admin/media.php?kind=documents')
    boundary = 'wp-oversize-boundary'
    preamble = b''
    for key, value in {'_wpnonce': h.token(page, action_field='upload_document'), 'action': 'upload_document', 'visibility': 'public', 'ajax': '1'}.items():
        preamble += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
    preamble += f'--{boundary}\r\nContent-Disposition: form-data; name="document[]"; filename="oversize-server.txt"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()
    closing = f'\r\n--{boundary}--\r\n'.encode()
    filler = 34 * 1024 * 1024
    body_path = fixture['work']/'oversize-body.bin'
    with body_path.open('wb') as stream:
        stream.write(preamble)
        chunk = b'x' * (1024 * 1024)
        for _ in range(filler // len(chunk)):
            stream.write(chunk)
        stream.write(closing)
    status = send_streamed_body(fixture, '/wp-admin/media.php', body_path, 'multipart/form-data; boundary=' + boundary)
    body_path.unlink()
    h.check(media_snapshot(fixture) == before, 'Server-layer refusal leaves database and asset state unchanged through ' + label)
    h.check(status != 422, 'Server-layer refusal signature differs from the application 422 through ' + label)
    h.check(h.dbrow(fixture['database'], "SELECT id FROM media WHERE original_name='oversize-server.txt'") is None, 'The server-refused oversize body registers no document through ' + label)
    if fixture['stack'] == 'nginx' or fixture['mode'] == 'terminated':
        h.check(status == 413, 'A body above the 32 MiB server limit is refused by the web server (413) through ' + label)
    else:
        log_path = fixture['work']/'php-error.log'
        logged = log_path.read_text(errors='replace') if log_path.exists() else ''
        h.check(status not in (200, 413) and 'exceeds the limit' in logged, 'A body above the 32 MiB PHP post limit is refused at the PHP layer through ' + label)


def media_snapshot(fixture):
    with sqlite3.connect(fixture['database']) as database:
        rows = database.execute('SELECT id,sha256,filename,kind,state FROM media ORDER BY id').fetchall()
    uploads = fixture['site']/'wp-content/uploads'
    files = sorted(str(path.relative_to(uploads)) for path in uploads.rglob('*') if path.is_file()) if uploads.exists() else []
    assets = fixture['state']/'assets'
    stored = sorted(str(path.relative_to(assets)) for path in assets.rglob('*') if path.is_file()) if assets.exists() else []
    return rows, files, stored


def send_streamed_body(fixture, path, body_path, content_type):
    parts = urllib.parse.urlsplit(fixture['base'])
    context = ssl.create_default_context(cafile=str(fixture['cert']))
    connection = http.client.HTTPSConnection(parts.hostname, parts.port, context=context, timeout=60)
    try:
        connection.putrequest('POST', path)
        connection.putheader('Content-Type', content_type)
        connection.putheader('Content-Length', str(body_path.stat().st_size))
        connection.putheader('Cookie', 'wp_session_token=' + fixture['admin'].cookie())
        connection.endheaders()
        with body_path.open('rb') as stream:
            while True:
                chunk = stream.read(1024 * 1024)
                if not chunk:
                    break
                connection.send(chunk)
        response = connection.getresponse()
        response.read()
        return response.status
    finally:
        connection.close()


def verify_native_batch(fixture):
    _, body, _ = fixture['admin'].request('/wp-admin/media.php?kind=documents')
    fields = {'_wpnonce': h.token(body, action_field='upload_document'), 'action': 'upload_document', 'visibility': 'public'}
    files = {'document[0]': ('batch-good.txt', b'NO-JAVASCRIPT-BATCH'), 'document[1]': ('batch-bad.txt', b'bad\xff')}
    status, _, _ = send_multipart(fixture['admin'], '/wp-admin/media.php', fields, files)
    record = h.dbrow(fixture['database'], "SELECT id FROM media WHERE original_name='batch-good.txt'")
    rejected = h.dbrow(fixture['database'], "SELECT id FROM media WHERE original_name='batch-bad.txt'")
    h.check(status == 200 and record is not None and rejected is None, 'Native multipart batch preserves valid intake and reports invalid input')


def verify_image_delivery(fixture):
    pixel = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
    status, body, _ = upload(fixture['admin'], 'pixel.png', pixel, image=True)
    h.check(status == 200 and json.loads(body)['ok'], 'Raster image uploads through FPM')
    record = h.dbrow(fixture['database'], 'SELECT * FROM media WHERE id=?', (json.loads(body)['id'],))
    fixture['image'] = record
    status, body, headers = raw_request(fixture['guest'], '/wp-content/uploads/' + record['filename'])
    h.check(status == 200 and hashlib.sha256(body).hexdigest() == record['sha256'], 'Static raster delivery preserves registered bytes')
    h.check(headers.get('Cache-Control') == 'public, max-age=31536000, immutable' and headers.get('X-Content-Type-Options') == 'nosniff', 'Static raster receives immutable and nosniff headers')


def verify_sensitive_paths(fixture):
    for path in ('/.env', '/var/auth.key', '/docs/INSTALL.md', '/wp-includes/user.php', '/bin/wp',
                 '/wp-admin/admin-functions.php', '/wp-admin/login.php/extra', '/wp-content/uploads/probe.php'):
        status, _, headers = fixture['guest'].request(path)
        h.check(status in (403, 404) and headers.get('X-Content-Type-Options') == 'nosniff', 'Server denies private/executable path: ' + path)


def verify_design(fixture):
    _, page, _ = fixture['admin'].request('/wp-admin/themes.php')
    nonce = re.search(r'data-design-preview[^>]*data-nonce="([^"]+)"', page)[1]
    before = fixture['guest'].request('/')[1]
    data = {'_wpnonce': nonce, 'design_action': 'live_preview', 'expected_version': '0', 'body_size': '18', 'section_header': '<p>FPM-PRIVATE-DESIGN</p>'}
    status, body, headers = fixture['admin'].request('/wp-admin/themes.php', data)
    h.check(status == 200 and 'FPM-PRIVATE-DESIGN' in json.loads(body)['html'], 'Private unsaved design renders through FPM')
    h.check('private' in headers.get('Cache-Control', '') and 'no-store' in headers.get('Cache-Control', ''), 'Design preview remains private and uncacheable')
    h.check(fixture['guest'].request('/')[1] == before, 'Private preview does not change the live site')
    data.update(_wpnonce=h.token(page, 'design-form'), design_action='publish')
    status, body, _ = fixture['admin'].request('/wp-admin/themes.php', data)
    h.check(status == 200 and 'Site design published.' in body, 'Design publication and isolated PHP CLI health check succeed')
    h.check('FPM-PRIVATE-DESIGN' in fixture['guest'].request('/')[1], 'Only publication exposes design changes')
    verify_health_refusal(fixture, data, '<?php throw new RuntimeException("controlled fixture failure");', 'Broken theme refuses publication through FPM')
    verify_health_refusal(fixture, data, '<?php sleep(30);', 'Theme health timeout refuses publication through FPM')
    verify_design_rollback(fixture)


def verify_health_refusal(fixture, data, code, label):
    template = fixture['site']/'wp-content/themes/default/page-wide.php'
    original = template.read_text()
    try:
        template.write_text(code)
        status, body, _ = fixture['admin'].request('/wp-admin/themes.php', dict(data, expected_version='1', active_theme='default', section_header='<p>FAILED-FPM-DESIGN</p>'))
    finally:
        template.write_text(original)
    version = h.dbrow(fixture['database'], "SELECT option_value FROM options WHERE option_name='design_version'")['option_value']
    h.check(status == 200 and 'health check failed' in body and version == '1' and not list(fixture['state'].glob('theme-probe-*')), label)


def verify_design_rollback(fixture):
    current = h.dbrow(fixture['database'], "SELECT option_value FROM options WHERE option_name='design_live_revision'")['option_value']
    revision = h.dbrow(fixture['database'], 'SELECT id FROM design_revisions WHERE published=1 ORDER BY id LIMIT 1')['id']
    _, body, _ = fixture['admin'].request('/wp-admin/themes.php')
    status, _, _ = fixture['admin'].request('/wp-admin/themes.php', {'_wpnonce': h.token(body, 'design-form'), 'design_action': 'restore', 'expected_version': '1', 'restore_revision': revision})
    version = h.dbrow(fixture['database'], "SELECT option_value FROM options WHERE option_name='design_version'")['option_value']
    h.check(status == 302 and version == '2' and 'FPM-PRIVATE-DESIGN' not in fixture['guest'].request('/')[1], 'Design rollback restores the initial baseline as another version')
    _, body, _ = fixture['admin'].request('/wp-admin/themes.php')
    status, _, _ = fixture['admin'].request('/wp-admin/themes.php', {'_wpnonce': h.token(body, 'design-form'), 'design_action': 'restore', 'expected_version': '2', 'restore_revision': current})
    h.check(status == 302 and 'FPM-PRIVATE-DESIGN' in fixture['guest'].request('/')[1], 'Restoring later design history recovers the published configuration')


def verify_portability(fixture):
    _, page, _ = fixture['admin'].request('/wp-admin/portability.php')
    status, archive, _ = raw_request(fixture['admin'], '/wp-admin/portability.php', {'_wpnonce': h.token(page, 'portability-export'), 'portability_action': 'export'})
    h.check(status == 200 and zipfile.is_zipfile(io.BytesIO(archive)), 'Native archive export streams through FPM')
    _, report, result = portability.preflight_and_import(fixture, {'archive': ('roundtrip.zip', archive)})
    h.check('Preflight Report' in report and 'Import Results' in result, 'Native archive preflights and imports through FPM')
    snapshot = post_count(fixture)
    portability.preflight_and_import(fixture, {'archive': ('roundtrip.zip', archive)})
    h.check(post_count(fixture) == snapshot, 'Repeated native import skips checkpointed content')
    wxr = (h.ROOT/'tests/fixtures/sample.wxr').read_bytes()
    portability.preflight_and_import(fixture, {'archive': ('sample.wxr', wxr)})
    h.check(h.dbrow(fixture['database'], "SELECT id FROM posts WHERE slug='wxr-hello'") is not None, 'WordPress WXR imports through FPM')
    snapshot = post_count(fixture)
    _, report, _ = portability.preflight_and_import(fixture, {'archive': ('hostile.xml', b'<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><rss>&e;</rss>')}, expect_ok=False)
    h.check('entities' in report and post_count(fixture) == snapshot, 'Hostile XML is refused without content writes')


def post_count(fixture):
    return h.dbrow(fixture['database'], 'SELECT COUNT(*) AS n FROM posts')['n']


def verify_discovery(fixture):
    for path, marker in (('/feed', '<rss'), ('/sitemap.xml', '<urlset'), ('/robots.txt', 'User-agent:')):
        status, body, _ = fixture['guest'].request(path)
        h.check(status == 200 and marker in body, 'Discovery route executes via FPM: ' + path)
        h.check('Private FPM Draft' not in body and 'private-fpm-draft' not in body, 'Discovery excludes draft content: ' + path)


def verify_operations(fixture):
    h.cli(fixture['site'], fixture['env'], 'queue:work', '200')
    h.check(h.dbrow(fixture['database'], "SELECT COUNT(*) AS n FROM job_queue WHERE status='pending'")['n'] == 0, 'Matching CLI environment drains FPM-created queue work')
    h.cli(fixture['site'], fixture['env'], 'backup')
    archive = next((fixture['state']/'backups').glob('*.tar'))
    verify_backup(fixture, archive)
    fixture['backup'] = archive.read_bytes()
    fixture['key_hash'] = hashlib.sha256((fixture['state']/'auth.key').read_bytes()).hexdigest()
    h.cli(fixture['site'], fixture['env'], 'maintain')
    verify_maintenance_admission(fixture)
    with sqlite3.connect(fixture['database']) as database:
        h.check(database.execute('PRAGMA integrity_check').fetchone()[0] == 'ok' and not database.execute('PRAGMA foreign_key_check').fetchall(), 'SQLite integrity and foreign keys survive FPM/CLI workflows')


def verify_maintenance_admission(fixture):
    barrier = fixture['state']/'maintenance.json'
    barrier.write_text('{"token":"isolated-deployment-fixture"}')
    try:
        h.check(fixture['guest'].request('/')[0] == 503, 'Maintenance barrier seals real FPM public admission')
    finally:
        barrier.unlink()
    h.check(fixture['guest'].request('/')[0] == 200, 'Removing the fixture-owned barrier restores public admission')


def verify_backup(fixture, archive):
    with tarfile.open(archive) as backup:
        restored = fixture['work']/'restored.sqlite'
        restored.write_bytes(backup.extractfile('site.sqlite').read())
        record = h.dbrow(restored, "SELECT title FROM posts WHERE slug='fpm-post'")
        image = backup.extractfile('uploads/' + fixture['image']['filename']).read()
        h.check(record['title'] == 'FPM Post' and hashlib.sha256(image).hexdigest() == fixture['image']['sha256'], 'Backup contains readable database and matching image bytes')
        h.check(backup.extractfile('auth.key').read() == (fixture['state']/'auth.key').read_bytes(), 'Backup preserves the matching site secret')


def verify_logout(fixture):
    _, page, _ = fixture['admin'].request('/wp-admin/index.php')
    cookie = fixture['admin'].cookie()
    status, _, _ = fixture['admin'].request('/wp-admin/login.php', {'action': 'logout', '_wpnonce': h.token(page, action_field='logout')})
    h.check(status == 302, 'Logout revokes the authenticated session')
    status, _, _ = fixture['guest'].request('/wp-admin/index.php', headers={'Cookie': 'wp_session_token=' + cookie})
    h.check(status == 302, 'Revoked session cookie replay is refused')


def verify_restoration(options, stack, mode, original):
    with deployed_site(options, stack, mode, original['backup']) as fixture:
        fixture.update(admin=TLSClient(fixture), guest=TLSClient(fixture))
        h.check(fixture['guest'].request('/fpm-post/')[0] == 200, 'Actual restored site serves published content after upgrade')
        h.check(fixture['admin'].login('admin')[0] == 302, 'Actual restored administrator can sign in')
        h.check(fixture['guest'].request('/documents/' + str(original['public_document']))[1] == 'PUBLIC-FPM-DOCUMENT', 'Restored public document preserves exact bytes')
        h.check(fixture['guest'].request('/documents/' + str(original['private_document']))[0] == 404, 'Restored private document stays private')
        h.check(fixture['admin'].request('/documents/' + str(original['private_document']))[1] == 'PRIVATE-FPM-DOCUMENT', 'Restored owner downloads protected document bytes')
        verify_restored_state(fixture, original)
        verify_epoch_isolation(fixture)


def verify_epoch_isolation(fixture):
    bad = fixture['state']/'api-epoch.json'
    env = dict(fixture['env'], WP_API_EPOCH_PATH=str(bad))
    result = subprocess.run([fixture['options'].php, str(fixture['site']/'bin/wp'), 'api:init'],
                            env=env, capture_output=True, text=True, timeout=60)
    h.check(result.returncode != 0 and 'outside' in result.stdout + result.stderr and not bad.exists(), 'The deployed instance refuses an API epoch path inside WP_VAR_DIR')


def verify_restored_state(fixture, original):
    status, body, _ = raw_request(fixture['guest'], '/wp-content/uploads/' + original['image']['filename'])
    h.check(status == 200 and hashlib.sha256(body).hexdigest() == original['image']['sha256'], 'Restored static image preserves its digest')
    h.check('FPM-PRIVATE-DESIGN' in fixture['guest'].request('/')[1], 'Actual restore retains published design')
    h.check(hashlib.sha256((fixture['state']/'auth.key').read_bytes()).hexdigest() == original['key_hash'], 'Actual restore retains the matching private site key')
    h.check(h.dbrow(fixture['database'], "SELECT COUNT(*) AS n FROM user_sessions")['n'] == 1, 'Restore revokes old sessions before new login')


if __name__ == '__main__':
    main()
