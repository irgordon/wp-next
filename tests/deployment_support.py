"""Disposable nginx/Apache, real FPM and explicitly trusted local TLS adapters."""
from contextlib import ExitStack, contextmanager
from pathlib import Path
import argparse
import http.cookiejar
import io
import os
import pwd
import re
import shutil
import socket
import ssl
import subprocess
import tempfile
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request

from http_helpers import Client, NoRedirect, ROOT


@contextmanager
def deployed_site(options, stack, mode, backup=None):
    with tempfile.TemporaryDirectory(prefix='wp-fpm-https-', dir='/tmp') as directory, ExitStack() as services:
        fixture = prepare_fixture(options, stack, mode, Path(directory).resolve())
        try:
            if backup is not None:
                restore_fixture(fixture, backup)
            start_services(services, fixture)
            yield fixture
        finally:
            services.close()
            retain_diagnostics(fixture)


def arguments():
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('php', 'fpm', 'nginx', 'apache', 'modules', 'mime_types', 'evidence'):
        parser.add_argument('--' + name.replace('_', '-'), required=True)
    parser.add_argument('--stack', choices=('nginx', 'apache', 'both'), default='both')
    parser.add_argument('--mode', choices=('direct', 'terminated', 'both'), default='both')
    return parser.parse_args()


def prepare_fixture(options, stack, mode, work):
    fixture = fixture_paths(options, stack, mode, work)
    copy_source(fixture)
    create_certificate(work)
    write_fpm_configuration(fixture)
    write_web_configuration(fixture)
    write_proxy_configuration(fixture)
    return fixture


def start_services(services, fixture):
    start_fpm(services, fixture)
    start_web_server(services, fixture)
    if fixture['mode'] == 'terminated':
        start_tls_proxy(services, fixture)


def restore_fixture(fixture, backup):
    staging = fixture['work']/'restore'
    with tarfile.open(fileobj=io.BytesIO(backup)) as archive:
        archive.extractall(staging, filter='data')
    fixture['state'].mkdir()
    shutil.copy2(staging/'site.sqlite', fixture['database'])
    shutil.copy2(staging/'auth.key', fixture['state']/'auth.key')
    (fixture['state']/'auth.key').chmod(0o600)
    shutil.copytree(staging/'uploads', fixture['site']/'wp-content/uploads', dirs_exist_ok=True)
    shutil.copytree(staging/'assets', fixture['state']/'assets')
    for operation in ('upgrade', 'sessions:revoke'):
        subprocess.run([fixture['options'].php, str(fixture['site']/'bin/wp'), operation],
                       env=fixture['env'], check=True, capture_output=True, timeout=60)


def fixture_paths(options, stack, mode, work):
    tls_port, http_port = free_port(), free_port()
    environment = clean_environment()
    environment.update(WP_ENV='production', WP_VAR_DIR=str(work/'state'),
                       WP_DB_PATH=str(work/'state/site.sqlite'), WP_PHP_CLI=options.php,
                       WP_API_EPOCH_PATH=str(work/'api-epoch.json'))
    if mode == 'terminated':
        environment['WP_HTTPS'] = '1'
    return dict(options=options, stack=stack, mode=mode, work=work, site=work/'site',
                state=work/'state', database=work/'state/site.sqlite', env=environment,
                base=f'https://localhost:{tls_port}', http=f'http://localhost:{http_port}',
                tls_port=tls_port, http_port=http_port, cert=work/'ca.pem')


def copy_source(fixture):
    ignore = shutil.ignore_patterns('.git', '.env*', 'var', 'vendor', 'node_modules',
                                   'uploads', 'qa', '__pycache__', '*.sqlite*', '*.log')
    shutil.copytree(ROOT, fixture['site'], ignore=ignore)
    (fixture['site']/'.htaccess').write_text('ThisDirectiveMustNeverBeRead On\n')


def clean_environment():
    return {name: value for name, value in os.environ.items() if not name.startswith('WP_')}


