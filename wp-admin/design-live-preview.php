<?php
/** Private live-preview presentation; rendering requests use a separate action nonce. */
declare( strict_types=1 );
if ( ! defined( 'WP_ROOT_DIR' ) ) {
	exit;
}
?>
<aside class="design-live-preview" aria-label="Private live site preview" data-design-preview data-endpoint="themes.php" data-nonce="<?php echo esc_attr( wp_nonce( 'design-live-preview' ) ); ?>" hidden>
<header class="design-preview-toolbar"><div><h2>Live site preview</h2><p>Changes are not published automatically</p></div><div class="design-preview-devices" role="group" aria-label="Preview viewport"><button type="button" data-preview-device="1440" aria-pressed="true">Desktop preview</button><button type="button" data-preview-device="390" aria-pressed="false">Mobile preview</button></div></header>
<div class="design-preview-feedback"><p class="design-preview-status" role="status" aria-live="polite">Preparing private preview…</p><button type="button" data-preview-refresh>Refresh preview</button></div>
<p class="design-preview-error" role="alert" hidden></p>
<div class="design-preview-viewport" aria-busy="true"></div>
<p class="design-preview-note">Preview only. Save draft keeps a version for later; Publish design makes it public.</p>
</aside>
