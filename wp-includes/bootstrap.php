<?php
/** Request admission, runtime verification, and service bindings. */
declare( strict_types=1 );
require_once dirname( __DIR__ ) . '/wp-config.php';
require_once __DIR__ . '/api-gateway.php';
foreach ( [ 'class-wp-error.php', 'l10n.php', 'formatting.php', 'security.php', 'psr/container-interface.php', 'psr/event-dispatcher-interfaces.php', 'class-wp-sqlite-db.php', 'schema.php', 'operations.php', 'class-wp-admission-gate.php', 'class-wp-authorization-service.php', 'class-wp-job-queue.php', 'class-wp-event-dispatcher.php', 'class-wp-plugin-activator.php', 'class-wp-post-mutation-service.php', 'class-wp-job-worker.php', 'theme.php', 'content.php', 'media.php', 'assets.php', 'user.php', 'mail.php', 'account-tokens.php', 'post.php', 'portability.php', 'discovery.php', 'api.php', 'plugin.php' ] as $file ) {
	require_once __DIR__ . '/' . $file;
}
use WordPress\Database\WP_SQLite_DB;
use WordPress\Security\WP_Admission_Gate;
use WordPress\Security\WP_Authorization_Service;
use WordPress\Queue\WP_Job_Queue;
use WordPress\Events\WP_Hierarchical_Listener_Provider;
use WordPress\Post\WP_Post_Mutation_Service;
wp_security_headers();
if ( 'cli' !== PHP_SAPI ) {
	$request_path = wp_normalize_request_path( $_SERVER['REQUEST_URI'] ?? '/' );
	if ( is_wp_error( $request_path ) || preg_match( '~/(?!index\.php(?:/|$))[^/]+\.php/~i', $request_path ) ) {
		wp_die( 'The requested address does not exist.', 'Not found', [ 'response' => 404 ] );
	}
}

