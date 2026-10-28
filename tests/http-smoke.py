#!/usr/bin/env python3
"""Real publishing workflows and request boundaries on native PHP 8.4+."""
from pathlib import Path
from html.parser import HTMLParser
import base64
import hashlib
import http.cookiejar
import json
import os
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parent.parent
PHP = sys.argv[1] if len(sys.argv) > 1 else shutil.which('php')
COUNT = 0
class FormParser(HTMLParser):
    def __init__(self, text):
        super().__init__(); self.forms=[]; self.current=None; self.feed(text)
    def handle_starttag(self, tag, attrs):
        attrs=dict(attrs)
        if tag=='form': self.current={'attrs':attrs,'fields':{}}; self.forms.append(self.current)
        if tag=='input' and self.current is not None and 'name' in attrs: self.current['fields'][attrs['name']]=attrs.get('value','')
    def handle_endtag(self, tag):
        if tag=='form':self.current=None

def token(body, form_id=None, action_field=None):
    for form in FormParser(body).forms:
        if form_id and form['attrs'].get('id')!=form_id:continue
        if action_field and form['fields'].get('action')!=action_field:continue
        if not form_id and not action_field and form['fields'].get('action')=='logout':continue
        if '_wpnonce' in form['fields']:return form['fields']['_wpnonce']
    raise AssertionError('No matching CSRF form found')

def check(ok, name):
    global COUNT
    if not ok:raise AssertionError(name)
    COUNT+=1; print('PASS:',name,flush=True)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None
class Client:
    def __init__(self,base):
        self.base=base;self.jar=http.cookiejar.CookieJar();self.open=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(self.jar),NoRedirect())
    def request(self,path,data=None,headers=None):
        if isinstance(data,dict):data=urllib.parse.urlencode(data,doseq=True).encode()
        req=urllib.request.Request(self.base+path,data=data,headers=headers or {})
        try:r=self.open.open(req,timeout=10)
        except urllib.error.HTTPError as e:r=e
        return r.status,r.read().decode('utf8','replace'),dict(r.headers)
    def login(self,name,password='disposable-test-password'):
        _,body,_=self.request('/wp-admin/login.php')
        return self.request('/wp-admin/login.php',{'log':name,'pwd':password,'_wpnonce':token(body)})
    def cookie(self):return next((c.value for c in self.jar if c.name=='wp_session_token'),'')

def cli(site,env,*args):
    p=subprocess.run([PHP,str(site/'bin/wp'),*args],env=env,cwd=site,capture_output=True,text=True,timeout=30)
    if p.returncode:raise AssertionError(p.stdout+p.stderr)
    return p.stdout

def dbrow(path,sql,params=()):
    with sqlite3.connect(path) as db:
        db.row_factory=sqlite3.Row;r=db.execute(sql,params).fetchone();return dict(r) if r else None