def create_certificate(work):
    openssl = shutil.which('openssl')
    ca = [openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
          '-subj', '/CN=WP Next Disposable Test CA', '-addext', 'basicConstraints=critical,CA:TRUE',
          '-addext', 'keyUsage=critical,keyCertSign,cRLSign', '-keyout', str(work/'ca.key'), '-out', str(work/'ca.pem')]
    csr = [openssl, 'req', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=localhost',
           '-keyout', str(work/'server.key'), '-out', str(work/'server.csr')]
    (work/'leaf.ext').write_text('basicConstraints=critical,CA:FALSE\nsubjectAltName=DNS:localhost\nextendedKeyUsage=serverAuth\nkeyUsage=critical,digitalSignature,keyEncipherment\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n')
    sign = [openssl, 'x509', '-req', '-in', str(work/'server.csr'), '-CA', str(work/'ca.pem'),
            '-CAkey', str(work/'ca.key'), '-CAcreateserial', '-days', '1',
            '-extfile', str(work/'leaf.ext'), '-out', str(work/'server.pem')]
    for command in (ca, csr, sign):
        subprocess.run(command, check=True, capture_output=True, timeout=30)
    for name in ('ca.key', 'server.key'):
        (work/name).chmod(0o600)


def write_fpm_configuration(fixture):
    work = fixture['work']
    settings = '\n'.join(f'env[{name}] = "{value}"' for name, value in fixture['env'].items() if name.startswith('WP_'))
    identity = pwd.getpwuid(os.getuid()).pw_name
    contents = f'''[global]
daemonize = no
error_log = {work}/fpm-error.log
[fixture]
user = {identity}
group = {os.getgid()}
listen = {work}/fpm.sock
listen.mode = 0600
pm = static
pm.max_children = 4
clear_env = yes
catch_workers_output = yes
{settings}
php_admin_value[upload_max_filesize] = 26M
php_admin_value[post_max_size] = 32M
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 30
php_admin_value[log_errors] = 1
php_admin_value[display_errors] = 0
php_admin_value[error_log] = {work}/php-error.log
'''
    (work/'fpm.conf').write_text(contents)


def write_web_configuration(fixture):
    documentation = (ROOT/'docs/INSTALL.md').read_text()
    builder = nginx_configuration if fixture['stack'] == 'nginx' else apache_configuration
    (fixture['work']/'web.conf').write_text(builder(fixture, documentation))


def nginx_configuration(fixture, documentation):
    work = fixture['work']
    block = re.search(r'```nginx\n(.*?)```', documentation, re.S)[1]
    block = block.replace('server_name example.com;', 'server_name localhost;')
    block = block.replace('/var/www/wordpress-next', str(fixture['site']))
    block = block.replace('/etc/nginx/mime.types', str(work/'mime.types'))
    block = block.replace('include fastcgi_params;', f'include {work}/fastcgi_params;')
    block = block.replace('unix:/run/php/php8.4-fpm.sock', f'unix:{work}/fpm.sock')
    zone, server = block.split('\nserver {', 1)
    body = 'server {' + server
    plain = body.replace('listen 80;', f'listen 127.0.0.1:{fixture["http_port"]};')
    secure = body.replace('listen 80;', tls_directives(fixture))
    servers = plain + (secure if fixture['mode'] == 'direct' else '')
    write_fastcgi_parameters(work)
    return nginx_global(work) + zone + servers + '\n}\n'


def nginx_global(work):
    (work/'mime.types').write_text('types { text/css css; application/javascript js mjs; image/png png; image/jpeg jpg; image/webp webp; image/gif gif; }')
    return f'''daemon off;
master_process off;
pid {work}/nginx.pid;
error_log {work}/nginx-error.log;
events {{}}
http {{
access_log {work}/nginx-access.log;
client_body_temp_path {work}/body;
fastcgi_temp_path {work}/fastcgi;
proxy_temp_path {work}/proxy;
uwsgi_temp_path {work}/uwsgi;
scgi_temp_path {work}/scgi;
'''


