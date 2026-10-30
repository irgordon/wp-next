#!/usr/bin/env python3
"""E1/F1 acceptance: mail settings and delivery honesty, invitations, username
derivation, neutral recovery, scanner-safe magic links, operator recovery,
disable, and separately confirmed user deletion with reassignment."""
from http_helpers import *
import http_helpers as h
import re, socketserver, threading
MESSAGES = []
class Sink(socketserver.BaseRequestHandler):
    def handle(self):
        f = self.request.makefile('rb'); state = {'auth': 0, 'data': False}; buf = []
        self.request.sendall(b'220 sink ready\r\n')
        while True:
            line = f.readline()
            if not line: return
            text = line.decode('utf-8', 'replace').rstrip('\r\n')
            if state['data']:
                if text == '.':
                    MESSAGES.append('\n'.join(buf)); buf = []; state['data'] = False
                    self.request.sendall(b'250 queued\r\n')
                else: buf.append(text)
                continue
            verb = text.split(' ')[0].upper()
            if verb in ('EHLO', 'HELO'): self.request.sendall(b'250-sink\r\n250 AUTH LOGIN\r\n')
            elif verb == 'AUTH':
                state['auth'] = 1; self.request.sendall(b'334 VXNlcm5hbWU6\r\n')
            elif state['auth'] == 1:
                state['auth'] = 2; self.request.sendall(b'334 UGFzc3dvcmQ6\r\n')
            elif state['auth'] == 2:
                state['auth'] = 3; self.request.sendall(b'235 authenticated\r\n')
            elif verb in ('MAIL', 'RCPT', 'RSET'): self.request.sendall(b'250 ok\r\n')
            elif verb == 'DATA':
                state['data'] = True; self.request.sendall(b'354 end with .\r\n')
            elif verb == 'QUIT':
                self.request.sendall(b'221 bye\r\n'); return
            else: self.request.sendall(b'250 ok\r\n')
def link_from(message, action):
    m = re.search(r'action=' + action + r'&token=([a-f0-9]{64})', message.replace('&amp;', '&'))
    return m.group(1) if m else None
def follow(client, path):
    status, body, headers = client.request(path)
    if status in (301, 302) and headers.get('Location'):
        loc = headers['Location']; path2 = loc[loc.find('/', loc.find('://') + 3):] if '://' in loc else loc
        return client.request(path2)
    return status, body, headers
