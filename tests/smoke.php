<?php
/** Native PHP domain checks. No transaction or authorization shims. */
declare( strict_types=1 );
$root = dirname( __DIR__ );
if ( ! defined( 'WP_ROOT_DIR' ) ) { define( 'WP_ROOT_DIR', $root ); }
foreach ( [ 'class-wp-error.php', 'l10n.php', 'formatting.php', 'security.php', 'psr/container-interface.php', 'psr/event-dispatcher-interfaces.php', 'class-wp-sqlite-db.php', 'schema.php', 'class-wp-authorization-service.php', 'class-wp-job-queue.php', 'class-wp-event-dispatcher.php', 'class-wp-post-mutation-service.php', 'class-wp-job-worker.php', 'theme.php', 'content.php', 'media.php', 'user.php' ] as $file ) { require_once $root . '/wp-includes/' . $file; }
require_once $root . '/wp-admin/class-wp-installer.php';
use WordPress\Database\WP_SQLite_DB;
use WordPress\Installer\WP_Installer;
use WordPress\Post\WP_Post_Mutation_Service;
use WordPress\Queue\WP_Job_Queue;
use WordPress\Jobs\WP_Job_Worker;
use WordPress\Security\WP_Authorization_Service;
$count = 0;
function check( bool $ok, string $message ): void { global $count; if ( ! $ok ) { throw new RuntimeException( $message ); } ++$count; echo 'PASS: ' . $message . "\n"; }
$temp = sys_get_temp_dir() . '/wp-domain-' . bin2hex( random_bytes( 6 ) ); mkdir( $temp, 0700 );
try {
	$installer = new WP_Installer( $temp . '/site.sqlite', $temp );
	$credentials = [ 'username' => 'admin', 'password' => 'disposable-test-password', 'email' => 'admin@example.test' ];
	$result = $installer->install( $credentials, [ 'title' => 'Test', 'url' => 'http://localhost' ] );
	if ( ! extension_loaded( 'dom' ) ) {
		check( is_wp_error( $result ) && 'missing_extensions' === $result->get_error_code(), 'Missing DOM is rejected by installation' );
		check( esc_html( '<a href="javascript:alert(1)">Link</a>' ) === wp_kses_post( '<a href="javascript:alert(1)">Link</a>' ), 'Missing DOM renders escaped text' ); return;
	}
	$zero = new WP_Error( 0, 'Zero code' );
	check( 'Zero code' === $zero->get_error_message() && [ '0' ] === $zero->get_error_codes(), 'Integer zero error codes preserve their message and string contract' );
	$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['HTTPS'] = 'off'; $_SERVER['SERVER_PORT'] = '80';
	check( '/local/' === wp_validate_redirect( '/local/' ) && 'index.php' === wp_validate_redirect( '/\\evil.test' ) && 'index.php' === wp_validate_redirect( 'https:evil.test' ) && 'index.php' === wp_validate_redirect( 'https://evil.test/' ) && 'index.php' === wp_validate_redirect( 'https://localhost:80/' ), 'Redirects reject foreign, malformed, backslash, and cross-scheme targets' );
	check( PHP_VERSION_ID >= 80400 && true === $result, 'Blank installation succeeds on native PHP 8.4+' );
	$result = $installer->install( $credentials, [] ); check( is_wp_error( $result ) && 'already_installed' === $result->get_error_code(), 'Reinstallation is rejected' );
	global $wpdb, $wp_auth_service;
	$wpdb = new WP_SQLite_DB( $temp . '/site.sqlite' ); $wp_auth_service = new WP_Authorization_Service();
	check( 'wal' === $wpdb->get_row( 'PRAGMA journal_mode' )['journal_mode'], 'WAL is enabled' );
	$rollback = new \PDO( 'sqlite:' . $temp . '/rollback.sqlite' ); $rollback->exec( 'CREATE TABLE probe (id INTEGER)' ); $rollback->exec( 'INSERT INTO probe (id) VALUES (42)' ); $rollback->exec( 'PRAGMA journal_mode=DELETE' ); $rollback = null;
	$converted = new WP_SQLite_DB( $temp . '/rollback.sqlite' );
	check( 'wal' === $converted->get_row( 'PRAGMA journal_mode' )['journal_mode'] && 42 === (int) $converted->get_row( 'SELECT id FROM probe' )['id'], 'Connections convert a rollback-journal database to WAL with its rows intact' );
	check( WP_SCHEMA_VERSION === wp_schema_version( $wpdb ), 'Schema version is recorded' );
	foreach ( [ 'javascript:alert(1)', "java\tscript:alert(1)", "java\nscript:alert(1)", '&#106;avascript:alert(1)', 'data:text/html,test' ] as $uri ) { $html = wp_kses_post( '<a href="' . $uri . '" onclick="alert(1)">Link</a>' ); check( ! str_contains( $html, 'href=' ) && ! str_contains( $html, 'onclick=' ), 'Unsafe URI removed: ' . json_encode( $uri ) ); }
	$html = wp_kses_post( '<meta http-equiv="refresh" content="0;url=https://example.invalid"><div id="wp-kses-root" style="position:fixed"><p id="location">Café 日本語</p></div>' );
	check( ! str_contains( $html, '<meta' ) && ! str_contains( $html, 'id=' ) && ! str_contains( $html, 'style=' ) && str_contains( $html, 'Café 日本語' ), 'Envelope exemptions and author IDs are removed while UTF-8 survives' );
	$html = wp_kses_post( '<figure><img src="/wp-content/uploads/aa/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png" alt="Photo" /><figcaption>Caption</figcaption></figure><table><tr><th scope="col">A</th><td>B</td></tr></table>' );
	check( str_contains( $html, '<img' ) && str_contains( $html, '<table' ) && str_contains( $html, 'scope="col"' ), 'Local upload images, figures, and tables survive' );
	$document = [ 'version' => 1, 'blocks' => [ [ 'type' => 'columns', 'columns' => [ [ [ 'type' => 'paragraph', 'html' => '<strong>Nested</strong> <a href="/about">link</a>' ] ], [ [ 'type' => 'code', 'text' => '<script>literal</script>' ] ] ] ] ] ];
	$normalized = wp_normalize_content( wp_json_encode( $document ) );
	check( is_string( $normalized ) && str_contains( wp_render_content( $normalized ), '<strong>Nested</strong>' ) && str_contains( wp_render_content( $normalized ), '&lt;script&gt;literal' ), 'Nested blocks and inline links render through the server schema' );
	$bad = wp_normalize_content( '{"version":1,"blocks":[{"type":"unknown"}]}' ); check( is_wp_error( $bad ), 'Unknown block types are rejected without silent loss' );
	$noop = new class implements \Psr\EventDispatcher\EventDispatcherInterface { public function dispatch( object $event ): object { return $event; } };
	$queue = new WP_Job_Queue( $wpdb ); $service = new WP_Post_Mutation_Service( $wpdb, $queue, $noop, $wp_auth_service );
	$first = $service->insert_post( [ 'post_title' => '日本語のタイトル' ], 1 ); $second = $service->insert_post( [ 'post_title' => '日本語のタイトル' ], 1 );
	check( is_int( $first ) && is_int( $second ) && '日本語のタイトル-2' === $wpdb->get_row( 'SELECT slug FROM posts WHERE id=:id', [ ':id' => $second ] )['slug'], 'Unicode duplicate titles receive deterministic suffixes' );
	$result = $service->update_post( [ 'ID' => $first, 'expected_revision' => 1, 'post_title' => 'Changed', 'post_name' => 'a b/c?d=1#x <>', 'post_content' => $normalized ], 1 );
	check( is_int( $result ) && 'a-b-c-d-1-x' === $wpdb->get_row( 'SELECT slug FROM posts WHERE id=:id', [ ':id' => $first ] )['slug'], 'Updates normalize slugs' );
	check( 1 === (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM post_revisions WHERE post_id=:id', [ ':id' => $first ] )['n'], 'Updates persist revision snapshots' );
	$result = $service->update_post( [ 'ID' => $first, 'expected_revision' => 1, 'post_title' => 'Stale' ], 1 ); check( is_wp_error( $result ) && 'concurrency_conflict' === $result->get_error_code(), 'Stale updates are rejected' );
	$wpdb->query( 'INSERT INTO users(id,username,email,password_hash,capabilities) VALUES(2,\'author\',\'author@example.test\',\'unused\',\'["edit_posts","publish_posts"]\')' );
	$page = $service->insert_post( [ 'post_title' => 'Forbidden page', 'post_type' => 'page', 'post_status' => 'published' ], 2 ); check( is_wp_error( $page ) && 'unauthorized' === $page->get_error_code(), 'Post capabilities do not grant Page creation or publication' );
	$own = $service->insert_post( [ 'post_title' => 'Author draft' ], 2 );
	$dispatcher = new class( $wpdb ) implements \Psr\EventDispatcher\EventDispatcherInterface {
		public bool $called = false;
		public function __construct( private WP_SQLite_DB $db ) {}
		public function dispatch( object $event ): object { $this->called = true; $this->db->query( "UPDATE users SET capabilities='[]' WHERE id=2" ); return $event; }
	};
	$before = (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM job_queue' )['n'];
	$restricted = new WP_Post_Mutation_Service( $wpdb, $queue, $dispatcher, $wp_auth_service );
	$result = $restricted->update_post( [ 'ID' => $own, 'expected_revision' => 1, 'post_title' => 'Rejected' ], 2 );
	check( $dispatcher->called && is_wp_error( $result ) && 'unauthorized' === $result->get_error_code(), 'Capability revocation after preflight is caught under the write lock' );
	check( 1 === (int) $wpdb->get_row( 'SELECT revision FROM posts WHERE id=:id', [ ':id' => $own ] )['revision'] && $before === (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM job_queue' )['n'], 'Rejected writes change neither revision nor outbox' );
	$worked = ( new WP_Job_Worker( $wpdb, $queue ) )->run( 100 );
	$post = $wpdb->get_row( 'SELECT * FROM posts WHERE id=:id', [ ':id' => $first ] );
	check( $worked > 0 && (int) $post['revision'] === (int) $post['rendered_revision'] && str_contains( $post['content_rendered'], 'Nested' ), 'Queue handlers consume work and materialize current content' );
	check( (int) $wpdb->get_row( "SELECT COUNT(*) AS n FROM job_queue WHERE status='pending'" )['n'] === 0 && (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM audit_log' )['n'] > 0, 'Transition jobs record idempotent activity and leave no pending work' );
	$wpdb->transaction( fn() => $queue->enqueue_in_transaction( 'WordPress\Jobs\Render_Post_Job', [ 'post_id' => $first, 'target_revision' => 2 ] ) );
	$job = $queue->claim_job(); $wpdb->query( 'UPDATE job_queue SET lease_expires_at=unixepoch()-1 WHERE id=:id', [ ':id' => $job['id'] ] );
	check( ! $queue->complete_job( (int) $job['id'], $job['lease_token'] ), 'Expired worker leases cannot acknowledge jobs' );
	$bad_url = wp_validate_site_url( 'https://example.test/?a=1&b=2' ); check( is_wp_error( $bad_url ) && 'https://example.test/site' === wp_validate_site_url( 'https://example.test/site/' ), 'Site URLs are raw base URLs and reject query-string corruption' );
	echo $count . " checks passed.\n";
} finally {
	unset( $restricted, $service, $queue, $wpdb, $dispatcher );
	foreach ( glob( $temp . '/*' ) ?: [] as $file ) { unlink( $file ); }
	rmdir( $temp );
}
