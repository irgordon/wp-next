#!/usr/bin/env python3
"""Live permalink settings, generation and canonical routing regression checks."""
from pathlib import Path
import os,re,shutil,socket,sqlite3,subprocess,sys,tempfile,time
import urllib.request,urllib.parse,urllib.error,http.cookiejar
ROOT=Path(__file__).resolve().parent.parent
PHP=sys.argv[1] if len(sys.argv)>1 else 'php'
COUNT=0
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None
class Client:
    def __init__(self,base):
        self.base=base
        self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
    def get(self,path,data=None):
        if data is not None:data=urllib.parse.urlencode(data).encode()
        try:r=self.opener.open(urllib.request.Request(self.base+path,data=data),timeout=10)
        except urllib.error.HTTPError as e:r=e
        return r.status,r.read().decode(),dict(r.headers)
def nonce(body):return re.findall(r'name="_wpnonce" value="([^"]+)"',body)[-1]
def check(ok,label):
    global COUNT
    assert ok,label
    COUNT+=1;print('PASS:',label,flush=True)
with tempfile.TemporaryDirectory(prefix='wp-permalinks-') as directory:
    temp=Path(directory);site=temp/'site';database=temp/'state/site.sqlite'
    shutil.copytree(ROOT,site,ignore=shutil.ignore_patterns('vendor','node_modules','__pycache__'))
    env=dict(os.environ,WP_ENV='development',WP_VAR_DIR=str(temp/'state'),WP_DB_PATH=str(database));env.pop('WP_AUTH_SALT',None)
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    base='http://127.0.0.1:'+str(port)
    with (temp/'server.log').open('w') as log:
        server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(site),str(site/'tools/router.php')],env=env,stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError:time.sleep(.03)
            admin=Client(base);guest=Client(base)
            _,body,_=admin.get('/wp-admin/install.php')
            assert admin.get('/wp-admin/install.php',{'_wpnonce':nonce(body),'site_title':'Permalink test','admin_user':'admin','admin_email':'admin@example.test','admin_password':'disposable-test-password'})[0]==200
            _,body,_=admin.get('/wp-admin/login.php')
            assert admin.get('/wp-admin/login.php',{'_wpnonce':nonce(body),'log':'admin','pwd':'disposable-test-password'})[0]==302
            with sqlite3.connect(database) as db:
                db.execute('UPDATE posts SET published_at=1735787045 WHERE id=1')
                db.execute("INSERT INTO posts(author_id,slug,title,content_raw,status,post_type) VALUES(1,'1','Numeric page','<p>Number page</p>','published','page')")
                page_id=db.execute('SELECT last_insert_rowid()').fetchone()[0]
                db.execute("INSERT INTO posts(author_id,slug,title,content_raw,status,post_type) VALUES(1,'draft-only','Hidden draft','<p>Secret marker</p>','draft','post')")
                draft_id=db.execute('SELECT last_insert_rowid()').fetchone()[0]
            def save(choice,structure=''):
                _,body,_=admin.get('/wp-admin/options-permalink.php')
                return admin.get('/wp-admin/options-permalink.php',{'_wpnonce':nonce(body),'permalink_choice':choice,'permalink_structure':structure})
            def canonical(path,expected):
                status,body,_=guest.get(path)
                check(status==200 and f'rel="canonical" href="{base+expected}"' in body,'Canonical content: '+path)
            def redirect(path,expected):
                status,_,headers=guest.get(path)
                check(status==301 and headers.get('Location')==base+expected,'Canonical redirect: '+path+' → '+expected)
            check(guest.get('/wp-admin/options-permalink.php')[0]==302,'Anonymous settings access requires login')
            check(admin.get('/wp-admin/options-permalink.php',{'permalink_choice':'plain'})[0]==403,'Permalink changes require CSRF token')
            for choice,path in [('day','/2025/01/02/hello-world/'),('month','/2025/01/hello-world/'),('numeric','/archives/1'),('plain','/?p=1'),('name','/hello-world/')]:
                status,body,_=save(choice)
                check(status==200 and 'Permalink settings saved.' in body,'Save preset: '+choice)
                canonical(path,path)
                if path!='/hello-world/':redirect('/hello-world/',path)
                redirect('/?slug=hello-world',path)
                if path!='/?p=1':redirect('/?p=1',path)
                _,archive,_=guest.get('/')
                check(base+path in archive,'Archive generates selected links: '+choice)
                if choice=='plain':canonical('/?page_id=2','/?page_id=2');redirect('/sample-page/','/?page_id=2')
                else:canonical('/sample-page/','/sample-page/')
            redirect('/2025/01/02/hello-world/','/hello-world/')
            check(guest.get('/2024/01/02/hello-world/')[0]==404,'Wrong year is a real 404')
            check(guest.get('/01/02/sample-page/')[0]==404,'Date prefix cannot address Pages')
            for path in ['/?p='+str(draft_id),'/?p=2','/?page_id=1','/?p=99999','/?p[]=1','/?p=1&page_id=2','/wrong/?p=1','/99/99/hello-world/?slug=hello-world']:
                status,body,_=guest.get(path)
                check(status==404 and 'Secret marker' not in body,'Invalid/private identity rejected: '+path)
            for invalid in ['https://evil.test/%postname%/','//evil/%postname%/','/%unsupported%/%postname%/','/%year%/','/%year%/%year%/%postname%/','/preview/%post_id%/','/%bogus/%postname%/','/x/../%postname%/','/%postname%/?x=1','/%postname%/#fragment']:
                _,body,_=save('custom',invalid)
                check('Permalink settings saved.' not in body,'Invalid structure rejected: '+invalid)
            save('custom','/%monthnum%/%day%/%postname%/')
            canonical('/01/02/hello-world/','/01/02/hello-world/')
            redirect('/1/2/hello-world/','/01/02/hello-world/')
            check(guest.get('/02/01/hello-world/')[0]==404,'Wrong date is a real 404')
            save('custom','/journal/%author%/%year%/%monthnum%/%day%/%hour%/%minute%/%second%/%post_id%-%postname%.html')
            full='/journal/admin/2025/01/02/03/04/05/1-hello-world.html'
            canonical(full,full)
            check(guest.get(full.replace('/admin/','/wrong/'))[0]==404,'Author tag is verified')
            check(guest.get(full.replace('/04/','/09/'))[0]==404,'Time tag is verified')
            save('custom','/index.php/%year%/%postname%/')
            canonical('/index.php/2025/hello-world/','/index.php/2025/hello-world/')
            save('custom','/%post_id%/')
            canonical('/1/','/1/')
            canonical('/?page_id='+str(page_id),'/?page_id='+str(page_id))
            check(guest.get('/sample-page/')[0]==200,'Other Pages keep slug addresses')
            save('name')
            for path in ['/%68ello-world/', '//hello-world/', '/hello-world//']:
                redirect(path,'/hello-world/')
            for path in ['/blog/?page=-1','/?page=abc','/?page=0','/?page[]=1','/?page=01','/wp-admin/login.php/extra']:
                check(guest.get(path)[0]==404,'Malformed address is rejected: '+path)
            redirect('/blog/?page=1','/blog/')
            # A fixed IP window applies across usernames, before expensive hashing.
            with sqlite3.connect(database) as db:db.execute('DELETE FROM login_attempts')
            _,login,_=guest.get('/wp-admin/login.php')
            outcomes=[]
            for n in range(40):
                status,_,headers=guest.get('/wp-admin/login.php',{'_wpnonce':nonce(login),'log':'rotating-'+str(n),'pwd':'wrong'})
                outcomes.append(status)
                if n>=30:check(headers.get('Retry-After')=='60','IP throttle exposes bounded retry delay')
            check(outcomes[:30]==[200]*30 and outcomes[30:]==[429]*10,'Rotating usernames cannot bypass the per-IP budget')
            check(guest.get('/hello-world/')[0]==200,'Public delivery remains available after a login flood')
            # Seed one remaining reservation; simultaneous PHP processes compete for it.
            with sqlite3.connect(database) as db:
                db.execute('DELETE FROM login_attempts')
            code='require $argv[1]."/wp-includes/bootstrap.php"; $_SERVER["REMOTE_ADDR"]="192.0.2.10"; echo is_wp_error(wp_reserve_login_ip()) ? "blocked" : "admitted";'
            for _ in range(29):subprocess.run([PHP,'-r',code,str(site)],env=env,check=True,capture_output=True)
            import concurrent.futures
            def reserve(_):return subprocess.check_output([PHP,'-r',code,str(site)],env=env,text=True)
            with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:results=list(pool.map(reserve,range(12)))
            check(results.count('admitted')==1 and results.count('blocked')==11,'Concurrent requests cannot overrun the last IP reservation')
            with sqlite3.connect(database) as db:db.execute('UPDATE login_attempts SET expires_at=0')
            check(reserve(0)=='admitted','Expired IP window admits a fresh reservation')
            with sqlite3.connect(database) as db:db.execute("UPDATE posts SET slug='日本語' WHERE id=1")
            unicode_path='/'+urllib.parse.quote('日本語')+'/'
            canonical(unicode_path,unicode_path)
            with sqlite3.connect(database) as db:db.execute("UPDATE options SET option_value=? WHERE option_name='site_url'",(base+'/journal',))
            canonical('/journal'+unicode_path,'/journal'+unicode_path)
            redirect('/journal/?p=1','/journal'+unicode_path)
            with sqlite3.connect(database) as db:db.execute("UPDATE users SET capabilities='[\"author\"]' WHERE id=1")
            check(admin.get('/wp-admin/options-permalink.php')[0]==403,'Nonadministrators cannot read permalink settings')
            with sqlite3.connect(database) as db:check(db.execute("SELECT COUNT(*) FROM audit_log WHERE action='permalinks_updated'").fetchone()[0]>=10,'Successful permalink changes are audited')
            print(COUNT,'permalink HTTP checks passed.')
        finally:server.terminate();server.wait(timeout=5)
