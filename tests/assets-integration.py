#!/usr/bin/env python3
"""Legacy image migration and real-process storage concurrency regressions."""
from pathlib import Path
import base64, hashlib, json, os, shutil, sqlite3, subprocess, sys, tempfile
ROOT=Path(__file__).resolve().parent.parent
PHP=sys.argv[1] if len(sys.argv)>1 else shutil.which('php')
with tempfile.TemporaryDirectory(prefix='wp-asset-integration-') as directory:
    temp=Path(directory);site=temp/'site';runtime=temp/'state';runtime.mkdir()
    shutil.copytree(ROOT,site,ignore=shutil.ignore_patterns('vendor','node_modules','var','__pycache__'))
    env=dict(os.environ,WP_ENV='development',WP_VAR_DIR=str(runtime),WP_DB_PATH=str(runtime/'site.sqlite'));env.pop('WP_AUTH_SALT',None)
    # Derive the real v4 table by removing only the new media migration and substituting its original CREATE statement.
    schema=(ROOT/'wp-includes/schema.php').read_text()
    import re
    old="\t\t$db->query( 'CREATE TABLE IF NOT EXISTS media (id INTEGER PRIMARY KEY,author_id INTEGER NOT NULL REFERENCES users(id),sha256 TEXT NOT NULL UNIQUE,filename TEXT NOT NULL,mime TEXT NOT NULL,original_name TEXT NOT NULL,alt_text TEXT NOT NULL DEFAULT \\\'\\\',caption TEXT NOT NULL DEFAULT \\\'\\\',width INTEGER NOT NULL,height INTEGER NOT NULL,size INTEGER NOT NULL,created_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT' );"
    schema=re.sub(r'const WP_SCHEMA_VERSION = [0-9]+;', 'const WP_SCHEMA_VERSION = 4;', schema).replace('\twp_migrate_media_asset_model( $db );','')
    lines=schema.splitlines();lines=[old if "CREATE TABLE IF NOT EXISTS media (" in x else x for x in lines];schema='\n'.join(lines)
    schema=schema.replace("\t\t$db->query( 'CREATE INDEX IF NOT EXISTS idx_media_sha256 ON media(sha256)' );",'')
    legacy=temp/'schema-v4.php';legacy.write_text(schema)
    setup=temp/'setup.php';setup.write_text('<?php require $argv[1]; require $argv[2]; $db=new \\WordPress\\Database\\WP_SQLite_DB($argv[3]); wp_upgrade_schema($db);')
    subprocess.run([PHP,str(setup),str(site/'wp-includes/class-wp-sqlite-db.php'),str(legacy),str(runtime/'site.sqlite')],check=True,capture_output=True,text=True)
    image=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');digest=hashlib.sha256(image).hexdigest();filename=digest[:2]+'/'+digest+'.png'
    blob=site/'wp-content/uploads'/filename;blob.parent.mkdir(parents=True,exist_ok=True);blob.write_bytes(image)
    with sqlite3.connect(runtime/'site.sqlite') as db:
        db.execute("INSERT INTO users(id,username,email,password_hash,capabilities,avatar_media_id) VALUES(7,'legacy','legacy@example.test','unused','[\"administrator\"]',42)")
        db.execute('INSERT INTO media(id,author_id,sha256,filename,mime,original_name,width,height,size) VALUES(42,7,?,?,?,\'legacy.png\',1,1,?)',(digest,filename,'image/png',len(image)))
        db.execute("INSERT INTO options(option_name,option_value) VALUES('site_installed','1'),('site_url','http://localhost'),('site_logo_id','42')")
        db.execute("INSERT INTO posts(author_id,slug,title,content_raw,status,featured_media_id) VALUES(7,'legacy','Legacy','<p>Legacy</p>','published',42)")
    run=lambda *args:subprocess.run([PHP,str(site/'bin/wp'),*args],env=env,capture_output=True,text=True)
    result=run('upgrade');assert result.returncode==0,result.stdout+result.stderr
    with sqlite3.connect(runtime/'site.sqlite') as db:
        assert db.execute("SELECT option_value FROM options WHERE option_name='schema_version'").fetchone()[0]=='8'
        assert db.execute('SELECT id,filename,sha256,width,height,size,kind,format,visibility,state FROM media').fetchone()==(42,filename,digest,1,1,len(image),'image','png','public','ready')
        assert db.execute('SELECT avatar_media_id FROM users WHERE id=7').fetchone()[0]==42
        assert db.execute('SELECT featured_media_id FROM posts').fetchone()[0]==42
        assert not db.execute('PRAGMA foreign_key_check').fetchall()
    assert blob.read_bytes()==image
    print('PASS: v4 image migration preserves IDs, URLs, bytes, dimensions, avatar/featured references and foreign keys.')
    assert run('upgrade').returncode==0
    print('PASS: Repeating current schema upgrade is idempotent and pre-upgrade backups are produced.')
    status=run('status');assert status.returncode==0 and json.loads(status.stdout)['assets']['registered_bytes']==len(image)
    print('PASS: CLI status reports migrated asset usage.')
    worker=temp/'register.php';worker.write_text('''<?php require $argv[1]; wp_set_current_user(7); $bytes=str_repeat('x',20);$hash=hash('sha256',$bytes);$source=tempnam(WP_VAR_DIR,'concurrent-');file_put_contents($source,$bytes);$staged=wp_asset_stage($source,$hash);$id=wp_asset_register(['author_id'=>7,'sha256'=>$hash,'filename'=>substr($hash,0,2).'/'.$hash,'mime'=>'text/plain','original_name'=>'concurrent.txt','size'=>20,'kind'=>'document','format'=>'txt','visibility'=>'private']); if(is_wp_error($id)){echo 'quota';exit(0);} if(true!==wp_asset_promote($id)){exit(1);} echo 'ready';''')
    with sqlite3.connect(runtime/'site.sqlite') as db:db.execute("INSERT INTO options(option_name,option_value) VALUES('assets_quota_bytes',?)",(str(len(image)+40),))
    processes=[subprocess.Popen([PHP,str(worker),str(site/'wp-includes/bootstrap.php')],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(4)]
    outcomes=[]
    for p in processes:
        out,err=p.communicate(timeout=15);assert p.returncode==0,(out,err);outcomes.append(out)
    assert outcomes.count('ready')==2 and outcomes.count('quota')==2,outcomes
    with sqlite3.connect(runtime/'site.sqlite') as db:assert db.execute('SELECT SUM(size) FROM media').fetchone()[0]==len(image)+40
    print('PASS: Four concurrent uploads admit exactly the two records that fit, including duplicate-byte records.')
    assert run('maintain').returncode==0
    assert blob.exists()
    print('PASS: Maintenance preserves referenced legacy image bytes alongside shared document blobs.')
    # Race cleanup against the legacy rename-before-register window while the writer owns the common lock.
    racer=temp/'racer.php';racer.write_text('''<?php require $argv[1];wp_set_current_user(7);wp_asset_storage_lock(function(){global $wpdb;$bytes='serialized document';$hash=hash('sha256',$bytes);$path=wp_assets_dir().'/'.substr($hash,0,2).'/'.$hash;if(!is_dir(dirname($path))){mkdir(dirname($path),0700,true);}file_put_contents($path,$bytes);touch($path,time()-90000);echo "holding\\n";fflush(STDOUT);usleep(400000);$id=wp_asset_register(['author_id'=>7,'sha256'=>$hash,'filename'=>substr($hash,0,2).'/'.$hash,'mime'=>'text/plain','original_name'=>'race.txt','size'=>strlen($bytes),'kind'=>'document','format'=>'txt','visibility'=>'private']);if(is_wp_error($id)||true!==wp_asset_promote($id)){throw new RuntimeException('race failed');}});''')
    with sqlite3.connect(runtime/'site.sqlite') as db:db.execute("UPDATE options SET option_value='0' WHERE option_name='assets_quota_bytes'")
    p=subprocess.Popen([PHP,str(racer),str(site/'wp-includes/bootstrap.php')],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    assert p.stdout.readline().strip()=='holding'
    cleanup=run('maintain');out,err=p.communicate(timeout=15)
    assert cleanup.returncode==0 and p.returncode==0,(cleanup.stdout,cleanup.stderr,out,err)
    with sqlite3.connect(runtime/'site.sqlite') as db:
        filename=db.execute("SELECT filename FROM media WHERE original_name='race.txt' AND state='ready'").fetchone()[0]
    assert (runtime/'assets'/filename).read_bytes()==b'serialized document'
    print('PASS: Cleanup waits for a concurrent storage writer and cannot delete its newly registered blob.')
    print('6 asset integration checks passed.')
