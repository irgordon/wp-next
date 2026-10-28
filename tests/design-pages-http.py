#!/usr/bin/env python3
"""Page inventory, private selection and scoped editor embedding on a blank site."""
from http_helpers import *

with tempfile.TemporaryDirectory(prefix='wp-design-pages-http-') as directory:
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
            admin.request('/wp-admin/install.php',{'_wpnonce':token(body),'site_title':'Page workspace fixture','admin_user':'admin','admin_email':'admin@example.test','admin_password':'disposable-test-password'})
            check(admin.login('admin')[0]==302,'Page workspace fixture authenticates')
            def create(title,state='published',kind='page'):
                route='/wp-admin/post-new.php?post_type='+kind
                _,form,_=admin.request(route)
                status,_,_=admin.request(route,{'_wpnonce':token(form,'post-editor'),'post_type':kind,'post_title':title,'post_status':state,'post_content':'<p>'+title+'-PRIVATE-BODY</p>'})
                assert status==302
                return dbrow(database,'SELECT id FROM posts WHERE title=?',(title,))['id']
            draft=create('Workspace draft','draft'); archived=create('Workspace archived','archived'); trash=create('Workspace trash','draft'); post=create('Workspace Post','draft','post')
            create('100%_ ready')
            for number in range(25): create('List fixture '+str(number).zfill(2))
            subprocess.run([PHP,'-r','require $argv[1];wp_set_current_user(1);wp_trash_post((int)$argv[2]);',str(site/'wp-includes/bootstrap.php'),str(trash)],env=env,check=True,capture_output=True)
            _,body,_=admin.request('/wp-admin/themes.php')
            list_nonce=re.search(r'data-design-pages[^>]*data-nonce="([^"]+)"',body)[1]
            preview_nonce=re.search(r'data-design-preview[^>]*data-nonce="([^"]+)"',body)[1]
            def inventory(**updates):
                status,body,headers=admin.request('/wp-admin/themes.php',dict({'design_action':'list_pages','_wpnonce':list_nonce},**updates))
                assert status==200,body
                return json.loads(body),headers
            def snapshot():
                with sqlite3.connect(database) as db:
                    return {name:db.execute('SELECT * FROM '+name+' ORDER BY rowid').fetchall() for name in ['options','design_revisions','posts','media']}
            before=snapshot(); public_before=guest.request('/')[1]
            result,headers=inventory()
            check(len(result['items'])==20 and result['pages']==2 and result['page']==1,'Page inventory bounds the first response to 20 items')
            last,_=inventory(page='999')
            check(last['page']==2 and 0<len(last['items'])<20,'Out-of-range pagination clamps to the last available page')
            check(inventory(page='-5')[0]['page']==1,'Negative pagination clamps to the first page')
            matches,_=inventory(search='Workspace')
            check({item['id'] for item in matches['items']}=={draft,archived},'Page inventory includes drafts and archives but excludes Posts and Trash')
            check([item['id'] for item in inventory(search='Workspace',status='draft')[0]['items']]==[draft],'Search and status filtering compose')
            check(inventory(search='%_')[0]['total']==1,'SQL wildcard characters are treated as literal search text')
            check(inventory(search='absent fixture')[0]['items']==[],'No matching Pages produces an empty bounded result')
            check('private' in headers.get('Cache-Control','') and 'no-store' in headers.get('Cache-Control','') and 'noindex' in headers.get('X-Robots-Tag',''),'Page inventory is private, uncacheable and nonindexable')
            check(headers.get('X-Frame-Options')=='DENY','Inventory keeps the default frame denial')
            invalid={'design_action':'list_pages','_wpnonce':preview_nonce}
            check(admin.request('/wp-admin/themes.php',invalid)[0]==403,'A preview nonce cannot authorize the Page inventory')
            guest_status,guest_body,_=guest.request('/wp-admin/themes.php',dict(invalid,_wpnonce=list_nonce))
            check(guest_status in [302,403] and 'editorUrl' not in guest_body,'A guest cannot enumerate private Pages')
            _,filtered,_=admin.request('/wp-admin/themes.php?search=Workspace&status=draft#design-pages')
            check('data-title="Workspace draft"' in filtered and 'data-title="Workspace archived"' not in filtered,'Native GET search and filtering also render without JavaScript')
            preview={'design_action':'live_preview','_wpnonce':preview_nonce,'expected_version':'0','preview_page':str(draft),'body_size':'18'}
            status,body,preview_headers=admin.request('/wp-admin/themes.php',preview)
            check(status==200 and 'Workspace draft-PRIVATE-BODY' in json.loads(body)['html'],'An explicitly selected draft renders privately with the current design')
            check('body{font-size:18px}' in json.loads(body)['html'] and preview_headers.get('X-Frame-Options')=='DENY','Selected-Page preview receives unsaved styles while retaining the read-only frame boundary')
            check('Workspace archived-PRIVATE-BODY' in json.loads(admin.request('/wp-admin/themes.php',dict(preview,preview_page=str(archived)))[1])['html'],'Archived Pages can be inspected privately')
            for value in [str(post),str(trash),'999999']:
                check(admin.request('/wp-admin/themes.php',dict(preview,preview_page=value))[0]==404,'Unavailable selected Page is refused: '+value)
            for value in ['-1','not-an-id']:
                check(admin.request('/wp-admin/themes.php',dict(preview,preview_page=value))[0]==422,'Malformed selected Page is refused: '+value)
            check(snapshot()==before and guest.request('/')[1]==public_before,'Listing and preview selection change no persisted records or public output')
            item=next(item for item in matches['items'] if item['id']==draft)
            editor='/wp-admin/'+item['editorUrl']
            status,body,headers=admin.request(editor)
            check(status==200 and 'data-design-embed="1"' in body,'The administrator can open the signed Page visual editor')
            check(headers.get('X-Frame-Options')=='SAMEORIGIN' and "frame-ancestors 'self'" in headers.get('Content-Security-Policy',''),'Only the authorized editor permits same-origin framing')
            _,_,normal_headers=admin.request('/wp-admin/post.php?post='+str(draft)+'&editor=visual')
            check(normal_headers.get('X-Frame-Options')=='DENY' and "frame-ancestors 'none'" in normal_headers.get('Content-Security-Policy',''),'An ordinary editor retains default frame protection')
            for route in [editor.replace('&_design_nonce=','&_design_nonce=invalid'),editor.replace('editor=visual','editor=form'),editor.replace('post='+str(draft)+'&','post='+str(archived)+'&'),'/wp-admin/post.php?post='+str(draft)+'&editor=visual&design_workspace=1']:
                denied,_,denied_headers=admin.request(route)
                check(denied==403 and denied_headers.get('X-Frame-Options')=='DENY','Invalid scope, mode or nonce cannot enable editor framing')
            check(guest.request(editor)[0] in [302,403],'The signed URL does not bypass login')
            form=next(form for form in FormParser(body).forms if form['attrs'].get('id')=='post-editor')
            check('design_workspace=1' in form['attrs']['action'] and '_design_nonce=' in form['attrs']['action'],'The native save action retains the authorized workspace context')
            save=dict(form['fields'],post_title='Workspace draft saved',post_name='workspace-draft',post_content='<p>EMBEDDED-DRAFT-SAVED</p>',post_excerpt='',post_status='draft',featured_media_id='',page_template='standard')
            denied,_,_=admin.request(editor,dict(save,_wpnonce=list_nonce))
            check(denied==403,'Embedding does not bypass the native content-save nonce')
            check(snapshot()==before,'Opening and rejecting an editor save changes no persistence')
            status,_,save_headers=admin.request(editor,save)
            check(status==302 and 'design_workspace=1' in save_headers.get('Location','') and 'editor=visual' in save_headers.get('Location',''),'Successful saving redirects back into the embedded visual editor')
            row=dbrow(database,'SELECT * FROM posts WHERE id=?',(draft,))
            check(row['status']=='draft' and row['title']=='Workspace draft saved' and 'EMBEDDED-DRAFT-SAVED' in row['content_raw'],'Native saving preserves draft status and stores the edited Page')
            status,stale,_=admin.request(editor,dict(save,post_title='Stale overwrite',post_content='<p>STALE-UNSAVED</p>'))
            check(status==200 and 'notice-error' in stale and 'STALE-UNSAVED' in stale and dbrow(database,'SELECT revision FROM posts WHERE id=?',(draft,))['revision']==row['revision'],'Revision conflicts retain submitted edits without overwriting the saved Page')
            after=snapshot()
            check(after['options']==before['options'] and after['design_revisions']==before['design_revisions'] and guest.request('/')[1]==public_before,'Saving a draft Page does not publish the Page or site design')
            caps=dbrow(database,'SELECT capabilities FROM users WHERE id=1')['capabilities']
            with sqlite3.connect(database) as db: db.execute('UPDATE users SET capabilities=? WHERE id=1',(json.dumps(['edit_posts']),))
            denied,_,headers=admin.request(editor)
            check(denied==403 and headers.get('X-Frame-Options')=='DENY','Revoked administrator permission invalidates a still-signed editor link')
            check(admin.request('/wp-admin/themes.php',{'design_action':'list_pages','_wpnonce':list_nonce})[0]==403,'Page inventory rechecks current administrator permission')
            print('Page workspace HTTP contract checks passed.')
        finally:
            server.terminate(); server.wait(timeout=5)
