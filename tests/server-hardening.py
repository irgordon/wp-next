#!/usr/bin/env python3
"""Exercise the documented Nginx denial rules with static fixtures; no PHP-FPM claim."""
from pathlib import Path
import re
import os
import pwd
import grp
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parent.parent
nginx = sys.argv[1] if len(sys.argv) > 1 else 'nginx'
block = re.search(r'```nginx\n(.*?)```', (root / 'docs/INSTALL.md').read_text(), re.S).group(1)
with tempfile.TemporaryDirectory(prefix='wp-nginx-') as temp_name:
    temp = Path(temp_name)
    site = temp / 'site'
    site.mkdir()
    fixtures = ['.env', '.git/config', '.git/hooks/probe.php', 'nested/.env', 'var/data/site.sqlite', 'docs/INSTALL.md', 'vendor/probe.php', 'tests/smoke.php', 'tools/lint.php', 'composer.lock', 'phpstan.neon', 'sample.sqlite-wal', 'var/auth.key', 'bin/wp', 'wp-includes/theme.php', 'wp-content/themes/default/probe.php', 'wp-content/uploads/probe.php', 'package-lock.json', 'public.css', 'wp-content/uploads/probe.phtml', 'wp-config.php', 'wp-cron.php', 'wp-admin/admin-functions.php', 'wp-admin/login.php/extra', 'wp-content/uploads/aa/'+'a'*64+'.png']
    for name in fixtures:
        target = site / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text('harmless fixture')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    (temp / 'fastcgi_params').write_text('')
    (temp / 'mime.types').write_text('types { text/css css; image/png png; image/jpeg jpg; image/webp webp; image/gif gif; }')
    block = block.replace('/etc/nginx/mime.types', str(temp/'mime.types'))
    block = block.replace('listen 80;', f'listen 127.0.0.1:{port};').replace('/var/www/wordpress-next', str(site)).replace('include fastcgi_params;', f'include {temp}/fastcgi_params;')
    conf = temp / 'nginx.conf'
    identity = f'user {pwd.getpwuid(os.getuid()).pw_name} {grp.getgrgid(os.getgid()).gr_name};\n' if os.getuid() == 0 else ''
    conf.write_text(identity + f'daemon off;\nmaster_process off;\npid {temp}/nginx.pid;\nerror_log {temp}/error.log;\nevents {{}}\nhttp {{ access_log off; client_body_temp_path {temp}/body; proxy_temp_path {temp}/proxy; fastcgi_temp_path {temp}/fastcgi; uwsgi_temp_path {temp}/uwsgi; scgi_temp_path {temp}/scgi;\n{block}\n}}\n')
    subprocess.run([nginx, '-t', '-p', str(temp), '-c', str(conf)], check=True)
    process = subprocess.Popen([nginx, '-p', str(temp), '-c', str(conf)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        for _ in range(100):
            if process.poll() is not None:
                raise RuntimeError((temp / 'error.log').read_text())
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1):
                    break
            except OSError:
                time.sleep(.03)
        for name in fixtures:
            try:
                response = opener.open(f'http://127.0.0.1:{port}/{name}', timeout=5)
            except urllib.error.HTTPError as error:
                response = error
            expected = 200 if name == 'public.css' or name.endswith('a'*64+'.png') else 404
            assert response.status == expected, (name, response.status, expected)
            assert response.headers.get('X-Content-Type-Options') == 'nosniff', name
            if name == 'public.css': assert response.headers.get('Content-Type') == 'text/css'
            if name.endswith('a'*64+'.png'):
                assert response.headers.get('Cache-Control') == 'public, max-age=31536000, immutable'
                assert response.headers.get('Content-Type') == 'image/png'
            print('PASS:', name, response.status)
        # Positive controls: allowlisted PHP endpoints must leave nginx and reach the
        # configured FastCGI handler. This fixture has no live FPM backend, so a
        # 500/502 proves routing only; it is not a PHP-FPM success claim.
        for endpoint in ['media.php','block-preview.php','portability.php']:
            (site/'wp-admin'/endpoint).write_text('harmless fixture')
            try: response=opener.open(f'http://127.0.0.1:{port}/wp-admin/{endpoint}',data=b'',timeout=5)
            except urllib.error.HTTPError as error: response=error
            assert response.status in (500,502), (endpoint, response.status)
            print('PASS: PHP endpoint reaches configured FastCGI handler:',endpoint,response.status)
        statuses=[]
        for _ in range(8):
            try: response=opener.open(f'http://127.0.0.1:{port}/wp-admin/login.php',data=b'',timeout=5)
            except urllib.error.HTTPError as error: response=error
            statuses.append(response.status)
        assert 429 in statuses, statuses
        print('PASS: Login requests are rate-limited before any PHP backend is available')
        print(f'{len(fixtures)+4} Nginx delivery/rate checks passed.')
    finally:
        process.terminate()
        process.wait(timeout=5)
