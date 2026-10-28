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
            create_nonce=re.search(r'data-page-create data-nonce="([^"]+)"',body)[1]
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
            # Explicit workspace buttons control status; form fields cannot change their intent.
            _,draft_markup,_=admin.request(editor)
            draft_form=next(form for form in FormParser(draft_markup).forms if form['attrs'].get('id')=='post-editor')
            check('data-page-publish' in draft_markup and '>Save Draft</button>' in draft_markup,'Draft editor exposes separate native Save Draft and Publish Page submitters')
            check('data-page-view' not in draft_markup,'A draft does not expose a live View Page action')
            draft_save=dict(draft_form['fields'],action='save_page_draft',post_status='published',post_title='Workspace draft saved',post_name='workspace-draft',post_content='<p>PRIVATE-DRAFT-ITERATION</p>',post_excerpt='',featured_media_id='',page_template='standard')
            draft_saved,_,draft_saved_headers=admin.request(editor,draft_save)
            staged=dbrow(database,'SELECT * FROM posts WHERE id=?',(draft,))
            check(draft_saved==302 and staged['status']=='draft' and staged['published_at'] is None and guest.request('/'+staged['slug']+'/')[0]==404,'Save Draft remains private despite a submitted Published status')
            check('page_action=save_page_draft' in draft_saved_headers['Location'],'Draft saving retains its explicit result in the embedded redirect')
            _,stale_notice,_=admin.request(editor+'&updated=1&page_action=publish_page')
            check('Page published.' not in stale_notice and 'data-persisted-status="draft"' in stale_notice,'A stale publication-result URL cannot label a saved draft as published')
            publish=dict(draft_save,action='publish_page',post_status='draft',post_content='<p>EXPLICIT-PUBLISHED-PAGE-BODY</p>')
            stale_publication,stale_markup,_=admin.request(editor,publish)
            check(stale_publication==200 and 'notice-error' in stale_markup and 'EXPLICIT-PUBLISHED-PAGE-BODY' in stale_markup and dbrow(database,'SELECT revision FROM posts WHERE id=?',(draft,))['revision']==staged['revision'],'Stale publication preserves submitted content and cannot overwrite a newer draft')
            publish['expected_revision']=str(staged['revision'])
            publication_before=snapshot()
            check(admin.request(editor,dict(publish,_wpnonce=create_nonce))[0]==403 and snapshot()==publication_before,'A creation nonce cannot publish Page content')
            check(guest.request(editor,publish)[0] in [302,403] and snapshot()==publication_before,'Publication cannot bypass authentication')
            revision_count=dbrow(database,'SELECT COUNT(*) AS n FROM post_revisions')['n']
            with sqlite3.connect(database) as db:
                db.execute("CREATE TRIGGER fixture_refuse_publication BEFORE UPDATE ON posts WHEN NEW.status='published' BEGIN SELECT RAISE(ABORT,'fixture refused publication'); END")
            failed_publication,failed_markup,_=admin.request(editor,publish)
            with sqlite3.connect(database) as db: db.execute('DROP TRIGGER fixture_refuse_publication')
            check(failed_publication==200 and 'notice-error' in failed_markup and 'EXPLICIT-PUBLISHED-PAGE-BODY' in failed_markup and 'data-persisted-status="draft"' in failed_markup and '>Save Draft</button>' in failed_markup and 'Page published.' not in failed_markup,'Failed publication retains content and reports the actual Draft state with retry actions')
            check(snapshot()==publication_before and dbrow(database,'SELECT COUNT(*) AS n FROM post_revisions')['n']==revision_count,'Publication failure rolls back the Page and revision snapshot')
            check('data-page-view' not in failed_markup,'Failed publication does not expose a live View Page action')
            published,_,published_headers=admin.request(editor,publish)
            live=dbrow(database,'SELECT * FROM posts WHERE id=?',(draft,))
            check(published==302 and 'design_workspace=1' in published_headers['Location'] and 'page_action=publish_page' in published_headers['Location'] and live['status']=='published' and live['published_at'] is not None,'Publish Page explicitly publishes the current content and stays in the embedded editor')
            _,published_markup,_=admin.request('/wp-admin/'+published_headers['Location'])
            check('Page published. Site design is published separately.' in published_markup and '>Update Page</button>' in published_markup and 'data-page-publish' not in published_markup,'A successful publication shows its result and replaces Publish Page with Update Page')
            live_tag=re.search(r'<a\b[^>]*data-page-view\b[^>]*>',published_markup)[0]
            live_attributes=dict(re.findall(r'([a-z-]+)="([^"]*)"',live_tag))
            check(live_attributes['href']==base+'/'+live['slug']+'/' and '/preview/' not in live_attributes['href'] and '_design_nonce' not in live_attributes['href'],'View Page points to the actual public permalink without workspace preview parameters')
            check(live_attributes['target']=='_blank' and {'noopener','noreferrer'}.issubset(set(live_attributes['rel'].split())) and 'new tab' in live_attributes['aria-label'],'View Page declares a separate tab without opener or referrer access')
            public_status,public_markup,_=guest.request('/'+live['slug']+'/')
            check(public_status==200 and 'EXPLICIT-PUBLISHED-PAGE-BODY' in public_markup,'Anonymous visitors receive the Page only after explicit publication')
            updated=dict(publish,action='update_page',expected_revision=str(live['revision']),post_status='draft',post_content='<p>UPDATED-PUBLISHED-PAGE-BODY</p>')
            check(admin.request(editor,updated)[0]==302 and dbrow(database,'SELECT status FROM posts WHERE id=?',(draft,))['status']=='published' and 'UPDATED-PUBLISHED-PAGE-BODY' in guest.request('/'+live['slug']+'/')[1],'Update Page keeps a published Page public despite a submitted Draft field')
            duplicate_status,duplicate_markup,_=admin.request(editor,updated)
            check(duplicate_status==200 and 'notice-error' in duplicate_markup and dbrow(database,'SELECT revision FROM posts WHERE id=?',(draft,))['revision']==live['revision']+1,'A repeated update is fenced by the original expected revision')
            publication_after=snapshot()
            check(publication_after['options']==publication_before['options'] and publication_after['design_revisions']==publication_before['design_revisions'] and guest.request('/')[1]==public_before,'Page publication and updates leave the site-design configuration and homepage output unchanged')
            # Creation is an explicit mutation; caller fields cannot change the draft policy.
            with sqlite3.connect(database) as db:
                db.execute("INSERT INTO options(option_name,option_value) VALUES('default_status','published') ON CONFLICT(option_name) DO UPDATE SET option_value='published'")
            creation={'design_action':'create_page','_wpnonce':create_nonce,'post_status':'published','post_type':'post','post_author':'999','post_title':'FORGED-TITLE','homepage_id':str(draft)}
            creation_before=snapshot()
            admin.request('/wp-admin/themes.php?design_action=create_page')
            check(snapshot()==creation_before,'GET cannot create a workspace draft')
            for wrong in ['',list_nonce,preview_nonce]:
                check(admin.request('/wp-admin/themes.php',dict(creation,_wpnonce=wrong))[0]==403,'Draft creation rejects a missing or wrong-action nonce')
            check(guest.request('/wp-admin/themes.php',creation)[0] in [302,403] and snapshot()==creation_before,'Unauthenticated draft creation cannot persist a Page')
            with sqlite3.connect(database) as db:
                db.execute("CREATE TRIGGER fixture_refuse_creation BEFORE INSERT ON posts WHEN NEW.post_type='page' BEGIN SELECT RAISE(ABORT,'fixture refused draft insert'); END")
            rejected,rejected_body,_=admin.request('/wp-admin/themes.php',creation)
            with sqlite3.connect(database) as db: db.execute('DROP TRIGGER fixture_refuse_creation')
            check(rejected==422 and not json.loads(rejected_body)['ok'] and 'could not be created' in json.loads(rejected_body)['error'] and snapshot()==creation_before,'Mutation-service failure returns a recoverable error and rolls back Page creation')
            status,body,headers=admin.request('/wp-admin/themes.php',creation)
            created=json.loads(body)['item']; new_row=dbrow(database,'SELECT * FROM posts WHERE id=?',(created['id'],))
            check(status==201 and created['status']=='draft' and new_row['status']=='draft' and new_row['published_at'] is None,'New Page stays a private draft even when the default and submitted status are Published')
            check(new_row['post_type']=='page' and new_row['author_id']==1 and new_row['title']=='Untitled Page','Creation uses the authenticated actor and fixed Page defaults')
            check(json.loads(new_row['content_raw'])=={'version':1,'blocks':[]} and json.loads(new_row['metadata'])['page_template']=='standard','The new Page starts with an editable empty block document and curated Standard template')
            check(created['listPage']>1 and any(item['id']==created['id'] for item in inventory(page=created['listPage'])[0]['items']),'Creation locates the new Page in the bounded sorted inventory')
            check('private' in headers.get('Cache-Control','') and 'no-store' in headers.get('Cache-Control','') and headers.get('X-Frame-Options')=='DENY','The creation response is private and retains default frame denial')
            opened,markup,embedded_headers=admin.request('/wp-admin/'+created['editorUrl'])
            check(opened==200 and 'data-design-embed="1"' in markup and embedded_headers.get('X-Frame-Options')=='SAMEORIGIN','The returned signed URL opens the new draft in the workspace editor')
            check(guest.request('/'+new_row['slug']+'/')[0]==404 and guest.request('/')[1]==public_before,'Draft creation exposes neither a public Page nor a changed homepage')
            creation_after=snapshot()
            check(len(creation_after['posts'])==len(creation_before['posts'])+1 and creation_after['options']==creation_before['options'] and creation_after['design_revisions']==creation_before['design_revisions'],'Creating one Page leaves site-design options and revisions unchanged')
            check(admin.request('/wp-admin/themes.php',dict(creation,design_action='publish'))[0]==403,'The creation nonce cannot publish the site design')
            second=json.loads(admin.request('/wp-admin/themes.php',creation)[1])['item']
            second_row=dbrow(database,'SELECT * FROM posts WHERE id=?',(second['id'],))
            check(second['id']!=created['id'] and second_row['slug']!=new_row['slug'] and second_row['status']=='draft','A separate intentional creation uses the normal unique-slug workflow')
            caps=dbrow(database,'SELECT capabilities FROM users WHERE id=1')['capabilities']
            with sqlite3.connect(database) as db: db.execute('UPDATE users SET capabilities=? WHERE id=1',(json.dumps(['edit_posts']),))
            denied,_,headers=admin.request(editor)
            check(denied==403 and headers.get('X-Frame-Options')=='DENY','Revoked administrator permission invalidates a still-signed editor link')
            check(admin.request('/wp-admin/themes.php',{'design_action':'list_pages','_wpnonce':list_nonce})[0]==403,'Page inventory rechecks current administrator permission')
            revoked=snapshot()
            check(admin.request('/wp-admin/themes.php',creation)[0]==403 and snapshot()==revoked,'A valid creation nonce cannot bypass revoked administrator access')
            check(admin.request(editor,publish)[0]==403 and snapshot()==revoked,'Revoked administrator access also refuses Page publication')
            print('Page workspace HTTP contract checks passed.')
        finally:
            server.terminate(); server.wait(timeout=5)
