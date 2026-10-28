#!/usr/bin/env python3
"""Manual fault-confirmation diagnostic for the v0.0.4 application snapshot.

Exit 0 plus four CONFIRMED lines means the known defects reproduced. This
is not a passing feature gate and is intentionally outside CI commands.
All mutations occur in disposable sites, never the installed local site.
Run from any directory, optionally supplying the PHP 8.4+ binary as argv[1].
"""
import sys
from pathlib import Path
REPO=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(REPO/'tests'))
source=(REPO/'tests/portability-http.py').read_text()
exec(compile(source[:source.index("with tempfile.TemporaryDirectory(prefix='wp-portability-')")],str(REPO/'tests/portability-http.py'),'exec'))
import hashlib

def manifest(posts,media=None):
    rows=media or []
    return {'format':'wpnext-portability','version':1,'authors':[],'terms':[],'media':rows,'posts':posts,'counts':{'authors':0,'terms':0,'media':len(rows),'posts':len(posts)}}
def post(source_id,slug,title,metadata=None,featured=0):
    return {'source_id':source_id,'type':'page','status':'published','author_source_id':0,'slug':slug,'title':title,'content_raw':json.dumps({'version':1,'blocks':[{'type':'paragraph','html':title}]}),'excerpt':'','metadata':metadata or {},'featured_source_id':featured,'term_source_ids':[],'published_at':1700000000,'created_at':1700000000,'updated_at':1700000000}
def archive(path,data,files=None):
    with zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED) as bundle:
        bundle.writestr('manifest.json',json.dumps(data))
        for name,body in (files or {}).items():bundle.writestr(name,body)
def literal(value):return "'"+str(value).replace('\\','\\\\').replace("'","\\'")+"'"
def run_native(obj,path,extra=''):
    return php_snippet(obj,"wp_set_current_user(1);$parsed=wp_portability_parse_native("+literal(path)+");if(is_wp_error($parsed)){throw new RuntimeException($parsed->get_error_message());}"+extra)

