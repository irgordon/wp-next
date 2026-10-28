#!/usr/bin/env python3
"""Positive v0.0.6 import regressions: real archives, faults and round trips."""
from portability_helpers import *
import hashlib

FAILURES=[]

def fixture_manifest(posts,media=None,authors=None):
    media=media or [];authors=authors or []
    return {'format':'wpnext-portability','version':1,'authors':authors,'terms':[],'media':media,'posts':posts,'counts':{'authors':len(authors),'terms':0,'media':len(media),'posts':len(posts)}}
def fixture_post(source,slug,title,metadata=None,featured=0):
    return {'source_id':source,'type':'page','status':'published','author_source_id':0,'slug':slug,'title':title,'content_raw':json.dumps({'version':1,'blocks':[{'type':'paragraph','html':title}]}),'excerpt':'','metadata':metadata or {},'featured_source_id':featured,'term_source_ids':[],'published_at':1700000000,'created_at':1700000000,'updated_at':1700000000}
def write_archive(path,data,files=None):
    with zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED) as bundle:
        bundle.writestr('manifest.json',json.dumps(data))
        for name,body in (files or {}).items():bundle.writestr(name,body)
def php_string(value):
    return "'"+str(value).replace('\\','\\\\').replace("'","\\'")+"'"
def native(obj,path,extra):
    return php_snippet(obj,"wp_set_current_user(1);$parsed=wp_portability_parse_native("+php_string(path)+");if(is_wp_error($parsed)){throw new RuntimeException($parsed->get_error_message());}"+extra)
def run_case(name,action):
    try:action();print('CASE PASSED:',name,flush=True)
    except Exception as error:FAILURES.append(name);print('CASE FAILED:',name,str(error),flush=True)

def social_case(obj,temp,media,image):
    path=temp/'social.zip';write_archive(path,fixture_manifest([fixture_post(101,'reg-social','Regression social',{'social_image_id':1},1)],[media]),{media['path']:image})
    row=json.loads(native(obj,path,"$r=wp_portability_run($parsed);$p=$wpdb->get_row(\"SELECT * FROM posts WHERE slug='reg-social'\");$m=$wpdb->get_row('SELECT * FROM media WHERE id=:id',[':id'=>$r['maps']['media'][1]]);echo wp_json_encode(['mapped'=>$m['id'],'social'=>json_decode($p['metadata'],true)['social_image_id']??0,'actual'=>wp_discovery_social_image_url($p),'expected'=>wp_media_url($m)]);"))
    check(row['mapped']!=1 and row['social']==row['mapped'] and row['actual']==row['expected'],'Social override follows the explicit source-to-target image map')
    path=temp/'missing-social.zip';write_archive(path,fixture_manifest([fixture_post(102,'reg-social-fallback','Regression social fallback',{'social_image_id':999},1)],[media]),{media['path']:image})
    row=json.loads(native(obj,path,"$r=wp_portability_run($parsed);$p=$wpdb->get_row(\"SELECT * FROM posts WHERE slug='reg-social-fallback'\");$m=$wpdb->get_row('SELECT * FROM media WHERE id=:id',[':id'=>$p['featured_media_id']]);echo wp_json_encode(['metadata'=>json_decode($p['metadata'],true),'actual'=>wp_discovery_social_image_url($p),'expected'=>wp_media_url($m)]);"))
    check('social_image_id' not in row['metadata'] and row['actual']==row['expected'],'An unmapped social override is cleared and falls back to mapped featured media')
    exported=json.loads(php_snippet(obj,"wp_set_current_user(1);$r=wp_portability_build_export();if(is_wp_error($r)){throw new RuntimeException($r->get_error_message());}echo wp_json_encode($r['manifest']);"))
    check(all('_wpnext_import' not in (p['metadata'] or {}) for p in exported['posts']),'Native export strips internal import provenance')
    check(all(m.get('source_url') for m in exported['media']),'Native exports retain original media URLs for legacy HTML remapping')
    nested=fixture_post(103,'reg-nested-html','Regression nested HTML')
    old_media=dict(media);old_media.pop('source_url')
    old_url='https://old.example/wp-content/uploads/'+media['sha256'][:2]+'/'+media['sha256']+'.png'
    nested['content_raw']=json.dumps({'version':1,'blocks':[{'type':'columns','columns':[[{'type':'html','html':'<p>Legacy native</p><img src="'+old_url+'" alt="legacy"/>'}],[{'type':'image','media_id':3,'alt':'Foreign ID','caption':''}]]}]})
    data=fixture_manifest([nested],[old_media]);data['site']={'home':'https://old.example'}
    path=temp/'old-native.zip';write_archive(path,data,{media['path']:image})
    row=json.loads(native(obj,path,"$r=wp_portability_run($parsed);$p=$wpdb->get_row(\"SELECT * FROM posts WHERE slug='reg-nested-html'\");echo wp_json_encode(['rendered'=>wp_render_content($p['content_raw']),'results'=>$r['results']]);"))
    check(row['rendered'].count('<img')==1 and 'old.example' not in row['rendered'] and 'Legacy native' in row['rendered'],'Older native archives remap nested legacy images from the source home and content address')
    check(any('source media #3' in r['detail'] for r in row['results']),'An undeclared block media ID cannot bind to an unrelated target record')

