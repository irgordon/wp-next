#!/usr/bin/env python3
"""Option B editorial API acceptance (v0.1.0): HTTPS-only boundary, activation
guard, scoped credentials, browser-equivalent visibility, idempotent writes,
atomic publish, revocation hooks, credential-inert backup and the restore
coordinator. Plain HTTP is refused before credentials or body processing; the
functional pass runs behind a local TLS-terminating proxy (WP_HTTPS=1)."""
from http_helpers import *
import http_helpers as h
import http.client, http.server, ssl, threading

CTX = ssl._create_unverified_context()
TOKEN_RE = re.compile(r'wpn1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}')
EMPTY_DOC = {'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'Body'}]}

def tls_client(base):
    client = Client(base)
    client.open = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(client.jar), NoRedirect(), urllib.request.HTTPSHandler(context=CTX))
    return client

def make_proxy(target_port):
    class Proxy(http.server.BaseHTTPRequestHandler):
        def _forward(self):
            length = int(self.headers.get('Content-Length') or 0)
            body = self.rfile.read(length) if length else None
            conn = http.client.HTTPConnection('127.0.0.1', target_port, timeout=15)
            headers = {k: v for k, v in self.headers.items() if k.lower() not in ('host', 'connection')}
            conn.request(self.command, self.path, body=body, headers=headers)
            response = conn.getresponse(); data = response.read()
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in ('transfer-encoding', 'connection', 'content-length'):
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(data)))
            self.end_headers(); self.wfile.write(data)
        do_GET = do_POST = do_PATCH = do_PUT = do_DELETE = _forward
        def log_message(self, *args): pass
    return Proxy

def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); return sock.getsockname()[1]

def api(base, method, path, token_value=None, body=None, headers=None, raw=None):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPSHandler(context=CTX))
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    request_headers = dict(headers or {})
    if token_value: request_headers['Authorization'] = 'Bearer ' + token_value
    if data is not None and 'Content-Type' not in request_headers: request_headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(base + path, data=data, headers=request_headers, method=method)
    try: r = opener.open(req, timeout=15)
    except urllib.error.HTTPError as e: r = e
    text = r.read().decode('utf8', 'replace')
    try: parsed = json.loads(text)
    except ValueError: parsed = text
    return r.status, parsed, dict(r.headers)

def problem_type(body):
    # The contract's machine-readable discriminator is the Problem `code`.
    return body.get('code', '') if isinstance(body, dict) else ''

def cli_out(site, env, *args):
    proc = subprocess.run([PHP, str(site/'bin/wp'), *args], env=env, cwd=site, capture_output=True, text=True, timeout=60)
    return proc.returncode, proc.stdout + proc.stderr

def issue_token(site, env, user, name, scopes, days=30):
    code, out = cli_out(site, env, 'api:issue', user, name, scopes, str(days))
    assert code == 0, out
    match = TOKEN_RE.search(out)
    assert match, out
    return match.group(0)

def sql(database, statement, params=()):
    with sqlite3.connect(database) as db:
        db.execute(statement, params); db.commit()

def reset_rates(database):
    sql(database, 'DELETE FROM api_rate_windows')

