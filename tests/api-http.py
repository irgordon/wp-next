#!/usr/bin/env python3
"""Option B editorial API acceptance (v0.1.0): HTTPS-only boundary, activation
guard, scoped credentials, browser-equivalent visibility, idempotent writes,
atomic publish, revocation hooks, credential-inert backup and the restore
coordinator. Plain HTTP is refused before credentials or body processing; the
functional pass runs behind a local TLS-terminating proxy (WP_HTTPS=1)."""
from http_helpers import *
import http_helpers as h
import http.client, http.server, ssl, threading, io

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

def upload_document(fixture, name, contents, visibility='public', image=False):
    admin=fixture['admin']; _,body,_=admin.request('/wp-admin/media.php?kind='+('images' if image else 'documents'))
    boundary='api-boundary-document'
    action='upload' if image else 'upload_document';field='image[]' if image else 'document[]'
    fields={'_wpnonce':token(body,action_field=action),'action':action,'visibility':visibility,'ajax':'1'}
    parts=[f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key,value in fields.items()]
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+contents+f'\r\n--{boundary}--\r\n'.encode())
    status,body,_=admin.request('/wp-admin/media.php',b''.join(parts),{'Content-Type':'multipart/form-data; boundary='+boundary})
    assert status==200,body
    return json.loads(body)['id']

def set_document_visibility(fixture, identifier, value):
    admin=fixture['admin']; _,body,_=admin.request('/wp-admin/media.php?kind=documents')
    nonce=next(form['fields']['_wpnonce'] for form in FormParser(body).forms if form['fields'].get('id')==str(identifier) and 'visibility' in form['fields'])
    status,body,_=admin.request('/wp-admin/media.php',{'_wpnonce':nonce,'id':str(identifier),'action':'visibility','visibility':value})
    assert status in (200,302),body
    assert dbrow(fixture['database'],'SELECT visibility FROM media WHERE id=?',(identifier,))['visibility']==value

def php_probe(fixture, code, *arguments):
    process=subprocess.run([PHP,'-r','require $argv[1];'+code,str(fixture['site']/'wp-includes/bootstrap.php'),*map(str,arguments)],env=fixture['env'],capture_output=True,text=True,timeout=30)
    assert process.returncode==0,process.stdout+process.stderr
    return process.stdout.strip()

def epoch_probe(fixture, path, overrides=None):
    env=dict(fixture['env'],WP_API_EPOCH_PATH=str(path),**(overrides or {}))
    code='require $argv[1]."/wp-config.php";require $argv[1]."/wp-includes/class-wp-error.php";require $argv[1]."/wp-includes/assets.php";require $argv[1]."/wp-includes/api.php";$r=wp_api_epoch_read();echo is_wp_error($r)?$r->get_error_code():"ok";'
    process=subprocess.run([PHP,'-r',code,str(fixture['site'])],env=env,capture_output=True,text=True,timeout=20)
    assert process.returncode==0,process.stderr
    return process.stdout

def verify_epoch_locations(fixture):
    roots=[fixture['site'],fixture['state'],fixture['site']/'wp-content/uploads',fixture['state']/'assets']
    for directory in roots:
        directory.mkdir(parents=True,exist_ok=True)
        candidate=directory/'forbidden-epoch.json'
        check(epoch_probe(fixture,candidate)=='epoch_location' and not candidate.exists(),'Epoch location rejects '+str(directory.relative_to(fixture['temp'])))
    external_db=fixture['temp']/'external-db';external_db.mkdir(mode=0o700)
    check(epoch_probe(fixture,external_db/'epoch.json',{'WP_DB_PATH':str(external_db/'site.sqlite')})=='epoch_location','An external database tree is also excluded')
    (external_db/'site.sqlite').touch(); db_alias=fixture['temp']/'database-alias.sqlite'; db_alias.symlink_to(external_db/'site.sqlite')
    check(epoch_probe(fixture,external_db/'epoch.json',{'WP_DB_PATH':str(db_alias)})=='epoch_location','The physical database directory is excluded when its file is symlinked')
    custom_assets=fixture['temp']/'custom-documents';custom_assets.mkdir(mode=0o700)
    check(epoch_probe(fixture,custom_assets/'epoch.json',{'WP_ASSETS_DIR':str(custom_assets)})=='epoch_location','An external protected-document tree is excluded')
    alias=fixture['temp']/'epoch-alias';alias.symlink_to(fixture['epoch'].parent,target_is_directory=True)
    check(epoch_probe(fixture,alias/'epoch.json')=='epoch_location','Parent symlinks cannot bypass epoch placement')
    unsafe=fixture['temp']/'unsafe';unsafe.mkdir(mode=0o777);unsafe.chmod(0o777)
    check(epoch_probe(fixture,unsafe/'epoch.json')=='epoch_permissions','Other-writable credential directories are refused')
    hostile_parent=fixture['temp']/'hostile';hostile_parent.mkdir(mode=0o777);hostile_parent.chmod(0o777)
    nested=hostile_parent/'vault';nested.mkdir(mode=0o700)
    check(epoch_probe(fixture,nested/'epoch.json')=='epoch_permissions','A private child cannot hide an unsafe writable ancestor')
    victim=fixture['temp']/'lock-victim';victim.write_text('PRESERVE')
    lock=Path(str(fixture['epoch'])+'.lock');lock.symlink_to(victim)
    check(epoch_probe(fixture,fixture['epoch'])=='epoch_permissions' and victim.read_text()=='PRESERVE','A symlinked epoch lock is refused without touching its target')
    lock.unlink()
    check(epoch_probe(fixture,fixture['epoch'])=='epoch_missing','A canonical external service-owned directory is accepted before initialization')

