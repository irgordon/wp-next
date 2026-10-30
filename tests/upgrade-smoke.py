#!/usr/bin/env python3
"""Upgrade a real prior schema with native PHP, including failure sealing."""
from pathlib import Path
import os,shutil,sqlite3,subprocess,tempfile,socket,time,urllib.request,urllib.error
ROOT=Path(__file__).resolve().parent.parent
PHP=os.environ.get('PHP_BINARY','php')
if __name__=='__main__':
    import sys
    if len(sys.argv)>1:PHP=sys.argv[1]
    with tempfile.TemporaryDirectory(prefix='wp-upgrade-') as name:
        temp=Path(name);site=temp/'site';runtime=temp/'runtime';runtime.mkdir()
        shutil.copytree(ROOT,site,ignore=shutil.ignore_patterns('vendor','node_modules','__pycache__','*.sqlite*','auth.key','maintenance.json'))
        path=runtime/'site.sqlite';env=dict(os.environ,WP_ENV='development',WP_VAR_DIR=str(runtime),WP_DB_PATH=str(path));env.pop('WP_AUTH_SALT',None)
        password=subprocess.check_output([PHP,'-r','echo password_hash($argv[1],PASSWORD_DEFAULT);','disposable-test-password'],text=True)
        with sqlite3.connect(path) as db:
            db.executescript('''CREATE TABLE options(option_name TEXT PRIMARY KEY,option_value TEXT NOT NULL) STRICT;
CREATE TABLE users(id INTEGER PRIMARY KEY,username TEXT NOT NULL UNIQUE,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,capabilities TEXT NOT NULL,created_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT;
CREATE TABLE posts(id INTEGER PRIMARY KEY,author_id INTEGER NOT NULL,slug TEXT NOT NULL UNIQUE,title TEXT NOT NULL,content_raw TEXT NOT NULL,content_rendered TEXT,status TEXT NOT NULL,post_type TEXT NOT NULL DEFAULT 'post',revision INTEGER NOT NULL DEFAULT 1,rendered_revision INTEGER NOT NULL DEFAULT 0,metadata TEXT,published_at INTEGER,created_at INTEGER NOT NULL DEFAULT(unixepoch()),updated_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT;
INSERT INTO options VALUES('site_installed','1');INSERT INTO options VALUES('site_title','Legacy Site');INSERT INTO options VALUES('site_url','http://localhost');
INSERT INTO posts(id,author_id,slug,title,content_raw,status) VALUES(10,7,'legacy-post','Preserved title','<p>Preserved body</p>','draft');''')
            db.execute('INSERT INTO users(id,username,email,password_hash,capabilities) VALUES(7,?,?,?,?)',('admin','admin@example.test',password,'["administrator"]'))
        p=subprocess.run([PHP,str(site/'bin/wp'),'upgrade'],env=env,capture_output=True,text=True)
        assert p.returncode==0,p.stdout+p.stderr
        with sqlite3.connect(path) as db:
            assert db.execute("SELECT option_value FROM options WHERE option_name='schema_version'").fetchone()[0]=='9'
            assert db.execute('SELECT title,content_raw FROM posts WHERE id=10').fetchone()==('Preserved title','<p>Preserved body</p>')
            assert db.execute('SELECT disabled FROM users WHERE id=7').fetchone()[0]==0
            assert db.execute('SELECT COUNT(*) FROM user_sessions').fetchone()[0]==0
        assert not (runtime/'maintenance.json').exists()
        assert len(list((runtime/'backups').glob('before-upgrade-*.tar')))==1
        print('PASS: Prior schema upgrades without losing users or content; backup is created; sessions are revoked; maintenance opens.')
        p=subprocess.run([PHP,str(site/'bin/wp'),'upgrade'],env=env,capture_output=True,text=True)
        assert p.returncode==0,p.stdout+p.stderr
        print('PASS: Repeating upgrade is safe.')
        with sqlite3.connect(path) as db:
            db.executescript("CREATE TRIGGER reject_upgrade BEFORE INSERT ON options WHEN NEW.option_name='schema_version' BEGIN SELECT RAISE(ABORT,'controlled upgrade failure'); END;")
        p=subprocess.run([PHP,str(site/'bin/wp'),'upgrade'],env=env,capture_output=True,text=True)
        assert p.returncode!=0 and (runtime/'maintenance.json').exists(),p.stdout+p.stderr
        print('PASS: Failed schema migration leaves admission sealed.')
        with sqlite3.connect(path) as db:db.execute('DROP TRIGGER reject_upgrade')
        p=subprocess.run([PHP,str(site/'bin/wp'),'upgrade'],env=env,capture_output=True,text=True)
        assert p.returncode==0 and not (runtime/'maintenance.json').exists(),p.stdout+p.stderr
        print('PASS: Corrected upgrade reopens the sealed site.')
