#!/usr/bin/env python3
"""H1 public discovery acceptance: RSS feed (latest 20, excerpt-only),
XML sitemap, robots.txt, site-wide indexing control, and social/Open
Graph metadata. Each named test from the H1 brief is reported
individually. Published public content only: drafts, archived and
trashed items, private documents and account contact data must never
appear in any discovery output."""
from http_helpers import *
import http_helpers as h
import html as html_lib
import json, urllib.parse, xml.etree.ElementTree as ET

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
    script.write_text("<?php require $argv[1];\nwp_set_current_user(1);\n" + code)
    result = subprocess.run([PHP, str(script), str(site_obj['site']/'wp-includes/bootstrap.php')], env=site_obj['env'], capture_output=True, text=True, timeout=30)
    assert result.returncode == 0, result.stdout + result.stderr
    return result.stdout.strip()

def dbexec(path, sql, params=()):
    with sqlite3.connect(path) as db:
        db.execute(sql, params)

def multipart(client, path, page_body, form_id_or_action, fields, files, by_id=True):
    nonce = token(page_body, form_id_or_action) if by_id else token(page_body, action_field=form_id_or_action)
    boundary = 'wp-discovery-boundary'
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="_wpnonce"\r\n\r\n{nonce}\r\n'.encode()]
    for name, value in fields.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    for name, (filename, data, ctype) in files.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{filename}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + data + b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    return client.request(path, b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + boundary})

def follow(client, path):
    status, body, headers = client.request(path)
    if status in (301, 302) and headers.get('Location'):
        loc = headers['Location']
        path2 = loc[loc.find('/', loc.find('://') + 3):] if '://' in loc else loc
        return client.request(path2)
    return status, body, headers

def settings_form_token(body):
    for form in FormParser(body).forms:
        if 'site_title' in form['fields'] and '_wpnonce' in form['fields']:
            return form['fields']['_wpnonce']
    raise AssertionError('settings form not found')

def save_settings(admin, base_fields, discourage=None):
    _, page, _ = admin.request('/wp-admin/options-general.php')
    fields = {'_wpnonce': settings_form_token(page)}
    fields.update(base_fields)
    if discourage is not None:
        fields['discourage_search_engines'] = discourage
    return admin.request('/wp-admin/options-general.php', fields)

def head_of(body):
    start = body.find('<head>')
    end = body.find('</head>')
    assert start >= 0 and end > start, 'no head section'
    return body[start:end]

def og_value(head, prop):
    marker = f'<meta property="og:{prop}" content="'
    i = head.find(marker)
    if i < 0: return None
    j = head.find('"', i + len(marker))
    return html_lib.unescape(head[i + len(marker):j])

