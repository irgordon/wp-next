#!/usr/bin/env python3
"""Regression tests for token revocation and author-dependent File URLs from a blank install."""
from pathlib import Path
import sys,os,subprocess,socket,time,shutil,sqlite3,json,hashlib
source=Path(__file__).resolve().parent.parent;sys.path.insert(0,str(source/'tests'))
import http_helpers as h
php=sys.argv[1] if len(sys.argv)>1 else os.environ.get('PHP_BINARY','php');h.PHP=php
import tempfile
fixture=tempfile.TemporaryDirectory(prefix='wp-account-boundaries-');root=Path(fixture.name);site=root/'site';shutil.copytree(source,site,ignore=shutil.ignore_patterns('vendor','node_modules','var','__pycache__'));dbpath=root/'state/site.sqlite'
env=dict(os.environ,WP_ENV='development',WP_VAR_DIR=str(root/'state'),WP_DB_PATH=str(dbpath));env.pop('WP_AUTH_SALT',None)
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
log=(root/'server.log').open('w');server=subprocess.Popen([php,'-d','display_errors=0','-S',f'127.0.0.1:{port}','-t',str(site),str(site/'tools/router.php')],env=env,stdout=log,stderr=log)
results=[]
def check(name,ok,details=None):
 r={'name':name,'passed':bool(ok),'details':details};results.append(r);print(('PASS: ' if ok else 'FAIL: ')+name+(' '+json.dumps(details) if details else ''),flush=True)
def sql(q,p=()):
 with sqlite3.connect(dbpath) as db:db.row_factory=sqlite3.Row;c=db.execute(q,p);return [dict(r) for r in c.fetchall()]
def option(k,v):sql('INSERT INTO options(option_name,option_value) VALUES(?,?) ON CONFLICT(option_name) DO UPDATE SET option_value=excluded.option_value',(k,v))
def upload(client,name,data,visibility='public'):
 _,page,_=client.request('/wp-admin/media.php?kind=documents');boundary='independent-boundary';fields={'_wpnonce':h.token(page,action_field='upload_document'),'action':'upload_document','visibility':visibility,'ajax':'1'}
 payload=b''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k,v in fields.items())+f'--{boundary}\r\nContent-Disposition: form-data; name="document[]"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+data+f'\r\n--{boundary}--\r\n'.encode()
 return client.request('/wp-admin/media.php?kind=documents',payload,{'Content-Type':'multipart/form-data; boundary='+boundary})
def save(client,title,status='draft',blocks=None):
 _,page,_=client.request('/wp-admin/post-new.php?post_type=post');return client.request('/wp-admin/post-new.php?post_type=post',{'_wpnonce':h.token(page,'post-editor'),'post_type':'post','post_title':title,'post_name':title.lower().replace(' ','-'),'post_status':status,'post_content':json.dumps({'version':1,'blocks':blocks or [{'type':'paragraph','html':'Independent post body'}]})})
def phprun(code,*args):
 path=root/'probe.php';path.write_text('<?php require $argv[1];'+code);p=subprocess.run([php,str(path),str(site/'wp-includes/bootstrap.php'),*map(str,args)],cwd=site,env=env,capture_output=True,text=True,timeout=20);assert p.returncode==0,(p.stdout,p.stderr);return p.stdout.strip()
