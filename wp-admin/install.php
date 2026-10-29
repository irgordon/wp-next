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
<h2>Welcome to WordPress Next!</h2>
<p>We’ve reimagined the publishing experience so you can focus entirely on your content. Enjoy a streamlined workspace with a powerful block editor, beautiful themes, and a central media library—all without the clutter of complicated databases or plugin sprawl.</p>
<ul class="wizard-points">
<li><strong>Zero setup required.</strong> Say goodbye to server configuration. Your site’s storage is completely self-contained and ready to go the moment you start.</li>
<li><strong>Secure from day one.</strong> Built-in protection works seamlessly in the background. We automatically handle the technical heavy lifting to keep your site safe from your very first click.</li>
<li><strong>Ready in 60 seconds.</strong> Next, you’ll simply name your site and create your login. That’s the entire setup!</li>
</ul>
<div class="wizard-actions"><span></span><button type="button" data-wizard-next>Let's get started</button></div>
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