def create_entry(fixture, kind, body, key):
    reset_rates(fixture['database'])
    status,result,headers=api(fixture['base'],'POST','/wp-next/v1/'+kind+'s',token_value=fixture['token'],body=body,headers={'Idempotency-Key':key})
    assert status==201,(status,result)
    return result

def call_write(fixture, method, route, body, key):
    return api(fixture['base'],method,route,token_value=fixture['token'],body=body,headers={'Idempotency-Key':key})

def check_replay(fixture, method, route, body, key, committed):
    before=dbrow(fixture['database'],'SELECT revision FROM posts WHERE id=?',(committed['id'],))['revision']
    status,result,headers=call_write(fixture,method,route,body,key)
    check(status in (200,201) and headers.get('Idempotency-Replayed')=='true' and result['mutation_id']==committed['mutation_id'],'A committed '+method+' receipt survives unrelated document visibility changes')
    check(dbrow(fixture['database'],'SELECT revision FROM posts WHERE id=?',(committed['id'],))['revision']==before,'Receipt replay does not add a content revision')

def verify_receipt_replay(fixture):
    # A public document owned by a second account can later become inaccessible.
    other=php_probe(fixture,"$wpdb->query(\"INSERT INTO users(username,email,password_hash,capabilities) VALUES('owner','owner@example.test','unusable','[]')\");echo $wpdb->get_row('SELECT last_insert_rowid() AS id')['id'];")
    document=upload_document(fixture,'receipt.txt',b'RECEIPT-BOUNDARY-BYTES')
    sql(fixture['database'],'UPDATE media SET author_id=? WHERE id=?',(int(other),document))
    # Remove manage_media/admin visibility from the API actor without changing its token.
    # Issuing for a built-in author exercises the real browser item-read permission.
    writer=php_probe(fixture,"$wpdb->query(\"INSERT INTO users(username,email,password_hash,capabilities) VALUES('boundary-writer','boundary-writer@example.test','unusable','[\\\"edit_posts\\\",\\\"edit_published_posts\\\",\\\"publish_posts\\\"]')\");echo $wpdb->get_row('SELECT last_insert_rowid() AS id')['id'];")
    admin_token=fixture['token']
    fixture['token']=issue_token(fixture['site'],fixture['env'],'boundary-writer','Receipt writer','posts:read,posts:write,posts:publish')
    content={'version':1,'blocks':[{'type':'file','media_id':document,'label':'Reference'}]}
    create={'title':'Receipt draft','content':content}; created=create_entry(fixture,'post',create,'boundary-create-0001')
    route='/wp-next/v1/posts/'+str(created['id'])
    patch={'expected_revision':1,'content':content,'title':'Receipt changed'}
    status,updated,_=call_write(fixture,'PATCH',route,patch,'boundary-patch-0001');assert status==200,updated
    publish={'expected_revision':2,'content':content}
    status,published,_=call_write(fixture,'POST',route+'/publish',publish,'boundary-publish-01');assert status==200,published
    set_document_visibility(fixture,document,'private')
    for method,path,body,key,ack in [('POST','/wp-next/v1/posts',create,'boundary-create-0001',created),('PATCH',route,patch,'boundary-patch-0001',updated),('POST',route+'/publish',publish,'boundary-publish-01',published)]:
        check_replay(fixture,method,path,body,key,ack)
    new={'title':'New invalid reference','content':content}
    status,result,_=call_write(fixture,'POST','/wp-next/v1/posts',new,'boundary-new-ref-01')
    check(status==422 and problem_type(result)=='validation-failed','A new request with an inaccessible File reference is a semantic refusal')
    fixture['token']=admin_token

