#!/usr/bin/env python3
"""Profiles, public privacy boundaries, taxonomy persistence and review regressions."""
import http_helpers as h
from http_helpers import *
with tempfile.TemporaryDirectory(prefix='wp-profiles-') as directory:
    temp=Path(directory);site=temp/'site';database=temp/'state/site.sqlite'
    shutil.copytree(ROOT,site,ignore=shutil.ignore_patterns('vendor','node_modules','__pycache__','*.sqlite*'))
    env=dict(os.environ,WP_ENV='development',WP_VAR_DIR=str(temp/'state'),WP_DB_PATH=str(database));env.pop('WP_AUTH_SALT',None)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base='http://127.0.0.1:'+str(port)
    with (temp/'server.log').open('w') as log:
        server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(site),str(site/'tools/router.php')],env=env,stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.03)
            admin=Client(base);guest=Client(base)
            _,body,_=admin.request('/wp-admin/install.php')
            status,_,_=admin.request('/wp-admin/install.php',{'_wpnonce':token(body),'site_title':'Profiles test','admin_user':'admin','admin_email':'admin@example.test','admin_password':'disposable-test-password'})
            check(status==200 and dbrow(database,"SELECT option_value FROM options WHERE option_name='schema_version'")['option_value']=='8','Blank install creates schema 8')
            check(admin.login('admin')[0]==302,'Administrator can sign in')
            _,body,_=admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php',{'_wpnonce':token(body),'username':'writer','email':'writer@example.test','password':'disposable-test-password','role':'author'})
            writer=Client(base);check(writer.login('writer')[0]==302,'Author can sign in')
            uid=dbrow(database,"SELECT id FROM users WHERE username='writer'")['id']
            author_path='/author/writer-'+str(uid)+'/'
            check(guest.request(author_path)[0]==404,'Public profiles are opt-in')
            check(writer.request('/wp-admin/profile.php?user=1')[0]==403,'Author cannot edit another profile')
            _,body,_=writer.request('/wp-admin/profile.php')
            data={'_wpnonce':token(body),'profile_fields':'1','display_name':'Writer Display','first_name':'PrivateFirst','last_name':'PrivateLast','biography':'Public biography marker','website':'https://example.test','email':'writer@example.test','avatar_media_id':'0','public_profile':'1','profile_revision':'1','capabilities':'["administrator"]','user_id':'1'}
            status,body,_=writer.request('/wp-admin/profile.php',data)
            check(status==200 and 'Profile saved.' in body,'Profile name/bio edits need no password when contact/security is unchanged')
            row=dbrow(database,'SELECT * FROM users WHERE id=?',(uid,))
            check('administrator' not in row['capabilities'] and dbrow(database,'SELECT username FROM users WHERE id=1')['username']=='admin','Profile mass assignment cannot change roles or target another account')
            status,public,_=guest.request(author_path)
            check(status==200 and 'Public biography marker' in public and 'Writer Display' in public and 'writer@example.test' not in public and 'PrivateFirst' not in public and 'password_hash' not in public,'Public profile exposes intended fields only')
            data['display_name']='Stale writer name'
            _,body,_=writer.request('/wp-admin/profile.php',data)
            check('could not be saved' in body and dbrow(database,'SELECT display_name FROM users WHERE id=?',(uid,))['display_name']=='Writer Display' and 'Stale writer name' in body,'Stale profile revision is rejected and input retained')
            data['profile_revision']='2';data['website']='javascript:alert(1)'
            _,body,_=writer.request('/wp-admin/profile.php',data)
            check('could not be saved' in body and dbrow(database,'SELECT website FROM users WHERE id=?',(uid,))['website']=='https://example.test','Unsafe profile website rejected')
            data['website']='https://example.test';data['email']='changed@example.test'
            _,body,_=writer.request('/wp-admin/profile.php',data)
            check('could not be saved' in body and dbrow(database,'SELECT email FROM users WHERE id=?',(uid,))['email']=='writer@example.test','Email change requires current password')
            _,body,_=writer.request('/wp-admin/media.php')
            pixel=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
            boundary='profile-image-fixture'
            fields={'_wpnonce':token(body,action_field='upload'),'action':'upload'}
            payload=b''.join(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n').encode() for key,value in fields.items())
            payload+=('--'+boundary+'\r\nContent-Disposition: form-data; name="image"; filename="avatar.png"\r\nContent-Type: image/png\r\n\r\n').encode()+pixel+('\r\n--'+boundary+'--\r\n').encode()
            check(writer.request('/wp-admin/media.php',payload,{'Content-Type':'multipart/form-data; boundary='+boundary})[0]==302,'Author uploads a local profile image')
            image=dbrow(database,'SELECT * FROM media ORDER BY id DESC LIMIT 1')
            _,body,_=writer.request('/wp-admin/profile.php')
            status,body,_=writer.request('/wp-admin/profile.php',{'_wpnonce':token(body),'profile_revision':'2','avatar_media_id':image['id']})
            check(status==200 and 'Profile saved.' in body and 'author-avatar' in guest.request(author_path)[1],'Public author profile uses the selected local image')
            _,body,_=writer.request('/wp-admin/media.php')
            _,body,_=writer.request('/wp-admin/media.php',{'_wpnonce':token(body,action_field='upload'),'action':'delete','id':image['id'],'confirm_delete':'yes'})
            check('could not be changed' in body and dbrow(database,'SELECT id FROM media WHERE id=?',(image['id'],)) is not None,'Profile image references prevent media removal')
            check(writer.request('/wp-admin/edit-tags.php')[0]==403,'Authors cannot manage terms')
            check(admin.request('/wp-admin/edit-tags.php',{'name':'CSRF'})[0]==403,'Term mutations require CSRF protection')
            def term_create(taxonomy,name,slug,parent=0):
                path='/wp-admin/edit-tags.php?taxonomy='+taxonomy
                _,body,_=admin.request(path)
                status,body,_=admin.request(path,{'_wpnonce':token(body),'name':name,'slug':slug,'parent_id':parent})
                check(status==302,'Create term: '+name)
                return dbrow(database,'SELECT * FROM terms WHERE taxonomy=? AND slug=?',(taxonomy,slug))
            parent=term_create('category','Technology','technology')
            child=term_create('category','Cybersecurity','cybersecurity',parent['id'])
            tag=term_create('post_tag','Research','research')
            _,body,_=admin.request('/wp-admin/edit-tags.php?taxonomy=category&term='+str(parent['id']))
            _,body,_=admin.request('/wp-admin/edit-tags.php?taxonomy=category',{'_wpnonce':token(body),'id':parent['id'],'name':parent['name'],'slug':parent['slug'],'parent_id':child['id'],'expected_revision':parent['revision']})
            check('could not be saved' in body and dbrow(database,'SELECT parent_id FROM terms WHERE id=?',(parent['id'],))['parent_id'] is None,'Category hierarchy cycles are rejected atomically')
            _,body,_=writer.request('/wp-admin/post-new.php')
            post_data={'_wpnonce':token(body,'post-editor'),'post_type':'post','post_title':'Classified article','post_name':'classified-article','post_content':'<p>Published taxonomy marker</p>','post_status':'published','term_ids[]':[child['id'],tag['id']]}
            check(writer.request('/wp-admin/post-new.php',post_data)[0]==302,'Author assigns existing categories and tags while creating a Post')
            post=dbrow(database,"SELECT * FROM posts WHERE slug='classified-article'")
            check(dbrow(database,'SELECT COUNT(*) AS n FROM post_terms WHERE post_id=?',(post['id'],))['n']==2,'Assignments persist with content')
            for path in ['/category/technology/','/category/technology/cybersecurity/','/tag/research/',author_path]:
                status,body,headers=guest.request(path)
                check(status==200 and 'Published taxonomy marker' in body,'Archive includes published matching Posts: '+path)
            _,body,_=writer.request('/wp-admin/post-new.php')
            post_data.update({'_wpnonce':token(body,'post-editor'),'post_title':'Secret taxonomy draft','post_name':'secret-draft','post_content':'<p>Do not expose this draft</p>','post_status':'draft'})
            writer.request('/wp-admin/post-new.php',post_data)
            check('Do not expose this draft' not in guest.request('/tag/research/')[1] and 'Do not expose this draft' not in guest.request(author_path)[1],'Archives never expose assigned drafts')
            # A failed term selection rolls content and outbox back together.
            _,body,_=writer.request('/wp-admin/post.php?post='+str(post['id']))
            before=dbrow(database,'SELECT COUNT(*) AS n FROM job_queue')['n']
            update={'_wpnonce':token(body,'post-editor'),'ID':post['id'],'expected_revision':post['revision'],'post_title':'Should roll back','post_name':post['slug'],'post_content':post['content_raw'],'post_status':'published','terms_present':'1','term_ids[]':[999999]}
            _,body,_=writer.request('/wp-admin/post.php?post='+str(post['id']),update)
            check('could not be saved' in body and dbrow(database,'SELECT title FROM posts WHERE id=?',(post['id'],))['title']==post['title'] and dbrow(database,'SELECT COUNT(*) AS n FROM job_queue')['n']==before,'Invalid assignment rolls back content, revision and queued jobs')
            update.update({'post_title':post['title'],'term_ids[]':[parent['id']]})
            check(writer.request('/wp-admin/post.php?post='+str(post['id']),update)[0]==302,'Author can update classification')
            _,body,_=writer.request('/wp-admin/post.php?post='+str(post['id']))
            revision=dbrow(database,'SELECT id FROM post_revisions WHERE post_id=? AND revision=1',(post['id'],))
            check(writer.request('/wp-admin/post.php?post='+str(post['id']),{'_wpnonce':token(body,'post-editor'),'action':'restore','ID':post['id'],'expected_revision':'2','revision_id':revision['id']})[0]==302,'Revision restore succeeds with classifications')
            check(dbrow(database,'SELECT COUNT(*) AS n FROM post_terms WHERE post_id=? AND term_id IN (?,?)',(post['id'],child['id'],tag['id']))['n']==2,'Revision history restores category/tag assignments')
            _,body,_=admin.request('/wp-admin/options-permalink.php')
            admin.request('/wp-admin/options-permalink.php',{'_wpnonce':token(body),'permalink_choice':'custom','permalink_structure':'/%category%/%postname%/'})
            status,_,headers=guest.request('/classified-article/')
            check(status==301 and headers.get('Location')==base+'/technology/cybersecurity/classified-article/','Category permalink includes the validated hierarchy')
            check(guest.request('/technology/cybersecurity/classified-article/')[0]==200 and guest.request('/wrong/cybersecurity/classified-article/')[0]==404,'Correct category permalink renders; wrong ancestry is rejected')
            # Status transitions fence stale editors and cannot be bypassed by a normal save.
            current=dbrow(database,'SELECT * FROM posts WHERE id=?',(post['id'],))
            _,body,_=writer.request('/wp-admin/edit.php')
            writer.request('/wp-admin/edit.php',{'_wpnonce':token(body),'list_action':'trash','post_id':post['id']})
            check(dbrow(database,'SELECT revision FROM posts WHERE id=?',(post['id'],))['revision']==current['revision']+1,'Trash advances the revision fence')
            _,body,_=writer.request('/wp-admin/post.php?post='+str(post['id']))
            update.update({'_wpnonce':token(body,'post-editor'),'expected_revision':str(current['revision']+1),'post_title':'Bypass trash'})
            writer.request('/wp-admin/post.php?post='+str(post['id']),update)
            check(dbrow(database,'SELECT status FROM posts WHERE id=?',(post['id'],))['status']=='trashed','Normal editor save cannot bypass Trash restore')
            _,body,_=writer.request('/wp-admin/edit.php?view=trash')
            writer.request('/wp-admin/edit.php?view=trash',{'_wpnonce':token(body),'list_action':'restore','post_id':post['id']})
            check(dbrow(database,'SELECT status FROM posts WHERE id=?',(post['id'],))['status']=='published','Restore recovers the locked pre-trash status')
            _,body,_=admin.request('/wp-admin/edit-tags.php')
            admin.request('/wp-admin/edit-tags.php',{'_wpnonce':token(body),'action':'delete','confirm_delete':'yes','id':child['id'],'expected_revision':child['revision']})
            check(dbrow(database,"SELECT COUNT(*) AS n FROM post_terms pt JOIN terms t ON t.id=pt.term_id WHERE pt.post_id=? AND t.slug='uncategorized'",(post['id'],))['n']==1,'Deleting the last category assigns Uncategorized without deleting the Post')
            check(dbrow(database,'SELECT COUNT(*) AS n FROM post_terms WHERE post_id=? AND term_id=?',(post['id'],tag['id']))['n']==1,'Deleting a category preserves unrelated tags')
            _,body,_=admin.request('/wp-admin/edit-tags.php')
            default=dbrow(database,"SELECT * FROM terms WHERE slug='uncategorized' AND taxonomy='category'")
            _,body,_=admin.request('/wp-admin/edit-tags.php',{'_wpnonce':token(body),'action':'delete','confirm_delete':'yes','id':default['id'],'expected_revision':default['revision']})
            check('could not be deleted' in body and dbrow(database,'SELECT id FROM terms WHERE id=?',(default['id'],)) is not None,'Default category cannot be deleted')
            check(guest.request('/uncategorized/classified-article/')[0]==200,'Category deletion produces a valid fallback permalink')
            _,body,_=writer.request('/wp-admin/profile.php')
            writer.request('/wp-admin/profile.php',{'_wpnonce':token(body),'profile_fields':'1','public_profile':'0','profile_revision':'3'})
            check(guest.request(author_path)[0]==404,'An author can withdraw the public profile without removing published Posts')
            _,body,_=admin.request('/wp-admin/profile.php?user='+str(uid))
            status,body,_=admin.request('/wp-admin/profile.php?user='+str(uid),{'_wpnonce':token(body),'profile_revision':'4','display_name':'Admin edited display'})
            check(status==200 and 'Profile saved.' in body and dbrow(database,'SELECT display_name FROM users WHERE id=?',(uid,))['display_name']=='Admin edited display','Administrator can edit an existing user profile without changing credentials or role')
            with sqlite3.connect(database) as db:check(not db.execute('PRAGMA foreign_key_check').fetchall(),'No foreign key violations remain')
            print(h.COUNT,'profile/taxonomy HTTP checks passed.')
        finally:
            server.terminate();server.wait(timeout=5)