with socketserver.ThreadingTCPServer(('127.0.0.1', 0), Sink) as sink:
    sink_port = sink.server_address[1]
    threading.Thread(target=sink.serve_forever, daemon=True).start()
    with tempfile.TemporaryDirectory(prefix='wp-accounts-') as directory:
        temp = Path(directory); site = temp/'site'; database = temp/'state/site.sqlite'
        shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('vendor', 'node_modules', '__pycache__', 'var'))
        env = dict(os.environ, WP_ENV='development', WP_VAR_DIR=str(temp/'state'), WP_DB_PATH=str(database),
                   WP_MAIL_SMTP_HOST='127.0.0.1', WP_MAIL_SMTP_PORT=str(sink_port), WP_MAIL_SMTP_ENCRYPTION='none',
                   WP_MAIL_SMTP_USERNAME='smtp-user', WP_MAIL_SMTP_PASSWORD='smtp-pass', WP_MAIL_FROM='site@example.test')
        env.pop('WP_AUTH_SALT', None)
        with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        base = 'http://127.0.0.1:' + str(port)
        fixture = temp/'make.php'; fixture.write_text("<?php $i=imagecreatetruecolor(64,48); imagefill($i,0,0,imagecolorallocate($i,10,120,200)); imagepng($i,$argv[1].'/pic.png');")
        subprocess.run([PHP, str(fixture), str(temp)], check=True, capture_output=True, text=True)
        def start_server(e, server_port=None):
            server_port = server_port or port
            log = (temp/'server.log').open('a')
            proc = subprocess.Popen([PHP, '-S', f'127.0.0.1:{server_port}', '-t', str(site), str(site/'tools/router.php')], env=e, stdout=log, stderr=log)
            for _ in range(100):
                if proc.poll() is not None: raise RuntimeError('The test server exited during startup')
                try:
                    with socket.create_connection(('127.0.0.1', server_port), timeout=.1): break
                except OSError: time.sleep(.03)
            return proc
        server = start_server(env)
        try:
            admin = Client(base); _, body, _ = admin.request('/wp-admin/install.php')
            admin.request('/wp-admin/install.php', {'_wpnonce': token(body), 'site_title': 'Accounts test', 'admin_user': 'admin', 'admin_email': 'admin@example.test', 'admin_password': 'disposable-test-password'})
            check(admin.login('admin')[0] == 302, 'Fixture logs in')
            check(dbrow(database, "SELECT option_value FROM options WHERE option_name='schema_version'")['option_value'] == '9', 'Blank install creates schema 9')
            check(dbrow(database, "SELECT COUNT(*) AS n FROM sqlite_master WHERE type='table' AND name='account_tokens'")['n'] == 1, 'Account tokens table exists')
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'writer', 'email': 'writer@example.test', 'password': 'disposable-test-password', 'role': 'author'})
            writer = Client(base); check(writer.login('writer')[0] == 302, 'Writer logs in')
            # Mail settings: the test send uses the environment SMTP and arrives; failure is reported honestly later.
            _, body, _ = admin.request('/wp-admin/options-general.php')
            status, body2, _ = admin.request('/wp-admin/options-general.php', {'_wpnonce': token(body, 'mail-settings'), 'mail_action': 'test'})
            check('Test email accepted' in body2 and any('Mail test' in m for m in MESSAGES), 'Test email is delivered through SMTP with AUTH')
            _, body, _ = admin.request('/wp-admin/options-general.php')
            admin.request('/wp-admin/options-general.php', {'_wpnonce': token(body, 'mail-settings'), 'mail_action': 'save', 'mail_smtp_host': '', 'mail_smtp_port': '587', 'mail_smtp_encryption': 'tls', 'mail_smtp_username': '', 'mail_smtp_password': '', 'mail_from_email': 'site@example.test', 'mail_from_name': 'Accounts Test'})
            check(dbrow(database, "SELECT option_value FROM options WHERE option_name='mail_from_name'")['option_value'] == 'Accounts Test', 'Mail settings save (environment still takes precedence for transport)')
            # Invitations.
            _, body, _ = admin.request('/wp-admin/users.php')
            _, body, _ = admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'action': 'invite', 'invite_email': 'jane.doe+news@example.test', 'invite_role': 'author'})
            check('Invitation sent' in body, 'Invitation reports sent only after delivery')
            invite_mail = [m for m in MESSAGES if 'invited to join' in m]
            check(len(invite_mail) == 1, 'Invitation email arrived at the sink')
            itok = link_from(invite_mail[0], 'invite')
            check(itok is not None and dbrow(database, "SELECT COUNT(*) AS n FROM users WHERE email='jane.doe+news@example.test'")['n'] == 0, 'No account exists before the invitation is accepted')
            guest = Client(base)
            status, body, _ = guest.request('/wp-admin/login.php?action=invite&token=' + itok)
            check(status == 200 and 'jane.doe-news' in body, 'Accept page suggests the derived username from the local part')
            check(dbrow(database, "SELECT COUNT(*) AS n FROM users WHERE email='jane.doe+news@example.test'")['n'] == 0, 'Opening the invitation link creates nothing (GET never consumes)')
            status, body, _ = guest.request('/wp-admin/login.php', {'_wpnonce': token(body, 'invite'), 'action': 'invite', 'token': itok, 'username': 'jane', 'display_name': 'Jane', 'pwd': 'short'})
            check('at least 12 characters' in body and dbrow(database, "SELECT consumed_at FROM account_tokens WHERE purpose='invite'")['consumed_at'] is None, 'A failed accept preserves the invitation')
            status, body, headers = guest.request('/wp-admin/login.php', {'_wpnonce': token(body, 'invite'), 'action': 'invite', 'token': itok, 'username': 'jane', 'display_name': 'Jane', 'pwd': 'disposable-test-password'})
            check(status == 302 and guest.request('/wp-admin/index.php')[0] == 200, 'Accepting creates the account and signs in')
            jane = dbrow(database, "SELECT * FROM users WHERE email='jane.doe+news@example.test'")
            check('"author"' in jane['capabilities'] or 'edit_posts' in jane['capabilities'], 'The invited role is applied')
            status, body, _ = Client(base).request('/wp-admin/login.php?action=invite&token=' + itok)
            check('invalid, expired, or already used' in body, 'Invitation links are single-use')
            _, body, _ = admin.request('/wp-admin/users.php')
            sent_before = len(MESSAGES)
            _, body, _ = admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'action': 'invite', 'invite_email': 'writer@example.test', 'invite_role': 'author'})
            check('already belongs to an account' in body and len(MESSAGES) == sent_before, 'Inviting an existing email is refused in admin and sends nothing')
            # Derivation collision: an existing "sam" pushes the suggestion to sam-2.
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'sam', 'email': 'sam2@example.test', 'password': 'disposable-test-password', 'role': 'author'})
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'action': 'invite', 'invite_email': 'sam@example.test', 'invite_role': 'author'})
            stok = link_from([m for m in MESSAGES if 'invited to join' in m][-1], 'invite')
            status, body, _ = Client(base).request('/wp-admin/login.php?action=invite&token=' + stok)
            check('value="sam-2"' in body, 'A taken derived username gets a numeric suffix')
            # Recovery: neutral, scanner-safe, revokes sessions, rate-limited.
            lp = Client(base)
            status, body, _ = lp.request('/wp-admin/login.php?action=lostpassword')
            _, body, _ = lp.request('/wp-admin/login.php', {'_wpnonce': token(body, 'lostpassword'), 'action': 'lostpassword', 'email': 'writer@example.test'})
            check('If an account exists for that email address' in body, 'Recovery answers neutrally')
            rtok = link_from([m for m in MESSAGES if 'Reset your password' in m][-1], 'rp')
            check(rtok is not None, 'Reset email arrived')
            resetter = Client(base)
            status, body, _ = resetter.request('/wp-admin/login.php?action=rp&token=' + rtok)
            check(status == 200 and 'New password' in body, 'Reset link opens the set-password form')
            check(dbrow(database, "SELECT consumed_at FROM account_tokens WHERE purpose='reset' ORDER BY id DESC LIMIT 1")['consumed_at'] is None, 'Opening the reset link does not consume it')
            probe = Client(base); check(probe.login('writer')[0] == 302, 'The old password still works after merely opening the link')
            _, body, _ = resetter.request('/wp-admin/login.php', {'_wpnonce': token(body, 'rp'), 'action': 'rp', 'token': rtok, 'pwd': 'brand-new-password-1', 'pwd_confirm': 'brand-new-password-1'})
            check(writer.request('/wp-admin/index.php')[0] in (301, 302), 'Completing a reset revokes existing sessions')
            probe2 = Client(base); st, body2, _ = probe2.login_full('writer') if hasattr(probe2, 'login_full') else (None, '', {})
            old_try = Client(base); s_old, b_old, _ = old_try.request('/wp-admin/login.php')
            s_old, b_old, _ = old_try.request('/wp-admin/login.php', {'_wpnonce': token(b_old), 'log': 'writer', 'pwd': 'disposable-test-password', 'redirect_to': 'index.php'})
            check('password you entered' in b_old.lower() or 'incorrect' in b_old.lower() or 'invalid' in b_old.lower(), 'The old password stops working')
            check(Client(base).login('writer', 'brand-new-password-1')[0] == 302, 'The new password works')
            status, body, _ = Client(base).request('/wp-admin/login.php?action=rp&token=' + rtok)
            check('invalid, expired, or already used' in body, 'Reset links are single-use')
            mails_before = len(MESSAGES)
            ghost = Client(base)
            status, body, _ = ghost.request('/wp-admin/login.php?action=lostpassword')
            _, body, _ = ghost.request('/wp-admin/login.php', {'_wpnonce': token(body, 'lostpassword'), 'action': 'lostpassword', 'email': 'ghost@example.test'})
            check('If an account exists for that email address' in body and len(MESSAGES) == mails_before, 'Unknown addresses get the identical answer and no email')
            rl = Client(base)
            for _ in range(6):
                st, b, _ = rl.request('/wp-admin/login.php?action=lostpassword')
                rl.request('/wp-admin/login.php', {'_wpnonce': token(b, 'lostpassword'), 'action': 'lostpassword', 'email': 'writer@example.test'})
            check(dbrow(database, "SELECT COUNT(*) AS n FROM account_tokens WHERE purpose='reset' AND email='writer@example.test'")['n'] == 5, 'Reset issuance is rate-limited per account and purpose')
            # Magic links: off by default, opt-in, confirmation required.
            status, body, _ = Client(base).request('/wp-admin/login.php')
            check('Email me a sign-in link' not in body, 'Magic links are hidden while disabled')
            status, body, _ = Client(base).request('/wp-admin/login.php?action=magic')
            check('not enabled' in body, 'The magic endpoint refuses while disabled')
            _, body, _ = admin.request('/wp-admin/options-general.php')
            admin.request('/wp-admin/options-general.php', {'_wpnonce': token(body, 'mail-settings'), 'mail_action': 'save', 'mail_smtp_host': '', 'mail_smtp_port': '587', 'mail_smtp_encryption': 'tls', 'mail_smtp_username': '', 'mail_smtp_password': '', 'mail_from_email': 'site@example.test', 'mail_from_name': 'Accounts Test', 'magic_links_enabled': '1'})
            status, body, _ = Client(base).request('/wp-admin/login.php')
            check('Email me a sign-in link' in body, 'Enabling magic links surfaces the login option')
            mg = Client(base)
            st, b, _ = mg.request('/wp-admin/login.php?action=magic')
            mg.request('/wp-admin/login.php', {'_wpnonce': token(b, 'magic'), 'action': 'magic', 'email': 'writer@example.test'})
            mtok = link_from([m for m in MESSAGES if 'sign-in link' in m.lower()][-1], 'ml')
            check(mtok is not None, 'Magic link email arrived')
            mclient = Client(base)
            status, body, _ = mclient.request('/wp-admin/login.php?action=ml&token=' + mtok)
            check('Confirm to sign in' in body and mclient.request('/wp-admin/index.php')[0] in (301, 302), 'Opening a magic link asks for confirmation and does not sign in')
            status, body, headers = mclient.request('/wp-admin/login.php', {'_wpnonce': token(body, 'ml'), 'action': 'ml', 'token': mtok})
            check(status == 302 and mclient.request('/wp-admin/index.php')[0] == 200, 'Confirming the magic link signs in')
            status, body, _ = Client(base).request('/wp-admin/login.php?action=ml&token=' + mtok)
            check('invalid, expired, or already used' in body, 'Magic links are single-use')
            # Changing an email never renames the account.
            wc = Client(base); check(wc.login('writer', 'brand-new-password-1')[0] == 302, 'Writer signs in for profile work')
            _, body, _ = wc.request('/wp-admin/profile.php')
            rev = dbrow(database, "SELECT profile_revision FROM users WHERE username='writer'")['profile_revision']
            wc.request('/wp-admin/profile.php', {'_wpnonce': token(body), 'profile_fields': '1', 'display_name': 'Writer', 'first_name': '', 'last_name': '', 'biography': '', 'website': '', 'email': 'writer-renamed@example.test', 'avatar_media_id': '0', 'profile_revision': str(rev), 'current_password': 'brand-new-password-1'})
            row = dbrow(database, "SELECT username,email FROM users WHERE email='writer-renamed@example.test'")
            check(row is not None and row['username'] == 'writer', 'Changing an email does not rename the username')
            # Disable is reversible and kills live sessions at once.
            wid = dbrow(database, "SELECT id FROM users WHERE username='writer'")['id']
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'id': str(wid), 'role': 'author', 'disabled': '1', 'password': ''})
            check(wc.request('/wp-admin/index.php')[0] in (301, 302) and Client(base).login('writer', 'brand-new-password-1')[0] != 302, 'Disabling revokes sessions and blocks sign-in')
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'id': str(wid), 'role': 'author', 'password': ''})
            check(Client(base).login('writer', 'brand-new-password-1')[0] == 302, 'Re-enabling restores sign-in')
            mg2 = Client(base)
            st, b, _ = mg2.request('/wp-admin/login.php?action=magic')
            mg2.request('/wp-admin/login.php', {'_wpnonce': token(b, 'magic'), 'action': 'magic', 'email': 'writer-renamed@example.test'})
            mtok2 = link_from([m for m in MESSAGES if 'sign-in link' in m.lower()][-1], 'ml')
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'id': str(wid), 'role': 'author', 'disabled': '1', 'password': ''})
            status, body, _ = Client(base).request('/wp-admin/login.php?action=ml&token=' + mtok2)
            check('invalid, expired, or already used' in body, 'Disabling an account invalidates an outstanding magic link')
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'id': str(wid), 'role': 'author', 'password': ''})
            # Operator recovery without mail.
            out = cli(site, env, 'user:reset-link', 'writer')
            otok = link_from(out, 'rp')
            check(otok is not None, 'The CLI mints a reset link without mail')
            oc = Client(base); status, body, _ = oc.request('/wp-admin/login.php?action=rp&token=' + otok)
            oc.request('/wp-admin/login.php', {'_wpnonce': token(body, 'rp'), 'action': 'rp', 'token': otok, 'pwd': 'operator-set-password', 'pwd_confirm': 'operator-set-password'})
            check(Client(base).login('writer', 'operator-set-password')[0] == 302, 'The operator link resets the password')
            # Deletion: content made by a victim account.
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'victim', 'email': 'victim@example.test', 'password': 'disposable-test-password', 'role': 'author'})
            victim = Client(base); check(victim.login('victim')[0] == 302, 'Victim logs in')
            _, body, _ = victim.request('/wp-admin/post-new.php?post_type=post')
            _, body, headers = victim.request('/wp-admin/post-new.php?post_type=post', {'_wpnonce': token(body, 'post-editor'), 'post_type': 'post', 'post_title': 'Victim Announcement', 'post_name': 'victim-announcement', 'post_status': 'published', 'post_content': '{"version":1,"blocks":[{"type":"paragraph","html":"Keep me after deletion."}]}'})
            victim_post = dbrow(database, "SELECT * FROM posts WHERE slug='victim-announcement'")
            _, body, _ = victim.request('/wp-admin/media.php')
            boundary = 'wp-acc-boundary'
            pic = (temp/'pic.png').read_bytes()
            parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="_wpnonce"\r\n\r\n{token(body, action_field="upload")}\r\n'.encode(), f'--{boundary}\r\nContent-Disposition: form-data; name="action"\r\n\r\nupload\r\n'.encode(), f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="pic.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + pic + f'\r\n--{boundary}--\r\n'.encode()]
            victim.request('/wp-admin/media.php', b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + boundary})
            vid = dbrow(database, "SELECT id FROM users WHERE username='victim'")['id']
            media_count = dbrow(database, 'SELECT COUNT(*) AS n FROM media WHERE author_id=?', (vid,))['n']
            check(media_count >= 1, 'Victim owns media before deletion')
            status, body, _ = admin.request('/wp-admin/users.php?delete=' + str(vid))
            check('Delete account: victim' in body and f'{media_count} media items' in body and '1 posts and 0 pages' in body, 'The confirmation shows the real content counts')
            status, body, _ = admin.request('/wp-admin/users.php', {'_wpnonce': token(body, action_field='delete_user'), 'action': 'delete_user', 'id': str(vid), 'reassign_to': str(wid)})
            check('Confirm the deletion' in body and dbrow(database, 'SELECT COUNT(*) AS n FROM users WHERE id=?', (vid,))['n'] == 1, 'Deletion requires the explicit confirmation')
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'id': str(wid), 'role': 'author', 'disabled': '1', 'password': ''})
            status, body, _ = admin.request('/wp-admin/users.php?delete=' + str(vid))
            status, body, _ = admin.request('/wp-admin/users.php', {'_wpnonce': token(body, action_field='delete_user'), 'action': 'delete_user', 'id': str(vid), 'reassign_to': str(wid), 'confirm_delete': '1'})
            check('existing enabled account' in body and dbrow(database, 'SELECT COUNT(*) AS n FROM users WHERE id=?', (vid,))['n'] == 1, 'A disabled reassignment target is refused inside the flow')
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'id': str(wid), 'role': 'author', 'password': ''})
            status, body, _ = admin.request('/wp-admin/users.php?delete=' + str(vid))
            status, body, headers = admin.request('/wp-admin/users.php', {'_wpnonce': token(body, action_field='delete_user'), 'action': 'delete_user', 'id': str(vid), 'reassign_to': str(wid), 'confirm_delete': '1'})
            check(status == 302 and dbrow(database, 'SELECT COUNT(*) AS n FROM users WHERE id=?', (vid,))['n'] == 0, 'Confirmed deletion removes the account')
            check(dbrow(database, 'SELECT author_id FROM posts WHERE id=?', (victim_post['id'],))['author_id'] == wid, 'Posts are reassigned, not deleted')
            check(dbrow(database, 'SELECT COUNT(*) AS n FROM media WHERE author_id=?', (wid,))['n'] >= media_count, 'Media rows are reassigned with their lineage intact')
            check(victim.request('/wp-admin/index.php')[0] in (301, 302), 'The deleted account loses its sessions')
            tomb = dbrow(database, "SELECT payload FROM audit_log WHERE action='user_deleted' ORDER BY id DESC LIMIT 1")
            check(tomb is not None and 'victim' in tomb['payload'] and 'victim@example.test' not in tomb['payload'], 'The tombstone keeps id and username but no contact fields')
            check(follow(Client(base), '/victim-announcement/')[0] == 200, 'Reassigned content stays public')
            # Self-deletion and last-enabled-administrator protections.
            aid = dbrow(database, "SELECT id FROM users WHERE username='admin'")['id']
            status, body, _ = admin.request('/wp-admin/users.php?delete=' + str(aid))
            status, body, _ = admin.request('/wp-admin/users.php', {'_wpnonce': token(body, action_field='delete_user'), 'action': 'delete_user', 'id': str(aid), 'reassign_to': str(wid), 'confirm_delete': '1'})
            check('cannot delete your own account' in body and dbrow(database, 'SELECT COUNT(*) AS n FROM users WHERE id=?', (aid,))['n'] == 1, 'Self-deletion is refused')
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'username': 'admin2', 'email': 'admin2@example.test', 'password': 'disposable-test-password', 'role': 'administrator'})
            a2 = dbrow(database, "SELECT id FROM users WHERE username='admin2'")['id']
            _, body, _ = admin.request('/wp-admin/users.php')
            admin.request('/wp-admin/users.php', {'_wpnonce': token(body), 'id': str(a2), 'role': 'administrator', 'disabled': '1', 'password': ''})
            status, body, _ = admin.request('/wp-admin/users.php?delete=' + str(a2))
            status, body, _ = admin.request('/wp-admin/users.php', {'_wpnonce': token(body, action_field='delete_user'), 'action': 'delete_user', 'id': str(a2), 'reassign_to': str(wid), 'confirm_delete': '1'})
            check(dbrow(database, 'SELECT COUNT(*) AS n FROM users WHERE id=?', (a2,))['n'] == 0, 'A disabled administrator can be deleted while an enabled one remains')
        finally:
            server.terminate(); server.wait(timeout=5)
        # Delivery failure is surfaced, never dressed as success.
        with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port2 = sock.getsockname()[1]
        base2 = 'http://127.0.0.1:' + str(port2)
        bad_env = dict(env, WP_MAIL_SMTP_PORT='9')
        server = start_server(bad_env, port2)
        try:
            admin2c = Client(base2); check(admin2c.login('admin')[0] == 302, 'Admin signs in for the failure phase')
            _, body, _ = admin2c.request('/wp-admin/options-general.php')
            status, body, _ = admin2c.request('/wp-admin/options-general.php', {'_wpnonce': token(body, 'mail-settings'), 'mail_action': 'test'})
            check('Test email failed' in body, 'A dead transport is reported as a failure in settings')
            status_row = dbrow(database, "SELECT option_value FROM options WHERE option_name='mail_last_status'")
            check(status_row is not None and '"ok":false' in status_row['option_value'].replace(' ', ''), 'The failed delivery is recorded for operators')
            lp2 = Client(base2)
            status, body, _ = lp2.request('/wp-admin/login.php?action=lostpassword')
            _, body, _ = lp2.request('/wp-admin/login.php', {'_wpnonce': token(body, 'lostpassword'), 'action': 'lostpassword', 'email': 'writer-renamed@example.test'})
            check('If an account exists for that email address' in body, 'Recovery stays neutral even when delivery fails')
            check(dbrow(database, "SELECT COUNT(*) AS n FROM audit_log WHERE action='account_link_delivery_failed'")['n'] >= 1, 'The failed recovery delivery is auditable')
        finally:
            server.terminate()
    print(f'{h.COUNT} account checks passed.')