def tls_directives(fixture):
    work = fixture['work']
    return f'listen 127.0.0.1:{fixture["tls_port"]} ssl;\nssl_certificate {work}/server.pem;\nssl_certificate_key {work}/server.key;'


def write_fastcgi_parameters(work):
    names = ('QUERY_STRING query_string', 'REQUEST_METHOD request_method', 'CONTENT_TYPE content_type',
             'CONTENT_LENGTH content_length', 'SCRIPT_NAME fastcgi_script_name', 'REQUEST_URI request_uri',
             'DOCUMENT_URI document_uri', 'DOCUMENT_ROOT document_root', 'SERVER_PROTOCOL server_protocol',
             'REQUEST_SCHEME scheme', 'REMOTE_ADDR remote_addr', 'REMOTE_PORT remote_port',
             'SERVER_ADDR server_addr', 'SERVER_PORT server_port', 'SERVER_NAME server_name')
    contents = '\n'.join('fastcgi_param ' + pair.replace(' ', ' $') + ';' for pair in names)
    contents += '\nfastcgi_param HTTPS $https if_not_empty;\nfastcgi_param REDIRECT_STATUS 200;\nfastcgi_param GATEWAY_INTERFACE CGI/1.1;\n'
    (work/'fastcgi_params').write_text(contents)


def apache_configuration(fixture, documentation):
    work = fixture['work']
    block = re.search(r'<VirtualHost \*:443>(.*?)</VirtualHost>', documentation, re.S)[1]
    block = block.replace('ServerName example.com', 'ServerName localhost')
    block = block.replace('/var/www/wordpress-next', str(fixture['site']))
    block = block.replace('/path/to/fullchain.pem', str(work/'server.pem')).replace('/path/to/privkey.pem', str(work/'server.key'))
    block = block.replace('/run/php/php8.4-fpm.sock', str(work/'fpm.sock'))
    plain = '\n'.join(line for line in block.splitlines() if not line.strip().startswith(('SSLEngine', 'SSLCertificate')))
    servers = f'<VirtualHost 127.0.0.1:{fixture["http_port"]}>\n{plain}\n</VirtualHost>\n'
    if fixture['mode'] == 'direct':
        servers += f'Listen 127.0.0.1:{fixture["tls_port"]}\n<VirtualHost 127.0.0.1:{fixture["tls_port"]}>\n{block}\n</VirtualHost>\n'
    return apache_global(fixture) + servers


def apache_global(fixture):
    work, options = fixture['work'], fixture['options']
    return f'''ServerRoot "{work}"
DefaultRuntimeDir "{work}"
PidFile "{work}/apache.pid"
ServerName localhost
Listen 127.0.0.1:{fixture['http_port']}
{apache_modules(options)}
User #{os.getuid()}
Group #{os.getgid()}
ErrorLog "{work}/apache-error.log"
LogLevel warn
TypesConfig "{options.mime_types}"
SSLSessionCache shmcb:{work}/ssl-cache(512000)
'''


def apache_modules(options):
    builtins = subprocess.check_output([options.apache, '-l'], text=True)
    names = ('mpm_event', 'unixd', 'authz_core', 'authz_host', 'dir', 'mime', 'rewrite',
             'headers', 'proxy', 'proxy_fcgi', 'socache_shmcb', 'ssl')
    return '\n'.join(f'LoadModule {name}_module {options.modules}/mod_{name}.so'
                     for name in names if 'mod_' + name + '.c' not in builtins)


def write_proxy_configuration(fixture):
    work = fixture['work']
    configuration = f'''daemon off;
master_process off;
pid {work}/proxy.pid;
error_log {work}/proxy-error.log;
events {{}}
http {{
access_log {work}/proxy-access.log;
client_body_temp_path {work}/proxy-body;
proxy_temp_path {work}/proxy-response;
server {{
{tls_directives(fixture)}
client_max_body_size 32m;
location / {{
proxy_set_header Host $http_host;
proxy_pass http://127.0.0.1:{fixture['http_port']};
}}
}}
}}
'''
    (work/'proxy.conf').write_text(configuration)


