<?php
/** Canonical post address settings. Changes are authorized and audited atomically. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
wp_admin_require_cap( 'administrator' );
$admin_page_title = 'Permalink Settings';
$admin_section = 'permalinks';
$error = '';
$message = '';
$structure = wp_permalink_structure();
$presets = wp_permalink_presets();
$choice = 'custom';
foreach ( $presets as $key => $preset ) {
	if ( $preset[1] === $structure ) {
		$choice = $key;
	}
}
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wp_require_post( 'permalinks' );
	$choice = wp_input( $_POST, 'permalink_choice' );
	$structure = 'custom' === $choice ? wp_input( $_POST, 'permalink_structure' ) : ( $presets[ $choice ][1] ?? '' );
	$validation = wp_validate_permalink_structure( $structure );
	if ( ! isset( $presets[ $choice ] ) && 'custom' !== $choice ) {
		$error = 'Choose a permalink format.';
	} elseif ( 'custom' === $choice && '' === $structure ) {
		$error = 'Enter a custom structure, or choose Plain.';
	} elseif ( is_wp_error( $validation ) ) {
		$error = $validation->get_error_message();
	} elseif ( wp_api_tables_exist( $wpdb ) && null !== ( $api_state = wp_api_state( $wpdb ) ) && ( 1 === (int) $api_state['enabled'] || '' !== (string) ( $api_state['bound_epoch'] ?? '' ) ) && [] !== ( $api_conflicts = wp_api_structure_conflicts( $wpdb, $structure ) ) ) {
		// While the editorial API is initialized or enabled, structures that
		// would claim its mounted namespace are refused, never rerouted.
		$error = 'This structure conflicts with the editorial API mount: ' . implode( ' ', $api_conflicts );
	} else {
		try {
			$wpdb->transaction( function ( $db ) use ( $structure ): void {
				$api_routes = wp_api_routes_snapshot( $db );
				if ( ! current_user_can( 'administrator' ) ) {
					throw new RuntimeException( 'Permalink permission revoked.' );
				}
				$previous = wp_permalink_structure();
				$history = wp_permalink_structures();
				if ( '' !== $previous && ! in_array( $previous, $history, true ) ) {
					$history[] = $previous;
				}
				$history = array_values( array_filter( $history, static fn( $item ) => $item !== $structure ) );
				if ( count( $history ) > 50 ) {
					throw new RuntimeException( 'The saved structure history is full.' );
				}
				foreach ( [
					'permalink_structure' => $structure,
					'permalink_history'   => wp_json_encode( $history )
				] as $key => $value ) {
					$db->query( 'INSERT INTO options(option_name,option_value) VALUES(:key,:value) ON CONFLICT(option_name) DO UPDATE SET option_value=:value', [
						':key'   => $key,
						':value' => $value
					] );
				}
				wp_audit( 'permalinks_updated', null, [
					'previous'  => $previous,
					'structure' => $structure
				] );
				if ( $structure !== $previous ) {
					// Route ownership may have moved between posts and documents; cached File links must be regenerated.
					wp_invalidate_file_link_renders( $db );
				}
				wp_api_routes_assert( $db, $api_routes );
			} );
			$message = 'Permalink settings saved. Existing structure links redirect to the selected format.';
		} catch ( Throwable $exception ) {
			$error = wp_failure( 'permalinks_failed', $exception, 'Permalink settings could not be saved.' )->get_error_message();
		}
	}
}
$sample = $wpdb->get_row( "SELECT * FROM posts WHERE post_type='post' AND status='published' ORDER BY id LIMIT 1" );
$sample ??= [
	'id'           => 123,
	'slug'         => 'sample-post',
	'author_id'    => get_current_user_id(),
	'published_at' => time()
];
require __DIR__ . '/admin-header.php';
wp_admin_notice( $error, true );
wp_admin_notice( $message );
?>
<nav class="actions settings-navigation" aria-label="Settings"><a href="options-general.php">General</a><a href="options-permalink.php" aria-current="page">Permalinks</a><a href="integrations.php">Integrations</a></nav>
<section class="panel"><h2>Common Settings</h2><p>Choose the permanent address format for posts. Pages keep their slug addresses; Plain uses an ID query for both.</p>
<form method="post"><?php wp_nonce_field( 'permalinks' ); ?>
<fieldset class="permalink-options"><legend>Post permalink format</legend>
<?php foreach ( $presets as $key => [ $label, $pattern ] ) : ?>
<label class="permalink-option"><span class="inline-checkbox"><input type="radio" name="permalink_choice" value="<?php echo esc_attr( $key ); ?>" <?php echo $choice === $key ? 'checked' : ''; ?> /><?php echo esc_html( $label ); ?></span><code><?php echo esc_html( '' === $pattern ? wp_home_url( '?p=' . $sample['id'] ) : wp_home_url( wp_permalink_path( $sample, $pattern ) ) ); ?></code></label>
<?php endforeach; ?>
<label class="inline-checkbox"><input type="radio" name="permalink_choice" value="custom" <?php echo 'custom' === $choice ? 'checked' : ''; ?> />Custom structure</label>
<label for="permalink_structure">Custom post path</label><input id="permalink_structure" name="permalink_structure" value="<?php echo esc_attr( '' === $structure ? '/%postname%/' : $structure ); ?>" aria-describedby="permalink-help" />
<p id="permalink-help" class="description">Begin with /. End with %postname% or %post_id%, with an optional .html suffix and trailing slash. For the month/day format, use /%monthnum%/%day%/%postname%/. Prefix with /index.php/ for PATH_INFO hosting.</p>
</fieldset>
<p>Available tags: <code><?php echo esc_html( implode( ' ', array_map( static fn( $tag ) => '%' . $tag . '%', wp_permalink_tags() ) ) ); ?></code></p>
<p class="description">Dates use UTC, matching displayed publication dates. The %category% tag uses the first assigned category by name, including its parent path. Category and tag archives use /category/ and /tag/. Custom structures cannot use application prefixes such as /preview/ or /wp-admin/.</p>
<div class="actions"><button type="submit">Save permalink settings</button><a href="<?php echo esc_url( wp_post_url( $sample ) ); ?>" target="_blank" rel="noopener">View current example ↗</a></div>
</form></section>
<?php require __DIR__ . '/admin-footer.php';
