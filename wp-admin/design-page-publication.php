<?php
/** Explicit Page save intent, independent of site-design publication. */
declare( strict_types=1 );
const WP_DESIGN_PAGE_PUBLISHED = 'published';
const WP_DESIGN_PAGE_ACTION_STATUSES = [
	'save_page_draft' => 'draft',
	'publish_page'    => 'published',
	'update_page'     => 'published'
];
const WP_DESIGN_PAGE_DRAFT_BUTTON = [
	'value' => 'save_page_draft',
	'label' => 'Save Draft',
	'class' => 'secondary'
];
const WP_DESIGN_PAGE_UPDATE_BUTTON = [
	'value' => 'update_page',
	'label' => 'Update Page',
	'class' => ''
];
const WP_DESIGN_PAGE_SAVE_NOTICES = [
	'save_page_draft' => 'Draft saved. This Page is not public.',
	'publish_page'    => 'Page published. Site design is published separately.',
	'update_page'     => 'Page updated.'
];
const WP_DESIGN_PAGE_GENERIC_NOTICE = 'Content saved.';
const WP_DESIGN_PAGE_RESULT_QUERIES = [
	'save_page_draft' => '&page_action=save_page_draft',
	'publish_page'    => '&page_action=publish_page',
	'update_page'     => '&page_action=update_page'
];

function wp_design_page_submission_status( bool $workspace, string $action, string $submitted ): string
{
	if ( ! $workspace ) {
		return $submitted;
	}
	return WP_DESIGN_PAGE_ACTION_STATUSES[ $action ] ?? $submitted;
}
function wp_design_page_failed_status( bool $workspace, string $persisted, string $submitted ): string
{
	return $workspace ? $persisted : $submitted;
}
function wp_design_page_save_button( string $persisted ): array
{
	return WP_DESIGN_PAGE_PUBLISHED === $persisted ? WP_DESIGN_PAGE_UPDATE_BUTTON : WP_DESIGN_PAGE_DRAFT_BUTTON;
}
function wp_design_page_show_publish( string $persisted, bool $permission ): bool
{
	if ( WP_DESIGN_PAGE_PUBLISHED === $persisted ) {
		return false;
	}
	return $permission;
}
function wp_design_page_save_notice( bool $workspace, string $action, string $persisted ): string
{
	if ( ! $workspace ) {
		return WP_DESIGN_PAGE_GENERIC_NOTICE;
	}
	if ( ( WP_DESIGN_PAGE_ACTION_STATUSES[ $action ] ?? null ) !== $persisted ) {
		return WP_DESIGN_PAGE_GENERIC_NOTICE;
	}
	return WP_DESIGN_PAGE_SAVE_NOTICES[ $action ] ?? WP_DESIGN_PAGE_GENERIC_NOTICE;
}
function wp_design_page_result_query( string $action ): string
{
	return WP_DESIGN_PAGE_RESULT_QUERIES[ $action ] ?? '';
}
