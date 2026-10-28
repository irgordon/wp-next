#!/usr/bin/env python3
"""G2 portability acceptance: native round-trip, WXR import, missing and
unsupported reporting, hostile archives, quota exhaustion, slug collisions
and duplicates, and interrupted-run resume. Each named release test is
reported individually."""
from http_helpers import *
import http_helpers as h
import io, json, sqlite3, urllib.request, zipfile

def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); return sock.getsockname()[1]

def start_site(temp, name, title):
    site = temp/name/'site'; state = temp/name/'state'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
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

with tempfile.TemporaryDirectory(prefix='wp-portability-') as directory:
    temp = Path(directory)
    servers = []
    try:
        # Image fixtures.
        maker = temp/'make-images.php'
        maker.write_text("""<?php
foreach ([['seed.png',800,400,30,90,140],['decoy.png',300,200,200,120,40],['wxr-photo.png',640,320,60,160,90]] as [$name,$w,$h,$r,$g,$b]) {
    $image = imagecreatetruecolor($w, $h); imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b)); imagepng($image, $argv[1] . '/' . $name);
}
""")
        subprocess.run([PHP, str(maker), str(temp)], check=True, capture_output=True, text=True)

        # ---------- Site A: the source site ----------
        a = start_site(temp, 'a', 'Portability A'); servers.append(a['server'])
        _, body, _ = a['admin'].request('/wp-admin/users.php')
        a['admin'].request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'writer', 'email': 'writer@example.test', 'password': 'disposable-test-password', 'role': 'author'})
        writer_a = Client(a['base']); assert writer_a.login('writer')[0] == 302
        term_ids = php_snippet(a, """
wp_set_current_user(1);
$cat = wp_save_term(['taxonomy'=>'category','name'=>'Field Notes','slug'=>'field-notes']);
$child = wp_save_term(['taxonomy'=>'category','name'=>'Local','slug'=>'local','parent_id'=>$cat]);
$tag = wp_save_term(['taxonomy'=>'post_tag','name'=>'Featured Tag','slug'=>'featured-tag']);
echo json_encode([$cat, $child, $tag]);
""")
        cat_id, child_id, tag_id = json.loads(term_ids)
        # Upload image + documents through the real media screen.
        def upload_media(client, page_path, form_action, field, filename, data, extra=None):
            _, page, _ = client.request(page_path)
            nonce = token(page, action_field=form_action)
            boundary = 'wp-media-boundary'
            parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="_wpnonce"\r\n\r\n{nonce}\r\n'.encode(),
                     f'--{boundary}\r\nContent-Disposition: form-data; name="action"\r\n\r\n{form_action}\r\n'.encode()]
            for name, value in (extra or {}).items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + data + f'\r\n--{boundary}--\r\n'.encode())
            return client.request(page_path, b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + boundary})
        upload_media(a['admin'], '/wp-admin/media.php', 'upload', 'image', 'seed.png', (temp/'seed.png').read_bytes())
        image_a = dbrow(a['database'], "SELECT * FROM media WHERE original_name='seed.png'")
        check(image_a is not None and image_a['state'] == 'ready', 'Source image uploads')
        upload_media(a['admin'], '/wp-admin/media.php?kind=documents', 'upload_document', 'document', 'guide.txt', b'Guide body text', {'visibility': 'public'})
        upload_media(a['admin'], '/wp-admin/media.php?kind=documents', 'upload_document', 'document', 'secret.txt', b'Secret body text', {'visibility': 'private'})
        guide_a = dbrow(a['database'], "SELECT * FROM media WHERE original_name='guide.txt'")
        secret_a = dbrow(a['database'], "SELECT * FROM media WHERE original_name='secret.txt'")
        check(guide_a['visibility'] == 'public' and secret_a['visibility'] == 'private', 'Source documents upload with visibility')
        blocks = [{'type': 'paragraph', 'html': 'First paragraph', 'align': 'left'},
                  {'type': 'image', 'media_id': image_a['id'], 'alt': 'Seed', 'caption': ''},
                  {'type': 'file', 'media_id': guide_a['id'], 'label': 'Guide'}]
        assert post_form(writer_a, 'Harbor field notes', 'harbor-field-notes', 'published', blocks, featured=image_a['id'], terms=[child_id, tag_id]) in (200, 302)
        post_a = dbrow(a['database'], "SELECT * FROM posts WHERE slug='harbor-field-notes'")
        check(post_a is not None and post_a['author_id'] == 2, 'Source post is authored by the writer')
        _, page, _ = a['admin'].request('/wp-admin/post-new.php?post_type=page')
        a['admin'].request('/wp-admin/post-new.php?post_type=page', {'_wpnonce': token(page, 'post-editor'), 'post_type': 'page', 'post_status': 'published', 'post_title': 'About the harbor', 'post_name': 'about-the-harbor', 'post_content': json.dumps({'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'About body', 'align': 'left'}]})})
        _, page, _ = a['admin'].request('/wp-admin/post-new.php?post_type=post')
        a['admin'].request('/wp-admin/post-new.php?post_type=post', {'_wpnonce': token(page, 'post-editor'), 'post_type': 'post', 'post_status': 'draft', 'post_title': 'Unpublished draft', 'post_name': 'unpublished-draft', 'post_content': json.dumps({'version': 1, 'blocks': [{'type': 'paragraph', 'html': 'Draft body', 'align': 'left'}]})})
        check(count(a['database'], 'SELECT COUNT(*) FROM posts') == 5, 'Source site holds its posts, page and the two installer seeds')

        # CLI export writes a real archive.
        cli_target = temp/'cli-export.zip'
        out = cli(a['site'], a['env'], 'export', str(cli_target))
        check('Export:' in out and cli_target.is_file(), 'CLI export writes an archive')
        with zipfile.ZipFile(cli_target) as archive:
            manifest = json.loads(archive.read('manifest.json'))
        check(manifest['format'] == 'wpnext-portability' and manifest['version'] == 1 and manifest['counts'] == {'authors': 2, 'terms': 4, 'media': 3, 'posts': 5}, 'Manifest is versioned with accurate counts')

        # HTTP export downloads the same kind of archive.
        _, page, _ = a['admin'].request('/wp-admin/portability.php')
        status, exported = raw_post(a['admin'], '/wp-admin/portability.php', {'_wpnonce': token(page, 'portability-export'), 'portability_action': 'export'})
        check(status == 200 and exported[:2] == b'PK', 'Admin export downloads a ZIP archive')
        status, _, _ = a['guest'].request('/wp-admin/portability.php')
        check(status in (301, 302), 'Guests are redirected away from the portability screen')

        # ---------- Native round-trip into blank site B ----------
        b = start_site(temp, 'b', 'Portability B'); servers.append(b['server'])
        _, body, _ = b['admin'].request('/wp-admin/users.php')
        b['admin'].request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'bystander', 'email': 'bystander@example.test', 'password': 'disposable-test-password', 'role': 'author'})
        # A decoy image shifts B's media IDs so ID remapping is observable.
        upload_media(b['admin'], '/wp-admin/media.php', 'upload', 'image', 'decoy.png', (temp/'decoy.png').read_bytes())
        decoy_b = dbrow(b['database'], "SELECT * FROM media WHERE original_name='decoy.png'")
        _, report, results = preflight_and_import(b, {'archive': ('export.zip', exported)})
        check('unusable password' in report, 'Preflight plans an unusable-password account for the unmapped writer')
        check('Harbor field notes' in report and 'About the harbor' in report, 'Preflight lists the content before any writes')
        post_b = dbrow(b['database'], "SELECT * FROM posts WHERE slug='harbor-field-notes'")
        check(post_b is not None and post_b['status'] == 'published', 'Round-trip: the published post arrives')
        writer_b = dbrow(b['database'], "SELECT * FROM users WHERE username='writer'")
        check(writer_b is not None and post_b['author_id'] == writer_b['id'], 'Round-trip: authorship maps to the created writer account')
        check('administrator' not in writer_b['capabilities'], 'Round-trip: the created account is not privileged')
        image_b = dbrow(b['database'], "SELECT * FROM media WHERE sha256=? AND kind='image' AND variant=''", (image_a['sha256'],))
        check(image_b is not None and image_b['id'] != image_a['id'], 'Round-trip: image imports under a new target ID')
        check(post_b['featured_media_id'] == image_b['id'], 'Round-trip: the featured image relationship is remapped')
        content_b = json.loads(post_b['content_raw'])
        referenced = [block.get('media_id') for block in content_b['blocks']]
        guide_b = dbrow(b['database'], "SELECT * FROM media WHERE original_name='guide.txt'")
        secret_b = dbrow(b['database'], "SELECT * FROM media WHERE original_name='secret.txt'")
        check(image_b['id'] in referenced and guide_b['id'] in referenced and image_a['id'] not in referenced, 'Round-trip: block media references are remapped')
        check(guide_b['visibility'] == 'public' and secret_b['visibility'] == 'private', 'Round-trip: document visibility survives')
        blob = b['state']/'assets'/secret_b['filename']
        check(blob.is_file() and blob.read_bytes() == b'Secret body text', 'Round-trip: private document bytes arrive in protected storage')
        status, _, _ = b['guest'].request('/documents/' + str(secret_b['id']))
        check(status == 404, 'Round-trip: the imported private document stays private')
        status, body, _ = b['guest'].request('/documents/' + str(guide_b['id']))
        check(status == 200 and 'Guide body text' in body, 'Round-trip: the imported public document downloads')
        check(count(b['database'], "SELECT COUNT(*) FROM media WHERE parent_id=?", (image_b['id'],)) >= 1, 'Round-trip: image variants regenerate on import')
        slugs = [row[0] for row in sqlite3.connect(b['database']).execute("SELECT t.slug FROM post_terms pt JOIN terms t ON t.id=pt.term_id WHERE pt.post_id=?", (post_b['id'],))]
        check('local' in slugs and 'featured-tag' in slugs, 'Round-trip: category and tag assignments survive')
        parent = dbrow(b['database'], "SELECT p.slug AS parent_slug FROM terms c JOIN terms p ON p.id=c.parent_id WHERE c.slug='local'")
        check(parent is not None and parent['parent_slug'] == 'field-notes', 'Round-trip: the category hierarchy survives')
        page_b = dbrow(b['database'], "SELECT * FROM posts WHERE slug='about-the-harbor'")
        check(page_b is not None and page_b['author_id'] == 1, 'Round-trip: the page maps to the email-matched administrator')
        draft_b = dbrow(b['database'], "SELECT * FROM posts WHERE slug='unpublished-draft'")
        check(draft_b is not None and draft_b['status'] == 'draft', 'Round-trip: drafts stay drafts')
        check(abs(post_b['published_at'] - post_a['published_at']) <= 2, 'Round-trip: the publication date is preserved')

        # ---------- Resume: the same archive again duplicates nothing ----------
        posts_before = count(b['database'], 'SELECT COUNT(*) FROM posts'); media_before = count(b['database'], "SELECT COUNT(*) FROM media WHERE variant=''")
        _, _, results2 = preflight_and_import(b, {'archive': ('export.zip', exported)})
        check('already-imported' in results2, 'Resume: completed items report as already imported')
        check(count(b['database'], 'SELECT COUNT(*) FROM posts') == posts_before and count(b['database'], "SELECT COUNT(*) FROM media WHERE variant=''") == media_before, 'Resume: re-running the same archive creates no duplicates')

        # ---------- Duplicates and slug collisions ----------
        _, page, _ = b['admin'].request('/wp-admin/portability.php')
        status, exported_b = raw_post(b['admin'], '/wp-admin/portability.php', {'_wpnonce': token(page, 'portability-export'), 'portability_action': 'export'})
        _, _, results3 = preflight_and_import(b, {'archive': ('b-export.zip', exported_b)})
        check('skipped-duplicate' in results3, 'Duplicates: an identical re-import under a new archive is skipped per item')
        check(count(b['database'], "SELECT COUNT(*) FROM posts WHERE slug='harbor-field-notes'") == 1, 'Duplicates: no second copy of the identical post exists')

        # ---------- WXR import (same site B) ----------
        # A colliding slug with different content forces the -imported suffix.
        assert post_form(b['admin'], 'Existing WXR Hello', 'wxr-hello', 'published', [{'type': 'paragraph', 'html': 'Different body', 'align': 'left'}]) in (200, 302)
        media_zip_buffer = io.BytesIO()
        with zipfile.ZipFile(media_zip_buffer, 'w') as bundle:
            bundle.writestr('wxr-photo.png', (temp/'wxr-photo.png').read_bytes())
            bundle.writestr('wxr-notes.txt', b'WXR notes body')
        wxr_bytes = (ROOT/'tests/fixtures/sample.wxr').read_bytes()
        _, report, results = preflight_and_import(b, {'archive': ('sample.wxr', wxr_bytes), 'media_archive': ('media.zip', media_zip_buffer.getvalue())})
        check('unsupported post type' in report and 'WXR Product' in report, 'WXR preflight reports the unsupported custom post type')
        check('missing' in report and 'wxr-absent.png' in report, 'WXR preflight lists media whose bytes were not supplied')
        wxr_post = dbrow(b['database'], "SELECT * FROM posts WHERE slug='wxr-hello-imported'")
        check(wxr_post is not None, 'Slug collisions: the WXR post is suffixed to wxr-hello-imported')
        check(dbrow(b['database'], "SELECT * FROM posts WHERE slug='wxr-hello'")['title'] == 'Existing WXR Hello', 'Slug collisions: the existing post keeps its slug')
        photo_b = dbrow(b['database'], "SELECT * FROM media WHERE original_name='wxr-photo.png'")
        check(photo_b is not None and wxr_post['featured_media_id'] == photo_b['id'], 'WXR: the featured image resolves through the media archive')
        check(photo_b['alt_text'] == 'Harbor at dawn', 'WXR: attachment alt text is carried over')
        check('WXR first paragraph' in wxr_post['content_raw'], 'WXR: HTML content arrives as sanitized legacy content')
        check('shortcode' in results, 'WXR: shortcode-style markup is reported per item and never executed')
        check('acme/widget' in results, 'WXR: the unsupported custom block is reported per item')
        check('_edit_lock' in results, 'WXR: plugin-style post meta is reported per item')
        check('keep me' in (wxr_post['metadata'] or ''), 'WXR: plain custom fields land in metadata')
        wxr_author = dbrow(b['database'], "SELECT * FROM users WHERE username='wxrauthor'")
        check(wxr_author is not None and wxr_post['author_id'] == wxr_author['id'], 'WXR: authors are created and attributed')
        landing = dbrow(b['database'], "SELECT * FROM posts WHERE slug='wxr-landing'")
        jane = dbrow(b['database'], "SELECT * FROM users WHERE username='editorjane'")
        check(landing is not None and jane is not None and landing['author_id'] == jane['id'], 'WXR: the second author maps to the page')
        secret_wxr = dbrow(b['database'], "SELECT * FROM posts WHERE slug='wxr-secret'")
        check(secret_wxr is not None and secret_wxr['status'] == 'draft', 'WXR: a WordPress private post is downgraded to a draft')
        check('imported as a draft' in results, 'WXR: the status downgrade is reported per item')
        check(dbrow(b['database'], "SELECT * FROM posts WHERE slug='wxr-product'") is None, 'WXR: the custom post type is not imported')
        notes_doc = dbrow(b['database'], "SELECT * FROM media WHERE original_name='wxr-notes.txt'")
        check(notes_doc is not None and notes_doc['kind'] == 'document' and notes_doc['visibility'] == 'public', 'WXR: a text attachment imports as a public document')
        check('missing' in results and 'wxr-absent.png' in results, 'WXR: missing attachment bytes are reported in the results')
        wxr_slugs = [row[0] for row in sqlite3.connect(b['database']).execute("SELECT t.slug FROM post_terms pt JOIN terms t ON t.id=pt.term_id WHERE pt.post_id=?", (wxr_post['id'],))]
        check('local' in wxr_slugs and 'featured' in wxr_slugs, 'WXR: category and tag assignments survive')
        check(wxr_post['published_at'] == 1714564800, 'WXR: the publication date is preserved')

        # ---------- Hostile inputs are refused without writes ----------
        posts_snapshot = count(b['database'], 'SELECT COUNT(*) FROM posts'); media_snapshot = count(b['database'], 'SELECT COUNT(*) FROM media')
        slip = io.BytesIO()
        with zipfile.ZipFile(slip, 'w') as bundle:
            bundle.writestr('../evil.txt', b'pwned')
            bundle.writestr('manifest.json', json.dumps({'format': 'wpnext-portability', 'version': 1, 'authors': [], 'terms': [], 'media': [], 'posts': []}))
        _, report, _ = preflight_and_import(b, {'archive': ('evil.zip', slip.getvalue())}, expect_ok=False)
        check('unsafe entry path' in report, 'Hostile: a zip-slip entry is refused')
        check(not (temp.parent/'evil.txt').exists() and not Path('/tmp/evil.txt').exists(), 'Hostile: nothing is written outside the archive sandbox')
        xxe = b'<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss><channel><title>&xxe;</title></channel></rss>'
        _, report, _ = preflight_and_import(b, {'archive': ('xxe.xml', xxe)}, expect_ok=False)
        check('entities' in report, 'Hostile: an XXE WXR file is refused')
        absurd = io.BytesIO()
        with zipfile.ZipFile(absurd, 'w') as bundle:
            bundle.writestr('manifest.json', json.dumps({'format': 'wpnext-portability', 'version': 1, 'counts': {'authors': 0, 'terms': 0, 'media': 0, 'posts': 999}, 'authors': [], 'terms': [], 'media': [], 'posts': []}))
        _, report, _ = preflight_and_import(b, {'archive': ('absurd.zip', absurd.getvalue())}, expect_ok=False)
        check('counts do not match' in report, 'Hostile: a manifest with absurd counts is refused')
        _, report, _ = preflight_and_import(b, {'archive': ('notzip.zip', b'this is not a zip')}, expect_ok=False)
        check('not a ZIP archive' in report, 'Hostile: a non-ZIP upload is refused')
        check(count(b['database'], 'SELECT COUNT(*) FROM posts') == posts_snapshot and count(b['database'], 'SELECT COUNT(*) FROM media') == media_snapshot, 'Hostile: refused archives write nothing')
        bystander = Client(b['base']); assert bystander.login('bystander')[0] == 302
        status, _, _ = bystander.request('/wp-admin/portability.php')
        check(status == 403, 'A non-administrator cannot open the portability screen')
        check(dbrow(b['database'], "SELECT * FROM users WHERE username='writer'")['password_hash'] != '', 'The imported writer account exists with a stored credential hash')
        intruder = Client(b['base'])
        check(intruder.login('writer')[0] != 302, 'The imported writer cannot sign in with the source site password')

        # ---------- Quota exhaustion on fresh site C ----------
        c = start_site(temp, 'c', 'Portability C'); servers.append(c['server'])
        with sqlite3.connect(c['database']) as conn:
            conn.execute("INSERT INTO options(option_name,option_value) VALUES('assets_quota_bytes','50') ON CONFLICT(option_name) DO UPDATE SET option_value='50'")
        _, _, results_c = preflight_and_import(c, {'archive': ('export.zip', exported)})
        check('quota' in results_c, 'Quota: media that would exceed the quota is reported per item')
        harbor_c = dbrow(c['database'], "SELECT * FROM posts WHERE slug='harbor-field-notes'")
        check(harbor_c is not None and 'First paragraph' in harbor_c['content_raw'], 'Quota: the post still imports with its text content')
        check(count(c['database'], "SELECT COUNT(*) FROM media WHERE kind='image' AND variant=''") == 0, 'Quota: the oversized image is not registered')
        check('was removed because that media did not import' in results_c or 'left unset' in results_c, 'Quota: posts referencing the failed media report it')

        # ---------- CLI import into fresh site D ----------
        d = start_site(temp, 'd', 'Portability D'); servers.append(d['server'])
        out = cli(d['site'], d['env'], 'import', str(cli_target))
        check('Preflight only' in out, 'CLI import without --commit stops at preflight')
        check(dbrow(d['database'], "SELECT * FROM posts WHERE slug='harbor-field-notes'") is None, 'CLI preflight writes nothing')
        out = cli(d['site'], d['env'], 'import', str(cli_target), '--commit')
        cli_post = dbrow(d['database'], "SELECT * FROM posts WHERE slug='harbor-field-notes'")
        check(cli_post is not None and 'imported' in out, 'CLI import with --commit imports the archive')
        print(f'{h.COUNT} portability checks passed.')
    finally:
        for server in servers: server.terminate()
        for server in servers:
            try: server.wait(timeout=5)
            except Exception: server.kill()
