<?php
/** Explicit workspace draft creation through the existing content mutation service. */
declare( strict_types=1 );
const WP_DESIGN_CREATE_PAGE_ACTION = 'design-create-page';
const WP_DESIGN_CREATE_WORKSPACE_CAPABILITY = 'administrator';
const WP_DESIGN_CREATE_PAGE_CAPABILITY = 'edit_pages';
const WP_DESIGN_PAGE_CREATED_STATUS = 201;
const WP_DESIGN_PAGE_REJECTED_STATUS = 422;
const WP_DESIGN_CREATED_PAGE_MISSING_CODE = 'page_unavailable';
const WP_DESIGN_CREATED_PAGE_MISSING_MESSAGE = 'The draft is no longer available. Refresh the Page list before trying again.';
const WP_DESIGN_NEW_PAGE = [
	'post_type'     => 'page',
	'post_title'    => 'Untitled Page',
	'post_status'   => 'draft',
	'post_content'  => '{"version":1,"blocks":[]}',
	'page_template' => 'standard'
];

function wp_design_create_page_response(): never
{
	wp_design_require_page_creation();
	$result = wp_design_create_page_draft();
	wp_design_reply_page_creation( $result );
}
function wp_design_require_page_creation(): void
{
	wp_require_post( WP_DESIGN_CREATE_PAGE_ACTION );
	wp_admin_require_cap( WP_DESIGN_CREATE_WORKSPACE_CAPABILITY );
	wp_admin_require_cap( WP_DESIGN_CREATE_PAGE_CAPABILITY );
}
function wp_design_create_page_draft(): array|WP_Error
{
	$id = wp_design_insert_content( WP_DESIGN_NEW_PAGE );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$row = wp_design_read_created_page( $id );
	if ( null === $row ) {
		return new WP_Error( WP_DESIGN_CREATED_PAGE_MISSING_CODE, WP_DESIGN_CREATED_PAGE_MISSING_MESSAGE );
	}
	return wp_design_present_created_page( $row );
}
function wp_design_reply_page_creation( array|WP_Error $result ): never
{
	if ( is_wp_error( $result ) ) {
		wp_design_send_creation_json( [
			'ok'    => false,
			'error' => $result->get_error_message()
		], WP_DESIGN_PAGE_REJECTED_STATUS );
	}
	wp_design_send_creation_json( [
		'ok'   => true,
		'item' => $result
	], WP_DESIGN_PAGE_CREATED_STATUS );
}
function wp_design_insert_content( array $input ): int|WP_Error
{
	return wp_insert_post( $input );
}
function wp_design_read_created_page( int $id ): ?array
{
	global $wpdb;
	return $wpdb->get_row( 'SELECT id,title,status,revision FROM posts WHERE id=:id', [ ':id' => $id ] );
}
function wp_design_present_created_page( array $row ): array
{
	return wp_design_page_inventory_item( $row ) + [ 'listPage' => wp_design_page_list_position( $row ) ];
}
function wp_design_send_creation_json( array $payload, int $status ): never
{
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=UTF-8' );
	header( 'Cache-Control: private, no-store' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo wp_json_encode( $payload );
	exit;
}