def start_fpm(services, fixture):
    work, options = fixture['work'], fixture['options']
    command = [options.fpm, '-F', '-y', str(work/'fpm.conf')]
    check_configuration([options.fpm, '-t', '-y', str(work/'fpm.conf')], work/'fpm-check.log')
    services.enter_context(running_process(command, work/'fpm-start.log', work/'fpm.sock'))


def start_web_server(services, fixture):
    options, work = fixture['options'], fixture['work']
    if fixture['stack'] == 'nginx':
        command = [options.nginx, '-p', str(work), '-c', str(work/'web.conf')]
        test = command + ['-t']
    else:
        command = [options.apache, '-X', '-f', str(work/'web.conf')]
        test = [options.apache, '-t', '-f', str(work/'web.conf')]
    check_configuration(test, work/'web-check.log')
    services.enter_context(running_process(command, work/'web-start.log', fixture['http_port']))


def start_tls_proxy(services, fixture):
    work = fixture['work']
    command = [fixture['options'].nginx, '-p', str(work), '-c', str(work/'proxy.conf')]
    check_configuration(command + ['-t'], work/'proxy-check.log')
    services.enter_context(running_process(command, work/'proxy-start.log', fixture['tls_port']))


def check_configuration(command, log_path):
    result = subprocess.run(command, capture_output=True, text=True, timeout=30)
    log_path.write_text(result.stdout + result.stderr)
    if result.returncode:
        raise RuntimeError(log_path.read_text())


@contextmanager
def running_process(command, log_path, listener):
    with log_path.open('w') as log:
        process = subprocess.Popen(command, stdout=log, stderr=log, env=clean_environment())
        try:
            wait_for_listener(process, listener, log_path)
            record_process_identity(process.pid, log_path)
            yield process
        finally:
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)


def wait_for_listener(process, listener, log_path):
    for _ in range(200):
        if process.poll() is not None:
            raise RuntimeError(log_path.read_text())
        try:
            address = str(listener) if isinstance(listener, Path) else ('127.0.0.1', listener)
            family = socket.AF_UNIX if isinstance(listener, Path) else socket.AF_INET
            with socket.socket(family) as connection:
                connection.settimeout(.1)
                connection.connect(address)
            return
        except OSError:
            time.sleep(.03)
    raise TimeoutError('Listener did not start: ' + str(listener))


def record_process_identity(pid, log_path):
    rows = subprocess.check_output(['ps', '-axo', 'pid=,ppid=,uid=,gid=,comm='], text=True).splitlines()
    processes = [row for row in rows if str(pid) in row.split(maxsplit=4)[:2]]
    log_path.with_suffix('.identity.log').write_text('PID PPID UID GID COMMAND\n' + '\n'.join(processes) + '\n')


def retain_diagnostics(fixture):
    destination = Path(fixture['options'].evidence)/(fixture['stack'] + '-' + fixture['mode'])
    destination.mkdir(parents=True, exist_ok=True)
    for path in fixture['work'].iterdir():
        if path.suffix in ('.log', '.conf'):
            shutil.copy2(path, destination/path.name)


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


class TLSClient(Client):
    def __init__(self, fixture):
        self.base = fixture['base']
        self.jar = http.cookiejar.CookieJar()
        context = ssl.create_default_context(cafile=str(fixture['cert']))
        self.open = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                    urllib.request.HTTPCookieProcessor(self.jar), NoRedirect(),
                    urllib.request.HTTPSHandler(context=context))


def raw_request(client, path, data=None, headers=None, method=None):
    if isinstance(data, dict):
        data = urllib.parse.urlencode(data, doseq=True).encode()
    request = urllib.request.Request(client.base + path, data, headers or {}, method=method)
    try:
        response = client.open.open(request, timeout=60)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read(), dict(response.headers)
