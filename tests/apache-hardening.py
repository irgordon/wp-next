#!/usr/bin/env python3
"""Run the documented Apache vhost with overrides disabled; test actual HTTP denials."""
from pathlib import Path
import os,re,socket,subprocess,sys,tempfile,time,urllib.request,urllib.error
root=Path(__file__).resolve().parent.parent
apache=sys.argv[1] if len(sys.argv)>1 else 'apache2'
modules=Path(sys.argv[2]) if len(sys.argv)>2 else Path('/usr/lib/apache2/modules')
mime_types=Path(sys.argv[3]) if len(sys.argv)>3 else Path('/etc/mime.types')
with tempfile.TemporaryDirectory(prefix='wp-apache-') as directory:
    temp=Path(directory);temp.chmod(0o755);site=temp/'site';site.mkdir()
    fixtures=['.env','.git/config','nested/.env','var/data/site.sqlite','docs/INSTALL.md','vendor/probe.php','tests/probe.php','tools/probe.php','bin/wp','wp-includes/theme.php','composer.lock','package-lock.json','phpstan.neon','sample.sqlite-wal','wp-config.php','wp-cron.php','wp-content/uploads/probe.php','wp-content/themes/default/probe.php','wp-admin/admin-functions.php','wp-admin/login.php/extra','public.css','wp-content/uploads/aa/'+'a'*64+'.png']
    for name in fixtures:
        path=site/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text('harmless fixture')
    # An invalid .htaccess proves the vhost does not read or depend on it.
    (site/'.htaccess').write_text('ThisDirectiveMustNeverBeRead On')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    block=re.search(r'<VirtualHost \*:443>(.*?)</VirtualHost>',(root/'docs/INSTALL.md').read_text(),re.S).group(1)
    block='\n'.join(line for line in block.splitlines() if not line.strip().startswith(('SSLEngine','SSLCertificate')))
    block=block.replace('/var/www/wordpress-next',str(site))
    names=['mpm_event','unixd','authz_core','authz_host','dir','mime','rewrite','headers','proxy','proxy_fcgi']
    builtins=subprocess.check_output([apache,'-l'],text=True)
    loads='\n'.join(f'LoadModule {name}_module {modules}/mod_{name}.so' for name in names if 'mod_'+name+'.c' not in builtins)
    identity='User #65534\nGroup #65534\n' if os.getuid()==0 else f'User #{os.getuid()}\nGroup #{os.getgid()}\n'
    conf=temp/'apache.conf';conf.write_text(f'ServerRoot "{temp}"\nDefaultRuntimeDir "{temp}"\nPidFile "{temp}/apache.pid"\nServerName localhost\nListen 127.0.0.1:{port}\n{loads}\n{identity}ErrorLog "{temp}/error.log"\nLogLevel warn\nTypesConfig "{mime_types}"\n<VirtualHost 127.0.0.1:{port}>\n{block}\n</VirtualHost>\n')
    subprocess.run([apache,'-t','-f',str(conf)],check=True)
    process=subprocess.Popen([apache,'-X','-f',str(conf)],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,text=True)
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        for _ in range(100):
            if process.poll() is not None:raise RuntimeError(process.stderr.read()+'\n'+(temp/'error.log').read_text())
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.1):break
            except OSError:time.sleep(.03)
        for name in fixtures:
            try:response=opener.open(f'http://127.0.0.1:{port}/{name}',timeout=5)
            except urllib.error.HTTPError as error:response=error
            public=name=='public.css' or name.endswith('a'*64+'.png')
            assert response.status==200 if public else response.status in (403,404),(name,response.status)
            assert response.headers.get('X-Content-Type-Options')=='nosniff',(name,dict(response.headers))
            if name=='public.css':assert response.headers.get('Content-Type')=='text/css'
            if name.endswith('a'*64+'.png'):
                assert response.headers.get('Cache-Control')=='public, max-age=31536000, immutable'
                assert response.headers.get('Content-Type')=='image/png'
            print('PASS:',name,response.status)
        # H1 discovery routes are front-controller routes: the documented
        # rewrite must hand /feed, /sitemap.xml and /robots.txt to index.php
        # and thus to the PHP-FPM proxy (500/502/503 with no live backend
        # proves routing only). A stray static file of that name in the
        # docroot would be served directly (200) and shadow the route.
        for route in ['feed','sitemap.xml','robots.txt']:
            assert not (site/route).exists(), route
            assert not (root/route).exists(), route
            try:response=opener.open(f'http://127.0.0.1:{port}/{route}',timeout=5)
            except urllib.error.HTTPError as error:response=error
            assert response.status in (500,502,503),(route,response.status)
            print('PASS: Discovery route reaches PHP through the front controller:',route,response.status)
        print(len(fixtures)+3,'Apache delivery checks passed with AllowOverride None.')
    finally:
        process.terminate();process.wait(timeout=5)
