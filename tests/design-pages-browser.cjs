'use strict';
/** Real unsaved-design rendering, privacy, asynchronous ordering and explicit publication. */
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const net = require( 'node:net' );
const { spawn, spawnSync } = require( 'node:child_process' );
const assert = require( 'node:assert/strict' );
const { chromium } = require( 'playwright' );
const source = path.resolve( __dirname, '..' );
const temp = fs.mkdtempSync( path.join( os.tmpdir(), 'wp-design-pages-browser-' ) );
const site = path.join( temp, 'site' ), runtime = path.join( temp, 'runtime' );
const php = process.env.PHP_BINARY || 'php';
let count = 0;
function check( value, message ) { assert.ok( value, message ); ++count; console.log( 'PASS: ' + message ); }
async function capture( page, name ) {
	if ( ! process.env.QA_DIR ) { return; }
	fs.mkdirSync( process.env.QA_DIR, { recursive: true } );
	await page.screenshot( { path: path.join( process.env.QA_DIR, name + '.png' ), fullPage: false } );
}
function isPreview( request ) { return request.method() === 'POST' && /name="design_action"\r\n\r\nlive_preview\r\n/.test( request.postData() || '' ); }
async function chooseSection( page, name ) {
	const navigation = page.getByRole( 'navigation', { name: 'Design workspace' } );
	if ( ! await navigation.getByRole( 'link', { name, exact: true } ).isVisible() ) { await page.getByRole( 'button', { name: 'Menu', exact: true } ).click(); }
	await navigation.getByRole( 'link', { name, exact: true } ).click();
}
function snapshot( database ) {
	const result = spawnSync( php, [ '-r', '$db=new PDO("sqlite:".$argv[1]);$out=[];foreach(["options","design_revisions","posts","media"]as$table){$out[$table]=$db->query("SELECT * FROM ".$table." ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);}echo json_encode($out);', database ], { encoding: 'utf8' } );
	assert.equal( result.status, 0, result.stderr ); return JSON.parse( result.stdout );
}
async function main() {
	assert.ok( ! ( process.env.BROWSER_ARGS || '' ).includes( '--disable-web-security' ) );
	fs.cpSync( source, site, { recursive: true, filter: current => ! /^(vendor|node_modules|var|\.git)(\/|$)/.test( path.relative( source, current ) ) } );
	const listener = net.createServer(); await new Promise( resolve => listener.listen( 0, '127.0.0.1', resolve ) );
	const port = listener.address().port; await new Promise( resolve => listener.close( resolve ) );
	const base = 'http://127.0.0.1:' + port, database = path.join( runtime, 'data/site.sqlite' );
	const environment = { ...process.env, WP_ENV: 'development', WP_VAR_DIR: runtime, WP_DB_PATH: database, WP_PHP_CLI: php }; delete environment.WP_AUTH_SALT;
	const log = fs.openSync( path.join( temp, 'server.log' ), 'w' );
	const server = spawn( php, [ '-d', 'display_errors=0', '-S', '127.0.0.1:' + port, '-t', site, path.join( site, 'tools/router.php' ) ], { env: environment, stdio: [ 'ignore', log, log ] } );
	let browser, page; const errors = [];
	try {
		for ( let attempt = 0; attempt < 100; attempt++ ) { try { await fetch( base ); break; } catch ( error ) { await new Promise( resolve => setTimeout( resolve, 30 ) ); } }
		browser = await chromium.launch( { executablePath: process.env.BROWSER_PATH || undefined, args: process.env.BROWSER_ARGS ? JSON.parse( process.env.BROWSER_ARGS ) : [], headless: true } );
		const context = await browser.newContext( { viewport: { width: 1680, height: 1050 } } );
		page = await context.newPage(); page.on( 'pageerror', error => errors.push( error.message ) );
		await page.goto( base + '/wp-admin/install.php' ); await page.getByRole( 'button', { name: 'Next', exact: true } ).click();
		await page.getByLabel( 'Site title', { exact: true } ).fill( 'Private preview studio' ); await page.getByLabel( 'Administrator username' ).fill( 'admin' ); await page.getByLabel( 'Administrator email' ).fill( 'admin@example.test' ); await page.getByLabel( 'Password', { exact: true } ).fill( 'disposable-test-password' );
		await Promise.all( [ page.waitForNavigation(), page.getByRole( 'button', { name: 'Install site', exact: true } ).click() ] );
		await page.getByRole( 'link', { name: 'Log in', exact: true } ).click(); await page.getByLabel( 'Username or email' ).fill( 'admin' ); await page.getByLabel( 'Password', { exact: true } ).fill( 'disposable-test-password' );
		await Promise.all( [ page.waitForURL( '**/wp-admin/index.php' ), page.getByRole( 'button', { name: 'Log in', exact: true } ).click() ] );
		const created = spawnSync( php, [ '-r', 'require $argv[1];wp_set_current_user(1);$id=wp_insert_post(["post_type"=>"page","post_title"=>"Live preview homepage","post_content"=>"<p>LIVE-PREVIEW-HOMEPAGE-BODY</p>","post_status"=>"published"]);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}echo $id;', path.join( site, 'wp-includes/bootstrap.php' ) ], { env: environment, encoding: 'utf8' } );
		assert.equal( created.status, 0, created.stderr ); const home = created.stdout.trim(); assert.match( home, /^\d+$/ );

        const extra = spawnSync(php,['-r','require $argv[1];wp_set_current_user(1);foreach([["Workspace draft","draft","ORIGINAL-DRAFT-BODY"],["Workspace archived","archived","ARCHIVED-BODY"],["Workspace trash","draft","TRASH-BODY"]]as$r){$id=wp_insert_post(["post_type"=>"page","post_title"=>$r[0],"post_content"=>json_encode(["version"=>1,"blocks"=>[["type"=>"paragraph","html"=>$r[2]]]]),"post_status"=>$r[1]]);if($r[0]==="Workspace trash"){wp_trash_post($id);}}for($i=1;$i<=25;$i++){wp_insert_post(["post_type"=>"page","post_title"=>sprintf("List fixture %02d",$i),"post_content"=>"<p>List body</p>","post_status"=>"published"]);}',path.join(site,'wp-includes/bootstrap.php')],{env:environment,encoding:'utf8'});
        assert.equal(extra.status,0,extra.stderr);
        const before=snapshot(database), guest=await browser.newContext();const publicBefore=await(await guest.request.get(base+'/')).text();
        await page.goto(base+'/wp-admin/themes.php');
        await page.locator('[data-design-preview][data-current=true]').waitFor();
        await chooseSection(page,'Styles');await page.getByLabel('Body text size',{exact:true}).fill('18');
        await chooseSection(page,'Pages');
        const pages=page.locator('[data-design-pages]'), search=page.getByLabel('Search Pages',{exact:true}), status=page.getByLabel('Page status',{exact:true});
        check(await pages.locator('.design-page-row').count()===20,'The Page list starts with a bounded first page');
        await pages.getByRole('button',{name:'Next Pages',exact:true}).click();await pages.getByText('Page 2 of 2',{exact:true}).waitFor();
        check(await pages.locator('.design-page-row').count()>0&&await pages.locator('.design-page-row').count()<20,'Pagination loads the remaining Pages');
        await search.fill('Workspace');await pages.getByText('2 Pages',{exact:true}).waitFor();
        check(await pages.getByRole('button',{name:'Workspace draft',exact:true}).isVisible()&&await pages.getByRole('button',{name:'Workspace archived',exact:true}).isVisible()&&await pages.getByText('Workspace trash',{exact:true}).count()===0,'Search includes editable drafts and archived Pages while excluding Trash');
        await status.selectOption('draft');await pages.getByText('1 Page',{exact:true}).waitFor();
        check(await pages.locator('.design-page-row').count()===1,'Status filtering combines with the Page search');
        await search.fill('No page matches this');await pages.getByText('No Pages found. Clear or change the search and status filter.',{exact:true}).waitFor();
        check(true,'An empty search gives actionable feedback');
        await search.fill('Workspace');await pages.getByRole('button',{name:'Workspace draft',exact:true}).click();
        const preview=page.frameLocator('.design-preview-viewport iframe');
        await preview.getByText('ORIGINAL-DRAFT-BODY',{exact:true}).waitFor();await page.locator('[data-design-preview][data-current=true]').waitFor();
        check(await page.getByLabel('Homepage',{exact:true}).inputValue()==='0','Selecting a Page does not change the actual homepage control');
        check(JSON.stringify(snapshot(database))===JSON.stringify(before),'Page browsing and draft preview do not persist any changes');
        check(await(await guest.request.get(base+'/')).text()===publicBefore,'Draft Page previews remain invisible to anonymous visitors');
        await capture(page,'page-list-preview-desktop');
        await page.getByRole('button',{name:'Open visual editor',exact:true}).click();
        const editor=page.frameLocator('.design-page-editor-host iframe'), canvas=editor.frameLocator('#builder-canvas-frame');
        await canvas.getByRole('textbox',{name:'Edit paragraph on page',exact:true}).waitFor();
        check(page.url().includes('/themes.php'),'The visual editor opens without leaving the design workspace document');
        await page.waitForFunction(()=>{const outer=document.querySelector('.design-page-editor-host iframe')?.contentDocument;const inner=outer?.querySelector('#builder-canvas-frame')?.contentDocument;return inner?.body&&getComputedStyle(inner.body).fontSize==='18px';});
        await capture(page,'page-visual-editor-desktop');
        check(true,'The embedded canvas receives the unsaved private design typography');
        await canvas.getByRole('textbox',{name:'Edit paragraph on page',exact:true}).fill('UNSAVED-PAGE-MARKER');
        await page.getByRole('button',{name:'Back to page list',exact:true}).click();
        await page.getByRole('dialog',{name:'Unsaved Page changes'}).waitFor();
        await page.getByRole('button',{name:'Keep editing',exact:true}).click();
        check(await canvas.getByText('UNSAVED-PAGE-MARKER',{exact:true}).isVisible(),'Keeping the editor retains unsaved Page content');
        await page.getByRole('button',{name:'Back to page list',exact:true}).click();await page.getByRole('button',{name:'Discard Page edits',exact:true}).click();
        check(await pages.isVisible()&&await page.getByLabel('Body text size',{exact:true}).inputValue()==='18','Discarding Page edits returns to the list without losing design changes');
        check(JSON.stringify(snapshot(database))===JSON.stringify(before),'Discarding unsaved Page edits does not alter server state');
        await page.getByRole('button',{name:'Open visual editor',exact:true}).click();await canvas.getByRole('textbox',{name:'Edit paragraph on page',exact:true}).fill('SAVED-DRAFT-PAGE-BODY');
        await canvas.locator('#canvas-page-title').fill('Workspace draft revised');
        await page.getByRole('button',{name:'Publish design',exact:true}).click();await page.getByRole('dialog',{name:'Unsaved Page changes'}).waitFor();
        check(JSON.stringify(snapshot(database).options)===JSON.stringify(before.options),'Design publication cannot silently drop unsaved Page edits');
        await page.getByRole('button',{name:'Keep editing',exact:true}).click();
        await editor.getByRole('button',{name:'Save Page',exact:true}).click();
        await page.waitForFunction(()=>document.querySelector('.design-page-editor-host iframe')?.contentDocument?.querySelector('#post-editor')?.dataset.saved==='1');
        await page.getByRole('button',{name:'Back to page list',exact:true}).click();
        await pages.getByRole('button',{name:'Workspace draft revised',exact:true}).waitFor();
        const after=snapshot(database), row=after.posts.find(row=>row.title==='Workspace draft revised');
        check(row.status==='draft'&&row.content_raw.includes('SAVED-DRAFT-PAGE-BODY'),'Saving the embedded editor retains the Page publication status and actual content');
        check(JSON.stringify(after.options)===JSON.stringify(before.options)&&after.design_revisions.length===before.design_revisions.length,'Saving a Page does not save or publish the site design');
        check(await(await guest.request.get(base+'/')).text()===publicBefore,'Saving a draft Page leaves public site output unchanged');
        check(await page.getByLabel('Body text size',{exact:true}).inputValue()==='18','Unsaved site-design values survive Page saving and editor return');
        await preview.getByText('SAVED-DRAFT-PAGE-BODY',{exact:true}).waitFor();
        check(true,'The selected Page preview refreshes after a successful save');
        await page.getByRole('button',{name:'Preview homepage',exact:true}).click();await page.locator('.design-preview-viewport iframe[data-page-id="0"]').waitFor();
        check(await page.getByRole('button',{name:'Open visual editor',exact:true}).isHidden(),'Returning to the homepage clears Page-only editing actions');
        await page.setViewportSize({width:390,height:844});await page.waitForFunction(()=>document.querySelector('.menu-toggle')?.getAttribute('aria-expanded')==='false');await chooseSection(page,'Pages');
        await pages.getByRole('button',{name:'Workspace draft revised',exact:true}).click();await page.getByRole('button',{name:'Live preview',exact:true}).click();
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Page-list selection and preview fit a narrow workspace');
        await page.locator('[data-design-preview][data-current=true]').waitFor();
        await preview.getByText('SAVED-DRAFT-PAGE-BODY',{exact:true}).waitFor();
        await page.getByRole('button',{name:'Mobile preview',exact:true}).click();
        await capture(page,'page-list-preview-mobile');
        await page.getByRole('button',{name:'Open visual editor',exact:true}).click();
        await canvas.getByRole('textbox',{name:'Edit paragraph on page',exact:true}).waitFor();
        check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'The embedded editor fits the mobile design workspace');
        await capture(page,'page-visual-editor-mobile');
        check(errors.length===0,'No JavaScript page errors: '+errors.join('; '));
        console.log(count+' design Page workspace browser checks passed.');
	} catch(error) { if(page){await capture(page,'failure');console.error('Preview state:',await page.locator('[data-design-preview]').innerText().catch(()=>''));}throw error;
	} finally { if(browser){await browser.close();}server.kill('SIGTERM');fs.closeSync(log);fs.rmSync(temp,{recursive:true,force:true}); }
}
main().catch(error=>{console.error(error);process.exitCode=1;});
