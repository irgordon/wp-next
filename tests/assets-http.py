#!/usr/bin/env python3
"""Phase-1 image consumers must not expose documents or incomplete assets."""
from http_helpers import *
with tempfile.TemporaryDirectory(prefix='wp-asset-http-') as directory:
    temp=Path(directory);site=temp/'site';database=temp/'state/site.sqlite'
    shutil.copytree(ROOT,site,ignore=shutil.ignore_patterns('vendor','node_modules','__pycache__','var'))
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
            admin=Client(base);_,body,_=admin.request('/wp-admin/install.php')
            admin.request('/wp-admin/install.php',{'_wpnonce':token(body),'site_title':'Asset test','admin_user':'admin','admin_email':'admin@example.test','admin_password':'disposable-test-password'})
            check(admin.login('admin')[0]==302,'Asset HTTP fixture logs in')
            with sqlite3.connect(database) as db:
                for i,kind,visibility,state,name in [(901,'document','private','ready','hidden-private-document'),(902,'document','public','ready','hidden-public-document'),(903,'image','public','pending','hidden-pending-image')]:
                    digest=hashlib.sha256(name.encode()).hexdigest();filename=digest[:2]+'/'+digest+('.png' if kind=='image' else '')
                    db.execute('INSERT INTO media(id,author_id,sha256,filename,mime,original_name,width,height,size,kind,format,visibility,state) VALUES(?,1,?,?,?,?,?,?,1,?,?,?,?)',(i,digest,filename,'image/png' if kind=='image' else 'text/plain',name,1 if kind=='image' else None,1 if kind=='image' else None,kind,'png' if kind=='image' else 'txt',visibility,state))
            for path in ['/wp-admin/media.php','/wp-admin/media.php?format=json','/wp-admin/post-new.php?post_type=post','/wp-admin/profile.php','/wp-admin/themes.php']:
                status,body,_=admin.request(path)
                check(status==200 and not any(n in body for n in ['hidden-private-document','hidden-public-document','hidden-pending-image']),'Image-only surface excludes document and pending metadata: '+path)
            for asset in [901,903]:
                _,body,_=admin.request('/wp-admin/post-new.php?post_type=post')
                admin.request('/wp-admin/post-new.php?post_type=post',{'_wpnonce':token(body),'post_title':'Rejected asset '+str(asset),'post_content':'<p>Body</p>','post_status':'draft','featured_media_id':str(asset)})
                check(dbrow(database,'SELECT COUNT(*) AS n FROM posts WHERE title=?',('Rejected asset '+str(asset),))['n']==0,'Crafted featured-image request rejects asset '+str(asset))
            _,body,_=admin.request('/wp-admin/post-new.php?post_type=post')
            admin.request('/wp-admin/post-new.php?post_type=post',{'_wpnonce':token(body),'post_title':'Rejected document block','post_content':json.dumps({'version':1,'blocks':[{'type':'image','media_id':901}]}),'post_status':'draft'})
            check(dbrow(database,"SELECT COUNT(*) AS n FROM posts WHERE title='Rejected document block'")['n']==0,'Crafted image block rejects a document ID')
            print('9 asset HTTP checks passed.')
        finally:server.terminate();server.wait(timeout=5)
