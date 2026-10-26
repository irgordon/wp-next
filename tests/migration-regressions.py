#!/usr/bin/env python3
"""Check v2 table rebuild identity preservation and truthful migration versioning."""
from pathlib import Path
import os,shutil,sqlite3,subprocess,sys,tempfile
ROOT=Path(__file__).resolve().parent.parent
PHP=sys.argv[1] if len(sys.argv)>1 else 'php'
with tempfile.TemporaryDirectory(prefix='wp-migration-review-') as directory:
    temp=Path(directory);site=temp/'site';runtime=temp/'state';runtime.mkdir()
    shutil.copytree(ROOT,site,ignore=shutil.ignore_patterns('vendor','node_modules','__pycache__','*.sqlite*'))
    path=runtime/'site.sqlite';env=dict(os.environ,WP_ENV='development',WP_VAR_DIR=str(runtime),WP_DB_PATH=str(path));env.pop('WP_AUTH_SALT',None)
    with sqlite3.connect(path) as db:
        db.executescript('''
CREATE TABLE options(option_name TEXT PRIMARY KEY,option_value TEXT NOT NULL) STRICT;
INSERT INTO options VALUES('schema_version','2');INSERT INTO options VALUES('site_installed','1');INSERT INTO options VALUES('site_title','Legacy');INSERT INTO options VALUES('site_url','http://localhost');
CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,capabilities TEXT NOT NULL,created_at INTEGER NOT NULL DEFAULT(unixepoch()),disabled INTEGER NOT NULL DEFAULT 0) STRICT;
INSERT INTO users(id,username,email,password_hash,capabilities) VALUES(7,'legacy','legacy@example.test','not-used','["administrator"]');
CREATE TABLE posts(id INTEGER PRIMARY KEY AUTOINCREMENT,author_id INTEGER NOT NULL REFERENCES users(id),slug TEXT NOT NULL UNIQUE,title TEXT NOT NULL,content_raw TEXT NOT NULL,content_rendered TEXT,status TEXT NOT NULL CHECK(status IN ('draft','published','archived')),post_type TEXT NOT NULL DEFAULT 'post',revision INTEGER NOT NULL DEFAULT 1,rendered_revision INTEGER NOT NULL DEFAULT 0,metadata TEXT,published_at INTEGER,created_at INTEGER NOT NULL DEFAULT(unixepoch()),updated_at INTEGER NOT NULL DEFAULT(unixepoch()),excerpt TEXT NOT NULL DEFAULT '',featured_media_id INTEGER) STRICT;
INSERT INTO posts(id,author_id,slug,title,content_raw,status) VALUES(1,999,'survivor','Surviving record','<p>Body</p>','published');
INSERT INTO posts(id,author_id,slug,title,content_raw,status) VALUES(100,7,'deleted','Deleted record','<p>Body</p>','draft');
DELETE FROM posts WHERE id=100;
''')
    result=subprocess.run([PHP,str(site/'bin/wp'),'upgrade'],env=env,capture_output=True,text=True)
    assert result.returncode!=0 and (runtime/'maintenance.json').exists(),result.stdout+result.stderr
    with sqlite3.connect(path) as db:
        assert db.execute("SELECT option_value FROM options WHERE option_name='schema_version'").fetchone()[0]=='2'
        db.execute('UPDATE posts SET author_id=7 WHERE id=1')
    print('PASS: A failed rebuild/foreign-key validation never advertises the new schema version.')
    result=subprocess.run([PHP,str(site/'bin/wp'),'upgrade'],env=env,capture_output=True,text=True)
    assert result.returncode==0,result.stdout+result.stderr
    with sqlite3.connect(path) as db:
        assert db.execute("SELECT option_value FROM options WHERE option_name='schema_version'").fetchone()[0]=='8'
        db.execute("INSERT INTO posts(author_id,slug,title,content_raw,status) VALUES(7,'new','New','<p>New</p>','draft')")
        assert db.execute('SELECT last_insert_rowid()').fetchone()[0]==101
        assert db.execute('SELECT display_name,public_profile,profile_revision FROM users WHERE id=7').fetchone()==('legacy',0,1)
        assert db.execute("SELECT COUNT(*) FROM post_terms pt JOIN terms t ON t.id=pt.term_id WHERE pt.post_id=1 AND t.slug='uncategorized'").fetchone()[0]==1
        assert not db.execute('PRAGMA foreign_key_check').fetchall()
    print('PASS: Posts-table rebuild preserves the deleted-ID high-water mark, preventing queued work from targeting a reused ID.')
    print('PASS: Existing users receive private profile defaults; existing Posts receive a default category with intact foreign keys.')