with tempfile.TemporaryDirectory(prefix='wp-review-probes-') as directory:
    temp=Path(directory);obj=start_site(temp,'probe','Review probe')
    try:
        # Populate a target-side ID collision and a distinct image for import.
        php_snippet(obj,"$im=imagecreatetruecolor(20,20);imagefill($im,0,0,imagecolorallocate($im,190,30,20));imagepng($im,"+literal(temp/'decoy.png')+");imagedestroy($im);$im=imagecreatetruecolor(20,20);imagefill($im,0,0,imagecolorallocate($im,20,60,190));imagepng($im,"+literal(temp/'source.png')+");imagedestroy($im);wp_set_current_user(1);$id=wp_portability_import_image("+literal(temp/'decoy.png')+",'decoy.png','','');if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}")
        image=(temp/'source.png').read_bytes();digest=hashlib.sha256(image).hexdigest()
        media={'source_id':1,'kind':'image','format':'png','visibility':'public','original_name':'source.png','alt_text':'Source image','caption':'','sha256':digest,'path':'files/'+digest+'.png'}
        social=temp/'social.zip';archive(social,manifest([post(101,'review-social','Review social',{'social_image_id':1},1)],[media]),{'files/'+digest+'.png':image})
        result=json.loads(run_native(obj,social,"$result=wp_portability_run($parsed);$row=$wpdb->get_row(\"SELECT * FROM posts WHERE slug='review-social'\");$target=$wpdb->get_row('SELECT * FROM media WHERE id=:id',[':id'=>$result['maps']['media'][1]]);$decoy=$wpdb->get_row('SELECT * FROM media WHERE id=1');echo wp_json_encode(['mapped_media'=>$result['maps']['media'][1],'stored_social'=>json_decode($row['metadata'],true)['social_image_id'],'actual'=>wp_discovery_social_image_url($row),'expected'=>wp_media_url($target),'decoy'=>wp_media_url($decoy)]);"))
        assert result['mapped_media']!=1 and result['actual']==result['decoy'] and result['actual']!=result['expected'],result
        print('CONFIRMED social image ID is copied instead of remapped:',json.dumps(result),flush=True)

        # Interrupt the checkpoint after the content transaction has committed.
        php_snippet(obj,"wp_set_current_user(1);$id=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'Existing collision','post_name'=>'resume-collision','post_content'=>'<p>Existing different body</p>']);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}")
        interrupted=temp/'resume.zip';archive(interrupted,manifest([post(202,'resume-collision','Review interrupted item')]))
        key=run_native(obj,interrupted,"echo wp_portability_state_key($parsed['sha256'],[]);")
        with sqlite3.connect(obj['database']) as db:
            db.execute("CREATE TRIGGER review_checkpoint_failure BEFORE INSERT ON options WHEN NEW.option_name=? BEGIN SELECT RAISE(ABORT,'review interrupted checkpoint'); END".replace('?',"'"+key+"'"))
        failure=run_native(obj,interrupted,"try{wp_portability_run($parsed);echo 'NO-FAULT';}catch(Throwable $error){echo $error->getMessage();}")
        assert 'review interrupted checkpoint' in failure,failure
        first=count(obj['database'],"SELECT COUNT(*) FROM posts WHERE title='Review interrupted item'")
        with sqlite3.connect(obj['database']) as db:db.execute('DROP TRIGGER review_checkpoint_failure')
        resumed=json.loads(run_native(obj,interrupted,"$result=wp_portability_run($parsed);echo wp_json_encode($wpdb->get_results(\"SELECT id,slug FROM posts WHERE title='Review interrupted item' ORDER BY id\"));"))
        assert first==1 and len(resumed)==2,resumed
        print('CONFIRMED resume duplicates a collision-suffixed item after an interrupted checkpoint:',json.dumps(resumed),flush=True)

        # Ordinary WXR image markup is not rewritten to the supplied attachment bytes.
        wxr=temp/'inline.xml';media_zip=temp/'inline-media.zip'
        wxr.write_text('''<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>Review</title><wp:wxr_version>1.2</wp:wxr_version><item><title>Inline attachment</title><wp:post_id>501</wp:post_id><wp:post_type>attachment</wp:post_type><wp:attachment_url>https://source.example/wp-content/uploads/2026/10/source.png</wp:attachment_url></item><item><title>Review inline image</title><wp:post_id>502</wp:post_id><wp:post_type>page</wp:post_type><wp:status>publish</wp:status><wp:post_name>review-inline-image</wp:post_name><content:encoded><![CDATA[<!-- wp:image {"id":501} --><figure class="wp-block-image"><img src="https://source.example/wp-content/uploads/2026/10/source.png" class="wp-image-501" alt="Review inline"/></figure><!-- /wp:image -->]]></content:encoded></item></channel></rss>''')
        with zipfile.ZipFile(media_zip,'w') as bundle:bundle.writestr('source.png',image)
        result=json.loads(php_snippet(obj,"wp_set_current_user(1);$parsed=wp_portability_parse_wxr("+literal(wxr)+","+literal(media_zip)+");if(is_wp_error($parsed)){throw new RuntimeException($parsed->get_error_message());}$result=wp_portability_run($parsed);$row=$wpdb->get_row(\"SELECT * FROM posts WHERE slug='review-inline-image'\");echo wp_json_encode(['media_map'=>$result['maps']['media'],'content'=>$row['content_raw'],'rendered'=>wp_render_content($row['content_raw'])]);"))
        assert result['media_map'] and '<img' not in result['rendered'],result
        print('CONFIRMED WXR inline image is lost despite successfully importing its supplied bytes:',json.dumps(result),flush=True)

        # Exporter produces a success archive above the importer's item limit.
        result=json.loads(php_snippet(obj,"wp_set_current_user(1);$wpdb->transaction(function($db){for($i=1;$i<=2001;$i++){$db->query('INSERT INTO posts(author_id,slug,title,content_raw,post_type) VALUES(1,:slug,:title,:body,\\'page\\')',[':slug'=>'cap-review-'.$i,':title'=>'Cap review '.$i,':body'=>'{\"version\":1,\"blocks\":[]}']);}});$archive=wp_portability_build_export();if(is_wp_error($archive)){throw new RuntimeException($archive->get_error_message());}$parsed=wp_portability_parse_native($archive['path']);echo wp_json_encode(['count'=>$archive['manifest']['counts']['posts'],'export_succeeded'=>true,'import_error'=>is_wp_error($parsed)?$parsed->get_error_message():null]);"))
        assert result['count']>2000 and result['import_error'],result
        print('CONFIRMED successful native export is rejected by the same importer above 2,000 posts:',json.dumps(result),flush=True)
    finally:
        obj['server'].terminate();obj['server'].wait(timeout=5)