def main():
    with tempfile.TemporaryDirectory(prefix='wp-discovery-') as directory:
        temp = Path(directory); servers = []
        try:
            s = start_site(temp, 'a', 'Discovery Site'); servers.append(s['server'])
            admin, guest, base, database = s['admin'], s['guest'], s['base'], s['database']
            dbexec(database, "UPDATE options SET option_value='Notes & dispatches' WHERE option_name='site_tagline'")
            dbexec(database, "UPDATE users SET display_name='Ada Admin' WHERE username='admin'")
            _, users_page, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(users_page), 'username': 'writer', 'email': 'writer@example.test', 'password': 'disposable-test-password', 'role': 'author'})
            dbexec(database, "UPDATE users SET display_name='Wendy Writer' WHERE username='writer'")
            # Remove the installer's seeded content so feed/sitemap membership is deterministic.
            php_snippet(s, "global $wpdb; foreach (['hello-world', 'sample-page'] as $slug) { $row = $wpdb->get_row('SELECT id FROM posts WHERE slug=:s', [':s' => $slug]); if (null !== $row) { wp_trash_post((int) $row['id']); wp_delete_post((int) $row['id']); } }")

            # ---------- Content fixtures ----------
            blocks = lambda text: json.dumps({'version': 1, 'blocks': [{'type': 'paragraph', 'html': text}]})
            base_ts = 1767225600  # 2026-01-01 00:00:00 UTC
            for i in range(1, 22):
                pid = php_snippet(s, f"$id = wp_insert_post(['post_type' => 'post', 'post_title' => 'Feed Post {i:02d}', 'post_name' => 'feed-post-{i:02d}', 'post_status' => 'published', 'post_excerpt' => 'Excerpt {i:02d}', 'post_content' => '{blocks(f'BODY-MARKER-{i:02d}')}']); echo $id;")
                dbexec(database, 'UPDATE posts SET published_at=?, updated_at=? WHERE id=?', (base_ts + i * 3600, base_ts + i * 3600, int(pid)))
            page_id = int(php_snippet(s, f"$id = wp_insert_post(['post_type' => 'page', 'post_title' => 'About Page', 'post_name' => 'about-page', 'post_status' => 'published', 'post_content' => '{blocks('About body')}']); echo $id;"))
            draft_id = int(php_snippet(s, f"$id = wp_insert_post(['post_type' => 'post', 'post_title' => 'Draft Secret', 'post_name' => 'draft-secret', 'post_status' => 'draft', 'post_content' => '{blocks('draft body')}']); echo $id;"))
            php_snippet(s, f"wp_insert_post(['post_type' => 'post', 'post_title' => 'Archived Secret', 'post_name' => 'archived-secret', 'post_status' => 'archived', 'post_content' => '{blocks('archived body')}']);")
            trash_id = int(php_snippet(s, f"$id = wp_insert_post(['post_type' => 'post', 'post_title' => 'Trashed Secret', 'post_name' => 'trashed-secret', 'post_status' => 'published', 'post_content' => '{blocks('trashed body')}']); echo $id;"))
            php_snippet(s, f"wp_trash_post({trash_id});")

            # ---------- Test 1: feed = latest 20 published Posts, excerpt-only ----------
            status, feed, headers = guest.request('/feed')
            check(status == 200 and headers.get('Content-Type', '').startswith('application/rss+xml'), 'Feed: /feed answers 200 as application/rss+xml')
            check(feed.count('<item>') == 20, 'Feed: exactly 20 items')
            check('<title>Feed Post 21</title>' in feed and '<title>Feed Post 02</title>' in feed, 'Feed: the newest 20 posts are present')
            check('Feed Post 01' not in feed, 'Feed: the 21st (oldest) post is absent')
            check('About Page' not in feed, 'Feed: published Pages are not feed items')
            check('Excerpt 21' in feed and 'BODY-MARKER-21' not in feed and 'BODY-MARKER' not in feed, 'Feed: excerpt-only, no body content appears')
            check('<dc:creator>Ada Admin</dc:creator>' in feed, 'Feed: author is the display name')
            check(feed.find('Feed Post 21') < feed.find('Feed Post 20'), 'Feed: items are newest-first')
            rss = ET.fromstring(feed)
            check(rss.tag == 'rss' and rss.find('channel/title').text == 'Discovery Site', 'Feed: parses as RSS with the site title as channel title')
            check(rss.find('channel/description').text == 'Notes & dispatches', 'Feed: channel description is the tagline, unescaped by the parser')
            first = rss.find('channel/item')
            check(first.find('link').text == base + '/feed-post-21/' and first.find('guid').text == base + '/feed-post-21/', 'Feed: item link and guid are the canonical URL')
            check(first.find('pubDate').text.endswith('+0000') and '2026' in first.find('pubDate').text, 'Feed: pubDate is RFC-822 from published_at')

            # ---------- Test 2 (part 1): unpublished content never appears ----------
            status, sitemap, headers = guest.request('/sitemap.xml')
            check(status == 200 and headers.get('Content-Type', '').startswith('application/xml'), 'Sitemap: /sitemap.xml answers 200 as application/xml')
            for marker in ['draft-secret', 'archived-secret', 'trashed-secret', 'Draft Secret', 'Archived Secret', 'Trashed Secret']:
                check(marker not in feed, f'Exclusion: {marker} is absent from the feed')
                check(marker not in sitemap, f'Exclusion: {marker} is absent from the sitemap')

            # ---------- Test 3 (part 1): sitemap under the default structure ----------
            check(f'<loc>{base}/</loc>' in sitemap, 'Sitemap: the home URL is listed')
            check(f'<loc>{base}/feed-post-21/</loc>' in sitemap, 'Sitemap: a published Post is listed at its canonical URL')
            check(f'<loc>{base}/about-page/</loc>' in sitemap, 'Sitemap: a published Page is listed at its canonical URL')
            check('<lastmod>2026-01-01</lastmod>' in sitemap, 'Sitemap: lastmod comes from updated_at')
            check('/documents/' not in sitemap and '/wp-admin/' not in sitemap and 'preview' not in sitemap, 'Sitemap: no document, admin or preview URLs')
            ET.fromstring(sitemap)
            check(True, 'Sitemap: parses as well-formed XML')

            # ---------- Test 4: escaping probe ----------
            hostile_title = 'Break ]]> Out & "Quoted" <Tag>'
            hostile_excerpt_in = 'Excerpt ]]> & "quoted"'
            hid = int(php_snippet(s, "$id = wp_insert_post(['post_type' => 'post', 'post_title' => 'Break ]]> Out & \"Quoted\" <Tag>', 'post_name' => 'hostile-post', 'post_status' => 'published', 'post_excerpt' => 'Excerpt ]]> & \"quoted\"', 'post_content' => 'plain body']); echo $id;"))
            status, feed2, _ = guest.request('/feed')
            check('<![CDATA[' not in feed2, 'Escaping: the feed uses no CDATA')
            check(']]&gt;' in feed2, 'Escaping: the CDATA terminator is neutralized in the feed')
            rss2 = ET.fromstring(feed2)
            titles = [it.find('title').text for it in rss2.find('channel').findall('item')]
            check(hostile_title in titles, 'Escaping: the hostile title round-trips through the feed as inert text')
            descs = [it.find('description').text or '' for it in rss2.find('channel').findall('item')]
            check(any(hostile_excerpt_in in d for d in descs), 'Escaping: the hostile excerpt round-trips through the feed')
            status, hostile_page, _ = follow(guest, f'/?p={hid}')
            hhead = head_of(hostile_page)
            check(og_value(hhead, 'title') == hostile_title, 'Escaping: og:title round-trips the hostile title')
            check('<Tag>' not in hhead, 'Escaping: raw markup from the title does not reach the head')

            # ---------- Test 5: social image chain ----------
            fixture = temp/'make-png.php'
            fixture.write_text("<?php $im = imagecreatetruecolor((int) $argv[2], 90); imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160)); imagepng($im, $argv[1]);")
            png_a = temp/'featured.png'; png_b = temp/'social.png'
            subprocess.run([PHP, str(fixture), str(png_a), '120'], check=True, capture_output=True)
            subprocess.run([PHP, str(fixture), str(png_b), '140'], check=True, capture_output=True)
            _, media_body, _ = admin.request('/wp-admin/media.php')
            multipart(admin, '/wp-admin/media.php', media_body, 'upload', {'action': 'upload'}, {'image': ('featured.png', png_a.read_bytes(), 'image/png')}, by_id=False)
            multipart(admin, '/wp-admin/media.php', media_body, 'upload', {'action': 'upload'}, {'image': ('social.png', png_b.read_bytes(), 'image/png')}, by_id=False)
            img_a = dbrow(database, "SELECT * FROM media WHERE original_name='featured.png'")
            img_b = dbrow(database, "SELECT * FROM media WHERE original_name='social.png'")
            check(img_a is not None and img_b is not None and img_a['state'] == 'ready', 'Social: fixture images upload ready')
            url_a = base + '/wp-content/uploads/' + img_a['filename']
            url_b = base + '/wp-content/uploads/' + img_b['filename']
            def create_post(title, slug, featured=None, social=None):
                _, form_page, _ = admin.request('/wp-admin/post-new.php?post_type=post')
                fields = {'_wpnonce': token(form_page, 'post-editor'), 'post_type': 'post', 'post_status': 'published', 'post_title': title, 'post_name': slug, 'post_content': blocks('social body'), 'post_excerpt': 'Social excerpt'}
                if featured is not None: fields['featured_media_id'] = str(featured)
                if social is not None: fields['social_image_id'] = str(social)
                admin.request('/wp-admin/post-new.php?post_type=post', fields)
                return dbrow(database, 'SELECT * FROM posts WHERE slug=?', (slug,))
            s1 = create_post('Social Featured', 'social-featured', featured=img_a['id'])
            _, body1, _ = follow(guest, f"/?p={s1['id']}")
            check(og_value(head_of(body1), 'image') == url_a, 'Social: the featured image is the fallback og:image')
            s2 = create_post('Social Override', 'social-override', featured=img_a['id'], social=img_b['id'])
            check(json.loads(s2['metadata'])['social_image_id'] == img_b['id'], 'Social: the override is stored in post metadata')
            _, body2, _ = follow(guest, f"/?p={s2['id']}")
            check(og_value(head_of(body2), 'image') == url_b, 'Social: an explicit override wins over the featured image')
            _, edit_page, _ = admin.request(f"/wp-admin/post.php?post={s2['id']}")
            check('id="social_image_id"' in edit_page and f'<option value="{img_b["id"]}" selected>' in edit_page, 'Social: the editor picker shows the stored override selected')
            s3 = create_post('Social None', 'social-none')
            _, body3, _ = follow(guest, f"/?p={s3['id']}")
            check(og_value(head_of(body3), 'image') is None, 'Social: no override and no featured image means no og:image')
            _, doc_page, _ = admin.request('/wp-admin/media.php?kind=documents')
            multipart(admin, '/wp-admin/media.php?kind=documents', doc_page, 'upload_document', {'action': 'upload_document', 'visibility': 'private'}, {'document': ('secret.txt', b'private document body', 'text/plain')}, by_id=False)
            doc = dbrow(database, "SELECT * FROM media WHERE kind='document' AND original_name='secret.txt'")
            check(doc is not None and doc['visibility'] == 'private', 'Social: private document fixture exists')
            dbexec(database, 'UPDATE posts SET metadata=? WHERE id=?', (json.dumps({'social_image_id': doc['id']}), s3['id']))
            _, body4, _ = follow(guest, f"/?p={s3['id']}")
            check(og_value(head_of(body4), 'image') is None and doc['filename'] not in body4.split('</head>')[0], 'Social: a private document can never be emitted as a social image')
            dbexec(database, 'UPDATE posts SET metadata=? WHERE id=?', (json.dumps({'social_image_id': 99999}), s3['id']))
            status, body5, _ = follow(guest, f"/?p={s3['id']}")
            check(status == 200 and og_value(head_of(body5), 'image') is None, 'Social: a stale override id degrades to no og:image')
            _, edit2, _ = admin.request(f"/wp-admin/post.php?post={s1['id']}")
            status, err_body, _ = admin.request(f"/wp-admin/post.php?post={s1['id']}", {'_wpnonce': token(edit2, 'post-editor'), 'ID': str(s1['id']), 'action': 'update', 'post_type': 'post', 'post_title': 'Social Featured', 'post_name': 'social-featured', 'post_content': blocks('social body'), 'post_excerpt': 'Social excerpt', 'post_status': 'published', 'expected_revision': str(s1['revision']), 'featured_media_id': str(img_a['id']), 'social_image_id': str(doc['id']), 'page_template': 'standard'})
            check('Select an existing social image.' in err_body, 'Social: the editor refuses a document as the social image')
            s1_meta = json.loads(dbrow(database, 'SELECT * FROM posts WHERE id=?', (s1['id'],))['metadata'] or '{}')
            if not isinstance(s1_meta, dict): s1_meta = {}
            check(s1_meta.get('social_image_id') != doc['id'], 'Social: the refused save leaves metadata untouched')

            # ---------- Test 6: contact data absence ----------
            _, home_body, _ = guest.request('/')
            contact_post = dbrow(database, "SELECT * FROM posts WHERE slug='feed-post-05'")
            _, post_body, _ = follow(guest, f"/?p={contact_post['id']}")
            _, robots_body, _ = guest.request('/robots.txt')
            for label, blob in [('feed', feed), ('sitemap', sitemap), ('robots', robots_body), ('homepage', home_body), ('post page', post_body)]:
                check('admin@example.test' not in blob and 'writer@example.test' not in blob, f'Contact data: no account email appears in the {label} output')

            # ---------- Test 8: description/canonical regression ----------
            post21 = dbrow(database, "SELECT * FROM posts WHERE slug='feed-post-21'")
            _, p21_body, _ = follow(guest, f"/?p={post21['id']}")
            phead = head_of(p21_body)
            check('<meta name="description" content="Excerpt 21" />' in phead, 'Regression: post description meta is the excerpt, unchanged')
            check(f'<link rel="canonical" href="{base}/feed-post-21/" />' in phead, 'Regression: post canonical is unchanged')
            check(phead.count('name="description"') == 1, 'Regression: exactly one description meta on a post')
            check(og_value(phead, 'description') == 'Excerpt 21', 'Regression: og:description uses the same excerpt source')
            check(og_value(phead, 'url') == base + '/feed-post-21/', 'Regression: og:url is the canonical URL')
            check(f'<link rel="alternate" type="application/rss+xml" title="Discovery Site" href="{base}/feed" />' in phead, 'Discovery link: the feed is advertised in the post head')
            hhead2 = head_of(home_body)
            check('<meta name="description" content="Notes &amp; dispatches" />' in hhead2, 'Regression: homepage description is the tagline, unchanged')
            check(f'<link rel="canonical" href="{base}/" />' in hhead2, 'Regression: homepage canonical is unchanged')
            _, blog_body, _ = guest.request('/blog/')
            bhead = head_of(blog_body)
            check('<meta name="description" content="Notes &amp; dispatches" />' in bhead and f'<link rel="canonical" href="{base}/blog/" />' in bhead, 'Regression: archive description and canonical are unchanged')
            check('og:title' not in bhead, 'Social: archives do not gain Open Graph tags')

            # ---------- Test 3 (part 2): custom permalink structure ----------
            _, perma_page, _ = admin.request('/wp-admin/options-permalink.php')
            admin.request('/wp-admin/options-permalink.php', {'_wpnonce': token(perma_page), 'permalink_choice': 'custom', 'permalink_structure': '/%year%/%monthnum%/%postname%/'})
            import datetime
            when = datetime.datetime.fromtimestamp(base_ts + 21 * 3600, datetime.timezone.utc)
            dated = f'{base}/{when.year}/{when.month:02d}/feed-post-21/'
            _, sitemap2, _ = guest.request('/sitemap.xml')
            check(f'<loc>{dated}</loc>' in sitemap2, 'Sitemap: post URLs follow the active custom permalink structure')
            check(f'<loc>{base}/about-page/</loc>' in sitemap2, 'Sitemap: Page URLs stay canonical under a custom post structure')
            _, feed3, _ = guest.request('/feed')
            check(f'<link>{dated}</link>' in feed3, 'Feed: item links follow the active custom permalink structure')
            _, perma_page, _ = admin.request('/wp-admin/options-permalink.php')
            admin.request('/wp-admin/options-permalink.php', {'_wpnonce': token(perma_page), 'permalink_choice': 'name', 'permalink_structure': '/%postname%/'})

            # ---------- Test 2 (part 2): unpublishing removes items ----------
            dbexec(database, "UPDATE posts SET status='draft' WHERE slug='feed-post-21'")
            _, feed4, _ = guest.request('/feed')
            _, sitemap3, _ = guest.request('/sitemap.xml')
            check('Feed Post 21' not in feed4 and 'feed-post-21' not in sitemap3, 'Exclusion: an unpublished post disappears from feed and sitemap on the next request')
            dbexec(database, "UPDATE posts SET status='published' WHERE slug='feed-post-21'")

            # ---------- Test 7: indexing toggle ----------
            _, robots_off, headers = guest.request('/robots.txt')
            check(headers.get('Content-Type', '').startswith('text/plain'), 'Robots: /robots.txt answers as text/plain')
            check(robots_off == f'User-agent: *\nDisallow:\n\nSitemap: {base}/sitemap.xml\n', 'Robots: the default body allows crawling and references the sitemap')
            check('name="robots"' not in head_of(home_body), 'Indexing: no robots meta while the toggle is off')
            status, preview_body, _ = admin.request(f'/preview/{draft_id}')
            check('<meta name="robots" content="noindex, nofollow" />' in head_of(preview_body), 'Indexing: previews carry the robots meta regardless of the toggle')
            check('og:title' not in head_of(preview_body), 'Social: previews do not gain Open Graph tags')
            site_fields = {'site_title': 'Discovery Site', 'site_tagline': 'Notes & dispatches', 'site_url': base, 'admin_email': 'admin@example.test', 'default_status': 'draft'}
            save_settings(admin, site_fields, discourage='1')
            _, robots_on, _ = guest.request('/robots.txt')
            check(robots_on == 'User-agent: *\nDisallow: /\n', 'Indexing: discouragement flips robots.txt to disallow all')
            _, home_on, _ = guest.request('/')
            check('<meta name="robots" content="noindex, nofollow" />' in head_of(home_on), 'Indexing: discouragement adds the site-wide robots meta')
            status, feed_on, _ = guest.request('/feed')
            status2, sitemap_on, _ = guest.request('/sitemap.xml')
            check(status == 200 and '<item>' in feed_on and status2 == 200 and '<urlset' in sitemap_on, 'Indexing: feed and sitemap remain reachable while discouraged')
            save_settings(admin, site_fields, discourage=None)
            _, robots_back, _ = guest.request('/robots.txt')
            _, home_back, _ = guest.request('/')
            check(robots_back == robots_off and 'name="robots"' not in head_of(home_back), 'Indexing: flipping the toggle back restores robots.txt and the head')

            print(f'{h.COUNT} discovery checks passed.')
        finally:
            for server in servers: server.terminate()
            for server in servers:
                try: server.wait(timeout=5)
                except Exception: server.kill()
if __name__ == '__main__':
    main()