def verify_namespace(fixture):
    # Existing history may predate activation; an operator can inspect it, but
    # subsequent ordinary writes must not introduce a new namespace claimant.
    sql(fixture['database'],"INSERT INTO options(option_name,option_value) VALUES('permalink_structure',?) ON CONFLICT(option_name) DO UPDATE SET option_value=excluded.option_value",('/%author%/v1/%postname%/',))
    _,body,_=fixture['admin'].request('/wp-admin/users.php')
    fixture['admin'].request('/wp-admin/users.php',{'_wpnonce':token(body),'username':'wp-next','email':'namespace@example.test','password':'disposable-test-password','role':'author'})
    check(dbrow(fixture['database'],"SELECT id FROM users WHERE username='wp-next'") is None,'Browser account creation cannot claim an initialized API namespace')
    result=php_probe(fixture,"wp_set_current_user(1);$r=wp_portability_create_author(['email'=>'import@example.test','display_name'=>'Import','first_name'=>'','last_name'=>'','biography'=>'','website'=>''],'wp-next');echo is_wp_error($r)?'refused':'accepted';")
    check(result=='refused','Imported account creation uses the same transactional namespace guard')
    # A category path can also create a claim; use normal taxonomy/mutation APIs.
    sql(fixture['database'],"UPDATE options SET option_value='/%category%/%postname%/' WHERE option_name='permalink_structure'")
    values=json.loads(php_probe(fixture,"wp_set_current_user(1);$p=wp_save_term(['taxonomy'=>'category','name'=>'Parent','slug'=>'wp-next']);$c=wp_save_term(['taxonomy'=>'category','name'=>'Child','slug'=>'safe','parent_id'=>$p]);echo json_encode([$p,$c]);"))
    entry=create_entry(fixture,'post',{'title':'Category guard','slug':'posts','term_ids':[values[1]],'content':EMPTY_DOC},'boundary-category-1')
    status,entry,_=call_write(fixture,'POST','/wp-next/v1/posts/'+str(entry['id'])+'/publish',{'expected_revision':1},'boundary-category-2');assert status==200,entry
    result=php_probe(fixture,"wp_set_current_user(1);$r=wp_save_term(['id'=>(int)$argv[2],'expected_revision'=>1,'taxonomy'=>'category','name'=>'Child','slug'=>'v1','parent_id'=>(int)$argv[3]]);echo is_wp_error($r)?'refused':'accepted';",values[1],values[0])
    check(result=='refused','Category changes cannot move published content under the API namespace')
    check(dbrow(fixture['database'],'SELECT slug FROM terms WHERE id=?',(values[1],))['slug']=='safe','A refused namespace mutation rolls its SQL back')
    # Correct segment matching does not reserve v10 as though it were v1.
    check(php_probe(fixture,"echo wp_api_path_claims_mount('wp-next/v10/post')?'bad':'ok';")=='ok','Namespace matching respects the full v1 segment boundary')
    # Import completion rewrites the author after the original insert commits.
    sql(fixture['database'],"UPDATE options SET option_value='[]' WHERE option_name='permalink_history'")
    sql(fixture['database'],"UPDATE options SET option_value='/%author%/%category%/%postname%/' WHERE option_name='permalink_structure'")
    imported=json.loads(php_probe(fixture,"wp_set_current_user(1);$a=wp_portability_create_author(['email'=>'mapped@example.test','display_name'=>'Mapped','first_name'=>'','last_name'=>'','biography'=>'','website'=>''],'wp-next');$t=wp_save_term(['taxonomy'=>'category','name'=>'V1','slug'=>'v1']);$p=wp_insert_post(['post_title'=>'Import bookkeeping','post_status'=>'published','post_content'=>'<p>Body</p>','term_ids'=>[$t]]);echo json_encode([$a,$p]);"))
    before=dbrow(fixture['database'],'SELECT author_id,published_at,created_at,updated_at FROM posts WHERE id=?',(imported[1],))
    result=php_probe(fixture,"try{wp_portability_write_source_fields((int)$argv[2],['author'=>(int)$argv[3],'published'=>123,'created'=>123,'updated'=>123]);echo 'accepted';}catch(WP_API_Route_Conflict $e){echo 'refused';}",imported[1],imported[0])
    check(result=='refused','Post-insert import bookkeeping cannot claim the API namespace')
    check(dbrow(fixture['database'],'SELECT author_id,published_at,created_at,updated_at FROM posts WHERE id=?',(imported[1],))==before,'Refused import bookkeeping rolls author and dates back together')


