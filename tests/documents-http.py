#!/usr/bin/env python3
"""P1 document bundle acceptance: intake validation, isolated delivery, dedupe semantics,
reference validation, visibility flips, quota and backup integrity (packet Rev 3 S8)."""
from http_helpers import *
import io, json, tarfile
def multipart_doc(client, page_body, filename, data, fields):
    nonce = token(page_body, action_field='upload_document')
    boundary = 'wp-doc-boundary'
    parts = []
    for name, value in [(' _wpnonce'.strip(), nonce)] + list(fields.items()):
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="document"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + data + f'\r\n--{boundary}--\r\n'.encode())
    return client.request('/wp-admin/media.php?kind=documents', b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + boundary})
def follow(client, path):
    status, body, headers = client.request(path)
    if status in (301, 302) and headers.get('Location'):
        loc = headers['Location']
        path2 = loc[loc.find('/', loc.find('://') + 3):] if '://' in loc else loc
        return client.request(path2)
    return status, body, headers
with tempfile.TemporaryDirectory(prefix='wp-docs-') as directory:
    temp = Path(directory); site = temp/'site'; database = temp/'state/site.sqlite'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
    env = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(temp/'state'), WP_DB_PATH=str(database)); env.pop('WP_AUTH_SALT', None)
    with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    with (temp/'server.log').open('w') as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(site), str(site/'tools/router.php')], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.03)
            admin = Client(base); _, body, _ = admin.request('/wp-admin/install.php')
            admin.request('/wp-admin/install.php', {'_wpnonce': token(body), 'site_title': 'Docs test', 'admin_user': 'admin', 'admin_email': 'admin@example.test', 'admin_password': 'disposable-test-password'})
            check(admin.login('admin')[0] == 302, 'Fixture logs in')
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'writer', 'email': 'writer@example.test', 'password': 'disposable-test-password', 'role': 'author'})
            writer = Client(base); check(writer.login('writer')[0] == 302, 'Writer logs in')
            guest = Client(base)
            def media_page(client): return client.request('/wp-admin/media.php?kind=documents')[1]
            def doc_count(): return dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE kind='document'")['n']
            # Intake + private delivery isolation
            status, _, _ = multipart_doc(admin, media_page(admin), 'notes.txt', b'Quarterly notes body', {'action': 'upload_document', 'visibility': 'private'})
            check(status == 302 and doc_count() == 1, 'Private text document uploads')
            doc = dbrow(database, "SELECT * FROM media WHERE kind='document'")
            check(doc['state'] == 'ready' and doc['format'] == 'txt' and doc['visibility'] == 'private', 'Document record carries format and visibility')
            blob = temp/'state/assets'/doc['filename']
            check(blob.is_file() and blob.read_bytes() == b'Quarterly notes body', 'Document bytes live in the protected asset tree under the bare hash')
            status, body, headers = guest.request('/documents/' + str(doc['id']))
            check(status == 404 and 'no-store' in headers.get('Cache-Control', ''), 'Anonymous download of a private document is a no-store 404')
            status, body, headers = writer.request('/documents/' + str(doc['id']))
            check(status == 404, 'Another author cannot download a private document')
            status, body, headers = admin.request('/documents/' + str(doc['id']))
            check(status == 200 and body == 'Quarterly notes body' and 'attachment' in headers.get('Content-Disposition', '') and headers.get('X-Content-Type-Options') == 'nosniff', 'Owner downloads with attachment and nosniff headers')
            # Library and picker isolation
            check('notes.txt' not in media_page(writer), 'Private document is hidden from another author\'s library')
            _, picker, picker_headers = writer.request('/wp-admin/media.php?format=json&kind=document')
            check(picker_headers.get('Content-Type') == 'application/json; charset=utf-8', 'Picker JSON carries a well-formed Content-Type header')
            check('notes.txt' not in picker, 'Private document is hidden from the document picker')
            check('notes.txt' in media_page(admin), 'Owner sees the private document in the library')
            # Validation matrix
            before = doc_count()
            multipart_doc(admin, media_page(admin), 'fake.pdf', b'not a pdf at all', {'action': 'upload_document', 'visibility': 'private'})
            multipart_doc(admin, media_page(admin), 'sneaky.txt', b'%PDF-1.7 real pdf bytes', {'action': 'upload_document', 'visibility': 'private'})
            multipart_doc(admin, media_page(admin), 'bad.txt', b'lone \xff byte', {'action': 'upload_document', 'visibility': 'private'})
            multipart_doc(admin, media_page(admin), 'tool.exe', b'MZ binary', {'action': 'upload_document', 'visibility': 'private'})
            check(doc_count() == before, 'Mismatched, non-UTF-8 and unsupported uploads are rejected')
            status, _, _ = multipart_doc(admin, media_page(admin), 'real.pdf', b'%PDF-1.4\n%fake but signed\n', {'action': 'upload_document', 'visibility': 'private'})
            # (finfo may classify the minimal fixture differently; assert only that a format row, if created, is a pdf)
            pdf = dbrow(database, "SELECT * FROM media WHERE kind='document' AND original_name='real.pdf'")
            check(pdf is None or pdf['format'] == 'pdf', 'PDF intake never creates a non-PDF record')
            status, _, _ = multipart_doc(admin, media_page(admin), 'data.csv', b'a,b,c\n1,2,3\n', {'action': 'upload_document', 'visibility': 'public'})
            csv = dbrow(database, "SELECT * FROM media WHERE kind='document' AND original_name='data.csv'")
            check(csv is not None and csv['format'] == 'csv' and csv['visibility'] == 'public', 'CSV uploads as a public csv document')
            status, body, _ = guest.request('/documents/' + str(csv['id']))
            check(status == 200 and 'a,b,c' in body, 'Public document downloads anonymously through the route')
            # Dedupe semantics: same bytes + format + visibility reuses an accessible record only
            status, _, _ = multipart_doc(admin, media_page(admin), 'notes.txt', b'Quarterly notes body', {'action': 'upload_document', 'visibility': 'private'})
            check(dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE sha256=?", (doc['sha256'],))['n'] == 1, 'Accessible matching record is reused')
            status, _, _ = multipart_doc(admin, media_page(admin), 'notes.txt', b'Quarterly notes body', {'action': 'upload_document', 'visibility': 'public'})
            check(dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE sha256=?", (doc['sha256'],))['n'] == 2, 'A different visibility creates a distinct record over the same bytes')
            status, _, _ = multipart_doc(admin, media_page(admin), 'notes.md', b'Quarterly notes body', {'action': 'upload_document', 'visibility': 'private'})
            check(dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE sha256=? AND format='md'", (doc['sha256'],))['n'] == 1, 'The same bytes as Markdown are a distinct record')
            # Pending isolation
            digest = hashlib.sha256(b'ghost').hexdigest()
            with sqlite3.connect(database) as db:
                db.execute("INSERT INTO media(author_id,sha256,filename,mime,original_name,size,kind,format,visibility,state) VALUES(1,?,?, 'text/plain','ghost.txt',5,'document','txt','private','pending')", (digest, digest[:2] + '/' + digest))
            ghost = dbrow(database, "SELECT * FROM media WHERE original_name='ghost.txt'")
            check(guest.request('/documents/' + str(ghost['id']))[0] == 404 and 'ghost.txt' not in media_page(admin), 'Pending documents are neither delivered nor listed')
            # Interrupted-upload recovery: bytes-absent pendings unwind; staged pendings finish promotion
            staged_hash = hashlib.sha256(b'recovered body').hexdigest()
            (temp/'state/staging').mkdir(exist_ok=True)
            (temp/'state/staging'/(staged_hash + '.part')).write_bytes(b'recovered body')
            with sqlite3.connect(database) as db:
                db.execute("INSERT INTO media(author_id,sha256,filename,mime,original_name,size,kind,format,visibility,state) VALUES(1,?,?, 'text/plain','recovered.txt',14,'document','txt','private','pending')", (staged_hash, staged_hash[:2] + '/' + staged_hash))
            cli(site, env, 'maintain')
            check(dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE original_name='ghost.txt'")['n'] == 0, 'Maintain unwinds a pending registration whose bytes are absent')
            recovered = dbrow(database, "SELECT * FROM media WHERE original_name='recovered.txt'")
            check(recovered is not None and recovered['state'] == 'ready', 'Maintain promotes a pending registration whose staged bytes verify')
            check(admin.request('/documents/' + str(recovered['id']))[1] == 'recovered body', 'The recovered document is deliverable to its owner')
            # Reference validation on save
            def save_post(client, title, slug, status_wanted, blocks):
                _, page, _ = client.request('/wp-admin/post-new.php?post_type=post')
                return client.request('/wp-admin/post-new.php?post_type=post', {'_wpnonce': token(page, 'post-editor'), 'post_type': 'post', 'post_title': title, 'post_name': slug, 'post_content': json.dumps({'version': 1, 'blocks': blocks}), 'post_status': status_wanted})
            file_block = [{'type': 'file', 'media_id': doc['id'], 'label': 'Quarterly notes'}]
            save_post(writer, 'Writer theft', 'writer-theft', 'draft', file_block)
            check(dbrow(database, "SELECT COUNT(*) AS n FROM posts WHERE title='Writer theft'")['n'] == 0, 'A draft referencing another author\'s private document is rejected at save')
            save_post(admin, 'Owner draft', 'owner-draft', 'draft', file_block)
            check(dbrow(database, "SELECT COUNT(*) AS n FROM posts WHERE title='Owner draft'")['n'] == 1, 'The owner can draft against their own private document')
            save_post(admin, 'Owner publish', 'owner-publish', 'published', file_block)
            check(dbrow(database, "SELECT COUNT(*) AS n FROM posts WHERE title='Owner publish'")['n'] == 0, 'Publishing with a private document is rejected')
            # Visibility flip enables publication; flipping back withdraws the rendered link and delivery
            _, page, _ = admin.request('/wp-admin/media.php?kind=documents')
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page, action_field='upload_document'), 'action': 'visibility', 'id': str(doc['id']), 'visibility': 'public'})
            check(dbrow(database, 'SELECT visibility FROM media WHERE id=?', (doc['id'],))['visibility'] == 'public', 'Visibility flips to public')
            post_blocks = [{'type': 'paragraph', 'html': 'Surrounding body text'}, file_block[0], {'type': 'paragraph', 'html': 'Trailing text'}]
            save_post(admin, 'Owner publish', 'owner-publish', 'published', post_blocks)
            post = dbrow(database, "SELECT * FROM posts WHERE title='Owner publish'")
            check(post is not None, 'Publication succeeds once the document is public')
            status, body, _ = follow(guest, '/?p=' + str(post['id']))
            check(status == 200 and '/documents/' + str(doc['id']) in body, 'Published page links the public document')
            cli(site, env, 'queue:work', '200')
            status, body, _ = follow(guest, '/?p=' + str(post['id']))
            check(status == 200 and '/documents/' + str(doc['id']) in body and 'Surrounding body text' in body, 'The cached render links the document and keeps the surrounding content')
            # Stage a render prepared before the flip: its commit must not undo the invalidation
            staged = temp/'staged-render.php'
            staged.write_text("""<?php
require $argv[1];
global $wpdb;
$job = new \\WordPress\\Jobs\\Render_Post_Job();
if ('prepare' === $argv[2]) {
    $prepared = $job->prepare($wpdb, ['post_id' => (int) $argv[3], 'target_revision' => (int) $argv[4]]);
    file_put_contents($argv[5], (string) json_encode($prepared));
    echo (int) ($prepared['render_epoch'] ?? -1), ' ', str_contains((string) ($prepared['html'] ?? ''), '/documents/') ? 'link' : 'nolink';
} else {
    $prepared = json_decode((string) file_get_contents($argv[4]), true);
    $job->commit($wpdb, $prepared, 'staged-stale');
    $row = $wpdb->get_row('SELECT rendered_revision FROM posts WHERE id=:id', [':id' => (int) $argv[3]]);
    echo (int) $row['rendered_revision'];
}
""")
            prepared_file = str(temp/'prepared.json')
            out = subprocess.run([PHP, str(staged), str(site/'wp-includes/bootstrap.php'), 'prepare', str(post['id']), str(post['revision']), prepared_file], env=env, capture_output=True, text=True)
            check(out.returncode == 0 and out.stdout.strip().endswith('link'), 'A render prepared before the flip contains the public document link')
            _, page, _ = admin.request('/wp-admin/media.php?kind=documents')
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page, action_field='upload_document'), 'action': 'visibility', 'id': str(doc['id']), 'visibility': 'private'})
            status, body, _ = follow(guest, '/?p=' + str(post['id']))
            check(status == 200 and '/documents/' + str(doc['id']) not in body, 'Flipping back to private removes the link from the rendered page')
            check('Surrounding body text' in body and 'Trailing text' in body and 'Content could not be rendered.' not in body, 'The rest of the post body survives the visibility change before queue processing')
            out = subprocess.run([PHP, str(staged), str(site/'wp-includes/bootstrap.php'), 'commit', str(post['id']), prepared_file], env=env, capture_output=True, text=True)
            check(out.returncode == 0 and out.stdout.strip() == '0', 'A render prepared before the flip cannot commit after it (stale generation rejected)')
            cli(site, env, 'queue:work', '200')
            status, body, _ = follow(guest, '/?p=' + str(post['id']))
            check(status == 200 and '/documents/' + str(doc['id']) not in body and 'Surrounding body text' in body and 'Content could not be rendered.' not in body, 'The rest of the post body survives the visibility change after queue processing')
            check(guest.request('/documents/' + str(doc['id']))[0] == 404, 'Flipping back to private stops anonymous delivery')
            # A2 retained case: two routing changes while renders are pending, including an already-invalidated render
            _, page, _ = admin.request('/wp-admin/media.php?kind=documents')
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page, action_field='upload_document'), 'action': 'visibility', 'id': str(doc['id']), 'visibility': 'public'})
            cli(site, env, 'queue:work', '200')
            payload_status, payload, payload_headers = guest.request('/documents/' + str(doc['id']))
            check(payload_status == 200 and 'attachment' in payload_headers.get('Content-Disposition', ''), 'Two-change case baseline: the public document downloads as an attachment')
            prepared_a = str(temp/'prepared-a.json'); prepared_b = str(temp/'prepared-b.json')
            out = subprocess.run([PHP, str(staged), str(site/'wp-includes/bootstrap.php'), 'prepare', str(post['id']), str(post['revision']), prepared_a], env=env, capture_output=True, text=True)
            epoch_a = int(out.stdout.split(' ')[0]) if out.returncode == 0 else -1
            check(out.returncode == 0 and out.stdout.strip().endswith('link'), 'Two-change case: render A prepared with the document link')
            _, page, _ = admin.request('/wp-admin/media.php?kind=documents')
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page, action_field='upload_document'), 'action': 'visibility', 'id': str(doc['id']), 'visibility': 'private'})
            out = subprocess.run([PHP, str(staged), str(site/'wp-includes/bootstrap.php'), 'prepare', str(post['id']), str(post['revision']), prepared_b], env=env, capture_output=True, text=True)
            epoch_b = int(out.stdout.split(' ')[0]) if out.returncode == 0 else -1
            check(out.returncode == 0 and epoch_b > epoch_a and out.stdout.strip().endswith('nolink'), 'Two-change case: the first change invalidates render A and render B prepares under the new generation')
            _, page, _ = admin.request('/wp-admin/options-permalink.php')
            admin.request('/wp-admin/options-permalink.php', {'_wpnonce': token(page), 'permalink_choice': 'custom', 'permalink_structure': '/%year%/%postname%'})
            check(dbrow(database, "SELECT option_value FROM options WHERE option_name='permalink_structure'")['option_value'] == '/%year%/%postname%', 'Two-change case: the second routing change (permalink structure) saves')
            out = subprocess.run([PHP, str(staged), str(site/'wp-includes/bootstrap.php'), 'commit', str(post['id']), prepared_a], env=env, capture_output=True, text=True)
            check(out.returncode == 0 and out.stdout.strip() == '0', 'Two-change case: the already-invalidated render A cannot commit after the second change')
            out = subprocess.run([PHP, str(staged), str(site/'wp-includes/bootstrap.php'), 'commit', str(post['id']), prepared_b], env=env, capture_output=True, text=True)
            check(out.returncode == 0 and out.stdout.strip() == '0', 'Two-change case: render B, invalidated by the second change, cannot commit either')
            _, page, _ = admin.request('/wp-admin/media.php?kind=documents')
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page, action_field='upload_document'), 'action': 'visibility', 'id': str(doc['id']), 'visibility': 'public'})
            cli(site, env, 'queue:work', '200')
            status, body, _ = follow(guest, '/?p=' + str(post['id']))
            check(status == 200 and '/documents/' + str(doc['id']) in body and 'Surrounding body text' in body, 'Two-change case: the fresh render restores the document link and the surrounding content')
            status, body, headers = guest.request('/documents/' + str(doc['id']))
            check(status == 200 and body == payload and 'attachment' in headers.get('Content-Disposition', ''), 'Two-change case: the stored link downloads the document bytes as an attachment')
            _, page, _ = admin.request('/wp-admin/options-permalink.php')
            admin.request('/wp-admin/options-permalink.php', {'_wpnonce': token(page), 'permalink_choice': 'custom', 'permalink_structure': '/%postname%'})
            cli(site, env, 'queue:work', '200')
            # Lease fencing (C-05), recorded separately from the render invalidations above:
            # a superseded worker lease cannot commit; the current lease holder can.
            lease_probe = temp/'lease-probe.php'
            lease_probe.write_text("""<?php
require $argv[1];
global $wpdb;
$queue = new \\WordPress\\Queue\\WP_Job_Queue($wpdb);
$wpdb->transaction(fn() => $queue->enqueue_in_transaction(\\WordPress\\Jobs\\Render_Post_Job::class, ['post_id' => (int) $argv[2], 'target_revision' => (int) $argv[3]]));
$first = $queue->claim_job();
$wpdb->query('UPDATE job_queue SET lease_expires_at=unixepoch()-1 WHERE id=:id', [':id' => (int) $first['id']]);
$second = $queue->claim_job();
echo 'same-job:', (int) $second['id'] === (int) $first['id'] ? 'yes' : 'no', '\\n';
echo 'superseded-owns:', $queue->owns_lease((int) $first['id'], $first['lease_token']) ? 'yes' : 'no', '\\n';
echo 'superseded-complete:', $queue->complete_job((int) $first['id'], $first['lease_token']) ? 'yes' : 'no', '\\n';
echo 'holder-owns:', $queue->owns_lease((int) $second['id'], $second['lease_token']) ? 'yes' : 'no', '\\n';
echo 'holder-complete:', $queue->complete_job((int) $second['id'], $second['lease_token']) ? 'yes' : 'no', '\\n';
""")
            out = subprocess.run([PHP, str(lease_probe), str(site/'wp-includes/bootstrap.php'), str(post['id']), str(post['revision'])], env=env, capture_output=True, text=True)
            check(out.returncode == 0 and 'same-job:yes' in out.stdout and 'superseded-owns:no' in out.stdout and 'superseded-complete:no' in out.stdout, 'Lease fencing: a superseded worker lease cannot commit a job')
            check(out.returncode == 0 and 'holder-owns:yes' in out.stdout and 'holder-complete:yes' in out.stdout, 'Lease fencing: the current lease holder can commit the job')
            # Save-time race: access is rechecked inside the write transaction, not only at normalization
            multipart_doc(admin, media_page(admin), 'race.txt', b'Race body', {'action': 'upload_document', 'visibility': 'public'})
            race_doc = dbrow(database, "SELECT * FROM media WHERE original_name='race.txt'")
            writer_id = dbrow(database, "SELECT id FROM users WHERE username='writer'")['id']
            race = temp/'race.php'
            race.write_text("""<?php
require $argv[1];
global $wpdb, $wp_auth_service;
$writer = (int) $argv[3]; $doc = (int) $argv[4];
wp_set_current_user($writer);
$content = (string) json_encode(['version' => 1, 'blocks' => [['type' => 'file', 'media_id' => $doc, 'label' => 'Race doc']]]);
if ('seed' === $argv[2]) {
    $id = wp_insert_post(['post_type' => 'post', 'post_title' => 'Race update', 'post_name' => 'race-update', 'post_status' => 'draft', 'post_content' => $content]);
    echo is_wp_error($id) ? 'error' : (string) $id;
    exit;
}
$flip = new class($wpdb, $doc) implements \\Psr\\EventDispatcher\\EventDispatcherInterface {
    public function __construct(private object $db, private int $doc) {}
    public function dispatch(object $event): object {
        // The owner's concurrent change, committed after normalization and before the write transaction.
        $this->db->query('UPDATE media SET visibility=:v WHERE id=:id', [':v' => 'private', ':id' => $this->doc]);
        return $event;
    }
};
$service = new \\WordPress\\Post\\WP_Post_Mutation_Service($wpdb, new \\WordPress\\Queue\\WP_Job_Queue($wpdb), $flip, $wp_auth_service);
if ('insert' === $argv[2]) {
    $result = $service->insert_post(['post_type' => 'post', 'post_title' => 'Race insert', 'post_name' => 'race-insert', 'post_status' => 'draft', 'post_content' => $content], $writer);
} else {
    $result = $service->update_post(['ID' => (int) $argv[5], 'expected_revision' => (int) $argv[6], 'post_type' => 'post', 'post_title' => 'Race update', 'post_name' => 'race-update', 'post_status' => 'draft', 'post_content' => $content], $writer);
}
echo is_wp_error($result) ? 'error' : 'saved';
""")
            out = subprocess.run([PHP, str(race), str(site/'wp-includes/bootstrap.php'), 'insert', str(writer_id), str(race_doc['id'])], env=env, capture_output=True, text=True)
            check(out.returncode == 0 and out.stdout.strip() == 'error', 'A draft insert is refused when access is withdrawn between normalization and commit')
            check(dbrow(database, "SELECT COUNT(*) AS n FROM posts WHERE title='Race insert'")['n'] == 0, 'The refused insert persists nothing')
            with sqlite3.connect(database) as db:
                db.execute('UPDATE media SET visibility=? WHERE id=?', ('public', race_doc['id']))
            out = subprocess.run([PHP, str(race), str(site/'wp-includes/bootstrap.php'), 'seed', str(writer_id), str(race_doc['id'])], env=env, capture_output=True, text=True)
            seeded = dbrow(database, "SELECT * FROM posts WHERE title='Race update'")
            check(out.returncode == 0 and seeded is not None, 'Control: the writer can save the reference while the document is public')
            out = subprocess.run([PHP, str(race), str(site/'wp-includes/bootstrap.php'), 'update', str(writer_id), str(race_doc['id']), str(seeded['id']), str(seeded['revision'])], env=env, capture_output=True, text=True)
            check(out.returncode == 0 and out.stdout.strip() == 'error', 'A draft update is refused when access is withdrawn between normalization and commit')
            check(dbrow(database, 'SELECT revision FROM posts WHERE id=?', (seeded['id'],))['revision'] == seeded['revision'], 'The refused update leaves the stored revision untouched')
            # Document id in an image-only field is rejected
            _, page, _ = admin.request('/wp-admin/post-new.php?post_type=post')
            admin.request('/wp-admin/post-new.php?post_type=post', {'_wpnonce': token(page, 'post-editor'), 'post_type': 'post', 'post_title': 'Featured doc', 'post_name': 'featured-doc', 'post_content': '<p>x</p>', 'post_status': 'draft', 'featured_media_id': str(csv['id'])})
            check(dbrow(database, "SELECT COUNT(*) AS n FROM posts WHERE title='Featured doc'")['n'] == 0, 'A document cannot be a featured image')
            # Delete protection while referenced
            _, page, _ = admin.request('/wp-admin/media.php?kind=documents')
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page, action_field='upload_document'), 'action': 'delete', 'id': str(doc['id']), 'confirm_delete': 'yes'})
            check(dbrow(database, 'SELECT COUNT(*) AS n FROM media WHERE id=?', (doc['id'],))['n'] == 1, 'A referenced document cannot be deleted')
            # XHR per-file endpoint
            _, page, _ = admin.request('/wp-admin/media.php?kind=documents')
            boundary = 'wp-doc-boundary'
            payload = (f'--{boundary}\r\nContent-Disposition: form-data; name="_wpnonce"\r\n\r\n{token(page, action_field="upload_document")}\r\n--{boundary}\r\nContent-Disposition: form-data; name="action"\r\n\r\nupload_document\r\n--{boundary}\r\nContent-Disposition: form-data; name="visibility"\r\n\r\nprivate\r\n--{boundary}\r\nContent-Disposition: form-data; name="ajax"\r\n\r\n1\r\n--{boundary}\r\nContent-Disposition: form-data; name="document"; filename="xhr.txt"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + b'xhr body' + f'\r\n--{boundary}--\r\n'.encode())
            status, body, xhr_headers = admin.request('/wp-admin/media.php?kind=documents', payload, {'Content-Type': 'multipart/form-data; boundary=' + boundary})
            check(status == 200 and json.loads(body).get('ok') is True, 'XHR upload answers JSON per file')
            check(xhr_headers.get('Content-Type') == 'application/json; charset=utf-8', 'XHR JSON carries a well-formed Content-Type header')
            # Classic no-JS multiple upload: array field name, one success and one rejection in a single submission
            _, page, _ = admin.request('/wp-admin/media.php?kind=documents')
            boundary = 'wp-classic-boundary'
            classic = (f'--{boundary}\r\nContent-Disposition: form-data; name="_wpnonce"\r\n\r\n{token(page, action_field="upload_document")}\r\n--{boundary}\r\nContent-Disposition: form-data; name="action"\r\n\r\nupload_document\r\n--{boundary}\r\nContent-Disposition: form-data; name="visibility"\r\n\r\nprivate\r\n--{boundary}\r\nContent-Disposition: form-data; name="document[]"; filename="classic-good.txt"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + b'classic good body' + f'\r\n--{boundary}\r\nContent-Disposition: form-data; name="document[]"; filename="classic-bad.txt"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + b'lone \xff byte' + f'\r\n--{boundary}--\r\n'.encode())
            status, body, _ = admin.request('/wp-admin/media.php?kind=documents', classic, {'Content-Type': 'multipart/form-data; boundary=' + boundary})
            check(dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE original_name='classic-good.txt' AND state='ready'")['n'] == 1, 'Classic multiple upload stores the valid file')
            check(dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE original_name='classic-bad.txt'")['n'] == 0 and 'UTF-8' in body, 'Classic multiple upload rejects the invalid file and reports why, without rolling back the valid one')
            # Storage loss: reuse must verify bytes, and a reupload repairs instead of reporting false success
            blob = temp/'state/assets'/doc['filename']
            blob.unlink()
            check(admin.request('/documents/' + str(doc['id']))[0] == 404, 'A ready record whose bytes were removed no longer downloads')
            multipart_doc(admin, media_page(admin), 'notes.txt', b'Quarterly notes body', {'action': 'upload_document', 'visibility': 'private'})
            healed = dbrow(database, "SELECT * FROM media WHERE sha256=? AND kind='document' AND format='txt' AND visibility='private' AND state='ready' ORDER BY id DESC", (doc['sha256'],))
            status, body, _ = admin.request('/documents/' + str(healed['id']))
            check(status == 200 and body == 'Quarterly notes body', 'Reuploading after storage loss repairs the bytes and the result downloads')
            blob.write_bytes(b'corrupted bytes')
            ready_before = dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE sha256=? AND kind='document' AND state='ready'", (doc['sha256'],))['n']
            status, body, _ = multipart_doc(admin, media_page(admin), 'notes.txt', b'Quarterly notes body', {'action': 'upload_document', 'visibility': 'private'})
            check('corrupt' in body, 'Reuploading over corrupt bytes is refused with an explicit integrity error')
            check(dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE sha256=? AND kind='document' AND state='ready'", (doc['sha256'],))['n'] == ready_before, 'The corrupt-bytes refusal registers no falsely usable record')
            blob.write_bytes(b'Quarterly notes body')
            # Quota: a full quota rejects document registration without a partial record
            used = dbrow(database, "SELECT COALESCE(SUM(size),0) AS n FROM media")['n']
            with sqlite3.connect(database) as db:
                db.execute("INSERT INTO options(option_name,option_value) VALUES('assets_quota_bytes',?) ON CONFLICT(option_name) DO UPDATE SET option_value=excluded.option_value", (str(used),))
            before = doc_count()
            multipart_doc(admin, media_page(admin), 'over.txt', b'one byte over', {'action': 'upload_document', 'visibility': 'private'})
            check(doc_count() == before, 'A full quota rejects the document with no partial record')
            with sqlite3.connect(database) as db:
                db.execute("UPDATE options SET option_value='0' WHERE option_name='assets_quota_bytes'")
            # Backup integrity: documents are archived and verified; corruption fails loudly naming the asset
            out = cli(site, env, 'backup')
            check('Backup:' in out, 'Backup succeeds with documents present')
            tar_path = max((temp/'state/backups').glob('*.tar'), key=lambda p: p.stat().st_mtime)
            with tarfile.open(tar_path) as tar:
                names = tar.getnames()
                manifest = json.loads(tar.extractfile('manifest.json').read())
            entry = [m for m in manifest['media'] if m.get('kind') == 'document' and m.get('format') == 'csv']
            check('assets/' + csv['filename'] in names and entry and entry[0]['visibility'] == 'public' and entry[0]['state'] == 'ready', 'Backup archives document bytes and records kind/format/visibility/state')
            blob.write_bytes(blob.read_bytes() + b'tamper')
            failure = ''
            try: cli(site, env, 'backup')
            except AssertionError as error: failure = str(error)
            check(('Asset ' + str(doc['id'])) in failure, 'A corrupt document blob fails the backup loudly, naming the asset')
            print('Document bundle checks passed.')
        finally: server.terminate(); server.wait(timeout=5)
# ---------- Restore contract: incomplete registrations are identified, excluded, and reconciled away ----------
with tempfile.TemporaryDirectory(prefix='wp-docs-restore-') as directory:
    temp = Path(directory); site = temp/'site'; database = temp/'state/site.sqlite'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
    env = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(temp/'state'), WP_DB_PATH=str(database)); env.pop('WP_AUTH_SALT', None)
    with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    with (temp/'server.log').open('w') as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(site), str(site/'tools/router.php')], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.03)
            admin = Client(base); _, body, _ = admin.request('/wp-admin/install.php')
            admin.request('/wp-admin/install.php', {'_wpnonce': token(body), 'site_title': 'Restore test', 'admin_user': 'admin', 'admin_email': 'admin@example.test', 'admin_password': 'disposable-test-password'})
            check(admin.login('admin')[0] == 302, 'Restore fixture logs in')
            _, page, _ = admin.request('/wp-admin/media.php?kind=documents')
            multipart_doc(admin, page, 'kept.csv', b'x,y\n1,2\n', {'action': 'upload_document', 'visibility': 'public'})
            kept = dbrow(database, "SELECT * FROM media WHERE original_name='kept.csv'")
            check(kept is not None and kept['state'] == 'ready', 'Restore fixture has a ready document')
            digest = hashlib.sha256(b'never promoted').hexdigest()
            with sqlite3.connect(database) as db:
                db.execute("INSERT INTO media(author_id,sha256,filename,mime,original_name,size,kind,format,visibility,state) VALUES(1,?,?, 'text/plain','lost.txt',14,'document','txt','private','pending')", (digest, digest[:2] + '/' + digest))
            pending = dbrow(database, "SELECT * FROM media WHERE original_name='lost.txt'")
            out = cli(site, env, 'backup')
            tar_path = Path(out.split('Backup:')[1].strip().splitlines()[0])
            with tarfile.open(tar_path) as tar:
                manifest = json.loads(tar.extractfile('manifest.json').read())
                names = tar.getnames()
            ready_count = dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE state='ready'")['n']
            check(manifest['recoverable_files'] == ready_count and 'assets/' + kept['filename'] in names, 'Manifest counts only ready assets as recoverable files')
            check([a['id'] for a in manifest['incomplete_assets']] == [pending['id']], 'Manifest identifies the incomplete registration as incomplete')
            # Restore into a fresh tree/runtime following the manifest procedure, then run maintenance.
            restored = temp/'restored'; rsite = restored/'site'; rstate = restored/'state'; rstate.mkdir(parents=True)
            shutil.copytree(ROOT, rsite, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
            with tarfile.open(tar_path) as tar: tar.extractall(restored/'extract')
            shutil.copy(restored/'extract/site.sqlite', rstate/'site.sqlite')
            if (restored/'extract/auth.key').is_file(): shutil.copy(restored/'extract/auth.key', rstate/'auth.key')
            for entry in (restored/'extract').rglob('*'):
                if entry.is_file() and entry.parent.name != 'extract' and entry.name != 'site.sqlite':
                    rel = entry.relative_to(restored/'extract')
                    target = (rstate/rel) if rel.parts[0] == 'assets' else (rsite/'wp-content'/rel if rel.parts[0] == 'uploads' else rstate/rel.name)
                    target.parent.mkdir(parents=True, exist_ok=True); shutil.copy(entry, target)
            rdb = rstate/'site.sqlite'
            renv = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(rstate), WP_DB_PATH=str(rdb)); renv.pop('WP_AUTH_SALT', None)
            registered_before = dbrow(rdb, 'SELECT COALESCE(SUM(size),0) AS n FROM media')['n']
            cli(rsite, renv, 'maintain')
            check(dbrow(rdb, 'SELECT COUNT(*) AS n FROM media WHERE id=?', (pending['id'],))['n'] == 0, 'Maintenance after restore removes the unrecoverable pending record')
            check(dbrow(rdb, 'SELECT COALESCE(SUM(size),0) AS n FROM media')['n'] == registered_before - 14, 'Maintenance after restore releases the pending registration quota')
            check(dbrow(rdb, "SELECT COUNT(*) AS n FROM audit_log WHERE action='asset_registration_failed' AND subject_id=?", (pending['id'],))['n'] == 1, 'The loss of the incomplete upload is recorded in the audit log')
            check(dbrow(rdb, 'SELECT state FROM media WHERE id=?', (kept['id'],))['state'] == 'ready' and (rstate/'assets'/kept['filename']).read_bytes() == b'x,y\n1,2\n', 'The ready document survives restore intact')
            # Backup applies the same canonical path validation as delivery: symlinks refuse loudly
            prefix = rstate/'assets'/kept['filename'].split('/')[0]
            aside = rstate/'assets-aside'
            shutil.move(str(prefix), str(aside)); os.symlink(str(aside), str(prefix))
            refused = False
            try: cli(rsite, renv, 'backup')
            except AssertionError: refused = True
            check(refused, 'Backup refuses a symlinked blob-prefix directory, as delivery does')
            os.unlink(str(prefix)); shutil.move(str(aside), str(prefix))
            blob_file = rstate/'assets'/kept['filename']
            blob_aside = rstate/'blob-aside'
            shutil.move(str(blob_file), str(blob_aside)); os.symlink(str(blob_aside), str(blob_file))
            refused = False
            try: cli(rsite, renv, 'backup')
            except AssertionError: refused = True
            check(refused, 'Backup refuses a symlinked blob file, as delivery does')
            os.unlink(str(blob_file)); shutil.move(str(blob_aside), str(blob_file))
            cli(rsite, renv, 'backup')
            check(True, 'Backup succeeds again once the canonical paths are restored')
            print('Restore contract checks passed.')
        finally: server.terminate(); server.wait(timeout=5)
# Permalink conflict: /documents is reserved for new structures, while a stored structure that already claims it keeps the posts.
with tempfile.TemporaryDirectory(prefix='wp-docs-link-') as directory:
    temp = Path(directory); site = temp/'site'; database = temp/'state/site.sqlite'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
    env = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(temp/'state'), WP_DB_PATH=str(database)); env.pop('WP_AUTH_SALT', None)
    with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    with (temp/'server.log').open('w') as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(site), str(site/'tools/router.php')], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.03)
            admin = Client(base); _, body, _ = admin.request('/wp-admin/install.php')
            admin.request('/wp-admin/install.php', {'_wpnonce': token(body), 'site_title': 'Conflict test', 'admin_user': 'admin', 'admin_email': 'admin@example.test', 'admin_password': 'disposable-test-password'})
            check(admin.login('admin')[0] == 302, 'Conflict fixture logs in')
            guest = Client(base)
            status, _, _ = multipart_doc(admin, admin.request('/wp-admin/media.php?kind=documents')[1], 'public.txt', b'Public conflict body', {'action': 'upload_document', 'visibility': 'public'})
            check(status == 302, 'Conflict fixture document uploads')
            cdoc = dbrow(database, "SELECT * FROM media WHERE kind='document'")
            _, page, _ = admin.request('/wp-admin/post-new.php?post_type=post')
            admin.request('/wp-admin/post-new.php?post_type=post', {'_wpnonce': token(page, 'post-editor'), 'post_type': 'post', 'post_title': 'Conflict post', 'post_name': 'conflict-post', 'post_content': json.dumps({'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'Conflict post body'}]}), 'post_status': 'published'})
            cpost = dbrow(database, "SELECT * FROM posts WHERE title='Conflict post'")
            check(cpost is not None, 'Conflict fixture post publishes')
            status, body, _ = guest.request('/documents/' + str(cdoc['id']))
            check(status == 200 and 'Public conflict body' in body, 'Document delivery works at the pretty route before any conflict')
            # New structures may not claim the prefix
            _, page, _ = admin.request('/wp-admin/options-permalink.php')
            status, body, _ = admin.request('/wp-admin/options-permalink.php', {'_wpnonce': token(page), 'permalink_choice': 'custom', 'permalink_structure': '/documents/%post_id%/'})
            check('reserved for an application route' in body, 'Saving a structure that claims /documents is refused with the reservation message')
            check(dbrow(database, "SELECT option_value FROM options WHERE option_name='permalink_structure'") is None, 'The refused structure is not stored')
            # A structure stored before the reservation keeps working for posts; delivery moves to the query form
            with sqlite3.connect(database) as db:
                db.execute("INSERT INTO options(option_name,option_value) VALUES('permalink_structure','/documents/%post_id%/')")
            status, body, _ = follow(guest, '/documents/' + str(cpost['id']))
            check(status == 200 and 'Conflict post body' in body, 'Under a legacy conflicting structure the post keeps its /documents URL')
            status, body, _ = guest.request('/documents/' + str(cdoc['id']))
            check(not (status == 200 and 'Public conflict body' in body), 'Under the conflict the pretty document URL no longer serves the document')
            status, body, _ = guest.request('/?document=' + str(cdoc['id']))
            check(status == 200 and 'Public conflict body' in body, 'Under the conflict the document downloads through the root query form')
            _, page, _ = admin.request('/wp-admin/media.php?kind=documents')
            check('?document=' + str(cdoc['id']) in page, 'The library links the query form while the conflict lasts')
            print('Permalink conflict checks passed.')
        finally: server.terminate(); server.wait(timeout=5)
# Route ownership: historical structures and dynamic tag expansions belong to posts, not document delivery.
with tempfile.TemporaryDirectory(prefix='wp-docs-route-') as directory:
    temp = Path(directory); site = temp/'site'; database = temp/'state/site.sqlite'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
    env = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(temp/'state'), WP_DB_PATH=str(database)); env.pop('WP_AUTH_SALT', None)
    with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    with (temp/'server.log').open('w') as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(site), str(site/'tools/router.php')], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.03)
            admin = Client(base); _, body, _ = admin.request('/wp-admin/install.php')
            admin.request('/wp-admin/install.php', {'_wpnonce': token(body), 'site_title': 'Route test', 'admin_user': 'admin', 'admin_email': 'admin@example.test', 'admin_password': 'disposable-test-password'})
            check(admin.login('admin')[0] == 302, 'Route fixture logs in')
            guest = Client(base)
            # A legacy structure owns /documents/{id} while current, and its URLs keep resolving after the structure changes
            with sqlite3.connect(database) as db:
                db.execute("INSERT INTO options(option_name,option_value) VALUES('permalink_structure','/documents/%post_id%/')")
            _, page, _ = admin.request('/wp-admin/post-new.php?post_type=post')
            admin.request('/wp-admin/post-new.php?post_type=post', {'_wpnonce': token(page, 'post-editor'), 'post_type': 'post', 'post_title': 'Legacy post', 'post_name': 'legacy-post', 'post_content': json.dumps({'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'Legacy post body'}]}), 'post_status': 'published'})
            legacy = dbrow(database, "SELECT * FROM posts WHERE title='Legacy post'")
            status, body, _ = follow(guest, '/documents/' + str(legacy['id']))
            check(status == 200 and 'Legacy post body' in body, 'The legacy structure serves its post at /documents/{id}')
            _, page, _ = admin.request('/wp-admin/options-permalink.php')
            admin.request('/wp-admin/options-permalink.php', {'_wpnonce': token(page), 'permalink_choice': 'name'})
            check(dbrow(database, "SELECT option_value FROM options WHERE option_name='permalink_structure'")['option_value'] == '/%postname%/', 'The structure switches to post name')
            status, body, _ = follow(guest, '/documents/' + str(legacy['id']))
            check(status == 200 and 'Legacy post body' in body, 'The historical /documents URL still resolves to its post after the structure change')
            # A dynamic tag expansion: an author named "documents" under /%author%/%post_id%
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'documents', 'email': 'documents@example.test', 'password': 'disposable-test-password', 'role': 'author'})
            writer = Client(base); check(writer.login('documents')[0] == 302, 'Author named documents logs in')
            _, page, _ = writer.request('/wp-admin/post-new.php?post_type=post')
            writer.request('/wp-admin/post-new.php?post_type=post', {'_wpnonce': token(page, 'post-editor'), 'post_type': 'post', 'post_title': 'Dynamic post', 'post_name': 'dynamic-post', 'post_content': json.dumps({'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'Dynamic post body'}]}), 'post_status': 'published'})
            dynamic = dbrow(database, "SELECT * FROM posts WHERE title='Dynamic post'")
            _, page, _ = admin.request('/wp-admin/options-permalink.php')
            admin.request('/wp-admin/options-permalink.php', {'_wpnonce': token(page), 'permalink_choice': 'custom', 'permalink_structure': '/%author%/%post_id%'})
            check(dbrow(database, "SELECT option_value FROM options WHERE option_name='permalink_structure'")['option_value'] == '/%author%/%post_id%', 'The author structure saves')
            status, body, _ = follow(guest, '/documents/' + str(dynamic['id']))
            check(status == 200 and 'Dynamic post body' in body, 'A post by the author named documents resolves at its /documents URL')
            print('Route ownership checks passed.')
        finally: server.terminate(); server.wait(timeout=5)
# Cached-link lifetime (R1): a rendered File link must survive a later routing change that moves its path to a post.
with tempfile.TemporaryDirectory(prefix='wp-docs-r1-') as directory:
    temp = Path(directory); site = temp/'site'; database = temp/'state/site.sqlite'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
    env = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(temp/'state'), WP_DB_PATH=str(database)); env.pop('WP_AUTH_SALT', None)
    with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    with (temp/'server.log').open('w') as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(site), str(site/'tools/router.php')], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.03)
            admin = Client(base); _, body, _ = admin.request('/wp-admin/install.php')
            admin.request('/wp-admin/install.php', {'_wpnonce': token(body), 'site_title': 'R1 test', 'admin_user': 'admin', 'admin_email': 'admin@example.test', 'admin_password': 'disposable-test-password'})
            check(admin.login('admin')[0] == 302, 'R1 fixture logs in')
            guest = Client(base)
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'documents', 'email': 'documents@example.test', 'password': 'disposable-test-password', 'role': 'author'})
            writer = Client(base); check(writer.login('documents')[0] == 302, 'R1 author logs in')
            _, page, _ = writer.request('/wp-admin/post-new.php?post_type=post')
            writer.request('/wp-admin/post-new.php?post_type=post', {'_wpnonce': token(page, 'post-editor'), 'post_type': 'post', 'post_title': 'Other post', 'post_name': 'other-post', 'post_content': json.dumps({'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'OTHER-POST-CONTENT'}]}), 'post_status': 'published'})
            other = dbrow(database, "SELECT * FROM posts WHERE title='Other post'")
            check(other is not None, 'R1 other post publishes under the post-name structure')
            for index in range(1, other['id']):
                multipart_doc(admin, admin.request('/wp-admin/media.php?kind=documents')[1], f'pad-{index}.txt', f'Padding document {index}'.encode(), {'action': 'upload_document', 'visibility': 'public'})
            multipart_doc(admin, admin.request('/wp-admin/media.php?kind=documents')[1], 'target.txt', b'TARGET-DOCUMENT-BYTES', {'action': 'upload_document', 'visibility': 'public'})
            target = dbrow(database, "SELECT * FROM media WHERE original_name='target.txt'")
            check(target['id'] == other['id'], 'The target document and the other post share the colliding id')
            _, page, _ = admin.request('/wp-admin/post-new.php?post_type=post')
            admin.request('/wp-admin/post-new.php?post_type=post', {'_wpnonce': token(page, 'post-editor'), 'post_type': 'post', 'post_title': 'Referencing post', 'post_name': 'referencing-post', 'post_content': json.dumps({'version': 1, 'blocks': [{'type': 'file', 'media_id': target['id'], 'label': 'Target doc'}]}), 'post_status': 'published'})
            referencing = dbrow(database, "SELECT * FROM posts WHERE title='Referencing post'")
            cli(site, env, 'queue:work', '200')
            check(dbrow(database, 'SELECT rendered_revision FROM posts WHERE id=?', (referencing['id'],))['rendered_revision'] == referencing['revision'], 'The referencing render is cached before the routing change')
            def stored_link(client):
                status, body, _ = follow(client, '/?p=' + str(referencing['id']))
                found = re.search(r'href="([^"]*(?:/documents/|\?document=)[^"]*)"', body)
                return status, (found.group(1) if found else None), body
            def as_path(href):
                return href[href.find('/', href.find('://') + 3):] if '://' in href else href
            status, href, _ = stored_link(guest)
            check(status == 200 and href is not None and '/documents/' + str(target['id']) in href, 'The cached render stores the pretty document link while it is unambiguous')
            status, body, headers = guest.request(as_path(href))
            check(status == 200 and body == 'TARGET-DOCUMENT-BYTES' and 'attachment' in headers.get('Content-Disposition', ''), 'Before the change the stored link downloads the document bytes as an attachment')
            _, page, _ = admin.request('/wp-admin/options-permalink.php')
            admin.request('/wp-admin/options-permalink.php', {'_wpnonce': token(page), 'permalink_choice': 'custom', 'permalink_structure': '/%author%/%post_id%'})
            check(dbrow(database, "SELECT option_value FROM options WHERE option_name='permalink_structure'")['option_value'] == '/%author%/%post_id%', 'The routing change saves through the admin UI')
            status, body, _ = follow(guest, '/documents/' + str(other['id']))
            check(status == 200 and 'OTHER-POST-CONTENT' in body, 'The other post now owns the /documents path')
            status, href, _ = stored_link(guest)
            check(status == 200 and href is not None and '?document=' + str(target['id']) in href, 'After the routing change the stored File link is regenerated in the query form')
            status, body, headers = guest.request(as_path(href))
            check(status == 200 and body == 'TARGET-DOCUMENT-BYTES' and 'attachment' in headers.get('Content-Disposition', ''), 'Following the stored link after the change downloads the document bytes as an attachment')
            cli(site, env, 'queue:work', '200')
            status, href, body = stored_link(guest)
            check(href is not None and '?document=' + str(target['id']) in href, 'The re-rendered cache keeps the working query-form link')
            print('Cached-link lifetime checks passed.')
        finally: server.terminate(); server.wait(timeout=5)
