#!/usr/bin/env python3
"""Design draft/publication boundaries and approved public file editing."""
from http_helpers import *
import http_helpers as h
import html
with tempfile.TemporaryDirectory(prefix='wp-design-') as directory:
    temp=Path(directory);site=temp/'site';database=temp/'state/site.sqlite'
    shutil.copytree(ROOT,site,ignore=shutil.ignore_patterns('vendor','node_modules','__pycache__','var'))
    env=dict(os.environ,WP_ENV='development',WP_VAR_DIR=str(temp/'state'),WP_DB_PATH=str(database),WP_PHP_CLI=PHP);env.pop('WP_AUTH_SALT',None)
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
            _,body,_=admin.request('/wp-admin/install.php');admin.request('/wp-admin/install.php',{'_wpnonce':token(body),'site_title':'Design test','admin_user':'admin','admin_email':'admin@example.test','admin_password':'disposable-test-password'})
            check(admin.login('admin')[0]==302,'Administrator logs in to design workflow')
            check(dbrow(database,"SELECT option_value FROM options WHERE option_name='schema_version'")['option_value']=='8','Blank install creates account schema 8')
            with sqlite3.connect(database) as db:
                db.execute('DROP TABLE design_revisions');db.execute('DROP TABLE managed_file_versions');db.execute("UPDATE options SET option_value='5' WHERE option_name='schema_version'")
            check(guest.request('/')[0]==503,'Existing schema 5 site stays sealed until upgrade')
            upgraded=subprocess.run([PHP,str(site/'bin/wp'),'upgrade'],env=env,capture_output=True,text=True)
            check(upgraded.returncode==0 and dbrow(database,"SELECT option_value FROM options WHERE option_name='schema_version'")['option_value']=='8','Upgrade reaches schema 8 with design and managed-file tables intact')
            check(guest.request('/')[0]==200 and dbrow(database,"SELECT option_value FROM options WHERE option_name='active_theme'")['option_value']=='default','Upgrade preserves existing live theme')
            # Upgrade revokes sessions by policy.
            admin.login('admin')
            _,body,_=admin.request('/wp-admin/themes.php');nonce=token(body)
            data={'_wpnonce':nonce,'expected_version':'0','design_action':'draft','active_theme':'studio','accent':'#195b72','background':'#ffffff','width':'1080','font':'serif','body_size':'18','spacing':'40','homepage_id':'0','site_logo_id':'0','section_header':'<p>Shared header marker</p>','menu[header][0][label]':'Home','menu[header][0][url]':'/','menu[header][0][level]':'1','menu[header][0][order]':'10','menu[header][1][label]':'Sections','menu[header][1][url]':'/blog/','menu[header][1][level]':'1','menu[header][1][order]':'20','menu[header][2][label]':'Journal','menu[header][2][url]':'/blog/','menu[header][2][level]':'2','menu[header][2][order]':'30','menu[footer][0][label]':'Contact','menu[footer][0][url]':'https://example.test/contact','menu[footer][0][level]':'1','menu[footer][0][order]':'10'}
            status,body,_=admin.request('/wp-admin/themes.php',data)
            check(status==200 and 'Design draft saved.' in body,'Save design creates a draft')
            check(dbrow(database,"SELECT option_value FROM options WHERE option_name='active_theme'")['option_value']=='default','Saving draft leaves live theme unchanged')
            match=re.search(r'href="([^"]*design_preview[^"]*)"',body);assert match,body[-4000:];preview=html.unescape(match.group(1)).replace(base,'')
            draft_id=dbrow(database,'SELECT id FROM design_revisions ORDER BY id DESC LIMIT 1')['id']
            check('Shared header marker' in admin.request('/wp-admin/themes.php?draft='+str(draft_id))[1],'Saved draft can be reopened without publishing it')
            check(guest.request(preview)[0]==404,'Anonymous preview requests reveal no draft')
            status,public,headers=admin.request(preview)
            check(status==200 and '/themes/studio/style.css' in public and 'Shared header marker' in public,'Signed-in preview renders draft theme and shared section')
            check('no-store' in headers.get('Cache-Control','') and 'noindex' in headers.get('X-Robots-Tag',''),'Preview sends private cache and indexing controls')
            check('Shared header marker' not in guest.request('/')[1],'Preview cannot affect anonymous output')
            data['design_action']='publish';status,body,_=admin.request('/wp-admin/themes.php',data)
            check(status==200 and 'Site design published.' in body,'Publication passes isolated theme health check')
            _,public,_=guest.request('/')
            check('/themes/studio/style.css' in public and 'Shared header marker' in public and 'Footer navigation' in public and 'https://example.test/contact' in public,'Published theme, shared sections and both menus reach public output')
            check('body{font-size:18px}' in public,'Typed typography setting reaches public output')
            before=dbrow(database,'SELECT COUNT(*) AS n FROM design_revisions')['n'];status,body,_=admin.request('/wp-admin/themes.php',data)
            check('Design changed' in body and dbrow(database,'SELECT COUNT(*) AS n FROM design_revisions')['n']==before,'Stale designer cannot overwrite or add a revision')
            data['expected_version']='1';data['active_theme']='../default';_,body,_=admin.request('/wp-admin/themes.php',data)
            check('valid installed theme' in body,'Theme path traversal is rejected')
            data['active_theme']='default';data['menu[header][0][label]']='Bad';data['menu[header][0][url]']='javascript:alert(1)';data['menu[header][0][level]']='1';data['menu[header][1][label]']='';data['menu[header][1][url]']='';data['menu[header][2][label]']='';data['menu[header][2][url]']='';_,body,_=admin.request('/wp-admin/themes.php',data)
            check('Navigation addresses' in body,'Unsafe external navigation is rejected')
            data['menu[header][0][label]']='Deep';data['menu[header][0][url]']='/';data['menu[header][0][level]']='3';_,body,_=admin.request('/wp-admin/themes.php',data);check('starting at level 1' in body,'Invalid menu nesting is rejected')
            pixel=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');digest=hashlib.sha256(pixel).hexdigest();filename=digest[:2]+'/'+digest+'.png';asset_path=site/'wp-content/uploads'/filename;asset_path.parent.mkdir(parents=True,exist_ok=True);asset_path.write_bytes(pixel)
            with sqlite3.connect(database) as db:db.execute("INSERT INTO media(id,author_id,sha256,filename,mime,original_name,width,height,size,format) VALUES(901,1,?,?,'image/png','composition.png',1,1,?,'png')",(digest,filename,len(pixel)))
            section_data=dict(data,active_theme='studio',design_action='draft',section_header=json.dumps({'version':1,'blocks':[{'type':'image','media_id':901}]}))
            for _i in range(3): section_data['menu[header]['+str(_i)+'][label]']='';section_data['menu[header]['+str(_i)+'][url]']=''
            _,body,_=admin.request('/wp-admin/themes.php',section_data)
            image_draft=dbrow(database,'SELECT id FROM design_revisions WHERE published=0 ORDER BY id DESC LIMIT 1')['id']
            check('Design draft saved' in body,'Shared section accepts a ready image reference')
            _,body,_=admin.request('/wp-admin/media.php');admin.request('/wp-admin/media.php',{'_wpnonce':token(body),'action':'delete','id':'901','confirm_delete':'yes'})
            check(dbrow(database,'SELECT COUNT(*) AS n FROM media WHERE id=901')['n']==1,'Shared-section draft prevents image deletion')
            _,body,_=admin.request('/wp-admin/themes.php');status,_,_=admin.request('/wp-admin/themes.php',{'_wpnonce':token(body),'design_action':'discard','draft_id':str(image_draft)})
            check(status==302,'Discarding a shared-section draft removes its reference')
            _,body,_=admin.request('/wp-admin/media.php');admin.request('/wp-admin/media.php',{'_wpnonce':token(body),'action':'delete','id':'901','confirm_delete':'yes'})
            check(dbrow(database,'SELECT COUNT(*) AS n FROM media WHERE id=901')['n']==0,'Unused image can be removed after draft discard')
            data['menu[header][0][label]']='';data['menu[header][0][url]']='';data['menu[header][1][label]']='';data['menu[header][1][url]']='';data['menu[header][2][label]']='';data['menu[header][2][url]']='';data['section_header']='<p>Unhealthy theme should not publish</p>'
            old=(site/'wp-content/themes/default/page-wide.php').read_text();(site/'wp-content/themes/default/page-wide.php').write_text('<?php throw new RuntimeException("broken template");')
            _,body,_=admin.request('/wp-admin/themes.php',data)
            check('health check failed' in body and dbrow(database,"SELECT option_value FROM options WHERE option_name='active_theme'")['option_value']=='studio','Broken template fails isolated health check and preserves live theme')
            title_before=dbrow(database,"SELECT option_value FROM options WHERE option_name='site_title'")['option_value']
            (site/'wp-content/themes/default/page-wide.php').write_text("<?php $GLOBALS['wpdb']->query(\"UPDATE options SET option_value='probe-only' WHERE option_name='site_title'\"); throw new RuntimeException('probe failure');")
            admin.request('/wp-admin/themes.php',data)
            check(dbrow(database,"SELECT option_value FROM options WHERE option_name='site_title'")['option_value']==title_before and not list((temp/'state').glob('theme-probe-*')),'Failed health probe writes only its private database snapshot and cleans it up')
            (site/'wp-content/themes/default/page-wide.php').write_text(old)
            restore=dbrow(database,'SELECT id FROM design_revisions WHERE published=1 ORDER BY id ASC LIMIT 1')['id'];_,body,_=admin.request('/wp-admin/themes.php')
            status,body,_=admin.request('/wp-admin/themes.php',{'_wpnonce':token(body),'expected_version':'1','design_action':'restore','restore_revision':str(restore)})
            check(status==302 and dbrow(database,"SELECT option_value FROM options WHERE option_name='design_version'")['option_value']=='2' and dbrow(database,"SELECT option_value FROM options WHERE option_name='active_theme'")['option_value']=='default','Design restore publishes history as another version')
            _,body,_=admin.request('/wp-admin/post-new.php?post_type=page');status,_,_=admin.request('/wp-admin/post-new.php?post_type=page',{'_wpnonce':token(body),'post_title':'Wide template page','post_content':'<p>Wide body</p>','post_status':'published','page_template':'wide'})
            page=dbrow(database,"SELECT * FROM posts WHERE title='Wide template page'")
            check(status==302 and json.loads(page['metadata'])['page_template']=='wide' and 'template-wide' in guest.request('/wide-template-page/')[1],'Page template assignment persists and renders')
            check(guest.request('/wp-admin/file-editor.php')[0] in [302,403],'File editor requires authentication')
            check(admin.request('/wp-admin/file-editor.php?file=../../wp-config.php')[0]==404,'File editor rejects unapproved paths')
            _,body,_=admin.request('/wp-admin/file-editor.php');filedata={'_wpnonce':token(body),'expected_version':'0','file_action':'publish','content':'.entry-title { color: #195b72; }'}
            check(admin.request('/wp-admin/file-editor.php',{'content':'body {}'})[0]==403,'File publication enforces CSRF')
            status,_,_=admin.request('/wp-admin/file-editor.php',filedata);check(status==302,'Approved CSS publishes')
            status,text,headers=guest.request('/site-assets/custom.css');check(status==200 and text==filedata['content'] and 'text/css' in headers.get('Content-Type','') and headers.get('X-Content-Type-Options')=='nosniff','Managed stylesheet uses fixed MIME and nosniff')
            check('/site-assets/custom.css' in guest.request('/')[1],'Published stylesheet is linked by the theme')
            first=dbrow(database,"SELECT id FROM managed_file_versions WHERE filename='custom.css'")['id'];status,body,_=admin.request('/wp-admin/file-editor.php',filedata)
            check('File changed' in body and dbrow(database,'SELECT COUNT(*) AS n FROM managed_file_versions')['n']==1,'Stale file edit preserves live bytes and submitted input')
            for bad in ['@import "https://tracker.example";','body { background-color: url(https://tracker.example); }','.entry-title { color: \\72 ed; }','</style><script>alert(1)</script>']:
                _,body,_=admin.request('/wp-admin/file-editor.php');attack=dict(filedata,_wpnonce=token(body),expected_version=str(first),content=bad);admin.request('/wp-admin/file-editor.php',attack)
                check(guest.request('/site-assets/custom.css')[1]==filedata['content'],'Unsafe stylesheet cannot replace published bytes: '+bad[:18])
            _,body,_=admin.request('/wp-admin/file-editor.php');status,_,_=admin.request('/wp-admin/file-editor.php',dict(filedata,_wpnonce=token(body),expected_version=str(first),content='body { color: #111111; }'))
            latest=dbrow(database,"SELECT option_value FROM options WHERE option_name='managed_file:custom.css'")['option_value'];_,body,_=admin.request('/wp-admin/file-editor.php')
            status,_,_=admin.request('/wp-admin/file-editor.php',{'_wpnonce':token(body),'expected_version':latest,'file_action':'restore','revision_id':str(first)})
            check(status==302 and guest.request('/site-assets/custom.css')[1]==filedata['content'] and dbrow(database,'SELECT COUNT(*) AS n FROM managed_file_versions')['n']==3,'File restore creates a new version with original bytes')
            _,body,_=admin.request('/wp-admin/file-editor.php?file=site-notice.txt');status,_,_=admin.request('/wp-admin/file-editor.php?file=site-notice.txt',{'_wpnonce':token(body),'expected_version':'0','file_action':'publish','content':'Public maintenance notice'})
            check(status==302 and guest.request('/site-assets/site-notice.txt')[1]=='Public maintenance notice','Approved public text file publishes separately')
            check([]==[dict(x) for x in sqlite3.connect(database).execute('PRAGMA foreign_key_check')],'Design and managed-file operations preserve foreign keys')
            _,body,_=admin.request('/wp-admin/themes.php')
            own_draft=dbrow(database,'SELECT id FROM design_revisions WHERE published=0 ORDER BY id DESC LIMIT 1')['id']
            status,_,_=admin.request('/wp-admin/themes.php',{'_wpnonce':token(body),'design_action':'discard','draft_id':str(own_draft)})
            check(status==302 and admin.request('/wp-admin/themes.php?draft='+str(own_draft))[0]==404,'Unpublished draft can be discarded and cannot be reopened')
            current_version=int(dbrow(database,"SELECT option_value FROM options WHERE option_name='design_version'")['option_value']);publish_id=dbrow(database,'SELECT id FROM design_revisions WHERE published=1 ORDER BY id DESC LIMIT 1')['id']
            racer=temp/'publish-race.php';racer.write_text("<?php require $argv[1];wp_set_current_user(1);try{wp_design_publish((int)$argv[2],(int)$argv[3]);echo 'winner';}catch(Throwable $error){echo 'rejected';}")
            processes=[subprocess.Popen([PHP,str(racer),str(site/'wp-includes/bootstrap.php'),str(publish_id),str(current_version)],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(2)]
            outcomes=[]
            for process in processes:
                out,err=process.communicate(timeout=20);assert process.returncode==0,(out,err);outcomes.append(out)
            check(sorted(outcomes)==['rejected','winner'] and int(dbrow(database,"SELECT option_value FROM options WHERE option_name='design_version'")['option_value'])==current_version+1,'Two concurrent design publications produce exactly one winner')
            # A corrupt manifest must fall back without breaking the live public page.
            (site/'wp-content/themes/studio/theme.json').write_text('{invalid')
            with sqlite3.connect(database) as db:db.execute("UPDATE options SET option_value='studio' WHERE option_name='active_theme'")
            check(guest.request('/')[0]==200 and '/themes/default/style.css' in guest.request('/')[1],'Invalid active manifest falls back to bundled default')
            backup=subprocess.run([PHP,str(site/'bin/wp'),'backup'],env=env,capture_output=True,text=True);assert backup.returncode==0,backup.stderr
            archive=Path(backup.stdout.strip().removeprefix('Backup: '))
            with tarfile.open(archive) as tar:
                snapshot=temp/'restored.sqlite';snapshot.write_bytes(tar.extractfile('site.sqlite').read())
                with sqlite3.connect(snapshot) as restored:check(restored.execute('SELECT COUNT(*) FROM managed_file_versions').fetchone()[0]>=4 and restored.execute('SELECT COUNT(*) FROM design_revisions').fetchone()[0]>0,'Backup snapshot preserves design and managed-file histories')
            print(str(h.COUNT)+' design/file HTTP checks passed.')
        finally:
            server.terminate();server.wait(timeout=5)
            errors=(temp/'server.log').read_text()
            if 'Fatal error' in errors:print(errors[-5000:])
