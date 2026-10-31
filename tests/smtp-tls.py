#!/usr/bin/env python3
"""Real account-link SMTP over STARTTLS/implicit TLS with strict client trust."""
from portability_helpers import *
from deployment_support import create_certificate
import socketserver, ssl, threading


class MailSink(socketserver.BaseRequestHandler):
    def handle(self):
        channel=self.request; reader=None; authentication=0; authenticated=False; data=None
        channel.settimeout(5)
        try:
            if self.server.mode=='ssl':
                channel=self.server.context.wrap_socket(channel,server_side=True)
            reader=channel.makefile('rb'); channel.sendall(b'220 localhost test sink\r\n')
            while True:
                line=reader.readline(4096)
                if not line:break
                text=line.decode('utf8','replace').rstrip('\r\n'); verb=text.split(' ')[0].upper()
                if data is not None:
                    if text=='.':
                        self.server.messages.append(('\n'.join(data),channel.version(),authenticated))
                        data=None; channel.sendall(b'250 accepted\r\n')
                    else:data.append(text[1:] if text.startswith('..') else text)
                elif authentication:
                    expected=base64.b64encode(b'tls-user' if authentication==1 else b'tls-pass').decode()
                    if text!=expected:channel.sendall(b'535 invalid test credential\r\n');break
                    authentication+=1
                    if authentication==3:authenticated=True;authentication=0
                    channel.sendall(b'334 UGFzc3dvcmQ6\r\n' if authentication==2 else b'235 authenticated\r\n')
                elif verb in ('EHLO','HELO'):
                    channel.sendall(b'250-localhost\r\n'+(b'250-STARTTLS\r\n' if not isinstance(channel,ssl.SSLSocket) else b'')+b'250 AUTH LOGIN\r\n')
                elif verb=='STARTTLS':
                    channel.sendall(b'220 begin TLS\r\n');reader.close()
                    channel=self.server.context.wrap_socket(channel,server_side=True);reader=channel.makefile('rb')
                elif verb=='AUTH':
                    self.server.auth_attempts+=1
                    if not isinstance(channel,ssl.SSLSocket):raise AssertionError('AUTH before TLS')
                    authentication=1;channel.sendall(b'334 VXNlcm5hbWU6\r\n')
                elif verb in ('MAIL','RCPT'):
                    if not authenticated:raise AssertionError('Envelope before authentication')
                    channel.sendall(b'250 ok\r\n')
                elif verb=='DATA':
                    if not authenticated:raise AssertionError('DATA before authentication')
                    data=[];channel.sendall(b'354 end with dot\r\n')
                elif verb=='QUIT':channel.sendall(b'221 bye\r\n');break
                else:raise AssertionError('Unexpected SMTP command: '+verb)
        except (ssl.SSLError,ConnectionError,TimeoutError):self.server.tls_failures+=1
        except Exception as error:self.server.errors.append(str(error))
        finally:
            # Peer-name verification can reject after the server handshake.
            # Record its pre-AUTH disconnect as well as server-side TLS errors.
            if isinstance(channel,ssl.SSLSocket) and not authenticated and self.server.auth_attempts==0:
                self.server.tls_failures=max(1,self.server.tls_failures)
            if reader is not None:reader.close()
            channel.close();self.server.finished.set()


def send_link(fixture, port, mode, host, ca, purpose, recipient):
    env={key:value for key,value in fixture['env'].items() if not key.startswith('WP_MAIL_')}
    env.update(WP_MAIL_SMTP_HOST=host,WP_MAIL_SMTP_PORT=str(port),WP_MAIL_SMTP_ENCRYPTION=mode,
               WP_MAIL_SMTP_USERNAME='tls-user',WP_MAIL_SMTP_PASSWORD='tls-pass',WP_MAIL_FROM='sender@example.test')
    code=r'''require $argv[1];wp_set_current_user(1);$purpose=$argv[2];$to=$argv[3];
if('invite'!==$purpose){$wpdb->query('UPDATE users SET email=:email WHERE id=1',[':email'=>$to]);}
$r=wp_account_send_link($purpose,'invite'===$purpose?null:1,$to,'author');
echo json_encode(['ok'=>true===$r,'code'=>is_wp_error($r)?$r->get_error_code():null,
'mail'=>json_decode((string)get_option('mail_last_status','{}'),true),
'failures'=>(int)$wpdb->get_row("SELECT COUNT(*) AS n FROM audit_log WHERE action='account_link_delivery_failed'")['n']]);'''
    result=subprocess.run([PHP,'-d','openssl.cafile='+str(ca),'-r',code,str(fixture['site']/'wp-includes/bootstrap.php'),purpose,recipient],env=env,capture_output=True,text=True,timeout=20)
    assert result.returncode==0,result.stderr
    return json.loads(result.stdout)


