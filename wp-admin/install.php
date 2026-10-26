<?php
/** Blank installation interface, with its own CSRF boundary. */
declare( strict_types=1 );
define( 'WP_INSTALLING', true );
require_once dirname( __DIR__ ) . '/wp-includes/bootstrap.php';
require_once __DIR__ . '/class-wp-installer.php';
require_once __DIR__ . '/admin-functions.php';
use WordPress\Installer\WP_Installer;
header( 'Cache-Control: no-store' );
global $installed;
$error = '';
$success = $installed;
$nonce = wp_nonce( 'install' );
if ( ! $success && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wp_require_post( 'install' );
	$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
	if ( ! preg_match( '/^[a-zA-Z0-9.\-\[\]:]+$/', $host ) ) {
		wp_die( 'Invalid host header.', 'Request rejected', [ 'response' => 400 ] );
	}
	$path = str_replace( '/wp-admin/install.php', '', $_SERVER['SCRIPT_NAME'] ?? '' );
	$result = ( new WP_Installer( WP_DB_PATH, WP_VAR_DIR ) )->install( [
		'username' => wp_input( $_POST, 'admin_user' ),
		'email'    => wp_input( $_POST, 'admin_email' ),
		'password' => wp_input( $_POST, 'admin_password' )
	], [
		'title' => wp_input( $_POST, 'site_title', 'My Site' ),
		'url'   => ( wp_is_https() ? 'https://' : 'http://' ) . $host . $path
	] );
	$success = true === $result;
	if ( is_wp_error( $result ) ) {
		$error = $result->get_error_message();
	}
}
wp_admin_auth_header( $success ? 'Installation complete' : 'Create your site' );
wp_admin_notice( $error, true );
if ( $success ) {
	echo '<p>Your site is ready. Sign in to start publishing.</p><a class="button" href="login.php">Log in</a>';
} else {
	?>
<script src="js/install.js" defer></script>
<div class="install-wizard" data-wizard>
<div class="wizard-progress" aria-hidden="true"><div class="wizard-progress-bar" data-wizard-bar style="width:50%"></div></div>
<p class="wizard-step-label" role="status">Step <span data-wizard-current>1</span> of 2</p>
<form action="install.php" method="post"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
<section class="wizard-step" data-wizard-step="1" aria-label="Welcome">
<h2>Welcome to WordPress Next</h2>
<p>WordPress Next is a publishing workspace for your content, pages, and design: a focused admin, a block editor for posts and pages, a media library, and themes you can restyle — without plug-in sprawl or a separate database server to run.</p>
<ul class="wizard-points">
<li><strong>Nothing to configure for storage.</strong> Your content lives in a single SQLite database file, created during this setup and protected by a unique per-install secret.</li>
<li><strong>Hardened by default.</strong> Hashed sessions, login throttling, and strict content sanitizing are on from the first request.</li>
<li><strong>About a minute.</strong> Next, you will name your site and create the administrator account — that is the whole setup.</li>
</ul>
<div class="wizard-actions"><span></span><button type="button" data-wizard-next>Next</button></div>
</section>
<section class="wizard-step" data-wizard-step="2" aria-label="Site and administrator">
<p>A publishing workspace for your content, pages, and design.</p>
	<?php wp_admin_field( 'site_title', 'Site title', wp_input( $_POST, 'site_title' ) );
	wp_admin_field( 'admin_user', 'Administrator username', wp_input( $_POST, 'admin_user' ) );
	wp_admin_field( 'admin_email', 'Administrator email', wp_input( $_POST, 'admin_email' ), 'email' ); ?>
<div class="form-field"><label for="admin_password">Password</label><input id="admin_password" name="admin_password" type="password" autocomplete="new-password" minlength="12" required /><p class="description">Use at least 12 characters. A unique site secret is created automatically.</p></div>
<div class="wizard-actions"><button type="button" class="button-link" data-wizard-back>Back</button><button type="submit">Install site</button></div>
</section>
</form>
</div>
<?php } wp_admin_auth_footer();
