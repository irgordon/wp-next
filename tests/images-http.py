#!/usr/bin/env python3
"""B1 image tools acceptance: variant generation and lineage, focal point, bounded
edits, reviewable replacement, pending recovery and backup of the whole family."""
from http_helpers import *
import hashlib, io, json, tarfile
def multipart_image(client, page_body, filename, data):
    nonce = token(page_body, action_field='upload')
    boundary = 'wp-img-boundary'
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="_wpnonce"\r\n\r\n{nonce}\r\n'.encode(),
             f'--{boundary}\r\nContent-Disposition: form-data; name="action"\r\n\r\nupload\r\n'.encode(),
             f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="{filename}"\r\nContent-Type: image/png\r\n\r\n'.encode() + data + f'\r\n--{boundary}--\r\n'.encode()]
    return client.request('/wp-admin/media.php', b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + boundary})
def follow(client, path):
    status, body, headers = client.request(path)
    if status in (301, 302) and headers.get('Location'):
        loc = headers['Location']
        path2 = loc[loc.find('/', loc.find('://') + 3):] if '://' in loc else loc
        return client.request(path2)
    return status, body, headers
with tempfile.TemporaryDirectory(prefix='wp-images-') as directory:
    temp = Path(directory); site = temp/'site'; database = temp/'state/site.sqlite'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
    env = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(temp/'state'), WP_DB_PATH=str(database)); env.pop('WP_AUTH_SALT', None)
    with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    # Fixtures: big 2000x1000 (left red, right blue), small 100x80 green, mid 800x400 yellow.
    fixture = temp/'make-fixtures.php'
    fixture.write_text("""<?php
$big = imagecreatetruecolor(2000, 1000);
imagefill($big, 0, 0, imagecolorallocate($big, 255, 0, 0));
imagefilledrectangle($big, 1000, 0, 2000, 1000, imagecolorallocate($big, 0, 0, 255));
imagepng($big, $argv[1] . '/big.png');
$small = imagecreatetruecolor(100, 80);
imagefill($small, 0, 0, imagecolorallocate($small, 0, 200, 0));
imagepng($small, $argv[1] . '/small.png');
$mid = imagecreatetruecolor(800, 400);
imagefill($mid, 0, 0, imagecolorallocate($mid, 240, 220, 0));
imagepng($mid, $argv[1] . '/mid.png');
$own = imagecreatetruecolor(700, 350);
imagefill($own, 0, 0, imagecolorallocate($own, 40, 80, 120));
imagepng($own, $argv[1] . '/own.png');
""")
    subprocess.run([PHP, str(fixture), str(temp)], check=True, capture_output=True, text=True)
    probe = temp/'pixel.php'
    probe.write_text("""<?php
$image = imagecreatefrompng($argv[1]);
$c = imagecolorsforindex($image, imagecolorat($image, (int) $argv[2], (int) $argv[3]));
echo $c['red'], ',', $c['green'], ',', $c['blue'], ' ', imagesx($image), 'x', imagesy($image);
""")
    def pixel(path, x, y):
        out = subprocess.run([PHP, str(probe), str(path), str(x), str(y)], capture_output=True, text=True)
        rgb = out.stdout.strip().split(' ')[0].split(',')
        return tuple(int(v) for v in rgb)
    def is_red(rgb): return rgb[0] > 200 and rgb[2] < 80
    def is_blue(rgb): return rgb[2] > 200 and rgb[0] < 80
    with (temp/'server.log').open('w') as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(site), str(site/'tools/router.php')], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.03)
            admin = Client(base); _, body, _ = admin.request('/wp-admin/install.php')
            admin.request('/wp-admin/install.php', {'_wpnonce': token(body), 'site_title': 'Images test', 'admin_user': 'admin', 'admin_email': 'admin@example.test', 'admin_password': 'disposable-test-password'})
            check(admin.login('admin')[0] == 302, 'Fixture logs in')
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'writer', 'email': 'writer@example.test', 'password': 'disposable-test-password', 'role': 'author'})
            writer = Client(base); check(writer.login('writer')[0] == 302, 'Writer logs in')
            guest = Client(base)
            def media_page(client): return client.request('/wp-admin/media.php')[1]
            def variants_of(master_id):
                with sqlite3.connect(database) as db:
                    db.row_factory = sqlite3.Row
                    return {r['variant']: dict(r) for r in db.execute('SELECT * FROM media WHERE parent_id=?', (master_id,))}
            # Upload generates the fixed variant set with lineage and master attribution
            status, _, _ = multipart_image(admin, media_page(admin), 'big.png', (temp/'big.png').read_bytes())
            check(status == 302, 'Big image uploads')
            big = dbrow(database, "SELECT * FROM media WHERE original_name='big.png'")
            check(big['variant'] == '' and big['state'] == 'ready' and big['width'] == 2000 and big['height'] == 1000, 'Master registered at full size')
            variants = variants_of(big['id'])
            check(set(variants) == {'thumbnail', 'medium', 'large'}, 'Upload generates thumbnail, medium and large variants')
            check((variants['thumbnail']['width'], variants['thumbnail']['height']) == (150, 150), 'Thumbnail is the 150x150 crop')
            check((variants['medium']['width'], variants['medium']['height']) == (600, 300), 'Medium preserves aspect at 600 wide')
            check((variants['large']['width'], variants['large']['height']) == (1200, 600), 'Large preserves aspect at 1200 wide')
            check(all(v['state'] == 'ready' and v['author_id'] == big['author_id'] for v in variants.values()), 'Variants are ready and keep the master author (derivation is not ownership)')
            check(all((site/'wp-content/uploads'/v['filename']).is_file() for v in variants.values()), 'Variant bytes live at the canonical uploads paths')
            # Small masters produce valid smaller outputs only
            multipart_image(admin, media_page(admin), 'small.png', (temp/'small.png').read_bytes())
            small = dbrow(database, "SELECT * FROM media WHERE original_name='small.png'")
            small_variants = variants_of(small['id'])
            check(set(small_variants) == {'thumbnail'} and (small_variants['thumbnail']['width'], small_variants['thumbnail']['height']) == (80, 80), 'A small master yields only a valid smaller thumbnail (no upscaling)')
            # Rendering: image blocks and featured images carry srcset from the family
            def save_post(client, title, slug, blocks, featured=None):
                _, page, _ = client.request('/wp-admin/post-new.php?post_type=post')
                fields = {'_wpnonce': token(page, 'post-editor'), 'post_type': 'post', 'post_status': 'published', 'post_title': title, 'post_name': slug, 'post_content': json.dumps({'version': 1, 'blocks': blocks})}
                if featured is not None: fields['featured_media_id'] = str(featured)
                return client.request('/wp-admin/post-new.php?post_type=post', fields)
            blocks = [{'type': 'image', 'media_id': big['id'], 'alt': 'Big', 'caption': ''}, {'type': 'paragraph', 'html': 'Body', 'align': 'left'}]
            save_post(admin, 'Gallery post', 'gallery-post', blocks, featured=big['id'])
            post = dbrow(database, "SELECT * FROM posts WHERE title='Gallery post'")
            cli(site, env, 'queue:work', '200')
            status, body, _ = follow(guest, '/?p=' + str(post['id']))
            check(status == 200 and 'srcset=' in body and '600w' in body and '1200w' in body and '2000w' in body and 'sizes=' in body, 'Image block renders srcset with variant and master candidates')
            check(body.count('srcset=') >= 2, 'The featured image renders its own srcset')
            # Upload dedupe resolves to masters, never to a variant row
            medium_bytes = (site/'wp-content/uploads'/variants['medium']['filename']).read_bytes()
            multipart_image(admin, media_page(admin), 'medium-copy.png', medium_bytes)
            copy_row = dbrow(database, "SELECT * FROM media WHERE original_name='medium-copy.png'")
            check(copy_row is not None and copy_row['variant'] == '' and copy_row['id'] != variants['medium']['id'], 'Uploading variant bytes registers a master, not the variant row')
            # Focal point drives the thumbnail crop
            thumb_path = site/'wp-content/uploads'/variants['thumbnail']['filename']
            check(is_red(pixel(thumb_path, 30, 75)), 'Default thumbnail crops around the center (left sample is red)')
            _, page, _ = admin.request('/wp-admin/media.php')
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page), 'action': 'focal', 'id': str(big['id']), 'focal_x': '100', 'focal_y': '50'})
            variants = variants_of(big['id'])
            thumb_path = site/'wp-content/uploads'/variants['thumbnail']['filename']
            check(is_blue(pixel(thumb_path, 30, 75)), 'Focal point at the right edge makes the thumbnail crop blue')
            check(dbrow(database, 'SELECT sha256 FROM media WHERE id=?', (big['id'],))['sha256'] == big['sha256'], 'Setting a focal point leaves the master bytes untouched')
            # Bounded edits create new masters; originals and references stay put
            _, page, _ = admin.request('/wp-admin/media.php')
            status, body, _ = admin.request('/wp-admin/media.php', {'_wpnonce': token(page), 'action': 'edit_image', 'id': str(big['id']), 'crop_x': '0', 'crop_y': '0', 'crop_w': '1000', 'crop_h': '1000', 'rotate': '0'})
            check('Edited copy created' in body, 'Crop edit reports a new copy')
            edited = dbrow(database, "SELECT * FROM media WHERE parent_id=? AND variant='' ORDER BY id DESC", (big['id'],))
            check(edited is not None and (edited['width'], edited['height']) == (1000, 1000), 'The edited copy is a new 1000x1000 master')
            edited_variants = variants_of(edited['id'])
            check(set(edited_variants) == {'thumbnail', 'medium'}, 'The edited copy gets its own variants (no large at 1000 wide)')
            check(dbrow(database, 'SELECT content_raw FROM posts WHERE id=?', (post['id'],))['content_raw'] == post['content_raw'], 'Editing does not rewrite referencing content')
            multipart_image(admin, media_page(admin), 'mid.png', (temp/'mid.png').read_bytes())
            mid = dbrow(database, "SELECT * FROM media WHERE original_name='mid.png'")
            _, page, _ = admin.request('/wp-admin/media.php')
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page), 'action': 'edit_image', 'id': str(mid['id']), 'rotate': '90'})
            rotated = dbrow(database, "SELECT * FROM media WHERE parent_id=? AND variant=''", (mid['id'],))
            check(rotated is not None and (rotated['width'], rotated['height']) == (400, 800), 'Rotate 90 produces a 400x800 copy')
            # Replacement: reviewable impact, then apply; logo and history are out of scope
            with sqlite3.connect(database) as db: db.execute("INSERT INTO options(option_name,option_value) VALUES('site_logo_id',?) ON CONFLICT(option_name) DO UPDATE SET option_value=excluded.option_value", (str(big['id']),))
            save_post(writer, 'Writer gallery', 'writer-gallery', [{'type': 'image', 'media_id': big['id'], 'alt': 'W', 'caption': ''}])
            _, page, _ = admin.request('/wp-admin/media.php')
            status, body, _ = admin.request('/wp-admin/media.php', {'_wpnonce': token(page), 'action': 'replace_preview', 'id': str(big['id']), 'replacement_id': str(edited['id'])})
            check('Replacement impact' in body and 'Gallery post' in body and 'Writer gallery' in body and 'featured image' in body, 'Replacement preview lists every affected item before applying')
            status, body, _ = admin.request('/wp-admin/media.php', {'_wpnonce': token(page), 'action': 'replace_apply', 'id': str(big['id']), 'replacement_id': str(edited['id'])})
            check('Replacement applied to 2 content item(s)' in body, 'Replacement applies to both posts')
            after = dbrow(database, 'SELECT * FROM posts WHERE id=?', (post['id'],))
            check(after['featured_media_id'] == edited['id'] and str(edited['id']) in after['content_raw'], 'Featured image and image block moved to the replacement')
            writer_post = dbrow(database, "SELECT * FROM posts WHERE title='Writer gallery'")
            check(str(edited['id']) in writer_post['content_raw'], "The other author's post moved too (administrator holds the required capabilities)")
            revision = dbrow(database, 'SELECT snapshot FROM post_revisions WHERE post_id=? ORDER BY revision DESC', (post['id'],))
            check(str(big['id']) in revision['snapshot'], 'Historical revisions keep the original references')
            check(dbrow(database, "SELECT option_value FROM options WHERE option_name='site_logo_id'")['option_value'] == str(big['id']), 'The site logo is never changed by replacement')
            # Replacement rejects unauthorized targets wholesale: the writer owns the
            # source image and one target post, but not the administrator's post.
            multipart_image(writer, media_page(writer), 'own.png', (temp/'own.png').read_bytes())
            own = dbrow(database, "SELECT * FROM media WHERE original_name='own.png'")
            save_post(writer, 'Writer second', 'writer-second', [{'type': 'image', 'media_id': own['id'], 'alt': 'S', 'caption': ''}])
            save_post(admin, 'Admin second', 'admin-second', [{'type': 'image', 'media_id': own['id'], 'alt': 'S', 'caption': ''}])
            _, page, _ = writer.request('/wp-admin/media.php')
            writer.request('/wp-admin/media.php', {'_wpnonce': token(page), 'action': 'replace_apply', 'id': str(own['id']), 'replacement_id': str(edited['id'])})
            check(str(own['id']) in dbrow(database, "SELECT content_raw FROM posts WHERE title='Writer second'")['content_raw'], 'A rejected replacement changes nothing, not even the permitted items')
            check(str(own['id']) in dbrow(database, "SELECT content_raw FROM posts WHERE title='Admin second'")['content_raw'], 'The unauthorized target is untouched after rejection')
            # Pending variants: maintenance regenerates from the master or removes the registration
            large = variants_of(big['id'])['large']
            large_path = site/'wp-content/uploads'/large['filename']
            large_bytes = large_path.read_bytes()
            with sqlite3.connect(database) as db: db.execute("UPDATE media SET state='pending' WHERE id=?", (large['id'],))
            large_path.unlink()
            cli(site, env, 'maintain')
            large_after = dbrow(database, 'SELECT * FROM media WHERE id=?', (large['id'],))
            check(large_after['state'] == 'ready' and large_path.read_bytes() == large_bytes, 'Maintenance regenerates an interrupted variant from its master')
            digest = hashlib.sha256(b'no-such-bytes').hexdigest()
            with sqlite3.connect(database) as db:
                db.execute("INSERT INTO media(author_id,sha256,filename,mime,original_name,width,height,size,kind,format,visibility,state,parent_id,variant) VALUES(1,?,?, 'image/png','ghost-medium.png',600,300,12345,'image','png','public','pending',?,'medium')", (digest, digest[:2] + '/' + digest + '.png', small['id']))
            total_before = dbrow(database, 'SELECT COALESCE(SUM(size),0) AS n FROM media')['n']
            cli(site, env, 'maintain')
            check(dbrow(database, "SELECT COUNT(*) AS n FROM media WHERE original_name='ghost-medium.png'")['n'] == 0, 'Maintenance removes a pending variant that cannot be produced')
            check(dbrow(database, 'SELECT COALESCE(SUM(size),0) AS n FROM media')['n'] == total_before - 12345, 'Removing the unusable registration releases its quota')
            # Storage accounting separates masters, variants and documents
            status_out = cli(site, env, 'status')
            check('registered_variants_bytes' in status_out and 'registered_masters_bytes' in status_out, 'Status reports registered bytes split by masters and variants')
            # Deleting a master removes its derived rows; variants cannot be deleted directly
            multipart_image(admin, media_page(admin), 'mid.png', (temp/'mid.png').read_bytes())
            _, page, _ = admin.request('/wp-admin/media.php')
            variant_id = variants_of(mid['id'])['thumbnail']['id']
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page), 'action': 'delete', 'id': str(variant_id), 'confirm_delete': 'yes'})
            check(dbrow(database, 'SELECT COUNT(*) AS n FROM media WHERE id=?', (variant_id,))['n'] == 1, 'A variant row cannot be deleted directly')
            check(dbrow(database, 'SELECT COUNT(*) AS n FROM media WHERE parent_id=?', (mid['id'],))['n'] >= 3, 'Variant family intact before master deletion')
            _, page, _ = admin.request('/wp-admin/media.php')
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page), 'action': 'delete', 'id': str(small['id']), 'confirm_delete': 'yes'})
            check(dbrow(database, 'SELECT COUNT(*) AS n FROM media WHERE parent_id=?', (small['id'],))['n'] == 0, 'Deleting a master removes its derived rows with it')
            print('Image tool checks passed.')
        finally: server.terminate(); server.wait(timeout=5)
