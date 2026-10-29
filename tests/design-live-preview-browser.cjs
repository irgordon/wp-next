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
const temp = fs.mkdtempSync( path.join( os.tmpdir(), 'wp-live-preview-browser-' ) );
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
	if ( ! await navigation.isVisible() ) { await page.getByRole( 'button', { name: 'Menu', exact: true } ).click(); }
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
		await page.goto( base + '/wp-admin/install.php' ); await page.getByRole( 'button', { name: "Let's get started", exact: true } ).click();
		await page.getByLabel( 'Site title', { exact: true } ).fill( 'Private preview studio' ); await page.getByLabel( 'Administrator username' ).fill( 'admin' ); await page.getByLabel( 'Administrator email' ).fill( 'admin@example.test' ); await page.getByLabel( 'Password', { exact: true } ).fill( 'disposable-test-password' );
		await Promise.all( [ page.waitForNavigation(), page.getByRole( 'button', { name: 'Install site', exact: true } ).click() ] );
		await page.getByRole( 'link', { name: 'Log in', exact: true } ).click(); await page.getByLabel( 'Username or email' ).fill( 'admin' ); await page.getByLabel( 'Password', { exact: true } ).fill( 'disposable-test-password' );
		await Promise.all( [ page.waitForURL( '**/wp-admin/index.php' ), page.getByRole( 'button', { name: 'Log in', exact: true } ).click() ] );
		const created = spawnSync( php, [ '-r', 'require $argv[1];wp_set_current_user(1);$id=wp_insert_post(["post_type"=>"page","post_title"=>"Live preview homepage","post_content"=>"<p>LIVE-PREVIEW-HOMEPAGE-BODY</p>","post_status"=>"published"]);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}echo $id;', path.join( site, 'wp-includes/bootstrap.php' ) ], { env: environment, encoding: 'utf8' } );
		assert.equal( created.status, 0, created.stderr ); const home = created.stdout.trim(); assert.match( home, /^\d+$/ );
		const guest = await browser.newContext(); const publicBefore = await ( await guest.request.get( base + '/' ) ).text(); const before = snapshot( database );
		await page.goto( base + '/wp-admin/themes.php' );
		const preview = page.locator( '[data-design-preview]' ), frame = page.frameLocator( '.design-preview-viewport iframe' );
		await page.locator( '[data-design-preview][data-current=true]' ).waitFor();
		check( await frame.locator( 'body' ).evaluate( () => innerWidth ) === 1440, 'Desktop preview uses a full 1440px viewport so the entire content-width range can be inspected' );
		check( await page.locator( '.design-controls' ).evaluate( element => element.getBoundingClientRect().right <= document.querySelector( '[data-design-preview]' ).getBoundingClientRect().left ), 'Desktop controls and the live site preview appear side by side' );
		check( await preview.getByText( 'Changes are not published automatically', { exact: true } ).isVisible(), 'The preview is clearly identified as private' );
		check( await page.locator( '.design-preview-viewport iframe' ).getAttribute( 'sandbox' ) === 'allow-same-origin', 'The site preview has no script, form, popup or top-navigation sandbox permission' );
		await chooseSection( page, 'Styles' );
		await page.getByLabel( 'Accent color', { exact: true } ).fill( '#195b72' );
		await page.getByLabel( 'Content width in pixels', { exact: true } ).fill( '1100' );
		await page.getByLabel( 'Body text size', { exact: true } ).fill( '18' );
		await page.getByLabel( 'Section spacing', { exact: true } ).fill( '40' );
		await page.getByLabel( 'Type style', { exact: true } ).selectOption( 'serif' );
		await page.waitForFunction( () => { const root=document.querySelector('.design-preview-viewport iframe')?.contentDocument;return document.querySelector('[data-design-preview]').dataset.current==='true'&&root?.body&&root.documentElement&&getComputedStyle(root.body).fontSize==='18px'&&getComputedStyle(root.documentElement).getPropertyValue('--content-width').trim()==='1100px'; } );
		check( await frame.locator( ':root' ).evaluate( root => getComputedStyle( root ).getPropertyValue( '--accent' ).trim() ) === '#195b72', 'Unsaved colors, width and body typography reach the actual site renderer' );
		await chooseSection( page, 'Theme' ); await page.getByLabel( 'Studio', { exact: true } ).check();
		await page.locator( '.design-preview-viewport iframe[data-theme=studio]' ).waitFor(); await page.locator( '[data-design-preview][data-current=true]' ).waitFor();
		check( await frame.locator( 'link[rel=stylesheet]' ).first().getAttribute( 'href' ).then( url => url.includes( '/studio/' ) ), 'Changing the theme previews its real public stylesheet without publication' );
		await chooseSection( page, 'Navigation' ); await page.getByLabel( 'Homepage', { exact: true } ).selectOption( home );
		await frame.getByRole( 'heading', { name: 'Live preview homepage', exact: true } ).waitFor();
		check( await frame.getByText( 'LIVE-PREVIEW-HOMEPAGE-BODY', { exact: true } ).isVisible(), 'The selected published Page becomes the private preview homepage' );
		await chooseSection( page, 'Header' );
		await page.getByLabel( 'Header shared section', { exact: true } ).fill( '<p>PRIVATE-LIVE-HEADER</p><script>window.__previewEscaped=true</script>' );
		const menu = page.locator( '[data-menu-editor=header]' ); await menu.getByLabel( 'Label', { exact: true } ).fill( 'Private navigation' ); await menu.getByLabel( 'Address', { exact: true } ).fill( '/preview-link/' );
		await frame.getByText( 'PRIVATE-LIVE-HEADER', { exact: true } ).waitFor(); await frame.getByRole( 'link', { name: 'Private navigation', exact: true } ).waitFor();
		check( await frame.locator( 'script' ).count() === 0 && await page.evaluate( () => ! window.__previewEscaped ), 'Shared-section scripts cannot execute in the frame or administration' );
		await frame.getByRole( 'link', { name: 'Private navigation', exact: true } ).click();
		check( await frame.getByText( 'PRIVATE-LIVE-HEADER', { exact: true } ).isVisible() && await page.locator( '.design-preview-viewport iframe' ).evaluate( element => element.contentWindow.location.href === 'about:srcdoc' ), 'Preview links cannot navigate away or load a public page' );
		await chooseSection( page, 'Footer' ); await page.getByLabel( 'Footer shared section', { exact: true } ).fill( '<p>PRIVATE-LIVE-FOOTER</p>' );
		await frame.getByText( 'PRIVATE-LIVE-FOOTER', { exact: true } ).waitFor();
		check( true, 'Unsaved footer edits update the same site preview' );
		check( await frame.locator( '#site-search' ).evaluate( input => input.readOnly ), 'Preview form fields remain read-only rather than accepting apparent site edits' );
		await preview.getByRole( 'button', { name: 'Mobile preview', exact: true } ).click();
		await page.waitForFunction( () => document.querySelector( '.design-preview-viewport iframe' ).contentWindow.innerWidth === 390 );
		await frame.locator( 'body' ).evaluate( () => scrollTo( 0, 250 ) );
		const previewScroll = await frame.locator( 'body' ).evaluate( () => scrollY );
		await page.getByLabel( 'Footer shared section', { exact: true } ).fill( '<p>PRIVATE-LIVE-FOOTER updated</p>' );
		await frame.getByText( 'PRIVATE-LIVE-FOOTER updated', { exact: true } ).waitFor();
		await page.locator( '[data-design-preview][data-current=true]' ).waitFor();
		console.log( 'Scroll retention:', { before: previewScroll, after: await frame.locator( 'body' ).evaluate( () => scrollY ) } );
		check( previewScroll > 0 && await frame.locator( 'body' ).evaluate( () => scrollY ).then( value => Math.abs( value - previewScroll ) <= 2 ), 'Live refresh retains preview scroll position while editing a shared section' );
		await preview.getByRole( 'button', { name: 'Desktop preview', exact: true } ).click();
		check( JSON.stringify( snapshot( database ) ) === JSON.stringify( before ), 'Typing and previewing create no design revisions and change no live site records' );
		check( await ( await guest.request.get( base + '/' ) ).text() === publicBefore, 'Anonymous visitors continue to receive the original site' );
		await chooseSection( page, 'Styles' ); await page.getByLabel( 'Accent color', { exact: true } ).fill( '#ffffff' );
		await preview.locator( '.design-preview-error' ).waitFor();
		check( await preview.locator( '.design-preview-error' ).innerText().then( text => text.includes( '4.5:1' ) ) && await page.getByLabel( 'Accent color', { exact: true } ).inputValue() === '#ffffff', 'Invalid design values explain why preview paused while retaining the controls' );
		check( await preview.getAttribute( 'data-current' ) === 'false' && await frame.getByText( 'PRIVATE-LIVE-HEADER', { exact: true } ).isVisible(), 'A failed update keeps and labels the last valid preview' );
		await page.getByLabel( 'Accent color', { exact: true } ).fill( '#195b72' ); await page.locator( '[data-design-preview][data-current=true]' ).waitFor();
		await frame.locator( 'body' ).evaluate( () => scrollTo( 0, 0 ) );
		await page.evaluate( () => scrollTo( 0, 0 ) );
		await capture( page, 'live-design-preview-desktop' );
		const outage = async route => { if ( isPreview( route.request() ) ) { await route.fulfill( { status: 503, contentType: 'application/json', body: '{"ok":false,"error":"Fixture preview outage. Try again."}' } ); } else { await route.continue(); } };
		await page.route( '**/wp-admin/themes.php', outage ); await preview.getByRole( 'button', { name: 'Refresh preview', exact: true } ).click();
		await preview.getByText( 'Fixture preview outage. Try again.', { exact: true } ).waitFor();
		check( await preview.locator( '.design-preview-viewport iframe' ).count() === 1, 'A preview connection error does not destroy the last rendered site' );
		await page.unroute( '**/wp-admin/themes.php', outage ); await preview.getByRole( 'button', { name: 'Refresh preview', exact: true } ).click(); await page.locator( '[data-design-preview][data-current=true]' ).waitFor();
		check( await preview.locator( '.design-preview-error' ).isHidden(), 'Refresh recovers the private preview after a connection error' );
		await page.evaluate( () => { window.__originalPreviewFetch=window.fetch;window.fetch=async(url,options)=>{const copy={...options};delete copy.signal;const response=await window.__originalPreviewFetch(url,copy);if(options?.body instanceof FormData&&options.body.get('width')==='990'){await response.clone().text();window.__oldPreviewArrived=true;}return response;}; } );
		let releaseOld, sawOld; const held = new Promise( resolve => { releaseOld=resolve; } ), captured = new Promise( resolve => { sawOld=resolve; } );
		const delayed = async route => { const response=await route.fetch();if(isPreview(route.request())&&/name="width"\r\n\r\n990\r\n/.test(route.request().postData()||'')){sawOld();await held;}await route.fulfill({response}); };
		await page.route( '**/wp-admin/themes.php', delayed ); await page.getByLabel( 'Content width in pixels', { exact: true } ).fill( '990' ); await captured;
		await page.getByLabel( 'Content width in pixels', { exact: true } ).fill( '1200' );
		await page.waitForFunction( () => { const root=document.querySelector('.design-preview-viewport iframe')?.contentDocument;return root?.documentElement&&getComputedStyle(root.documentElement).getPropertyValue('--content-width').trim()==='1200px'; } );
		releaseOld(); await page.waitForFunction( () => window.__oldPreviewArrived ); await page.unroute( '**/wp-admin/themes.php', delayed ); await page.evaluate( () => new Promise( resolve => requestAnimationFrame( () => requestAnimationFrame( resolve ) ) ) );
		check( await frame.locator( ':root' ).evaluate( root => getComputedStyle( root ).getPropertyValue( '--content-width' ).trim() ) === '1200px', 'A delayed older response cannot replace a newer design preview even if cancellation is ignored' );
		await page.evaluate( () => window.fetch=window.__originalPreviewFetch );
		check( await frame.locator( '.site-container' ).evaluate( element => element.getBoundingClientRect().width ) > 1100, 'Changing content width changes the rendered site layout rather than only a token value' );
		await preview.getByRole( 'button', { name: 'Mobile preview', exact: true } ).click();
		check( await frame.locator( 'body' ).evaluate( () => innerWidth ) === 390, 'The mobile device control changes the actual preview viewport to 390px' );
		await page.setViewportSize( { width: 390, height: 844 } ); await page.getByRole( 'button', { name: 'Live preview', exact: true } ).click();
		check( await preview.isVisible() && await page.locator( '.design-controls' ).isHidden() && await page.evaluate( () => document.documentElement.scrollWidth <= innerWidth+1 ), 'Narrow screens offer a usable preview view without horizontal overflow' );
		await capture( page, 'live-design-preview-mobile' );
		await chooseSection( page, 'Styles' );
		check( await page.getByLabel( 'Content width in pixels', { exact: true } ).isVisible() && await page.getByLabel( 'Content width in pixels', { exact: true } ).inputValue() === '1200', 'Selecting a design section returns to controls and retains unsaved edits' );
		check( JSON.stringify( snapshot( database ) ) === JSON.stringify( before ), 'Retry, device changes and responsive navigation still do not save or publish' );
		await Promise.all( [ page.waitForNavigation(), page.getByRole( 'button', { name: 'Save draft', exact: true } ).click() ] );
		check( await page.getByRole( 'status' ).filter( { hasText: 'Design draft saved' } ).isVisible(), 'Save draft remains an explicit user action' );
		check( snapshot(database).design_revisions.length===before.design_revisions.length+1 && JSON.stringify(snapshot(database).options)===JSON.stringify(before.options), 'Saving a draft adds one private version and leaves published options untouched' );
		await Promise.all( [ page.waitForNavigation(), page.getByRole( 'button', { name: 'Publish design', exact: true } ).click() ] );
		check( await page.getByRole( 'status' ).filter( { hasText: 'Site design published' } ).isVisible(), 'Publication still requires the explicit protected Publish design action' );
		const published=await(await guest.request.get(base+'/')).text();
		check( published.includes('PRIVATE-LIVE-HEADER')&&published.includes('PRIVATE-LIVE-FOOTER')&&published.includes('/themes/studio/'), 'Anonymous visitors receive the new design only after publication' );
		check( errors.length===0, 'No JavaScript page errors: '+errors.join('; ') );
		console.log(count+' live design preview browser checks passed.');
	} catch(error) { if(page){await capture(page,'failure');console.error('Preview state:',await page.locator('[data-design-preview]').innerText().catch(()=>''));}throw error;
	} finally { if(browser){await browser.close();}server.kill('SIGTERM');fs.closeSync(log);fs.rmSync(temp,{recursive:true,force:true}); }
}
main().catch(error=>{console.error(error);process.exitCode=1;});
