<?php
/** Small shared UI helpers. All labels and values are escaped at output. */
declare( strict_types=1 );
function wp_admin_require_cap( string $capability ): void
{
	if ( ! current_user_can( $capability ) ) {
		wp_die( 'You do not have permission to use this screen.', 'Access denied', [ 'response' => 403 ] );
	}
}
function wp_admin_notice( string $message, bool $error = false ): void
{
	if ( '' === $message ) {
		return;
	}
	echo '<div class="notice ' . ( $error ? 'notice-error' : 'notice-success' ) . '" role="' . ( $error ? 'alert' : 'status' ) . '" tabindex="-1">' . esc_html( $message ) . '</div>';
}
function wp_admin_field( string $name, string $label, string $value, string $type = 'text', string $description = '' ): void
{
	echo '<div class="form-field"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" />';
	if ( '' !== $description ) {
		echo '<p class="description">' . esc_html( $description ) . '</p>';
	}
	echo '</div>';
}
function wp_admin_auth_header( string $title ): void
{
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width,initial-scale=1" /><title>' . esc_html( $title . ' · WordPress Next' ) . '</title><link rel="stylesheet" href="css/admin.css" /></head><body class="auth-page"><a class="skip-link" href="#main-content">Skip to content</a><main id="main-content" class="auth-panel"><p class="eyebrow">WordPress Next</p><h1>' . esc_html( $title ) . '</h1>';
}
function wp_admin_auth_footer(): void
{
	echo '</main></body></html>';
}