def recovery_case(obj,temp):
    php_snippet(obj,"wp_set_current_user(1);$id=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'Existing collision','post_name'=>'reg-collision','post_content'=>'<p>Existing body</p>']);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}")
    author={'source_id':77,'username':'import-writer','email':'import-writer@example.test','display_name':'Import writer','first_name':'','last_name':'','biography':'','website':''}
    item=fixture_post(202,'reg-collision','Regression interrupted');item['author_source_id']=77
    path=temp/'interrupted.zip';write_archive(path,fixture_manifest([item],authors=[author]))
    key=native(obj,path,"echo wp_portability_state_key($parsed['sha256'],[]);")
    assert re.fullmatch(r'portability_run_[a-f0-9]{64}',key)
    with sqlite3.connect(obj['database']) as db:
        db.execute("CREATE TRIGGER checkpoint_fault BEFORE INSERT ON options WHEN NEW.option_name='"+key+"' AND instr(NEW.option_value,'\"post:202\"')>0 BEGIN SELECT RAISE(ABORT,'checkpoint fault'); END")
    fault=native(obj,path,"try{wp_portability_run($parsed);echo 'NO-FAULT';}catch(Throwable $e){echo $e->getMessage();}")
    check('checkpoint fault' in fault,'The fault is injected after the Post commit, before the progress checkpoint')
    initial=dbrow(obj['database'],"SELECT * FROM posts WHERE title='Regression interrupted'")
    check(initial is not None,'The interrupted item has actually committed')
    jobs_before=count(obj['database'],"SELECT COUNT(*) FROM job_queue WHERE json_extract(payload,'$.post_id')=?",(initial['id'],))
    with sqlite3.connect(obj['database']) as db:db.execute('DROP TRIGGER checkpoint_fault')
    resumed=json.loads(native(obj,path,"$r=wp_portability_run($parsed);echo wp_json_encode($r);"))
    rows=list(sqlite3.connect(obj['database']).execute("SELECT id,slug,author_id,published_at,created_at,updated_at,revision FROM posts WHERE title='Regression interrupted'"))
    check(len(rows)==1 and rows[0][0]==initial['id'],'Retry recovers the committed collision-suffixed Post without duplicating it')
    check(rows[0][1]=='reg-collision-imported' and rows[0][3:6]==(1700000000,)*3,'Recovery preserves the chosen slug and restores source dates')
    writer=dbrow(obj['database'],"SELECT id FROM users WHERE username='import-writer'")
    check(rows[0][2]==writer['id'] and rows[0][6]==1,'Recovery preserves mapped ownership and does not invent a revision')
    check(count(obj['database'],"SELECT COUNT(*) FROM job_queue WHERE json_extract(payload,'$.post_id')=?",(initial['id'],))==jobs_before,'Recovery does not enqueue duplicate content jobs')
    check(any('Recovered' in r['detail'] for r in resumed['results']),'The resumed result identifies the recovered commit')
    native(obj,path,'wp_portability_run($parsed);')
    check(count(obj['database'],"SELECT COUNT(*) FROM posts WHERE title='Regression interrupted'")==1,'A later ordinary retry is also idempotent')
    # Interruption before the separate author/date correction must also recover.
    item=fixture_post(203,'reg-correction','Regression correction');item['author_source_id']=77
    path2=temp/'correction.zip';write_archive(path2,fixture_manifest([item],authors=[author]))
    with sqlite3.connect(obj['database']) as db:
        db.execute("CREATE TRIGGER correction_fault BEFORE UPDATE ON posts WHEN NEW.slug='reg-correction' BEGIN SELECT RAISE(ABORT,'correction fault'); END")
    fault=native(obj,path2,"try{wp_portability_run($parsed);echo 'NO-FAULT';}catch(Throwable $e){echo $e->getMessage();}")
    check('correction fault' in fault,'A second fault occurs before author/date restoration')
    with sqlite3.connect(obj['database']) as db:db.execute('DROP TRIGGER correction_fault')
    native(obj,path2,'wp_portability_run($parsed);')
    corrected=dbrow(obj['database'],"SELECT * FROM posts WHERE slug='reg-correction'")
    check(corrected['author_id']==writer['id'] and corrected['created_at']==1700000000 and count(obj['database'],"SELECT COUNT(*) FROM posts WHERE title='Regression correction'")==1,'Retry completes author/date restoration on the original inserted item')
    # An operator edit after the interrupted commit must survive recovery.
    item=fixture_post(205,'reg-operator','Regression operator edit');item['author_source_id']=77
    edited=temp/'operator.zip';write_archive(edited,fixture_manifest([item],authors=[author]))
    edit_key=native(obj,edited,"echo wp_portability_state_key($parsed['sha256'],[]);")
    with sqlite3.connect(obj['database']) as db:
        db.execute("CREATE TRIGGER edit_checkpoint_fault BEFORE INSERT ON options WHEN NEW.option_name='"+edit_key+"' AND instr(NEW.option_value,'\"post:205\"')>0 BEGIN SELECT RAISE(ABORT,'edit checkpoint fault'); END")
    native(obj,edited,"try{wp_portability_run($parsed);}catch(Throwable $e){}")
    changed=json.loads(php_snippet(obj,"wp_set_current_user(1);$p=$wpdb->get_row(\"SELECT * FROM posts WHERE slug='reg-operator'\");$r=wp_update_post(['ID'=>$p['id'],'expected_revision'=>$p['revision'],'post_title'=>'Operator retained title','post_content'=>'<p>Operator retained body</p>']);if(is_wp_error($r)){throw new RuntimeException($r->get_error_message());}echo wp_json_encode($wpdb->get_row('SELECT * FROM posts WHERE id=:id',[':id'=>$p['id']]));"))
    with sqlite3.connect(obj['database']) as db:db.execute('DROP TRIGGER edit_checkpoint_fault')
    native(obj,edited,'wp_portability_run($parsed);')
    retained=dbrow(obj['database'],'SELECT * FROM posts WHERE id=?',(changed['id'],))
    check(retained['title']==changed['title'] and retained['content_raw']==changed['content_raw'] and retained['updated_at']==changed['updated_at'] and retained['revision']==changed['revision'],'Recovery preserves operator edits made after the original import commit')
    # Missing source dates retain the original committed fallback timestamps.
    item=fixture_post(206,'reg-undated','Regression undated');item.update(published_at=0,created_at=0,updated_at=0)
    undated=temp/'undated.zip';write_archive(undated,fixture_manifest([item]))
    date_key=native(obj,undated,"echo wp_portability_state_key($parsed['sha256'],[]);")
    with sqlite3.connect(obj['database']) as db:
        db.execute("CREATE TRIGGER date_checkpoint_fault BEFORE INSERT ON options WHEN NEW.option_name='"+date_key+"' BEGIN SELECT RAISE(ABORT,'date checkpoint fault'); END")
    native(obj,undated,"try{wp_portability_run($parsed);}catch(Throwable $e){}")
    first_dates=dbrow(obj['database'],"SELECT published_at,created_at,updated_at FROM posts WHERE slug='reg-undated'")
    with sqlite3.connect(obj['database']) as db:db.execute('DROP TRIGGER date_checkpoint_fault')
    time.sleep(1.1)
    native(obj,undated,'wp_portability_run($parsed);')
    check(dbrow(obj['database'],"SELECT published_at,created_at,updated_at FROM posts WHERE slug='reg-undated'")==first_dates,'Recovery preserves fallback dates when the archive has no source timestamps')
    # A second process holding the same run lock prevents concurrent application.
    locked=temp/'locked.zip';write_archive(locked,fixture_manifest([fixture_post(204,'reg-lock','Regression lock')]))
    lock_key=native(obj,locked,"echo wp_portability_state_key($parsed['sha256'],[]);")
    hold=obj['site'].parent/'hold-lock.php';hold.write_text("<?php require $argv[1];$lock=wp_portability_acquire_run_lock($argv[2]);echo \"LOCKED\\n\";flush();usleep(1500000);wp_portability_release_run_lock($lock);")
    process=subprocess.Popen([PHP,str(hold),str(obj['site']/'wp-includes/bootstrap.php'),lock_key],env=obj['env'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    assert process.stdout.readline().strip()=='LOCKED'
    busy=json.loads(native(obj,locked,'echo wp_json_encode(wp_portability_run($parsed));'))
    process.communicate(timeout=5);assert process.returncode==0
    check(count(obj['database'],"SELECT COUNT(*) FROM posts WHERE slug='reg-lock'")==0 and busy['results'][0]['status']=='failed','Concurrent application of the same archive is refused without a write')
    native(obj,locked,'wp_portability_run($parsed);')
    check(count(obj['database'],"SELECT COUNT(*) FROM posts WHERE slug='reg-lock'")==1,'The import succeeds normally after its run lock is released')

def wxr_case(obj,temp,image):
    xml=temp/'inline.xml';bundle=temp/'inline.zip'
    url='https://source.example/wp-content/uploads/source.png';doc='https://source.example/wp-content/uploads/readme.txt'
    xml.write_text('''<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>Regression</title><wp:wxr_version>1.2</wp:wxr_version><item><title>Photo</title><wp:post_id>501</wp:post_id><wp:post_type>attachment</wp:post_type><wp:attachment_url>'''+url+'''</wp:attachment_url></item><item><title>Document</title><wp:post_id>502</wp:post_id><wp:post_type>attachment</wp:post_type><wp:attachment_url>'''+doc+'''</wp:attachment_url></item><item><title>Regression inline</title><wp:post_id>503</wp:post_id><wp:post_type>page</wp:post_type><wp:status>publish</wp:status><wp:post_name>reg-inline</wp:post_name><content:encoded><![CDATA[<p>Before café</p><!-- wp:image {"id":501} --><figure><img src="'''+url+'''" class="wp-image-501" srcset="https://source.example/remote.png 300w" alt="source"/><figcaption>Caption</figcaption></figure><!-- /wp:image --><img src="'''+url+'''" alt="plain"/><a href="'''+doc+'''">Read document</a><p>After</p>]]></content:encoded></item></channel></rss>''')
    with zipfile.ZipFile(bundle,'w') as archive:
        archive.writestr('source.png',image);archive.writestr('readme.txt',b'Supplied document body')
    code="$p=wp_portability_parse_wxr("+php_string(xml)+","+php_string(bundle)+");if(is_wp_error($p)){throw new RuntimeException($p->get_error_message());}$r=wp_portability_run($p);$post=$wpdb->get_row(\"SELECT * FROM posts WHERE slug='reg-inline'\");$image=$wpdb->get_row('SELECT * FROM media WHERE id=:id',[':id'=>$r['maps']['media'][501]]);$doc=$wpdb->get_row('SELECT * FROM media WHERE id=:id',[':id'=>$r['maps']['media'][502]]);echo wp_json_encode(['rendered'=>wp_render_content($post['content_raw']),'image'=>wp_media_url($image),'document'=>wp_document_url($doc)]);"
    result=json.loads(php_snippet(obj,'wp_set_current_user(1);'+code))
    check(result['rendered'].count('<img')==2 and result['image'] in result['rendered'],'WXR inline images use the supplied mapped image bytes, with or without an ID class')
    check(result['document'] in result['rendered'],'WXR legacy attachment links use the mapped document URL')
    check('source.example' not in result['rendered'] and 'Before café' in result['rendered'] and 'Caption' in result['rendered'] and 'After' in result['rendered'],'Remote srcset is removed while surrounding text, UTF-8 and captions survive')
    # JPEG attachments with the .jpeg extension are part of the supported raster set.
    jpeg_xml=temp/'inline-jpeg.xml';jpeg_bundle=temp/'inline-jpeg.zip'
    jpeg_xml.write_text(xml.read_text().replace('source.png','source.jpeg').replace('reg-inline','reg-inline-jpeg'))
    php_snippet(obj,"$im=imagecreatefrompng("+php_string(temp/'source.png')+");imagejpeg($im,"+php_string(temp/'source.jpeg')+",90);imagedestroy($im);")
    with zipfile.ZipFile(jpeg_bundle,'w') as archive:
        archive.writestr('source.jpeg',(temp/'source.jpeg').read_bytes());archive.writestr('readme.txt',b'Supplied document body')
    jpeg=json.loads(php_snippet(obj,"wp_set_current_user(1);$p=wp_portability_parse_wxr("+php_string(jpeg_xml)+","+php_string(jpeg_bundle)+");$r=wp_portability_run($p);$post=$wpdb->get_row(\"SELECT * FROM posts WHERE slug='reg-inline-jpeg'\");echo wp_json_encode(['rendered'=>wp_render_content($post['content_raw']),'mapped'=>$r['maps']['media'][501]??0]);"))
    check(jpeg['mapped']>0 and jpeg['rendered'].count('<img')==2 and '.jpg' in jpeg['rendered'],'WXR .jpeg inline attachments use the normal JPEG intake and mapped local URLs')

    # Missing supplied bytes remain safe and produce a per-item report.
    missing=json.loads(php_snippet(obj,"wp_set_current_user(1);$p=wp_portability_parse_wxr("+php_string(xml)+",null);$p['model']['posts'][0]['slug']='reg-inline-missing';$r=wp_portability_run($p);$post=$wpdb->get_row(\"SELECT * FROM posts WHERE slug='reg-inline-missing'\");echo wp_json_encode(['rendered'=>wp_render_content($post['content_raw']),'results'=>$r['results']]);"))
    check('<img' not in missing['rendered'] and 'Before café' in missing['rendered'] and 'Read document' in missing['rendered'],'Missing legacy attachments do not bind to foreign IDs and retain ordinary text')
    check(any('source media #501' in r['detail'] and 'source media #502' in r['detail'] for r in missing['results']),'Unresolved legacy attachment references are reported per item')

def export_case(obj,temp):
    # This is a valid large site, not a forged input manifest.
    boundary=json.loads(php_snippet(obj,"wp_set_current_user(1);$n=(int)$wpdb->get_row(\"SELECT COUNT(*) AS n FROM posts WHERE status<>'trashed'\")['n'];$wpdb->transaction(function($db)use($n){for($i=$n+1;$i<=2000;$i++){$db->query(\"INSERT INTO posts(author_id,slug,title,content_raw,post_type) VALUES(1,:slug,:title,:body,'page')\",[':slug'=>'cap-'.$i,':title'=>'Cap '.$i,':body'=>'{\"version\":1,\"blocks\":[]}']);}});$r=wp_portability_build_export();if(is_wp_error($r)){throw new RuntimeException($r->get_error_message());}$parsed=wp_portability_parse_native($r['path']);echo wp_json_encode(['count'=>$r['manifest']['counts']['posts'],'readable'=>!is_wp_error($parsed)]);"))
    check(boundary['count']==2000 and boundary['readable'],'An export at the 2,000-record boundary is accepted by the same native importer')
    result=json.loads(php_snippet(obj,"wp_set_current_user(1);$wpdb->query(\"INSERT INTO posts(author_id,slug,title,content_raw,post_type) VALUES(1,'cap-over','Cap over',:body,'page')\",[':body'=>'{\"version\":1,\"blocks\":[]}']);$before=glob(wp_portability_workdir().'/export-*.zip')?:[];$r=wp_portability_build_export();$after=glob(wp_portability_workdir().'/export-*.zip')?:[];echo wp_json_encode(['refused'=>is_wp_error($r),'code'=>is_wp_error($r)?$r->get_error_code():null,'new_files'=>count(array_diff($after,$before))]);"))
    check(result['refused'] and result['code']=='export_limits' and result['new_files']==0,'A site above 2,000 records is refused before creating an unusable export archive')

    # The format limit contract also covers bytes and ZIP entry count.
    bounds=json.loads(php_snippet(obj,"$base=['manifest'=>10,'entries'=>WP_PORTABILITY_MAX_ENTRIES,'largest'=>WP_PORTABILITY_MAX_ENTRY_BYTES,'total'=>WP_PORTABILITY_MAX_TOTAL_BYTES];$results=['boundary'=>null===wp_portability_export_bounds_error($base)];foreach(['manifest'=>WP_PORTABILITY_MAX_MANIFEST_BYTES+1,'entries'=>WP_PORTABILITY_MAX_ENTRIES+1,'largest'=>WP_PORTABILITY_MAX_ENTRY_BYTES+1,'total'=>WP_PORTABILITY_MAX_TOTAL_BYTES+1]as$key=>$value){$s=$base;$s[$key]=$value;$results[$key]=is_wp_error(wp_portability_export_bounds_error($s));}echo wp_json_encode($results);"))
    check(all(bounds.values()),'Export bounds accept exact limits and refuse manifest, entry, single-file and total-byte excess')

with tempfile.TemporaryDirectory(prefix='wp-portability-regressions-') as directory:
    temp=Path(directory);obj=start_site(temp,'site','Portability regressions')
    try:
        php_snippet(obj,"$im=imagecreatetruecolor(20,20);imagefill($im,0,0,imagecolorallocate($im,190,30,20));imagepng($im,"+php_string(temp/'decoy.png')+");imagedestroy($im);$im=imagecreatetruecolor(20,20);imagefill($im,0,0,imagecolorallocate($im,20,60,190));imagepng($im,"+php_string(temp/'source.png')+");imagedestroy($im);wp_set_current_user(1);$id=wp_portability_import_image("+php_string(temp/'decoy.png')+",'decoy.png','','');if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}")
        image=(temp/'source.png').read_bytes();digest=hashlib.sha256(image).hexdigest()
        media={'source_id':1,'source_url':'https://source.example/source.png','kind':'image','format':'png','visibility':'public','original_name':'source.png','alt_text':'Source','caption':'','sha256':digest,'path':'files/'+digest+'.png'}
        run_case('social mapping',lambda:social_case(obj,temp,media,image))
        run_case('interrupted recovery',lambda:recovery_case(obj,temp))
        run_case('WXR legacy attachments',lambda:wxr_case(obj,temp,image))
        run_case('export compatibility bounds',lambda:export_case(obj,temp))
        print(str(h.COUNT)+' portability regression checks passed; '+str(len(FAILURES))+' failed cases.',flush=True)
        if FAILURES:raise AssertionError('Failed cases: '+', '.join(FAILURES))
    finally:
        obj['server'].terminate();obj['server'].wait(timeout=5)