with tempfile.TemporaryDirectory(prefix='wp-release-http-') as temp_name:
    temp=Path(temp_name);site=temp/'site';storage=temp/'runtime'
    shutil.copytree(ROOT,site,ignore=shutil.ignore_patterns('vendor','__pycache__','*.sqlite*','auth.key','maintenance.json'))
    env=dict(os.environ,WP_ENV='development',WP_VAR_DIR=str(storage),WP_DB_PATH=str(storage/'data/site.sqlite'))
    env.pop('WP_AUTH_SALT',None)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}';database=storage/'data/site.sqlite'
    with (temp/'server.log').open('w') as log:
        server=subprocess.Popen([PHP,'-d','display_errors=0','-d','log_errors=1','-S',f'127.0.0.1:{port}','-t',str(site),str(site/'tools/router.php')],env=env,stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.03)
            admin=Client(base)
            status,_,headers=admin.request('/')
            check(status==302 and headers.get('Location')=='wp-admin/install.php','Blank root routes to installer')
            status,body,_=admin.request('/wp-admin/install.php')
            check(status==200 and 'Create your site' in body,'Blank installer loads')
            form={'site_title':'Publishing Test','admin_user':'admin','admin_email':'admin@example.test','admin_password':'disposable-test-password'}
            status,_,_=admin.request('/wp-admin/install.php',form)
            check(status==403 and not database.exists(),'Installer refuses a missing CSRF token')
            status,body,_=admin.request('/wp-admin/install.php');form['_wpnonce']=token(body)
            status,body,_=admin.request('/wp-admin/install.php',form)
            check(status==200 and 'Installation complete' in body,'Blank installation completes')
            check(len((storage/'auth.key').read_text())==64 and ((storage/'auth.key').stat().st_mode&0o777)==0o600,'A unique private site secret is generated without a shared fallback')
            check(database.exists() and not (site/'var/data/site.sqlite').exists(),'Custom runtime storage is respected')
            status,_,_=admin.request('/wp-admin/login.php',{'log':'admin','pwd':'disposable-test-password'})
            check(status==403,'Login refuses a missing CSRF token')
            status,_,headers=admin.login('admin')
            check(status==302 and len(admin.cookie())==64,'Login creates an opaque session token')
            check(dbrow(database,'SELECT COUNT(*) AS n FROM user_sessions')['n']==1 and dbrow(database,'SELECT token_hash FROM user_sessions')['token_hash']!=admin.cookie(),'Only session hashes are stored')
            status,body,headers=admin.request('/wp-admin/index.php')
            check(status==200 and headers.get('X-Frame-Options')=='DENY' and "script-src 'self'" in headers.get('Content-Security-Policy',''),'Authenticated shell sends security headers')
            status,_,_=admin.request('/wp-admin/login.php?action=logout')
            check(status==405,'Logout is not a state-changing GET')
            status,body,_=admin.request('/wp-admin/post-new.php')
            before=dbrow(database,'SELECT COUNT(*) AS n FROM posts')['n']
            status,_,_=admin.request('/wp-admin/post-new.php',{'post_title':'CSRF attempt','post_status':'published'})
            check(status==403 and dbrow(database,'SELECT COUNT(*) AS n FROM posts')['n']==before,'Content creation refuses a missing CSRF token')
            status,body,_=admin.request('/wp-admin/post-new.php?post_type=page')
            page_form={'_wpnonce':token(body,'post-editor'),'post_type':'page','post_title':'Private draft title','post_name':'private-draft','post_content':'<p>Private body marker</p>','post_status':'draft'}
            status,_,headers=admin.request('/wp-admin/post-new.php?post_type=page',page_form)
            check(status==302,'Page creation succeeds through the authenticated form')
            private_id=dbrow(database,"SELECT id FROM posts WHERE slug='private-draft'")['id']
            status,body,_=admin.request('/wp-admin/users.php')
            for name,role in [('writer','author'),('pagewriter','page_editor')]:
                data={'_wpnonce':token(body),'username':name,'email':name+'@example.test','password':'disposable-test-password','role':role}
                status,body,_=admin.request('/wp-admin/users.php',data)
                check(status==200 and 'Account saved.' in body,'Administrator creates '+role+' account')
            writer=Client(base);check(writer.login('writer')[0]==302,'Author account can log in')
            status,body,_=writer.request('/wp-admin/post.php?post='+str(private_id))
            check(status==403 and 'Private body marker' not in body and 'Private draft title' not in body,'Other authors cannot read draft title or body')
            status,body,_=writer.request('/wp-admin/post.php?post='+str(private_id)+'&editor=visual')
            check(status==403 and 'Private body marker' not in body,'Visual builder preserves draft read isolation')
            check(writer.request('/wp-admin/post-new.php?post_type=page&editor=visual')[0]==403,'Visual builder does not grant Page capabilities to Post authors')
            status,body,_=admin.request('/wp-admin/post.php?post='+str(private_id)+'&editor=visual')
            check(status==200 and 'data-editor-view="visual"' in body and '&amp;editor=visual' in body,'Visual builder form retains the dedicated view in its save action')
            status,_,_=admin.request('/wp-admin/post.php?post='+str(private_id)+'&editor=visual',{'ID':private_id,'post_title':'Forged canvas change'})
            check(status==403,'Visual builder saves still require the action-bound CSRF token')
            nested={'version':1,'blocks':[{'type':'columns','columns':[[{'type':'columns','columns':[[{'type':'paragraph','html':'Nested preview marker'}],[]]}],[]]}]}
            status,body,_=admin.request('/wp-admin/block-preview.php',{'_wpnonce':'', 'content':json.dumps(nested)})
            check(status==403,'Canvas rendering refuses a missing preview nonce')
            _,body,_=admin.request('/wp-admin/post.php?post='+str(private_id)+'&editor=visual')
            preview_nonce=re.search(r'id="block-preview-nonce" value="([^"]+)"',body).group(1)
            status,body,_=admin.request('/wp-admin/block-preview.php',{'_wpnonce':preview_nonce,'content':json.dumps(nested)})
            tree=json.loads(body)['blocks']
            check(status==200 and 'Nested preview marker' in tree[0]['columns'][0][0]['columns'][0][0]['html'],'Public renderer previews every nested column boundary')
            check(writer.request('/wp-admin/post-new.php?post_type=page')[0]==403,'Post roles cannot create Pages')
            check(writer.request('/wp-admin/options-general.php')[0]==403,'Nonadministrators cannot access settings')
            status,body,_=writer.request('/wp-admin/edit.php?post_type=post')
            check(status==200 and 'Hello World!' not in body,'Author listings exclude other authors content')
            pagewriter=Client(base);check(pagewriter.login('pagewriter')[0]==302,'Page editor can log in')
            check(pagewriter.request('/wp-admin/post-new.php')[0]==403,'Page roles do not implicitly gain Post rights')
            status,body,_=pagewriter.request('/wp-admin/post-new.php?post_type=page')
            status,_,_=pagewriter.request('/wp-admin/post-new.php?post_type=page',{'_wpnonce':token(body,'post-editor'),'post_type':'page','post_title':'Public Page','post_name':'public-page','post_content':'<p>Public body</p>','post_status':'published'})
            check(status==302,'Page editor publishes their own Page')
            public=dbrow(database,"SELECT * FROM posts WHERE slug='public-page'")
            status,body,_=admin.request('/public-page/')
            check(status==200 and 'Public body' in body and 'page-entry' in body,'Pretty Page route selects page template')
            for path in ['/missing-route/','/atom/','/wp-json/']:
                check(admin.request(path)[0]==404,'Unknown route has a real 404: '+path)
            status,_,headers=admin.request('/?slug=public-page')
            check(status==301 and headers.get('Location')==base+'/public-page/','Legacy slug URL redirects to canonical route')
            status,body,_=admin.request('/wp-admin/post.php?post='+str(public['id']))
            edit={'_wpnonce':token(body,'post-editor'),'ID':public['id'],'expected_revision':1,'post_title':'Updated Page','post_name':'public-page','post_content':'<p>Fresh body marker</p>','post_status':'published'}
            check(admin.request('/wp-admin/post.php?post='+str(public['id']),edit)[0]==302,'Editor update succeeds')
            edit['post_title']='Unsaved conflict title';edit['post_content']='<p>Retain my conflict input</p>'
            status,body,_=admin.request('/wp-admin/post.php?post='+str(public['id']),edit)
            check(status==200 and 'Retain my conflict input' in body and 'Unsaved conflict title' in body and 'another session' in body,'Conflict response preserves submitted title and body')
            status,body,_=admin.request('/public-page/')
            check(status==200 and 'Fresh body marker' in body,'Stale materialized content falls back to current blocks')
            status,body,_=admin.request('/wp-admin/post.php?post='+str(public['id']))
            revision=dbrow(database,'SELECT id FROM post_revisions WHERE post_id=?',(public['id'],))['id']
            status,_,_=admin.request('/wp-admin/post.php?post='+str(public['id']),{'_wpnonce':token(body,'post-editor'),'action':'restore','ID':public['id'],'expected_revision':2,'revision_id':revision})
            check(status==302 and dbrow(database,'SELECT revision,title FROM posts WHERE id=?',(public['id'],))=={'revision':3,'title':'Public Page'},'Restore creates a new revision from history')
            status,body,_=admin.request('/wp-admin/media.php')
            boundary='wp-test-boundary';pixel=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
            def multipart(filename,payload,mime,nonce):
                return (f'--{boundary}\r\nContent-Disposition: form-data; name="_wpnonce"\r\n\r\n{nonce}\r\n--{boundary}\r\nContent-Disposition: form-data; name="action"\r\n\r\nupload\r\n--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode()+payload+f'\r\n--{boundary}--\r\n'.encode())
            headers={'Content-Type':'multipart/form-data; boundary='+boundary}
            status,body2,_=admin.request('/wp-admin/media.php',multipart('bad.png',b'<?php echo 1; ?>','image/png',token(body,action_field='upload')),headers)
            check(status==200 and 'Use a complete' in body2 and dbrow(database,'SELECT COUNT(*) AS n FROM media')['n']==0,'Forged image upload is rejected by content inspection')
            status,_,_=admin.request('/wp-admin/media.php',multipart('pixel.png',pixel,'image/png',token(body,action_field='upload')),headers)
            check(status==302 and dbrow(database,"SELECT COUNT(*) AS n FROM media WHERE variant=''")['n']==1,'Raster upload is stored in the media library')
            media=dbrow(database,'SELECT * FROM media');check(admin.request('/wp-content/uploads/'+media['filename'])[0]==200,'Immutable image is publicly readable')
            status,body,_=admin.request('/wp-admin/themes.php')
            design={'design_action':'publish','expected_version':'0','_wpnonce':token(body),'active_theme':'studio','accent':'#195b72','background':'#ffffff','width':'1080','font':'serif','homepage_id':public['id'],'navigation[]':[public['id']],'site_logo_id':media['id']}
            status,body,_=admin.request('/wp-admin/themes.php',design)
            check(status==200 and 'Site design published.' in body,'Theme and design settings persist')
            status,body,_=admin.request('/')
            check(status==200 and '/themes/studio/style.css' in body and 'Public Page' in body and '--content-width:1080px' in body,'Homepage, theme, navigation, and design tokens affect public rendering')
            check(admin.request('/?s=Welcome')[0]==200 and admin.request('/blog/?page=999')[0]==404,'Search is implemented and invalid pagination is a 404')
            before_title=dbrow(database,"SELECT option_value FROM options WHERE option_name='site_title'")['option_value']
            status,_,_=admin.request('/wp-admin/options-general.php',{'site_title':'CSRF settings'})
            check(status==403 and dbrow(database,"SELECT option_value FROM options WHERE option_name='site_title'")['option_value']==before_title,'Settings reject a missing CSRF token')
            status,body,_=admin.request('/wp-admin/options-general.php')
            settings={'_wpnonce':token(body),'site_title':'Updated Site','site_tagline':'Tagline','site_url':base,'admin_email':'admin@example.test','default_status':'draft'}
            status,body,_=admin.request('/wp-admin/options-general.php',settings)
            check(status==200 and 'Settings saved.' in body,'Validated raw settings save normally')
            settings['site_url']=base+'/?a=1&b=2'
            status,body,_=admin.request('/wp-admin/options-general.php',settings)
            check(status==200 and 'without credentials, a query' in body and dbrow(database,"SELECT option_value FROM options WHERE option_name='site_url'")['option_value']==base,'Site address validation prevents query/entity corruption')
            cli(site,env,'queue:work','200')
            check(dbrow(database,"SELECT COUNT(*) AS n FROM job_queue WHERE status='pending'")['n']==0 and dbrow(database,'SELECT COUNT(*) AS n FROM posts WHERE rendered_revision<revision')['n']==0,'CLI worker drains jobs and refreshes rendered revisions')
            cli(site,env,'backup');check(len(list((storage/'backups').glob('*.sqlite')))==1,'Operator command creates a database backup')
            archive=next((storage/'backups').glob('*.tar'))
            with tarfile.open(archive) as backup:
                snapshot=temp/'restored.sqlite';snapshot.write_bytes(backup.extractfile('site.sqlite').read())
                restored=dbrow(snapshot,'SELECT title FROM posts WHERE id=?',(public['id'],))
                assets=backup.extractfile('uploads/'+media['filename']).read()
                check(restored['title']=='Public Page' and hashlib.sha256(assets).hexdigest()==media['sha256'] and len(backup.extractfile('auth.key').read())==64 and (archive.stat().st_mode&0o777)==0o600,'Backup archive restores readable database, matching image bytes, and private site key')
            status,body,_=admin.request('/wp-admin/media.php')
            status,body,_=admin.request('/wp-admin/media.php',{'_wpnonce':token(body,action_field='upload'),'action':'delete','id':media['id'],'confirm_delete':'yes'})
            check(status==200 and 'could not be changed' in body and dbrow(database,'SELECT id FROM media WHERE id=?',(media['id'],)) is not None,'Site-logo references prevent media library removal')
            second=Client(base);check(second.login('pagewriter')[0]==302,'A second session can be established for profile controls')
            status,body,_=pagewriter.request('/wp-admin/profile.php')
            status,body,_=pagewriter.request('/wp-admin/profile.php',{'_wpnonce':token(body),'action':'revoke'})
            check(status==200 and 'Other sessions were revoked' in body and second.request('/wp-admin/edit.php?post_type=page')[0]==302,'Profile revokes other sessions while preserving the current session')
            old_cookie=pagewriter.cookie();status,body,_=pagewriter.request('/wp-admin/profile.php')
            status,_,_=pagewriter.request('/wp-admin/profile.php',{'_wpnonce':token(body),'email':'pagewriter@example.test','current_password':'disposable-test-password','password':'new-disposable-password'})
            check(status==302 and Client(base).request('/wp-admin/profile.php',headers={'Cookie':'wp_session_token='+old_cookie})[0]==302 and pagewriter.login('pagewriter','new-disposable-password')[0]==302,'Password change revokes old cookies and accepts the new password')
            replay=admin.cookie();status,body,_=admin.request('/wp-admin/index.php')
            status,_,_=admin.request('/wp-admin/login.php',{'action':'logout','_wpnonce':token(body,action_field='logout')})
            anonymous=Client(base);status,_,_=anonymous.request('/wp-admin/edit.php',headers={'Cookie':'wp_session_token='+replay})
            check(status==302,'Logged-out cookie cannot be replayed')
            writer_token=writer.cookie()
            with sqlite3.connect(database) as db:db.execute('UPDATE user_sessions SET expires_at=0 WHERE token_hash=?',(hashlib.sha256(writer_token.encode()).hexdigest(),))
            check(writer.request('/wp-admin/edit.php')[0]==302,'Server-side session expiration is enforced')
            throttle=Client(base)
            for _ in range(6):status,body,_=throttle.login('nonexistent','wrong')
            check(status==429 and 'Too many login attempts' in body,'Repeated login attempts are throttled with HTTP 429')
            (storage/'maintenance.json').write_text('{"token":"test"}')
            check(anonymous.request('/')[0]==503,'Maintenance seals public requests')
            (storage/'maintenance.json').unlink()
            check(all(anonymous.request(path)[0]==404 for path in ['/.env','/.git/config','/var/auth.key','/docs/INSTALL.md']), 'Development router does not expose private files')
            print(str(COUNT)+' HTTP checks passed.')
        finally:
            server.terminate();server.wait(timeout=5)
            log.flush()
            text=(temp/'server.log').read_text()
            errors=[line for line in text.splitlines() if 'Fatal error' in line or 'Uncaught ' in line]
            if errors:raise AssertionError('\n'.join(errors))