try:
 for _ in range(100):
  try:
   with socket.create_connection(('127.0.0.1',port),timeout=.1):break
  except OSError:time.sleep(.03)
 admin=h.Client(f'http://127.0.0.1:{port}');guest=h.Client(admin.base)
 _,page,_=admin.request('/wp-admin/install.php');status,body,_=admin.request('/wp-admin/install.php',{'_wpnonce':h.token(page),'site_title':'Boundary test','admin_user':'admin','admin_email':'admin@example.test','admin_password':'disposable-test-password'})
 assert status==200 and admin.login('admin')[0]==302
 for name in ['alice','documents']:
  _,page,_=admin.request('/wp-admin/users.php');admin.request('/wp-admin/users.php',{'_wpnonce':h.token(page),'username':name,'email':name+'@example.test','role':'author','password':'disposable-test-password'})
 alice=sql("SELECT id FROM users WHERE username='alice'")[0]['id'];target=sql("SELECT id FROM users WHERE username='documents'")[0]['id']
 # A policy toggle must apply to links already issued, not only new requests.
 option('magic_links_enabled','1');tok=phprun("echo wp_account_token_create('magic','alice@example.test',2);")
 _,page,_=guest.request('/wp-admin/login.php?action=ml&token='+tok);nonce=h.token(page,'ml')
 option('magic_links_enabled','0')
 status,body,headers=guest.request('/wp-admin/login.php',{'action':'ml','token':tok,'_wpnonce':nonce})
 check('Disabling magic links refuses an already issued link',status!=302 and guest.request('/wp-admin/index.php')[0]!=200,{'status':status,'location':headers.get('Location'),'session_created':bool(guest.cookie())})
 # Token consumed after expiry or disable: simulate state change after initial lookup.
 for mode in ['expired','disabled']:
  result=phprun("$t=wp_account_token_create('reset','alice@example.test',2);$r=wp_account_token_lookup($t,'reset');"+("$wpdb->query('UPDATE account_tokens SET expires_at=unixepoch()-1 WHERE id=:id',[':id'=>$r['id']]);" if mode=='expired' else "$wpdb->query('UPDATE users SET disabled=1 WHERE id=2');")+"echo $wpdb->transaction(static fn($db)=>wp_account_token_consume($db,(int)$r['id']))?'consumed':'refused';")
  check('Commit-time token consumption refuses '+mode+' state',result=='refused',{'result':result})
  sql('UPDATE users SET disabled=0 WHERE id=2')
 # A reassign-to-documents author creates a new pretty-route collision.
 status,body,_=upload(admin,'boundary.txt',b'BOUNDARY-DOCUMENT-BYTES');docid=json.loads(body)['id']
 _,page,_=admin.request('/wp-admin/post-new.php?post_type=post');admin.request('/wp-admin/post-new.php?post_type=post',{'_wpnonce':h.token(page,'post-editor'),'post_type':'post','post_title':'Route claim','post_name':str(docid),'post_author':str(alice),'post_status':'published','post_content':json.dumps({'version':1,'blocks':[{'type':'paragraph','html':'ROUTE-CLAIM-POST'}]})})
 claim=sql("SELECT id,author_id FROM posts WHERE title='Route claim'")[0]
 # Explicitly set author through the supported mutation facade to avoid assuming a form field.
 out=phprun("wp_set_current_user(1);$r=wp_update_post(['ID'=>(int)$argv[2],'post_author'=>2,'expected_revision'=>1]);echo is_wp_error($r)?$r->get_error_message():'ok';",claim['id']);assert out=='ok',out
 option('permalink_structure','/%author%/%postname%');option('permalink_history','[]')
 save(admin,'Cached reference','published',[{'type':'file','media_id':docid,'label':'BOUNDARY-LINK'}]);ref=sql("SELECT * FROM posts WHERE title='Cached reference'")[0]
 p=subprocess.run([php,str(site/'bin/wp'),'queue:work','100'],cwd=site,env=env,capture_output=True,text=True);assert p.returncode==0,p.stdout+p.stderr
 import re,urllib.parse
 def click():
  status,body,_=guest.request('/admin/cached-reference');m=re.search(r'<a href="([^"]+)"[^>]*>BOUNDARY-LINK</a>',body);assert m,body[:200];u=m[1].replace('&amp;','&');path=urllib.parse.urlsplit(u);r=guest.request(path.path+('?' + path.query if path.query else ''));return u,r
 before,resp=click();check('Cached-link positive control downloads document before reassignment',resp[1]=='BOUNDARY-DOCUMENT-BYTES',{'link':before})
 out=json.loads(phprun("wp_set_current_user(1);$r=wp_delete_user(2,3,1);echo wp_json_encode(['error'=>is_wp_error($r)]);"));assert not out['error'],out
 after,resp=click();check('User deletion with reassignment preserves cached document delivery',resp[1]=='BOUNDARY-DOCUMENT-BYTES',{'before':before,'after':after,'click_status':resp[0],'returns_other_post':'ROUTE-CLAIM-POST' in resp[1]})

 # Force the unsafe pre-transaction count ordering across eight separate PHP workers.
 # With the fix, the count is made under the write lock and never waits at this barrier.
 hook=root/'token-race.php';barriers=root/'barriers';barriers.mkdir()
 hook.write_text(r'''<?php require $argv[1];class RaceDB extends \WordPress\Database\WP_SQLite_DB {public function get_row(string $sql,array $p=[]):?array {$r=parent::get_row($sql,$p);if(str_starts_with($sql,'SELECT COUNT(*) AS n FROM account_tokens')&&!$this->is_in_transaction()){file_put_contents($GLOBALS['barrier'].'/'.getmypid(),'ready');$end=microtime(true)+5;while(count(glob($GLOBALS['barrier'].'/*'))<8&&microtime(true)<$end){usleep(1000);}}return $r;}}$GLOBALS['barrier']=$argv[2];$wpdb=new RaceDB(WP_DB_PATH);$GLOBALS['wpdb']=$wpdb;$r=wp_account_token_create('reset','admin@example.test',1);echo is_wp_error($r)?'refused':'issued';''')
 procs=[subprocess.Popen([php,str(hook),str(site/'wp-includes/bootstrap.php'),str(barriers)],env=env,cwd=site,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(8)]
 outs=[]
 for proc in procs:
  stdout,stderr=proc.communicate(timeout=12);assert proc.returncode==0,(stdout,stderr);outs.append(stdout)
 check('Eight concurrent token requests issue exactly five links',outs.count('issued')==5 and outs.count('refused')==3 and sql("SELECT COUNT(*) AS n FROM account_tokens WHERE email='admin@example.test'")[0]['n']==5,{'issued':outs.count('issued'),'refused':outs.count('refused')})

finally:
 server.terminate();server.wait(timeout=5);log.close()
 print('Boundary results: '+str(sum(r['passed'] for r in results))+'/'+str(len(results)),flush=True)
 fixture.cleanup()
if not all(r['passed'] for r in results):sys.exit(1)
