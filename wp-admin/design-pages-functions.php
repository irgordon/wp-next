<?php
/** Bounded editable-Page inventory and scoped trusted editor framing. */
declare( strict_types=1 );
const WP_DESIGN_PAGES_LIMIT = 20;
const WP_DESIGN_PAGE_SEARCH_LIMIT = 200;
const WP_DESIGN_PAGE_STATUSES = [ 'draft', 'published', 'archived' ];

function wp_design_pages_response(): never
{
	wp_require_post( 'design-pages' );
	$result = wp_design_pages_inventory( wp_input( $_POST, 'search' ), wp_input( $_POST, 'status', 'all' ), (int) wp_input( $_POST, 'page', '1' ) );
	header( 'Content-Type: application/json; charset=UTF-8' );
	header( 'Cache-Control: private, no-store' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo wp_json_encode( $result );
	exit;
}
function wp_design_pages_inventory( string $search = '', string $status = 'all', int $page = 1 ): array
{
	$criteria = wp_design_pages_criteria( $search, $status );
	$window = wp_design_pages_window( $criteria, $page );
	return wp_design_pages_collection( $criteria, $window );
}
function wp_design_pages_criteria( string $search, string $status ): array
{
	return [
		'search' => wp_design_pages_search_pattern( $search ),
		'status' => in_array( $status, WP_DESIGN_PAGE_STATUSES, true ) ? $status : null
	];
}
function wp_design_pages_window( array $criteria, int $requested ): array
{
	$total = wp_design_pages_count( $criteria );
	$pages = max( 1, (int) ceil( $total / WP_DESIGN_PAGES_LIMIT ) );
	return [
		'page'  => min( max( 1, $requested ), $pages ),
		'pages' => $pages,
		'total' => $total
	];
}
function wp_design_pages_collection( array $criteria, array $window ): array
{
	$rows = wp_design_pages_rows( $criteria, $window['page'] );
	return $window + [ 'items' => wp_design_pages_present_rows( $rows ) ];
}
function wp_design_pages_search_pattern( string $search ): string
{
	return '%' . str_replace( [ '\\', '%', '_' ], [ '\\\\', '\\%', '\\_' ], substr( $search, 0, WP_DESIGN_PAGE_SEARCH_LIMIT ) ) . '%';
}
function wp_design_pages_count( array $criteria ): int
{
	global $wpdb;
	$query = wp_design_pages_query( $criteria );
	return (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM posts WHERE ' . $query['where'], $query['parameters'] )['n'];
}
function wp_design_pages_rows( array $criteria, int $page ): array
{
	global $wpdb;
	$query = wp_design_pages_query( $criteria );
	return $wpdb->get_results( 'SELECT id,title,status,revision FROM posts WHERE ' . $query['where'] . ' ORDER BY title COLLATE NOCASE,id LIMIT ' . WP_DESIGN_PAGES_LIMIT . ' OFFSET :offset', $query['parameters'] + [ ':offset' => ( $page - 1 ) * WP_DESIGN_PAGES_LIMIT ] );
}
function wp_design_pages_query( array $criteria ): array
{
	$where = "post_type='page' AND status<>'trashed' AND title LIKE :search ESCAPE '\\'";
	$parameters = [ ':search' => $criteria['search'] ];
	if ( null !== $criteria['status'] ) {
		$where .= ' AND status=:status';
		$parameters[':status'] = $criteria['status'];
	}
	return [
		'where'      => $where,
		'parameters' => $parameters
	];
}
function wp_design_pages_present_rows( array $rows ): array
{
	return array_values( array_map( 'wp_design_page_inventory_item', array_filter( $rows, static fn( array $row ): bool => current_user_can( 'edit_post', (int) $row['id'] ) ) ) );
}
function wp_design_page_inventory_item( array $row ): array
{
	$id = (int) $row['id'];
	return [
		'id'        => $id,
		'title'     => $row['title'],
		'status'    => $row['status'],
		'revision'  => (int) $row['revision'],
		'editorUrl' => 'post.php?post=' . $id . '&editor=visual' . wp_design_editor_query( $id )
	];
}
function wp_design_editor_query( int $id ): string
{
	return '&design_workspace=1&_design_nonce=' . rawurlencode( wp_nonce( 'design-editor:' . $id ) );
}
function wp_design_editor_authorize( int $id, string $type, bool $visual, string $status ): bool
{
	if ( '1' !== wp_input( $_GET, 'design_workspace' ) ) {
		return false;
	}
	if ( 'page' !== $type || 'trashed' === $status || ! $visual || ! current_user_can( 'administrator' ) || ! wp_verify_nonce( wp_input( $_GET, '_design_nonce' ), 'design-editor:' . $id ) ) {
		wp_die( 'The workspace editor link expired or is unavailable. Reload Site design.', 'Access denied', [ 'response' => 403 ] );
	}
	wp_security_headers( true );
	return true;
}
