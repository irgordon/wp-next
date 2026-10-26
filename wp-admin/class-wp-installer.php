<?php
/** Blank installation with explicit runtime and storage requirements. */
declare( strict_types=1 );
namespace WordPress\Installer;
use WordPress\Database\WP_SQLite_DB;
use Throwable;
use WP_Error;
require_once dirname( __DIR__ ) . '/wp-includes/class-wp-error.php';
require_once dirname( __DIR__ ) . '/wp-includes/l10n.php';
require_once dirname( __DIR__ ) . '/wp-includes/formatting.php';
require_once dirname( __DIR__ ) . '/wp-includes/schema.php';
require_once dirname( __DIR__ ) . '/wp-includes/image.php';
class WP_Installer {
	public function __construct( protected string $db_path, protected string $var_dir )
	{
	}
	public function verify_storage_environment(): true|WP_Error
	{
		if ( PHP_VERSION_ID < 80400 ) {
			return new WP_Error( 'unsupported_php', 'PHP 8.4 or newer is required.' );
		}
		foreach ( [ 'dom', 'libxml', 'pdo_sqlite', 'fileinfo', 'gd', 'exif', 'tokenizer' ] as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				return new WP_Error( 'missing_extensions', 'Required PHP extension is missing: ' . $extension );
			}
		}
		if ( ! wp_image_runtime_supported() ) {
			return new WP_Error( 'missing_image_codecs', 'GD requires JPEG, PNG, GIF and WebP support, plus EXIF.' );
		}
		$directory = dirname( $this->db_path );
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700, true ) ) {
			return new WP_Error( 'storage_error', 'Cannot create the database directory.' );
		}
		if ( ! is_writable( $directory ) ) {
			return new WP_Error( 'storage_error', 'Database directory is not writable by the PHP service account.' );
		}
		$probe = $directory . '/lock-probe-' . bin2hex( random_bytes( 6 ) );
		$handle = @fopen( $probe, 'x' );
		if ( false === $handle ) {
			return new WP_Error( 'storage_error', 'Cannot create the storage probe.' );
		}
		$locked = flock( $handle, LOCK_EX | LOCK_NB );
		flock( $handle, LOCK_UN );
		fclose( $handle );
		unlink( $probe );
		return $locked ? true : new WP_Error( 'storage_unsupported', 'Advisory locking is unavailable.' );
	}
	public function install( array $admin, array $site ): true|WP_Error
	{
		$result = $this->verify_storage_environment();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$username = sanitize_text_field( $admin['username'] ?? '' );
		$email = trim( (string) ( $admin['email'] ?? '' ) );
		$password = (string) ( $admin['password'] ?? '' );
		if ( ! preg_match( '/^[\pL\pN_.-]{2,60}$/u', $username ) ) {
			return new WP_Error( 'invalid_username', 'Use 2–60 letters, numbers, dots, underscores, or hyphens for the username.' );
		}
		if ( strlen( $password ) < 12 ) {
			return new WP_Error( 'weak_password', 'Password must be at least 12 characters.' );
		}
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return new WP_Error( 'invalid_email', 'Enter a valid email address.' );
		}
		$url = wp_validate_site_url( (string) ( $site['url'] ?? 'http://localhost' ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		try {
			$db = new WP_SQLite_DB( $this->db_path );
			if ( version_compare( (string) $db->get_row( 'SELECT sqlite_version() AS version' )['version'], '3.45.0', '<' ) ) {
				return new WP_Error( 'unsupported_sqlite', 'SQLite 3.45 or newer is required.' );
			}
			if ( null !== $db->get_row( "SELECT name FROM sqlite_master WHERE type='table' AND name='options'" ) && '1' === ( $db->get_row( "SELECT option_value FROM options WHERE option_name='site_installed'" )['option_value'] ?? '' ) ) {
				return new WP_Error( 'already_installed', 'The application is already installed.' );
			}
			wp_upgrade_schema( $db );
			$db->transaction( function ( WP_SQLite_DB $db ) use ( $username, $email, $password, $site, $url ): void {
				if ( null !== $db->get_row( "SELECT option_value FROM options WHERE option_name='site_installed'" ) ) {
					throw new \RuntimeException( 'Installation was completed by another request.' );
				}
				$db->query( 'INSERT INTO users(username,email,password_hash,capabilities,display_name) VALUES(:username,:email,:hash,:caps,:username)', [
					':username' => $username,
					':email'    => $email,
					':hash'     => password_hash( $password, PASSWORD_DEFAULT ),
					':caps'     => '["administrator"]'
				] );
				$author = (int) $db->get_row( 'SELECT last_insert_rowid() AS id' )['id'];
				foreach ( [ [ 'hello-world', 'Hello World!', 'post', '<p>Welcome to your new site.</p>' ], [ 'sample-page', 'Sample Page', 'page', '<h2>About this site</h2><p>Make this page your own.</p>' ] ] as [ $slug, $title, $type, $body ] ) {
					$db->query( "INSERT INTO posts(author_id,slug,title,post_type,content_raw,content_rendered,status,rendered_revision,published_at) VALUES(:author,:slug,:title,:type,:body,:body,'published',1,unixepoch())", [
						':author' => $author,
						':slug'   => $slug,
						':title'  => $title,
						':type'   => $type,
						':body'   => $body
					] );
				}
				foreach ( [
					'site_title'     => sanitize_text_field( $site['title'] ?? 'My Site' ),
					'site_tagline'   => '',
					'site_url'       => $url,
					'admin_email'    => $email,
					'default_status' => 'draft',
					'active_theme'   => defined( 'WP_DEFAULT_THEME' ) ? WP_DEFAULT_THEME : 'default',
					'site_installed' => '1'
				] as $name => $value ) {
					$db->query( 'INSERT INTO options(option_name,option_value) VALUES(:name,:value) ON CONFLICT(option_name) DO UPDATE SET option_value=:value', [
						':name'  => $name,
						':value' => $value
					] );
				}
				$db->query( "INSERT OR IGNORE INTO post_terms(post_id,term_id) SELECT p.id,t.id FROM posts p JOIN terms t ON t.taxonomy='category' AND t.slug='uncategorized' WHERE p.post_type='post'" );
			} );
			return true;
		} catch ( Throwable $error ) {
			error_log( 'Installation failed: ' . $error->getMessage() );
			return new WP_Error( 'install_failed', 'Installation could not complete. Check the server log and storage configuration.' );
		}
	}
}