foreach ( [ 'dom', 'libxml', 'pdo_sqlite', 'fileinfo', 'gd', 'exif', 'tokenizer' ] as $extension ) {
	if ( ! extension_loaded( $extension ) ) {
		wp_die( 'Required PHP extension is missing: ' . $extension, 'Runtime unavailable', [ 'response' => 503 ] );
	}
}
if ( ! wp_image_runtime_supported() ) {
	wp_die( 'GD requires JPEG, PNG, GIF and WebP support, plus EXIF.', 'Runtime unavailable', [ 'response' => 503 ] );
}
$is_installing = defined( 'WP_INSTALLING' ) && WP_INSTALLING;
$maintenance_command = defined( 'WP_MAINTENANCE_COMMAND' ) && WP_MAINTENANCE_COMMAND && 'cli' === PHP_SAPI;
$wp_admission_gate = new WP_Admission_Gate( WP_VAR_DIR );
if ( ! $maintenance_command && ! $wp_admission_gate->admit() ) {
	if ( defined( 'WP_API_REQUEST' ) ) {
		wp_api_problem_exit( 503, 'maintenance', 'Site maintenance is in progress. Please retry shortly.', null, [ 'Retry-After' => '30' ] );
	}
	header( 'Retry-After: 30' );
	wp_die( 'Site maintenance is in progress. Please retry shortly.', 'Maintenance', [ 'response' => 503 ] );
}
$wpdb = null;
try {
	if ( is_file( WP_DB_PATH ) ) {
		$wpdb = new WP_SQLite_DB( WP_DB_PATH );
	}
	$installed = null !== $wpdb && null !== $wpdb->get_row( "SELECT name FROM sqlite_master WHERE type='table' AND name='options'" ) && '1' === ( $wpdb->get_row( "SELECT option_value FROM options WHERE option_name='site_installed'" )['option_value'] ?? '' );
} catch ( Throwable $error ) {
	error_log( 'Database startup: ' . $error->getMessage() );
	wp_die( 'Database startup failed. Check storage and the server log.', 'Site unavailable', [ 'response' => 503 ] );
}
if ( ! $installed && ! $is_installing ) {
	if ( defined( 'WP_API_REQUEST' ) ) {
		wp_api_problem_exit( 503, 'storage-unavailable', 'The editorial API is unavailable until the site is installed.' );
	}
	if ( 'cli' === PHP_SAPI ) {
		wp_die( 'Install the site through wp-admin/install.php first.' );
	}
	$script = (string) ( $_SERVER['SCRIPT_NAME'] ?? '' );
	header( 'Location: ' . ( str_contains( $script, '/wp-admin/' ) ? 'install.php' : 'wp-admin/install.php' ) );
	exit;
}
if ( $installed && ! $maintenance_command && wp_schema_version( $wpdb ) !== WP_SCHEMA_VERSION ) {
	if ( defined( 'WP_API_REQUEST' ) ) {
		wp_api_problem_exit( 503, 'storage-unavailable', 'The editorial API is unavailable until the database upgrade completes.' );
	}
	wp_die( 'A database upgrade is required. Back up the site and run: php bin/wp upgrade', 'Upgrade required', [ 'response' => 503 ] );
}
$wp_auth_service = new WP_Authorization_Service();
$wp_job_queue = null !== $wpdb ? new WP_Job_Queue( $wpdb ) : null;
$wp_container = new class implements \Psr\Container\ContainerInterface {
	public function get( string $id ): mixed
	{
		return new $id();
	}
	public function has( string $id ): bool
	{
		return class_exists( $id );
	}
};
$wp_listener_provider = new WP_Hierarchical_Listener_Provider( $wp_container );
$registry = WP_VAR_DIR . '/cache/listeners.json';
if ( ! $maintenance_command && is_file( $registry ) ) {
	try {
		$listeners = json_decode( (string) file_get_contents( $registry ), true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $listeners ) ) {
			throw new RuntimeException( 'Invalid registry.' );
		}
		foreach ( $listeners as $event => $entries ) {
			if ( ! is_string( $event ) || ! is_array( $entries ) ) {
				throw new RuntimeException( 'Invalid registry event.' );
			}
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) || count( $entry ) !== 2 || ! is_string( $entry[0] ) || ! is_string( $entry[1] ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $entry[1] ) ) {
					throw new RuntimeException( 'Invalid listener entry.' );
				}
				$wp_listener_provider->add_listener( $event, $entry[0], $entry[1] );
			}
		}
	} catch ( Throwable $error ) {
		error_log( 'Listener registry: ' . $error->getMessage() );
		wp_die( 'The listener registry must be rebuilt by the site operator.', 'Site unavailable', [ 'response' => 503 ] );
	}
}
$wp_event_dispatcher = new class( $wp_listener_provider ) implements \Psr\EventDispatcher\EventDispatcherInterface {
	public function __construct( private \Psr\EventDispatcher\ListenerProviderInterface $provider )
	{
	}
	public function dispatch( object $event ): object
	{
		foreach ( $this->provider->getListenersForEvent( $event ) as $listener ) {
			if ( $event instanceof \Psr\EventDispatcher\StoppableEventInterface && $event->isPropagationStopped() ) {
				break;
			}
			$listener( $event );
		}
		return $event;
	}
};
$wp_post_mutation_service = null !== $wpdb && null !== $wp_job_queue ? new WP_Post_Mutation_Service( $wpdb, $wp_job_queue, $wp_event_dispatcher, $wp_auth_service ) : null;
if ( $installed && ! $maintenance_command && ! ( defined( 'WP_API_REQUEST' ) ) ) {
	if ( wp_design_live_preview_request() ) {
		wp_design_live_preview_init();
	} else {
		wp_design_preview_init();
		wp_css_preview_init();
	}
}
if ( $installed && ! $maintenance_command && ! ( defined( 'WP_API_REQUEST' ) ) && is_file( wp_theme_directory() . '/functions.php' ) ) {
	try {
		require_once wp_theme_directory() . '/functions.php';

	} catch ( Throwable $error ) {
		error_log('Theme functions failed: '.$error->getMessage());
		$GLOBALS['wp_design_override']['active_theme'] = 'default'; 
	}
}
