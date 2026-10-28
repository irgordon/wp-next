#!/usr/bin/env python3
"""Shared disposable-site and HTTP/CLI portability fixture adapters."""
from http_helpers import *
import http_helpers as h
import io, json, sqlite3, urllib.request, zipfile

def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); return sock.getsockname()[1]

def start_site(temp, name, title):
    site = temp/name/'site'; state = temp/name/'state'
    shutil.copytree(Path(os.environ.get('WP_TEST_SOURCE', str(ROOT))), site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var', '.git', '.env'))
    database = state/'site.sqlite'
    env = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(state), WP_DB_PATH=str(database)); env.pop('WP_AUTH_SALT', None)
    port = free_port(); base = 'http://127.0.0.1:' + str(port)
    log = (temp/name/'server.log').open('w')
    server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(site), str(site/'tools/router.php')], env=env, stdout=log, stderr=log)
    for _ in range(100):
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=.1): break
        except OSError: time.sleep(.03)
    admin = Client(base); _, body, _ = admin.request('/wp-admin/install.php')
    admin.request('/wp-admin/install.php', {'_wpnonce': token(body), 'site_title': title, 'admin_user': 'admin', 'admin_email': 'admin@example.test', 'admin_password': 'disposable-test-password'})
    assert admin.login('admin')[0] == 302
    return {'site': site, 'state': state, 'database': database, 'env': env, 'base': base, 'admin': admin, 'server': server, 'guest': Client(base)}

def php_snippet(site_obj, code):
    script = site_obj['site'].parent/'snippet.php'
    script.write_text("<?php require $argv[1];\n" + code)
    result = subprocess.run([PHP, str(script), str(site_obj['site']/'wp-includes/bootstrap.php')], env=site_obj['env'], capture_output=True, text=True, timeout=30)
    assert result.returncode == 0, result.stdout + result.stderr
    return result.stdout.strip()

def multipart(client, path, page_body, form_id, fields, files):
    nonce = token(page_body, form_id)
    boundary = 'wp-portability-boundary'
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="_wpnonce"\r\n\r\n{nonce}\r\n'.encode()]
    for name, value in fields.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    for name, (filename, data) in files.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + data + b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    return client.request(path, b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + boundary})

def form_fields(body, form_id):
    for form in FormParser(body).forms:
        if form['attrs'].get('id') == form_id: return form['fields']
    raise AssertionError('form not found: ' + form_id)

def raw_post(client, path, data):
    req = urllib.request.Request(client.base + path, data=urllib.parse.urlencode(data).encode())
    try:
        with client.open.open(req, timeout=15) as response: return response.status, response.read()
    except urllib.error.HTTPError as e: return e.code, e.read()

def preflight_and_import(site_obj, files, expect_ok=True):
    admin = site_obj['admin']
    _, page, _ = admin.request('/wp-admin/portability.php')
    status, report, _ = multipart(admin, '/wp-admin/portability.php', page, 'portability-preflight', {'portability_action': 'preflight'}, files)
    if not expect_ok: return status, report, None
    assert 'Preflight report' in report, report[-2000:]
    fields = form_fields(report, 'portability-import')
    status, results, _ = admin.request('/wp-admin/portability.php', {'_wpnonce': token(report, 'portability-import'), 'portability_action': 'import', 'archive_key': fields['archive_key'], 'media_key': fields['media_key']})
    assert 'Import results' in results, results[-2000:]
    return status, report, results

def post_form(client, title, slug, status_value, blocks, featured=None, terms=None):
    _, page, _ = client.request('/wp-admin/post-new.php?post_type=' + ('page' if False else 'post'))
    pairs = [('_wpnonce', token(page, 'post-editor')), ('post_type', 'post'), ('post_status', status_value), ('post_title', title), ('post_name', slug), ('post_content', json.dumps({'version': 1, 'blocks': blocks}))]
    if featured is not None: pairs.append(('featured_media_id', str(featured)))
    for term_id in (terms or []): pairs.append(('term_ids[]', str(term_id)))
    data = urllib.parse.urlencode(pairs).encode()
    req = urllib.request.Request(client.base + '/wp-admin/post-new.php?post_type=post', data=data)
    try:
        with client.open.open(req, timeout=15) as response: return response.status
    except urllib.error.HTTPError as e: return e.code

def count(db, sql, params=()):
    with sqlite3.connect(db) as conn: return conn.execute(sql, params).fetchone()[0]
