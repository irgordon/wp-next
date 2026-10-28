<?php
/** Trusted embedded editor; the public preview remains independently script-disabled. */
declare( strict_types=1 );
if ( ! defined( 'WP_ROOT_DIR' ) ) {
	exit;
}
?>
<section class="design-page-editor-region" aria-label="Page visual editor in design workspace" hidden>
<header><div><h2 data-page-editor-title>Page visual editor</h2><p data-page-editor-status>Page saving and design publication are separate.</p></div><button type="button" data-page-editor-close>Back to page list</button></header>
<p class="design-page-editor-error" role="alert" hidden></p><div class="design-page-editor-host"></div>
</section>
<dialog class="design-page-discard" aria-labelledby="design-page-discard-title"><h2 id="design-page-discard-title">Unsaved Page changes</h2><p>Keep editing this Page, or discard its unsaved edits before continuing. Your unsaved site-design settings will stay intact.</p><p data-page-discard-error role="alert" hidden></p><div class="actions"><button type="button" data-page-keep-editing>Keep editing</button><button type="button" class="button-danger" data-page-discard-edits>Discard Page edits</button></div></dialog>
