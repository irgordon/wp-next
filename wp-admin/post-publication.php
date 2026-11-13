<?php
/**
 * Explicit publication intent for the general content editor. This is
 * legacy browser-handler behavior for the admin form endpoints (post.php
 * and post-new.php), not a public API promise: the editorial API applies its own contract and does not consult this file.
 */
declare( strict_types=1 );
const WP_POST_ACTION_STATUSES = [
	'save_draft' => 'draft',
	'publish'    => 'published',
	'unpublish'  => 'draft',
	'archive'    => 'archived'
];
const WP_POST_SAVE_NOTICES = [
	'save_draft' => 'Draft saved.',
	'publish'    => 'Published.',
	'update'     => 'Updated.',
	'unpublish'  => 'Unpublished.',
	'archive'    => 'Archived.'
];
const WP_POST_GENERIC_NOTICE = 'Content saved.';
/** The submitted intent: null when the action field is absent, the string when one was submitted, a sentinel when malformed (non-string) — intake never erases the absent/explicit distinction. */
function wp_post_submitted_intent(): ?string
{
	if ( ! isset( $_POST['action'] ) ) {
		return null;
	}
	return is_string( $_POST['action'] ) ? $_POST['action'] : '__malformed__';
}
/** Update submissions, three ways: an absent action keeps the legacy behavior (the submitted status governs); a known intent maps to its outcome; anything else — explicit update, unknown or malformed actions — preserves the persisted status and ignores the submitted one. */
function wp_post_submission_status( ?string $intent, string $submitted, string $persisted ): string
{
	if ( null === $intent ) {
		return $submitted;
	}
	return WP_POST_ACTION_STATUSES[ $intent ] ?? $persisted;
}
/** Creation has no persisted status: known intents map, the rest is legacy. */
function wp_post_creation_status( ?string $intent, string $submitted ): string
{
	if ( null === $intent ) {
		return $submitted;
	}
	return WP_POST_ACTION_STATUSES[ $intent ] ?? $submitted;
}
/** The primary save button for a persisted state ('new' when unsaved). */
function wp_post_save_button( string $persisted ): array
{
	if ( 'published' === $persisted ) {
		return [
			'value' => 'update',
			'label' => 'Update',
			'class' => ''
		];
	}
	if ( 'trashed' === $persisted ) {
		return [
			'value' => 'update',
			'label' => 'Save changes',
			'class' => ''
		];
	}
	return [
		'value' => 'save_draft',
		'label' => 'Save Draft',
		'class' => 'secondary'
	];
}
function wp_post_show_publish( string $persisted, bool $permission ): bool
{
	if ( 'published' === $persisted || 'trashed' === $persisted ) {
		return false;
	}
	return $permission;
}
function wp_post_show_live_view( string $persisted ): bool
{
	return 'published' === $persisted;
}
/** The notice for a completed save: it resolves only when the action's outcome matches the persisted status, and falls back to the generic notice otherwise, so a refused or redirected intent never claims success. */
function wp_post_save_notice( string $action, string $persisted ): string
{
	if ( 'update' === $action ) {
		return WP_POST_SAVE_NOTICES['update'];
	}
	if ( ( WP_POST_ACTION_STATUSES[ $action ] ?? null ) !== $persisted ) {
		return WP_POST_GENERIC_NOTICE;
	}
	return WP_POST_SAVE_NOTICES[ $action ] ?? WP_POST_GENERIC_NOTICE;
}
function wp_post_result_query( ?string $intent ): string
{
	if ( null === $intent || ( 'update' !== $intent && ! isset( WP_POST_ACTION_STATUSES[ $intent ] ) ) ) {
		return '';
	}
	return '&saved_as=' . $intent;
}
function wp_post_summary_result_query( array $form ): string
{
	if ( ! isset( $form['post_excerpt'] ) ) {
		return '';
	}
	$submitted = sanitize_text_field( (string) $form['post_excerpt'] );
	if ( '' === $submitted || wp_summary_trim( $submitted, 60, '' ) === $submitted ) {
		return '';
	}
	return '&summary_shortened=1';
}
