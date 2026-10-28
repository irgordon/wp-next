#!/usr/bin/env python3
"""Unsaved site preview: authenticated, action-bound, request-local and nonpublishing."""
from http_helpers import *

with tempfile.TemporaryDirectory(prefix='wp-live-design-http-') as directory:
    temp=Path(directory); site=temp/'site'; database=temp/'state/site.sqlite'
    shutil.copytree(ROOT,site,ignore=shutil.ignore_patterns('vendor','node_modules','__pycache__','var','.git'))
    env=dict(os.environ,WP_ENV='development',WP_VAR_DIR=str(temp/'state'),WP_DB_PATH=str(database),WP_PHP_CLI=PHP); env.pop('WP_AUTH_SALT',None)
    with socket.socket() as listener: listener.bind(('127.0.0.1',0)); port=listener.getsockname()[1]
    base='http://127.0.0.1:'+str(port)
    with (temp/'server.log').open('w') as log:
        server=subprocess.Popen([PHP,'-d','display_errors=0','-S',f'127.0.0.1:{port}','-t',str(site),str(site/'tools/router.php')],env=env,stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                except OSError: time.sleep(.03)
            admin=Client(base); guest=Client(base)
            _,body,_=admin.request('/wp-admin/install.php')
            admin.request('/wp-admin/install.php',{'_wpnonce':token(body),'site_title':'Preview fixture','admin_user':'admin','admin_email':'admin@example.test','admin_password':'disposable-test-password'})
            check(admin.login('admin')[0]==302,'Live preview fixture authenticates')
            _,body,_=admin.request('/wp-admin/themes.php')
            preview_nonce=re.search(r'data-design-preview[^>]*data-nonce="([^"]+)"',body)[1]
            design_nonce=token(body,'design-form')
            def create_page(title,state,marker):
                _,form,_=admin.request('/wp-admin/post-new.php?post_type=page')
                return admin.request('/wp-admin/post-new.php?post_type=page',{'_wpnonce':token(form,'post-editor'),'post_type':'page','post_title':title,'post_status':state,'post_content':json.dumps({'version':1,'blocks':[{'type':'paragraph','html':marker}]})})
            create_page('Public preview homepage','published','PUBLISHED-HOMEPAGE-BODY')
            home=dbrow(database,"SELECT id FROM posts WHERE title='Public preview homepage'")['id']
            create_page('Private hidden page','draft','UNPUBLISHED-PAGE-BODY')
            hidden=dbrow(database,"SELECT id FROM posts WHERE title='Private hidden page'")['id']
            functions=site/'wp-content/themes/studio/functions.php'
            functions.write_text(functions.read_text()+"\n$GLOBALS['fixture_preview_theme_loaded']='SELECTED-THEME-FUNCTIONS';\n")
            header=site/'wp-content/themes/studio/header.php'
            header.write_text(header.read_text().replace('<body>', '<body onload="window.__themeHandler=true"><p><?php echo esc_html($GLOBALS[\'fixture_preview_theme_loaded\']??\'WRONG-THEME\'); ?></p><script>window.__themeScript=true</script><meta http-equiv="refresh" content="0;url=/outside-preview">'))
            data={'_wpnonce':preview_nonce,'design_action':'live_preview','expected_version':'0','active_theme':'studio','accent':'#195b72','background':'#ffffff','width':'1080','font':'serif','body_size':'18','spacing':'40','homepage_id':str(home),'site_logo_id':'0','section_header':'<p>PRIVATE-UNSAVED-HEADER</p>','section_footer':'<p>PRIVATE-UNSAVED-FOOTER</p>','menu[header][0][label]':'Second link','menu[header][0][url]':'/second/','menu[header][0][level]':'1','menu[header][0][order]':'20','menu[header][1][label]':'First link','menu[header][1][url]':'/first/','menu[header][1][level]':'1','menu[header][1][order]':'10'}
            def snapshot():
                with sqlite3.connect(database) as db:
                    return {name:db.execute('SELECT * FROM '+name+' ORDER BY rowid').fetchall() for name in ['options','design_revisions','posts','media']}
            before=snapshot(); public_before=guest.request('/')[1]
            status,body,headers=admin.request('/wp-admin/themes.php',data)
            check(status==200,'Unsaved configuration renders without saving a draft')
            payload=json.loads(body); markup=payload['html']
            check(payload['ok'] and payload['theme']=='studio' and payload['version']==0,'Preview acknowledges the rendered theme and source revision')
            check('/themes/studio/style.css' in markup and 'SELECTED-THEME-FUNCTIONS' in markup,'Selected theme functions load before rendering its actual template')
            check('PUBLISHED-HOMEPAGE-BODY' in markup and 'UNPUBLISHED-PAGE-BODY' not in markup,'Preview uses the chosen published homepage with ordinary read isolation')
            check('PRIVATE-UNSAVED-HEADER' in markup and 'PRIVATE-UNSAVED-FOOTER' in markup,'Both unsaved shared sections reach the preview')
            check(markup.index('First link')<markup.index('Second link'),'Preview uses the same ordered navigation adapter as persistence')
            check('body{font-size:18px}' in markup and '--content-width:1080px' in markup and '--accent:#195b72' in markup,'Actual theme styles reflect unsaved design tokens')
            check('no-store' in headers.get('Cache-Control','') and 'private' in headers.get('Cache-Control','') and 'noindex' in headers.get('X-Robots-Tag',''),'Preview response cannot enter public caches or search indexes')
            check(headers.get('X-Frame-Options')=='DENY' and "frame-ancestors 'none'" in headers.get('Content-Security-Policy',''),'The JSON path retains global frame protections')
            check('wp-design-preview-id' in markup and payload['renderId'] in markup and "script-src 'none'" in markup,'Frame markup has an identifiable render and restrictive local policy')
            check('<script' not in markup and 'onload=' not in markup and 'url=/outside-preview' not in markup,'Scripts, handler attributes and refresh navigation are removed')
            check(snapshot()==before,'Live preview changes no options, design revisions, posts or media records')
            check(guest.request('/')[1]==public_before,'Anonymous site output remains byte-for-byte unchanged by private preview')
            check(guest.request('/wp-admin/themes.php',data)[0]==403,'Anonymous POST cannot obtain an unsaved preview')
            check(admin.request('/wp-admin/themes.php',dict(data,_wpnonce=''))[0]==403,'Preview rejects a missing nonce')
            check(admin.request('/wp-admin/themes.php',dict(data,_wpnonce=design_nonce))[0]==403,'The persistence nonce cannot authorize live preview')
            check(admin.request('/wp-admin/themes.php',dict(data,design_action='publish'))[0]==403 and snapshot()==before,'The preview nonce cannot publish a configuration')
            for updates,label in [({'expected_version':'1'},'Stale preview versions are refused'),({'active_theme':'../default'},'Theme traversal is refused'),({'accent':'#ffffff'},'Invalid contrast is refused'),({'homepage_id':str(hidden)},'A draft Page cannot become the preview homepage'),({'section_header':'x'*40001},'Shared-section limits apply before preview rendering')]:
                status,body,_=admin.request('/wp-admin/themes.php',dict(data,**updates))
                check(status in [409,422] and not json.loads(body)['ok'],label)
            admin.request('/wp-admin/themes.php',dict(data,section_header='<p>SAFE-HEADER</p><script>UNTRUSTED-SCRIPT</script>'))
            check(snapshot()==before,'Rejected and sanitized preview requests also leave persistence untouched')
            original_caps=dbrow(database,"SELECT capabilities FROM users WHERE id=1")['capabilities']
            with sqlite3.connect(database) as db: db.execute("UPDATE users SET capabilities=? WHERE id=1",(json.dumps(['edit_posts']),))
            check(admin.request('/wp-admin/themes.php',data)[0]==403,'Revoked administrator capability refuses a still-valid preview nonce')
            with sqlite3.connect(database) as db: db.execute("UPDATE users SET capabilities=? WHERE id=1",(original_caps,))
            # Publication remains the only path which changes the live configuration.
            status,body,_=admin.request('/wp-admin/themes.php',dict(data,_wpnonce=design_nonce,design_action='publish'))
            check(status==200 and 'Site design published.' in body,'Explicit publication still uses the original protected controller')
            public=guest.request('/')[1]
            check('/themes/studio/style.css' in public and 'PRIVATE-UNSAVED-HEADER' in public and 'PUBLISHED-HOMEPAGE-BODY' in public,'Only explicit publication exposes the new site design publicly')
            check(dbrow(database,"SELECT option_value FROM options WHERE option_name='design_version'")['option_value']=='1','Publication advances the design version once')
            print('Live design preview HTTP contract checks passed.')
        finally:
            server.terminate(); server.wait(timeout=5)