def exercise(fixture, certificates, unrelated_ca, mode, purpose, negative=None):
    context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(certificates/'server.pem',certificates/'server.key')
    with socketserver.ThreadingTCPServer(('127.0.0.1',0),MailSink) as sink:
        sink.daemon_threads=True;sink.mode=mode;sink.context=context
        sink.messages=[];sink.auth_attempts=0;sink.tls_failures=0;sink.errors=[];sink.finished=threading.Event()
        thread=threading.Thread(target=sink.serve_forever,kwargs={'poll_interval':.05},daemon=True);thread.start()
        try:
            recipient=mode+'-'+(negative or purpose)+'@example.test'
            before=dbrow(fixture['database'],"SELECT COUNT(*) AS n FROM audit_log WHERE action='account_link_delivery_failed'")['n']
            result=send_link(fixture,sink.server_address[1],mode,'127.0.0.1' if negative=='hostname' else 'localhost',
                             unrelated_ca if negative=='untrusted' else certificates/'ca.pem',purpose,recipient)
            assert sink.finished.wait(5),{'result':result,'errors':sink.errors}
            check(not sink.errors,mode+': SMTP protocol completes without fixture errors')
            if negative:
                check(not result['ok'] and result['code']=='mail_failed' and not result['mail']['ok'],mode+': '+negative+' certificate refusal is reported as failed delivery')
                check(not sink.messages and sink.auth_attempts==0 and sink.tls_failures>0,mode+': '+negative+' certificate prevents AUTH and DATA')
                check(result['failures']==before+1,mode+': '+negative+' refusal records the account delivery-failure audit')
            else:
                check(result['ok'] and result['mail']['ok'] and len(sink.messages)==1,mode+': '+purpose+' delivery is accepted and recorded')
                message,protocol,authenticated=sink.messages[0]
                action={'invite':'invite','reset':'rp','magic':'ml'}[purpose]
                match=re.search(r'action='+action+r'&token=([a-f0-9]{64})',message)
                row=dbrow(fixture['database'],'SELECT purpose,email FROM account_tokens WHERE token_hash=?',
                          (hashlib.sha256(match[1].encode()).hexdigest(),)) if match else None
                check(row=={'purpose':purpose,'email':recipient} and 'To: <'+recipient+'>' in message,mode+': '+purpose+' email contains its real purpose-bound account link')
                check(protocol.startswith('TLS') and authenticated and sink.auth_attempts==1,mode+': '+purpose+' AUTH and DATA travel over negotiated TLS')
        finally:sink.shutdown();thread.join(timeout=5)


with tempfile.TemporaryDirectory(prefix='wp-smtp-tls-') as directory:
    temp=Path(directory);certificates=temp/'trusted';certificates.mkdir()
    unrelated=temp/'unrelated';unrelated.mkdir()
    create_certificate(certificates);create_certificate(unrelated)
    fixture=start_site(temp,'application','SMTP TLS verification')
    try:
        with sqlite3.connect(fixture['database']) as database:
            database.execute("INSERT INTO options(option_name,option_value) VALUES('magic_links_enabled','1') ON CONFLICT(option_name) DO UPDATE SET option_value='1'")
        for mode in ('tls','ssl'):
            for purpose in ('invite','reset','magic'):exercise(fixture,certificates,unrelated/'ca.pem',mode,purpose)
            for failure in ('untrusted','hostname'):exercise(fixture,certificates,unrelated/'ca.pem',mode,'invite',failure)
        print(str(h.COUNT)+' SMTP TLS checks passed.')
    finally:fixture['server'].terminate();fixture['server'].wait(timeout=5)
