<?php
/** Raw, validated settings storage and consistent error handling. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
wp_admin_require_cap( 'administrator' );
$admin_page_title = 'General Settings';
$admin_section = 'settings';
$error = '';
$message = '';
$form = [];
foreach ( [ 'site_title', 'site_tagline', 'site_url', 'admin_email', 'default_status' ] as $key ) {
	$form[ $key ] = (string) get_option( $key, '' );
}
$form['discourage_search_engines'] = (string) get_option( 'discourage_search_engines', '0' );
$mail_form = [];
foreach ( [ 'mail_smtp_host', 'mail_smtp_port', 'mail_smtp_encryption', 'mail_smtp_username', 'mail_from_email', 'mail_from_name' ] as $key ) {
	$mail_form[ $key ] = (string) get_option( $key, '' );
}
$mail_form['magic_links_enabled'] = (string) get_option( 'magic_links_enabled', '0' );
$mail_status = json_decode( (string) get_option( 'mail_last_status', '' ), true );
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['mail_action'] ) ) {
	wp_require_post( 'mail-settings' );
	try {
		if ( 'test' === wp_input( $_POST, 'mail_action' ) ) {
			$sent = wp_mail( (string) get_option( 'admin_email', '' ), 'Mail test — ' . (string) get_option( 'site_title', '' ), "This is a test message from your site's mail settings. If you received it, account email can be delivered." );
			if ( is_wp_error( $sent ) ) {
				throw new RuntimeException( 'Test email failed: ' . $sent->get_error_message() );
			}
			$message = 'Test email accepted by the mail transport.';
		} else {
			foreach ( array_keys( $mail_form ) as $key ) {
				if ( 'magic_links_enabled' !== $key ) {
					$mail_form[ $key ] = trim( wp_input( $_POST, $key ) );
				}
			}
			$mail_form['magic_links_enabled'] = '1' === wp_input( $_POST, 'magic_links_enabled' ) ? '1' : '0';
			if ( ! in_array( $mail_form['mail_smtp_encryption'], [ 'none', 'tls', 'ssl' ], true ) || ( '' !== $mail_form['mail_smtp_port'] && ( (int) $mail_form['mail_smtp_port'] < 1 || (int) $mail_form['mail_smtp_port'] > 65535 ) ) || ( '' !== $mail_form['mail_from_email'] && ! filter_var( $mail_form['mail_from_email'], FILTER_VALIDATE_EMAIL ) ) ) {
				throw new RuntimeException( 'Check the mail settings: encryption, port and From address must be valid.' );
			}
			$wpdb->transaction( function ( $db ) use ( $mail_form ): void {
				if ( ! current_user_can( 'administrator' ) ) {
					throw new RuntimeException( 'Settings permission was revoked.' );
				}
				foreach ( $mail_form as $key => $value ) {
					$db->query( 'INSERT INTO options(option_name,option_value) VALUES(:key,:value) ON CONFLICT(option_name) DO UPDATE SET option_value=:value', [
						':key'   => $key,
						':value' => $value
					] );
				}
				$password = wp_input( $_POST, 'mail_smtp_password' );
				if ( '1' === wp_input( $_POST, 'mail_smtp_password_clear' ) ) {
					$db->query( 'INSERT INTO options(option_name,option_value) VALUES(:key,:value) ON CONFLICT(option_name) DO UPDATE SET option_value=:value', [
						':key'   => 'mail_smtp_password',
						':value' => ''
					] );
				} elseif ( '' !== $password ) {
					$db->query( 'INSERT INTO options(option_name,option_value) VALUES(:key,:value) ON CONFLICT(option_name) DO UPDATE SET option_value=:value', [
						':key'   => 'mail_smtp_password',
						':value' => $password
					] );
				}
				wp_audit( 'mail_settings_updated' );
			} );
			$message = 'Mail settings saved.';
		}
	} catch ( Throwable $exception ) {
		$error = $exception->getMessage();
	}
	$mail_status = json_decode( (string) get_option( 'mail_last_status', '' ), true );
}
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! isset( $_POST['mail_action'] ) ) {
	wp_require_post( 'settings' );
	foreach ( $form as $key => $value ) {
		$form[ $key ] = wp_input( $_POST, $key );
	}
	$form['discourage_search_engines'] = '1' === wp_input( $_POST, 'discourage_search_engines' ) ? '1' : '0';
	$url = wp_validate_site_url( $form['site_url'] );
	if ( '' === trim( $form['site_title'] ) ) {
		$error = 'Site title cannot be empty.';
	} elseif ( is_wp_error( $url ) ) {
		$error = $url->get_error_message();
	} elseif ( ! filter_var( $form['admin_email'], FILTER_VALIDATE_EMAIL ) ) {
		$error = 'Enter a valid administration email.';
	} elseif ( ! in_array( $form['default_status'], [ 'draft', 'published' ], true ) ) {
		$error = 'Choose a valid default status.';
	} else {
		try {
			$form['site_url'] = $url;
			$form['site_title'] = sanitize_text_field( $form['site_title'] );
			$form['site_tagline'] = sanitize_text_field( $form['site_tagline'] );
			$wpdb->transaction( function ( $db ) use ( $form ): void {
				if ( ! current_user_can( 'administrator' ) ) {
					throw new RuntimeException( 'Settings permission was revoked.' );
				}
				foreach ( $form as $key => $value ) {
					$db->query( 'INSERT INTO options(option_name,option_value) VALUES(:key,:value) ON CONFLICT(option_name) DO UPDATE SET option_value=:value', [
						':key'   => $key,
						':value' => $value
					] );
				}
				wp_audit( 'settings_updated' );
			} );
			$message = 'Settings saved.';
		} catch ( Throwable $exception ) {
			$error = wp_failure( 'settings_failed', $exception, 'Settings could not be saved.' )->get_error_message();
		}
	}
}
require __DIR__ . '/admin-header.php';
wp_admin_notice( $error, true );
wp_admin_notice( $message );
?>
<nav class="actions settings-navigation" aria-label="Settings"><a href="options-general.php" aria-current="page">General</a><a href="options-permalink.php">Permalinks</a><a href="integrations.php">Integrations</a></nav>
<section class="panel"><form method="post"><?php wp_nonce_field( 'settings' ); ?>
<?php wp_admin_field( 'site_title', 'Site title', $form['site_title'] );
wp_admin_field( 'site_tagline', 'Tagline', $form['site_tagline'] );
wp_admin_field( 'site_url', 'Site address', $form['site_url'], 'url', 'The absolute site base URL. Queries and fragments are not part of a site address.' );
wp_admin_field( 'admin_email', 'Administration email', $form['admin_email'], 'email' ); ?>
<div class="form-field"><label for="default_status">Default content status</label><select id="default_status" name="default_status"><?php foreach ( [ 'draft', 'published' ] as $status ) :
	?><option <?php echo $status === $form['default_status'] ? 'selected' : ''; ?> value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></option><?php
																																	endforeach; ?></select></div>
<div class="form-field"><label class="inline-checkbox"><input type="checkbox" name="discourage_search_engines" value="1" <?php echo '1' === $form['discourage_search_engines'] ? 'checked' : ''; ?> /> Discourage search engines from indexing this site</label><p class="description">Applies site-wide: every page carries a noindex robots meta tag and robots.txt disallows all crawling. The feed and sitemap remain available.</p></div><button type="submit">Save settings</button></form></section>
<section class="panel"><h2>Mail</h2><form id="mail-settings" method="post"><?php wp_nonce_field( 'mail-settings' ); ?>
<p>Account email — invitations, password recovery and sign-in links — uses SMTP when a host is set, and the server's PHP mail transport otherwise. Environment variables (WP_MAIL_SMTP_HOST and related) take precedence over these settings. Delivery failures are recorded, never reported as sent.</p>
<?php if ( is_array( $mail_status ) ) :
	?><p>Last delivery: <?php echo ! empty( $mail_status['ok'] ) ? 'accepted' : 'FAILED'; ?> · <?php echo esc_html( gmdate( 'Y-m-d H:i', (int) ( $mail_status['at'] ?? 0 ) ) ); ?> UTC<?php echo ! empty( $mail_status['detail'] ) ? ' · ' . esc_html( (string) $mail_status['detail'] ) : ''; ?></p><?php 
endif; ?>
<?php wp_admin_field( 'mail_smtp_host', 'SMTP host', $mail_form['mail_smtp_host'], 'text', 'Leave empty to use the PHP mail transport.' );
wp_admin_field( 'mail_smtp_port', 'SMTP port', '' !== $mail_form['mail_smtp_port'] ? $mail_form['mail_smtp_port'] : '587', 'number' ); ?>
<div class="form-field"><label for="mail_smtp_encryption">Encryption</label><select id="mail_smtp_encryption" name="mail_smtp_encryption"><?php foreach ( [
	'tls'  => 'STARTTLS',
	'ssl'  => 'Implicit TLS',
	'none' => 'None'
] as $value => $label ) :
	?><option value="<?php echo esc_attr( $value ); ?>" <?php echo ( '' !== $mail_form['mail_smtp_encryption'] ? $mail_form['mail_smtp_encryption'] : 'tls' ) === $value ? 'selected' : ''; ?>><?php echo esc_html( $label ); ?></option><?php 
                                                                                                                                          endforeach; ?></select></div>
<?php wp_admin_field( 'mail_smtp_username', 'SMTP username', $mail_form['mail_smtp_username'] ); ?>
<div class="form-field"><label for="mail_smtp_password">SMTP password</label><input id="mail_smtp_password" name="mail_smtp_password" type="password" autocomplete="new-password" /><p class="description"><?php echo '' !== (string) get_option( 'mail_smtp_password', '' ) ? 'A password is stored. Leave blank to keep it.' : 'No password stored.'; ?></p><label class="inline-checkbox"><input type="checkbox" name="mail_smtp_password_clear" value="1" /> Clear the stored password</label></div>
<?php wp_admin_field( 'mail_from_email', 'From email', $mail_form['mail_from_email'], 'email' );
wp_admin_field( 'mail_from_name', 'From name', $mail_form['mail_from_name'] ); ?>
<label class="inline-checkbox"><input type="checkbox" name="magic_links_enabled" value="1" <?php echo '1' === $mail_form['magic_links_enabled'] ? 'checked' : ''; ?> /> Allow email sign-in links (magic links)</label>
<p><button type="submit" name="mail_action" value="save">Save mail settings</button> <button type="submit" name="mail_action" value="test" class="secondary">Send test email to the administration address</button></p></form></section>
<?php require __DIR__ . '/admin-footer.php';