def archive_with_change(source, target, name, replacement):
    with tarfile.open(source) as original, tarfile.open(target,'w') as output:
        for item in original.getmembers():
            if not item.isfile():continue
            data=original.extractfile(item).read()
            if item.name==name:
                if replacement is None:continue
                data=replacement
            item.size=len(data);output.addfile(item,io.BytesIO(data))

def verify_restore(fixture):
    reset_rates(fixture['database'])
    status,_,_=api(fixture['base'],'GET','/wp-next/v1/posts',token_value=fixture['token'])
    check(status==200,'A live API credential works before restore')
    document=upload_document(fixture,'restore.txt',b'EXACT-RESTORED-DOCUMENT')
    pixel=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
    image=upload_document(fixture,'restore.png',pixel,image=True)
    code,out=cli_out(fixture['site'],fixture['env'],'backup');assert code==0,out
    good=Path(out.split('Backup:')[1].strip().splitlines()[0])
    for identifier,root,prefix in [(document,fixture['state']/'assets','assets/'),(image,fixture['site']/'wp-content/uploads','uploads/')]:
        row=dbrow(fixture['database'],'SELECT * FROM media WHERE id=?',(identifier,))
        live=root/row['filename']; original=live.read_bytes()
        for label,replacement in [('corrupt',b'CORRUPTED'),('missing',None)]:
            bad=fixture['temp']/(prefix.strip('/')+'-'+label+'.tar');archive_with_change(good,bad,prefix+row['filename'],replacement)
            before=dbrow(fixture['database'],'SELECT * FROM api_security_state')
            code,out=cli_out(fixture['site'],fixture['env'],'restore',str(bad))
            check(code!=0 and 'Restore complete:' not in out,'Restore refuses '+label+' registered '+row['kind']+' bytes')
            check((fixture['state']/'maintenance.json').exists(),'A refused restore retains sealed admission')
            check(live.read_bytes()==original and dbrow(fixture['database'],'SELECT * FROM api_security_state')==before,'Archive validation preserves live asset bytes and the database')
            check(not list(live.parent.glob('.restore-*')),'Failed staging leaves no temporary credential or asset bytes')
            code,out=cli_out(fixture['site'],fixture['env'],'restore',str(good));assert code==0,out
            check(live.read_bytes()==original and not (fixture['state']/'maintenance.json').exists(),'An operator can recover with the valid archive and reopen verified bytes')
    reset_rates(fixture['database'])
    code,out=cli_out(fixture['site'],fixture['env'],'api:enable');assert code==0,out
    status,payload,_=api(fixture['base'],'GET','/wp-next/v1/posts',token_value=fixture['token'])
    check(status==401 and problem_type(payload).endswith('invalid-token'),'Restore revokes live API credentials (epoch rotated by the restore)')
    # Deny a destination write without corrupting the live bytes first.
    saved_mode=live.parent.stat().st_mode & 0o777; saved_file_mode=live.stat().st_mode & 0o777
    live.parent.chmod(0o500); live.chmod(0o400)
    try:code,out=cli_out(fixture['site'],fixture['env'],'restore',str(good))
    finally:live.parent.chmod(saved_mode); live.chmod(saved_file_mode)
    check(code!=0 and (fixture['state']/'maintenance.json').exists() and live.read_bytes()==original,'Destination-write refusal cannot become Restore complete')
    code,out=cli_out(fixture['site'],fixture['env'],'restore',str(good));assert code==0,out


with tempfile.TemporaryDirectory(prefix='wp-api-') as directory:
    temp = Path(directory).resolve(); site = temp/'site'; database = temp/'state/site.sqlite'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
    epoch_directory=temp/'credentials'; epoch_directory.mkdir(mode=0o700)
    epoch_path=epoch_directory/'api-epoch.json'
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
        fixture=dict(temp=temp,site=site,state=temp/'state',database=database,env=env,base=base,epoch=epoch_path)
        verify_epoch_locations(fixture)
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
        fixture.update(admin=admin,token=issue_token(site,env,'admin','Boundary integration','posts:read,posts:write,posts:publish,pages:read,pages:write,pages:publish'))
        verify_receipt_replay(fixture)
        verify_namespace(fixture)
        verify_restore(fixture)
    finally:
        server.terminate(); server.wait(timeout=10)
        proxy_server.shutdown()
print(f'{h.COUNT} api checks passed.')