# Backup restores the family; a missing ready variant fails loudly; interrupted generation follows the incomplete contract
with tempfile.TemporaryDirectory(prefix='wp-images-restore-') as directory:
    temp = Path(directory); site = temp/'site'; database = temp/'state/site.sqlite'
    shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
    env = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(temp/'state'), WP_DB_PATH=str(database)); env.pop('WP_AUTH_SALT', None)
    with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    base = 'http://127.0.0.1:' + str(port)
    fixture = temp/'make-fixtures.php'
    fixture.write_text("<?php $i = imagecreatetruecolor(2000, 1000); imagefill($i, 0, 0, imagecolorallocate($i, 10, 20, 30)); imagepng($i, $argv[1] . '/big.png');")
    subprocess.run([PHP, str(fixture), str(temp)], check=True, capture_output=True, text=True)
    with (temp/'server.log').open('w') as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(site), str(site/'tools/router.php')], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1): break
                except OSError: time.sleep(.03)
            admin = Client(base); _, body, _ = admin.request('/wp-admin/install.php')
            admin.request('/wp-admin/install.php', {'_wpnonce': token(body), 'site_title': 'Family test', 'admin_user': 'admin', 'admin_email': 'admin@example.test', 'admin_password': 'disposable-test-password'})
            check(admin.login('admin')[0] == 302, 'Family fixture logs in')
            def media_page(client): return client.request('/wp-admin/media.php')[1]
            multipart_image(admin, media_page(admin), 'big.png', (temp/'big.png').read_bytes())
            master = dbrow(database, "SELECT * FROM media WHERE original_name='big.png'")
            _, page, _ = admin.request('/wp-admin/media.php')
            admin.request('/wp-admin/media.php', {'_wpnonce': token(page), 'action': 'edit_image', 'id': str(master['id']), 'crop_x': '0', 'crop_y': '0', 'crop_w': '1000', 'crop_h': '1000', 'rotate': '0'})
            family = dbrow(database, 'SELECT COUNT(*) AS n FROM media')['n']
            check(family == 7, 'Master, its three variants, the edited copy and its two variants form the family')
            cli(site, env, 'backup')
            tar_path = max((temp/'state/backups').glob('*.tar'), key=lambda p: p.stat().st_mtime)
            with tarfile.open(tar_path) as tar: names = tar.getnames()
            check(len({n for n in names if n.startswith('uploads/')}) == 6, 'Backup archives every distinct ready family file (the two solid-color thumbnails share one stored file)')
            large = dbrow(database, "SELECT * FROM media WHERE parent_id=? AND variant='large'", (master['id'],))
            large_file = site/'wp-content/uploads'/large['filename']
            large_file.unlink()
            try:
                cli(site, env, 'backup'); check(False, 'Backup must fail on a missing ready variant')
            except AssertionError as failure:
                check(('Asset ' + str(large['id'])) in str(failure), 'A missing ready variant fails the backup loudly, naming the asset')
            # Model an interrupted generation instead: registration present, bytes absent.
            with tarfile.open(tar_path) as tar: large_file.write_bytes(tar.extractfile('uploads/' + large['filename']).read())
            with sqlite3.connect(database) as db: db.execute("UPDATE media SET state='pending' WHERE id=?", (large['id'],))
            large_file.unlink()
            cli(site, env, 'backup')
            tar_path = max((temp/'state/backups').glob('*.tar'), key=lambda p: p.stat().st_mtime)
            with tarfile.open(tar_path) as tar:
                manifest = json.loads(tar.extractfile('manifest.json').read())
            check(any(item['id'] == large['id'] for item in manifest['incomplete_assets']), 'The interrupted variant travels as an incomplete registration, not a recoverable file')
            restored = temp/'restored'; rsite = restored/'site'; rstate = restored/'state'; rstate.mkdir(parents=True)
            shutil.copytree(site, rsite, ignore=shutil.ignore_patterns('var'))
            with tarfile.open(tar_path) as tar: tar.extractall(restored/'extract')
            shutil.copy(restored/'extract/site.sqlite', rstate/'site.sqlite')
            if (restored/'extract/auth.key').is_file(): shutil.copy(restored/'extract/auth.key', rstate/'auth.key')
            for entry in (restored/'extract').rglob('*'):
                if entry.is_file() and entry.name not in ('site.sqlite', 'auth.key', 'manifest.json'):
                    rel = entry.relative_to(restored/'extract')
                    target = (rstate/rel) if rel.parts[0] == 'assets' else (rsite/'wp-content'/rel)
                    target.parent.mkdir(parents=True, exist_ok=True); shutil.copy(entry, target)
            rdb = restored/'state/site.sqlite'
            check(dbrow(rdb, 'SELECT COUNT(*) AS n FROM media')['n'] == 7, 'Restore carries the whole family')
            check(dbrow(rdb, "SELECT COUNT(*) AS n FROM media WHERE parent_id IS NOT NULL AND state='ready'")['n'] == 5, 'Restored lineage survives with the interrupted variant still pending')
            edited = dbrow(rdb, "SELECT * FROM media WHERE parent_id=? AND variant=''", (master['id'],))
            check(edited is not None and (rsite/'wp-content/uploads'/edited['filename']).is_file(), 'The edited copy restores with its bytes')
            renv = dict(env, WP_VAR_DIR=str(rstate), WP_DB_PATH=str(rdb))
            cli(rsite, renv, 'maintain')
            check(dbrow(rdb, 'SELECT COUNT(*) AS n FROM media')['n'] == 7, 'Maintenance after restore keeps the complete family')
            healed = dbrow(rdb, 'SELECT * FROM media WHERE id=?', (large['id'],))
            check(healed['state'] == 'ready' and (rsite/'wp-content/uploads'/healed['filename']).is_file() and hashlib.sha256((rsite/'wp-content/uploads'/healed['filename']).read_bytes()).hexdigest() == large['sha256'], 'Maintenance regenerates the interrupted variant from the restored master')
            print('Backup family checks passed.')
        finally: server.terminate(); server.wait(timeout=5)
print('All image checks passed.')