with tempfile.TemporaryDirectory(prefix='wp-api-') as directory:
    temp = Path(directory); site = temp/'site'; database = temp/'state/site.sqlite'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
    epoch_path = temp/'state/api-epoch.json'
    env = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(temp/'state'), WP_DB_PATH=str(database),
               WP_API_EPOCH_PATH=str(epoch_path))
    env.pop('WP_AUTH_SALT', None); env.pop('WP_HTTPS', None); env.pop('WP_API_BASE_PATH', None)
    port_a, port_b, port_proxy = free_port(), free_port(), free_port()
    base_a = 'http://127.0.0.1:' + str(port_a)
    base = 'https://127.0.0.1:' + str(port_proxy)
    def start_server(e, server_port):
        log = (temp/'server.log').open('a')
        proc = subprocess.Popen([PHP, '-S', f'127.0.0.1:{server_port}', '-t', str(site), str(site/'tools/router.php')], env=e, stdout=log, stderr=log)
        for _ in range(100):
            if proc.poll() is not None: raise RuntimeError('The test server exited during startup')
            try:
                with socket.create_connection(('127.0.0.1', server_port), timeout=.1): break
            except OSError: time.sleep(.03)
        return proc
    # Phase A: plain HTTP refuses every API route before credentials or body.
    server = start_server(env, port_a)
    try:
        admin = Client(base_a); _, body, _ = admin.request('/wp-admin/install.php')
        admin.request('/wp-admin/install.php', {'_wpnonce': token(body), 'site_title': 'API test', 'admin_user': 'admin', 'admin_email': 'admin@example.test', 'admin_password': 'disposable-test-password'})
        check(admin.login('admin')[0] == 302, 'Fixture logs in')
        check(dbrow(database, "SELECT option_value FROM options WHERE option_name='schema_version'")['option_value'] == '9', 'Blank install creates schema 9')
        check(dbrow(database, "SELECT COUNT(*) AS n FROM sqlite_master WHERE type='table' AND name IN ('api_tokens','api_security_state','api_mutation_receipts')")['n'] == 3, 'Schema 9 creates the API tables')
        state = dbrow(database, 'SELECT * FROM api_security_state')
        check(state is not None and int(state['enabled']) == 0 and state['bound_epoch'] is None, 'The API starts disabled and unbound')
        for method, path, headers in [('GET', '/wp-next/v1/posts', {}),
                                      ('GET', '/wp-next/v1/posts', {'Authorization': 'Bearer wpn1.' + '0' * 32 + '.' + 'A' * 43}),
                                      ('POST', '/wp-next/v1/posts', {'Content-Type': 'application/json', 'Idempotency-Key': 'plain-http-probe-01'})]:
            req = urllib.request.Request(base_a + path, data=b'{}' if method == 'POST' else None, headers=headers, method=method)
            try: r = urllib.request.build_opener(urllib.request.ProxyHandler({})).open(req, timeout=10)
            except urllib.error.HTTPError as e: r = e
            payload = json.loads(r.read().decode())
            check(r.status == 503 and problem_type(payload).endswith('https-required') and 'application/problem+json' in r.headers.get('Content-Type', ''), f'Plain HTTP {method} {path} is refused 503 https-required before any processing')
        # Plant the routing conflict before initialization: a wp-next author
        # plus an author-first permalink structure.
        _, body, _ = admin.request('/wp-admin/users.php')
        admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'wp-next', 'email': 'wpnext@example.test', 'password': 'disposable-test-password', 'role': 'author'})
        _, body, _ = admin.request('/wp-admin/users.php')
        admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'writer', 'email': 'writer@example.test', 'password': 'disposable-test-password', 'role': 'author'})
        _, body, _ = admin.request('/wp-admin/options-permalink.php')
        admin.request('/wp-admin/options-permalink.php', {'_wpnonce': token(body), 'permalink_choice': 'custom', 'permalink_structure': '/%author%/v1/%postname%/'})
        check(dbrow(database, "SELECT option_value FROM options WHERE option_name='permalink_structure'")['option_value'] == '/%author%/v1/%postname%/', 'Conflicting permalink structure planted before API initialization')
    finally:
        server.terminate(); server.wait(timeout=10)
    # Phase B: trusted HTTPS termination (WP_HTTPS=1) behind a TLS proxy.
    env['WP_HTTPS'] = '1'
    server = start_server(env, port_b)
    proxy_server = http.server.ThreadingHTTPServer(('127.0.0.1', port_proxy), make_proxy(port_b))
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-keyout', str(temp/'key.pem'), '-out', str(temp/'cert.pem'), '-days', '1', '-nodes', '-subj', '/CN=localhost'], check=True, capture_output=True)
    proxy_ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); proxy_ctx.load_cert_chain(str(temp/'cert.pem'), str(temp/'key.pem'))
    proxy_server.socket = proxy_ctx.wrap_socket(proxy_server.socket, server_side=True)
    threading.Thread(target=proxy_server.serve_forever, daemon=True).start()
    try:
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts')
        check(status == 503 and problem_type(payload).endswith('credentials-unavailable'), 'HTTPS request to the disabled API returns 503 credentials-unavailable')
        code, out = cli_out(site, env, 'api:init')
        state = dbrow(database, 'SELECT * FROM api_security_state')
        check(code == 0 and state['bound_epoch'] and int(state['enabled']) == 0 and epoch_path.is_file(), 'api:init binds the epoch and leaves the API disabled')
        check(oct(epoch_path.stat().st_mode & 0o777) == '0o600', 'The epoch file is mode 0600')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts')
        check(status == 503 and problem_type(payload).endswith('credentials-unavailable'), 'Initialized but not enabled still refuses requests')
        code, out = cli_out(site, env, 'api:enable')
        check('Enable failed' in out and 'wp-next' in out, 'Activation is blocked while a route conflict exists')
        admin = tls_client(base); check(admin.login('admin')[0] == 302, 'Admin logs in over the TLS proxy')
        # The operator remedy for an author-route conflict is removing the
        # colliding account; historical structures keep guarding until then.
        wpnext_id = dbrow(database, "SELECT id FROM users WHERE username='wp-next'")['id']
        _, body, _ = admin.request('/wp-admin/users.php?delete=' + str(wpnext_id))
        admin.request('/wp-admin/users.php', {'_wpnonce': token(body, action_field='delete_user'), 'action': 'delete_user', 'id': str(wpnext_id), 'reassign_to': '1', 'confirm_delete': '1'})
        check(dbrow(database, "SELECT COUNT(*) AS n FROM users WHERE username='wp-next'")['n'] == 0, 'The colliding wp-next account is deleted')
        code, out = cli_out(site, env, 'api:enable')
        check(code == 0 and 'enabled' in out and int(dbrow(database, 'SELECT enabled FROM api_security_state')['enabled']) == 1, 'Activation succeeds once the conflict is cleared')
        # Credential issuance rules.
        code, out = cli_out(site, env, 'api:issue', 'admin', 'bad', 'posts:publish')
        check('publish' in out and TOKEN_RE.search(out) is None, 'Issuing a publish scope without its write scope is refused')
        admin_token = issue_token(site, env, 'admin', 'Admin integration', 'posts:read,posts:write,posts:publish,pages:read,pages:write,pages:publish')
        writer_token = issue_token(site, env, 'writer', 'Writer integration', 'posts:read,posts:write,posts:publish')
        reader_token = issue_token(site, env, 'writer', 'Reader', 'posts:read')
        check(dbrow(database, "SELECT COUNT(*) AS n FROM api_tokens WHERE revoked_at IS NULL")['n'] == 3, 'Issued credentials are stored')
        stored = dbrow(database, 'SELECT secret_hash FROM api_tokens LIMIT 1')['secret_hash']
        check(len(stored) == 64 and admin_token.split('.')[2] not in stored, 'Only the SHA-256 hash of the secret is stored')
        # Authentication failures are neutral.
        status, payload, headers = api(base, 'GET', '/wp-next/v1/posts')
        check(status == 401 and problem_type(payload).endswith('authentication-required') and 'Bearer' in headers.get('WWW-Authenticate', ''), 'Missing credential returns 401 with a Bearer challenge')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts', token_value='not-a-token')
        check(status == 401 and problem_type(payload).endswith('invalid-token'), 'Malformed credential returns a neutral 401 invalid-token')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts', token_value='wpn1.' + 'f' * 32 + '.' + 'A' * 43)
        check(status == 401 and problem_type(payload).endswith('invalid-token'), 'Unknown credential returns the same neutral 401')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/pages', token_value=reader_token)
        check(status == 403 and problem_type(payload).endswith('insufficient-scope'), 'A missing resource-type scope is refused 403 before any lookup')
        # Create, replay and conflict semantics.
        reset_rates(database)
        key1 = {'Idempotency-Key': 'create-alpha-00001'}
        create_body = {'title': 'Alpha', 'content': {'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'First body'}]}}
        status, created, headers = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body=create_body, headers=key1)
        check(status == 201 and created['status'] == 'draft' and created['revision'] == 1 and created['slug'] == 'alpha' and created['url'] is None and len(created['mutation_id']) == 36, 'Create returns a draft MutationResult')
        check(headers.get('Location', '').endswith('/wp-next/v1/posts/' + str(created['id'])) and headers.get('Idempotency-Replayed') == 'false', 'Create returns the API Location and a non-replayed marker')
        alpha_id = created['id']
        status, replayed, headers = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body=create_body, headers=key1)
        check(status == 201 and headers.get('Idempotency-Replayed') == 'true' and replayed['mutation_id'] == created['mutation_id'], 'An identical retry returns the committed receipt')
        check(int(dbrow(database, 'SELECT revision FROM posts WHERE id=?', (alpha_id,))['revision']) == 1 and dbrow(database, "SELECT COUNT(*) AS n FROM audit_log WHERE action='api_post_created'")['n'] == 1, 'A replay creates no new revision or audit event')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body={'title': 'Changed', 'content': create_body['content']}, headers=key1)
        check(status == 409 and problem_type(payload).endswith('idempotency-key-conflict'), 'The same key with different request bytes returns 409')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts?x=1', token_value=admin_token, body=create_body, headers={'Idempotency-Key': 'query-probe-00001'})
        check(status == 400 and problem_type(payload).endswith('malformed-request'), 'Writes reject query parameters')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, raw=b'{}', headers={'Idempotency-Key': 'ctype-probe-00001', 'Content-Type': 'text/plain'})
        check(status == 415 and problem_type(payload).endswith('unsupported-media-type'), 'Writes require application/json')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, raw=b'{oops', headers={'Idempotency-Key': 'json-probe-000001'})
        check(status == 400 and problem_type(payload).endswith('malformed-request'), 'Malformed JSON returns 400')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body=create_body)
        check(status == 400 and problem_type(payload).endswith('malformed-request'), 'A missing Idempotency-Key returns 400')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, raw=b'{"title":"' + b'x' * 3_200_000 + b'"}', headers={'Idempotency-Key': 'huge-probe-000001'})
        check(status == 413 and problem_type(payload).endswith('request-too-large'), 'An oversized envelope returns 413')
        # Visibility mirrors the browser editor.
        status, item, _ = api(base, 'GET', f'/wp-next/v1/posts/{alpha_id}', token_value=admin_token)
        check(status == 200 and item['title'] == 'Alpha' and item['content']['blocks'][0]['html'] == 'First body', 'Item read returns the full editorial representation')
        status, payload, _ = api(base, 'GET', f'/wp-next/v1/posts/{alpha_id}', token_value=writer_token)
        check(status == 404 and problem_type(payload).endswith('not-found'), "Another author's draft is a uniform 404 for the writer")
        status, listing, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=writer_token)
        check(status == 200 and all(entry['id'] != alpha_id for entry in listing['items']) and 'total' not in listing and 'total_count' not in json.dumps(listing), 'Lists filter invisible rows before pagination and expose no total count')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=reader_token, body=create_body, headers={'Idempotency-Key': 'reader-write-00001'})
        check(status == 403 and problem_type(payload).endswith('insufficient-scope'), 'A read-only token cannot create')
        status, mine, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=writer_token, body={'title': 'Writer draft', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'writer-create-001'})
        check(status == 201 and mine['status'] == 'draft', 'The writer creates an own draft')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts?bogus=1', token_value=admin_token)
        check(status == 400 and problem_type(payload).endswith('malformed-request'), 'Unknown list query parameters return 400')
        # Update and publish pin the revision contract.
        status, updated, _ = api(base, 'PATCH', f'/wp-next/v1/posts/{alpha_id}', token_value=admin_token, body={'title': 'Alpha 2', 'expected_revision': 1}, headers={'Idempotency-Key': 'patch-alpha-00001'})
        check(status == 200 and updated['revision'] == 2 and updated['status'] == 'draft' and updated['slug'] == 'alpha', 'PATCH updates a draft and an omitted slug stays unchanged')
        status, payload, _ = api(base, 'PATCH', f'/wp-next/v1/posts/{alpha_id}', token_value=admin_token, body={'title': 'Nope', 'expected_revision': 1}, headers={'Idempotency-Key': 'patch-alpha-00002'})
        check(status == 409 and problem_type(payload).endswith('revision-conflict'), 'A stale expected revision returns 409 without changes')
        status, payload, _ = api(base, 'PATCH', f'/wp-next/v1/posts/{alpha_id}', token_value=admin_token, body={'title': 'Nope'}, headers={'Idempotency-Key': 'patch-alpha-00003'})
        check(status == 400 and problem_type(payload).endswith('malformed-request'), 'A missing expected_revision returns 400')
        status, published, _ = api(base, 'POST', f'/wp-next/v1/posts/{alpha_id}/publish', token_value=admin_token, body={'expected_revision': 2}, headers={'Idempotency-Key': 'publish-alpha-001'})
        check(status == 200 and published['status'] == 'published' and published['revision'] == 3 and (published['url'] or '').endswith('/alpha/'), 'Publish returns the public URL')
        visitor = tls_client(base); pstatus, pbody, _ = visitor.request(urllib.parse.urlparse(published['url']).path)
        check(pstatus == 200 and 'Alpha 2' in pbody, 'The published post is live at its public URL')
        status, payload, _ = api(base, 'PATCH', f'/wp-next/v1/posts/{alpha_id}', token_value=admin_token, body={'title': 'Nope', 'expected_revision': 3}, headers={'Idempotency-Key': 'patch-alpha-00004'})
        check(status == 409 and problem_type(payload).endswith('status-conflict'), 'PATCH on published content returns 409 status-conflict')
        status, republished, _ = api(base, 'POST', f'/wp-next/v1/posts/{alpha_id}/publish', token_value=admin_token, body={'title': 'Alpha 3', 'expected_revision': 3}, headers={'Idempotency-Key': 'publish-alpha-002'})
        check(status == 200 and republished['revision'] == 4, 'Publish-with-changes updates live content atomically')
        status, item, _ = api(base, 'GET', f'/wp-next/v1/posts/{alpha_id}', token_value=admin_token)
        check(item['title'] == 'Alpha 3' and item['revision'] == 4, 'The published change is visible on read')
        # A browser save between API calls moves the revision; the API loses.
        _, ebody, _ = admin.request('/wp-admin/post.php?post=' + str(alpha_id))
        admin.request('/wp-admin/post.php?post=' + str(alpha_id), {'_wpnonce': token(ebody, 'post-editor'), 'ID': alpha_id, 'expected_revision': 4, 'post_title': 'Browser edit', 'post_name': 'alpha', 'post_content': '<p>Browser body</p>', 'post_status': 'published'})
        check(int(dbrow(database, 'SELECT revision FROM posts WHERE id=?', (alpha_id,))['revision']) == 5, 'Browser save advances the revision')
        status, payload, _ = api(base, 'POST', f'/wp-next/v1/posts/{alpha_id}/publish', token_value=admin_token, body={'expected_revision': 4}, headers={'Idempotency-Key': 'publish-alpha-003'})
        check(status == 409 and problem_type(payload).endswith('revision-conflict'), 'API publish with a pre-browser revision conflicts')
        # Slug pins.
        reset_rates(database)
        status, first, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body={'title': 'Slug One', 'slug': 'taken', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'slug-create-00001'})
        check(status == 201 and first['slug'] == 'taken', 'An explicit slug is used verbatim')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body={'title': 'Slug Two', 'slug': 'taken', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'slug-create-00002'})
        check(status == 409 and problem_type(payload).endswith('slug-conflict'), 'An explicit slug collision returns 409 and never suffixes')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body={'title': 'Slug Three', 'slug': 'Not Canonical!', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'slug-create-00003'})
        check(status == 422 and problem_type(payload).endswith('invalid-slug'), 'A non-canonical explicit slug returns 422')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body={'title': 'Slug Four', 'slug': 'feed', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'slug-create-00004'})
        check(status == 422 and problem_type(payload).endswith('reserved-slug'), 'A reserved slug returns 422')
        status, twin1, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body={'title': 'Twin', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'slug-create-00005'})
        status, twin2, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body={'title': 'Twin', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'slug-create-00006'})
        check(twin1['slug'] == 'twin' and twin2['slug'] == 'twin-2', 'Only title-derived creation slugs may be suffixed')
        # Cursor pagination and binding.
        reset_rates(database)
        for i in range(5):
            api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body={'title': f'Cursor {i}', 'content': EMPTY_DOC}, headers={'Idempotency-Key': f'cursor-create-00{i}'})
        status, page1, _ = api(base, 'GET', '/wp-next/v1/posts?status=draft&limit=2', token_value=admin_token)
        check(status == 200 and len(page1['items']) == 2 and page1['next_cursor'], 'The first list page is bounded and returns a cursor')
        status, page2, _ = api(base, 'GET', '/wp-next/v1/posts?status=draft&limit=2&cursor=' + urllib.parse.quote(page1['next_cursor']), token_value=admin_token)
        check(status == 200 and len(page2['items']) == 2 and {e['id'] for e in page1['items']}.isdisjoint({e['id'] for e in page2['items']}), 'The cursor advances without overlap')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts?status=draft&limit=2&cursor=' + urllib.parse.quote(page1['next_cursor']), token_value=writer_token)
        check(status == 400 and problem_type(payload).endswith('invalid-cursor'), 'A cursor is bound to its actor and filters')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts?limit=0', token_value=admin_token)
        check(status == 422 or status == 400, 'An out-of-range limit is refused')
        # Pages mirror posts under their own scopes.
        reset_rates(database)
        status, page, _ = api(base, 'POST', '/wp-next/v1/pages', token_value=admin_token, body={'title': 'About', 'content': {'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'About body'}]}}, headers={'Idempotency-Key': 'page-create-00001'})
        check(status == 201 and page['type'] == 'page' and page['status'] == 'draft', 'Page creation mirrors post creation')
        status, page_pub, _ = api(base, 'POST', f"/wp-next/v1/pages/{page['id']}/publish", token_value=admin_token, body={'expected_revision': 1}, headers={'Idempotency-Key': 'page-publish-0001'})
        check(status == 200 and page_pub['status'] == 'published' and (page_pub['url'] or '').endswith('/about/'), 'Page publication mirrors post publication')
        status, payload, _ = api(base, 'GET', f"/wp-next/v1/pages/{page['id']}", token_value=writer_token)
        check(status == 403 and problem_type(payload).endswith('insufficient-scope'), 'Post scopes do not leak into pages')
        # A private document may sit in a draft but blocks publication.
        digest = hashlib.sha256(b'secret doc bytes').hexdigest()
        blob_dir = temp/'state/assets'/digest[:2]; blob_dir.mkdir(parents=True, exist_ok=True)
        (blob_dir/digest).write_bytes(b'secret doc bytes')
        with sqlite3.connect(database) as db:
            cur = db.execute("INSERT INTO media(author_id,sha256,filename,mime,original_name,size,kind,format,visibility,state) VALUES(1,?,?, 'text/plain','secret.txt',16,'document','txt','private','ready')", (digest, digest[:2] + '/' + digest))
            doc_id = cur.lastrowid; db.commit()
        doc_blocks = {'version': 1, 'blocks': [{'type': 'file', 'media_id': doc_id, 'label': 'Secret'}]}
        status, doc_post, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body={'title': 'Doc holder', 'content': doc_blocks}, headers={'Idempotency-Key': 'doc-create-000001'})
        check(status == 201, 'A draft may reference a private document')
        status, payload, _ = api(base, 'POST', f"/wp-next/v1/posts/{doc_post['id']}/publish", token_value=admin_token, body={'expected_revision': 1}, headers={'Idempotency-Key': 'doc-publish-00001'})
        check(status == 422 and dbrow(database, 'SELECT status FROM posts WHERE id=?', (doc_post['id'],))['status'] == 'draft', 'Publication referencing a private document is refused and changes nothing')
        # Receipt window expiry: the committed key can never execute again.
        sql(database, 'UPDATE api_mutation_receipts SET created_at=created_at-90000, replay_until=replay_until-90000')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=writer_token, body={'title': 'Writer draft', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'writer-create-001'})
        check(status == 409 and problem_type(payload).endswith('idempotency-window-expired'), 'A retry after the replay window returns 409 and does not execute')
        # Account changes revoke credentials inside the account transaction.
        status, _, _ = api(base, 'GET', f"/wp-next/v1/posts/{mine['id']}", token_value=writer_token)
        check(status == 200, 'Writer credential works before the account change')
        writer_id = dbrow(database, "SELECT id FROM users WHERE username='writer'")['id']
        _, body, _ = admin.request('/wp-admin/users.php')
        admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'id': writer_id, 'username': 'writer', 'email': 'writer@example.test', 'password': '', 'role': 'editor'})
        check(dbrow(database, "SELECT revoke_reason FROM api_tokens WHERE name='Writer integration'")['revoke_reason'] == 'account_change', 'A role change revokes the account credentials')
        status, payload, _ = api(base, 'GET', f"/wp-next/v1/posts/{mine['id']}", token_value=writer_token)
        check(status == 401 and problem_type(payload).endswith('invalid-token'), 'A revoked credential returns 401')
        # Token expiry.
        sql(database, "UPDATE api_tokens SET created_at=unixepoch()-3000000, expires_at=unixepoch()-10 WHERE name='Reader'")
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=reader_token)
        check(status == 401 and problem_type(payload).endswith('invalid-token'), 'An expired credential returns 401')
        # Route shape errors.
        status, payload, _ = api(base, 'GET', '/wp-next/v1/widgets', token_value=admin_token)
        check(status == 404 and problem_type(payload).endswith('not-found'), 'An unknown API route returns 404')
        status, payload, headers = api(base, 'DELETE', f'/wp-next/v1/posts/{alpha_id}', token_value=admin_token)
        check(status == 405 and problem_type(payload).endswith('method-not-allowed') and 'PATCH' in headers.get('Allow', ''), 'A wrong method returns 405 with Allow')
        # Admission limits, measured with dedicated credentials.
        reset_rates(database)
        rate_reader = issue_token(site, env, 'admin', 'Rate reader', 'posts:read')
        ok = all(api(base, 'GET', '/wp-next/v1/posts', token_value=rate_reader)[0] == 200 for _ in range(60))
        status, payload, headers = api(base, 'GET', '/wp-next/v1/posts', token_value=rate_reader)
        check(ok and status == 429 and problem_type(payload).endswith('rate-limited') and 'Retry-After' in headers, 'The 61st read in a minute is rate limited with Retry-After')
        reset_rates(database)
        rate_writer = issue_token(site, env, 'admin', 'Rate writer', 'posts:write')
        ok = all(api(base, 'POST', '/wp-next/v1/posts', token_value=rate_writer, body={'title': f'Bulk {i}', 'content': EMPTY_DOC}, headers={'Idempotency-Key': f'bulk-create-{i:05d}'})[0] == 201 for i in range(20))
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=rate_writer, body={'title': 'Bulk 21', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'bulk-create-00021'})
        check(ok and status == 429 and problem_type(payload).endswith('rate-limited'), 'The 21st mutation in a minute is rate limited')
        # Receipt capacity refuses rather than evicting a retry guarantee.
        rate_writer_id = dbrow(database, "SELECT id FROM api_tokens WHERE name='Rate writer'")['id']
        now = int(time.time())
        with sqlite3.connect(database) as db:
            db.executemany('INSERT INTO api_mutation_receipts(token_id,key_hash,request_hash,resource_kind,operation,resource_id,committed_revision,committed_status,mutation_id,http_status,response_json,location,state,created_at,replay_until) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                           [(rate_writer_id, hashlib.sha256(f'k{i}'.encode()).hexdigest(), hashlib.sha256(f'r{i}'.encode()).hexdigest(), 'post', 'create', 900000 + i, 1, 'draft', f'{i:036d}', 201,
                             json.dumps({'id': 900000 + i, 'type': 'post', 'revision': 1, 'status': 'draft', 'mutation_id': f'{i:036d}', 'slug': 'seed', 'url': None}), None, 'replayable', now, now + 86400) for i in range(1000)])
            db.commit()
        reset_rates(database)
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=rate_writer, body={'title': 'One too many', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'capacity-probe-001'})
        check(status == 429 and problem_type(payload).endswith('receipt-capacity-exceeded'), 'A full receipt store refuses new mutations instead of evicting')
        # Revoked credentials cannot replay receipts.
        admin2 = issue_token(site, env, 'admin', 'Second admin', 'posts:read,posts:write,posts:publish,pages:read,pages:write,pages:publish')
        admin_token_id = dbrow(database, "SELECT id FROM api_tokens WHERE name='Admin integration'")['id']
        code, out = cli_out(site, env, 'api:revoke', admin_token_id)
        check('revoked' in out, 'CLI revocation succeeds')
        status, payload, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin_token, body=create_body, headers=key1)
        check(status == 401 and problem_type(payload).endswith('invalid-token'), 'A revoked credential cannot replay its receipts')
        # Epoch rotation revokes everything and keeps the API enabled.
        status, _, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=admin2)
        check(status == 200, 'Second admin credential works before rotation')
        code, out = cli_out(site, env, 'api:rotate')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=admin2)
        check(code == 0 and status == 401, 'Epoch rotation revokes every credential')
        admin3 = issue_token(site, env, 'admin', 'Post-rotation', 'posts:read,posts:write,posts:publish')
        status, _, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=admin3)
        check(status == 200 and int(dbrow(database, 'SELECT enabled FROM api_security_state')['enabled']) == 1, 'New credentials work after rotation with the API still enabled')
        # Backup snapshots are credential-inert; restore rotates and disables.
        status, marker, _ = api(base, 'POST', '/wp-next/v1/posts', token_value=admin3, body={'title': 'Backup marker', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'backup-marker-0001'})
        check(status == 201, 'Marker draft created before backup')
        code, out = cli_out(site, env, 'backup')
        archive_path = out.split('Backup:')[1].strip().splitlines()[0]
        with tarfile.open(archive_path) as tar:
            check('site.sqlite' in tar.getnames(), 'The backup archive contains the database snapshot')
            tar.extract('site.sqlite', temp/'snap')
        with sqlite3.connect(temp/'snap/site.sqlite') as snap:
            snap.row_factory = sqlite3.Row
            snap_state = dict(snap.execute('SELECT * FROM api_security_state').fetchone())
            snap_active = snap.execute('SELECT COUNT(*) AS n FROM api_tokens WHERE revoked_at IS NULL').fetchone()['n']
        check(int(snap_state['enabled']) == 0 and snap_state['bound_epoch'] is None and snap_active == 0, 'The backup snapshot is credential-inert')
        status, _, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=admin3)
        check(status == 200, 'Backup inerting leaves live credentials working')
        api(base, 'POST', '/wp-next/v1/posts', token_value=admin3, body={'title': 'After backup', 'content': EMPTY_DOC}, headers={'Idempotency-Key': 'after-backup-00001'})
        code, out = cli_out(site, env, 'restore', archive_path)
        check(code == 0 and 'Restore complete' in out, 'The restore coordinator completes')
        check(dbrow(database, "SELECT COUNT(*) AS n FROM posts WHERE title='After backup'")['n'] == 0 and dbrow(database, "SELECT COUNT(*) AS n FROM posts WHERE title='Backup marker'")['n'] == 1, 'Restore returns content to the backup point')
        check(dbrow(database, 'SELECT COUNT(*) AS n FROM user_sessions')['n'] == 0 and int(dbrow(database, 'SELECT enabled FROM api_security_state')['enabled']) == 0, 'Restore clears sessions and leaves the API disabled')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=admin3)
        check(status == 503 and problem_type(payload).endswith('credentials-unavailable'), 'Requests are refused while the restored API is disabled')
        code, out = cli_out(site, env, 'api:enable')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=admin3)
        check(code == 0 and status == 401 and problem_type(payload).endswith('invalid-token'), 'Pre-restore credentials stay dead after re-enable (epoch rotated)')
        admin4 = issue_token(site, env, 'admin', 'Post-restore', 'posts:read')
        status, _, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=admin4)
        check(status == 200, 'Fresh credentials work on the restored site')
        # Disable revokes everything.
        code, out = cli_out(site, env, 'api:disable')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=admin4)
        check(status == 503 and problem_type(payload).endswith('credentials-unavailable'), 'A disabled API refuses requests')
        cli_out(site, env, 'api:enable')
        status, payload, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=admin4)
        check(status == 401, 'Disable revoked every credential')
        # The Integrations screen issues credentials over HTTPS. (Restore
        # cleared every session, so sign in again first.)
        check(admin.login('admin')[0] == 302, 'Admin signs in again after restore')
        _, body, _ = admin.request('/wp-admin/integrations.php')
        check('Editorial API' in body and 'Integrations' in body, 'The Integrations screen renders')
        status, body, _ = admin.request('/wp-admin/integrations.php', {'_wpnonce': token(body), 'api_action': 'issue', 'user_id': '1', 'token_name': 'UI issued', 'scopes[]': ['posts:read'], 'days': '30'})
        ui_match = TOKEN_RE.search(body)
        check(status == 200 and ui_match is not None, 'The Integrations screen shows an issued credential exactly once')
        status, _, _ = api(base, 'GET', '/wp-next/v1/posts', token_value=ui_match.group(0))
        check(status == 200, 'The UI-issued credential authenticates')
    finally:
        server.terminate(); server.wait(timeout=10)
        proxy_server.shutdown()
print(f'{h.COUNT} api checks passed.')
