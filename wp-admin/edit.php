<?php
/** Paginated, permission-scoped content listing with sortable columns and row actions. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
$type = wp_input( $_GET, 'post_type', 'post' );
if ( ! in_array( $type, [ 'post', 'page' ], true ) ) {
	$type = 'post';
}
$plural = 'page' === $type ? 'pages' : 'posts';
wp_admin_require_cap( 'edit_' . $plural );
$admin_page_title = ucfirst( $plural );
$admin_section = $type;
$view = 'trash' === wp_input( $_GET, 'view' ) ? 'trash' : 'all';
$sorts = [
	'title'    => 'p.title',
	'author'   => "COALESCE(NULLIF(u.display_name,''),u.username)",
	'status'   => 'p.status',
	'revision' => 'p.revision',
	'updated'  => 'p.updated_at'
];
$orderby = wp_input( $_GET, 'orderby', 'updated' );
if ( ! isset( $sorts[ $orderby ] ) ) {
	$orderby = 'updated';
}
$order = 'asc' === strtolower( wp_input( $_GET, 'order' ) ) ? 'ASC' : 'DESC';
$term = wp_input( $_GET, 's' );
$list_params = static function ( array $extra = [] ) use ( $type, $view, $orderby, $order, $term ): string {
	$params = [
		'post_type' => $type,
		'orderby'   => $orderby,
		'order'     => strtolower( $order )
	];
	if ( 'trash' === $view ) {
		$params['view'] = 'trash';
	}
	if ( '' !== $term ) {
		$params['s'] = $term;
	}
	return http_build_query( $extra + $params );
};
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wp_require_post( 'content_list' );
	$action = wp_input( $_POST, 'list_action' );
	$id = (int) wp_input( $_POST, 'post_id' );
	$target = $wpdb->get_row( 'SELECT * FROM posts WHERE id=:id AND post_type=:type', [
		':id'   => $id,
		':type' => $type
	] );
	if ( null === $target || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( 'You cannot modify this content.', 'Access denied', [ 'response' => 403 ] );
	}
	$notice = '';
	$failed = '';
	if ( 'quickedit' === $action ) {
		$status = wp_input( $_POST, 'post_status' );
		if ( 'trashed' === $target['status'] ) {
			$failed = 'Restore this content from the Trash before editing it.';
		} elseif ( ! in_array( $status, [ 'draft', 'published', 'archived' ], true ) ) {
			$failed = 'Choose a valid status.';
		} else {
			$result = wp_update_post( [
				'ID'                => $id,
				'post_title'        => wp_input( $_POST, 'post_title' ),
				'post_name'         => wp_input( $_POST, 'post_name' ),
				'post_content'      => (string) $target['content_raw'],
				'post_excerpt'      => (string) $target['excerpt'],
				'post_status'       => $status,
				'featured_media_id' => (string) ( $target['featured_media_id'] ?? '' ),
				'expected_revision' => wp_input( $_POST, 'expected_revision' )
			] );
			if ( is_wp_error( $result ) ) {
				$failed = $result->get_error_message();
			} else {
				$notice = 'updated';
			}
		}
	} elseif ( 'trash' === $action ) {
		$result = wp_trash_post( $id );
		if ( is_wp_error( $result ) ) {
			$failed = $result->get_error_message();
		} else {
			$notice = 'trashed';
		}
	} elseif ( 'restore' === $action ) {
		$result = wp_restore_post( $id );
		if ( is_wp_error( $result ) ) {
			$failed = $result->get_error_message();
		} else {
			$notice = 'restored';
		}
	} elseif ( 'delete' === $action ) {
		$result = wp_delete_post( $id );
		if ( is_wp_error( $result ) ) {
			$failed = $result->get_error_message();
		} else {
			$notice = 'deleted';
		}
	} else {
		$failed = 'Unknown list action.';
	}
	$query = [
		'post_type' => $type,
		'orderby'   => $orderby,
		'order'     => strtolower( $order )
	];
	if ( 'trash' === $view ) {
		$query['view'] = 'trash';
	}
	if ( '' !== $term ) {
		$query['s'] = $term;
	}
	if ( '' !== $failed ) {
		$query['list_error'] = $failed;
	} elseif ( '' !== $notice ) {
		$query['notice'] = $notice;
	}
	wp_safe_redirect( 'edit.php?' . http_build_query( $query ) );
}
$page = max( 1, (int) wp_input( $_GET, 'page', '1' ) );
$scope = 'p.post_type=:type';
$params = [ ':type' => $type ];
if ( ! current_user_can( 'edit_others_' . $plural ) ) {
	$scope .= ' AND p.author_id=:author';
	$params[':author'] = get_current_user_id();
}
$count_all = (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM posts p WHERE ' . $scope . " AND p.status<>'trashed'", $params )['n'];
$count_trash = (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM posts p WHERE ' . $scope . " AND p.status='trashed'", $params )['n'];
$where = $scope . ( 'trash' === $view ? " AND p.status='trashed'" : " AND p.status<>'trashed'" );
if ( '' !== $term ) {
	$where .= ' AND p.title LIKE :search';
	$params[':search'] = '%' . $term . '%';
}
$total = (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM posts p WHERE ' . $where, $params )['n'];
$page = min( $page, max( 1, (int) ceil( $total / 20 ) ) );
$posts = $wpdb->get_results( "SELECT p.*,COALESCE(NULLIF(u.display_name,''),u.username) AS author_name FROM posts p JOIN users u ON u.id=p.author_id WHERE " . $where . ' ORDER BY ' . $sorts[ $orderby ] . ' ' . $order . ',p.id ' . $order . ' LIMIT 20 OFFSET :offset', $params + [ ':offset' => ( $page - 1 ) * 20 ] );
require __DIR__ . '/admin-header.php';
$notices = [
	'updated'  => 'Content updated.',
	'trashed'  => 'Content moved to the Trash.',
	'restored' => 'Content restored from the Trash.',
	'deleted'  => 'Content permanently deleted.'
];
wp_admin_notice( $notices[ wp_input( $_GET, 'notice' ) ] ?? '' );
wp_admin_notice( wp_input( $_GET, 'list_error' ), true );
$sort_header = static function ( string $key, string $label ) use ( $orderby, $order, $list_params ): void {
	$active = $key === $orderby;
	$next = $active && 'ASC' === $order ? 'desc' : 'asc';
	$arrow = $active ? ( 'ASC' === $order ? '↑' : '↓' ) : '↕';
	?>
<th scope="col" <?php echo $active ? 'aria-sort="' . ( 'ASC' === $order ? 'ascending' : 'descending' ) . '"' : ''; ?>><a class="sort-link" href="<?php echo esc_url( 'edit.php?' . $list_params( [
	'orderby' => $key,
	'order'   => $next
] ) ); ?>"><?php echo esc_html( $label ); ?> <span class="sort-arrow" aria-hidden="true"><?php echo $arrow; ?></span></a></th>
	<?php
};
?>
<nav class="views" aria-label="Content views"><a href="<?php echo esc_url( 'edit.php?post_type=' . $type ); ?>" <?php echo 'all' === $view ? 'aria-current="page"' : ''; ?>>All (<?php echo $count_all; ?>)</a><a href="<?php echo esc_url( 'edit.php?post_type=' . $type . '&view=trash' ); ?>" <?php echo 'trash' === $view ? 'aria-current="page"' : ''; ?>>Trash (<?php echo $count_trash; ?>)</a></nav>
<div class="toolbar"><a class="button" href="post-new.php?post_type=<?php echo esc_attr( $type ); ?>">Add <?php echo 'page' === $type ? 'page' : 'post'; ?></a><form class="inline-form" method="get"><input type="hidden" name="post_type" value="<?php echo esc_attr( $type ); ?>" /><?php if ( 'trash' === $view ) :
	?><input type="hidden" name="view" value="trash" /><?php 
                                                                    endif; ?><label class="hidden" for="s">Search content</label><input id="s" name="s" value="<?php echo esc_attr( $term ); ?>" placeholder="Search titles" aria-label="Search titles" /><button type="submit">Search</button></form></div>
<div class="table-scroll"><table><caption><?php echo $total; ?> <?php echo esc_html( $plural ); ?> <?php echo 'trash' === $view ? 'in the Trash' : 'in your workspace'; ?></caption><thead><tr><?php $sort_header( 'title', 'Title' );
$sort_header( 'author', 'Author' );
$sort_header( 'status', 'Status' );
$sort_header( 'revision', 'Revision' );
$sort_header( 'updated', 'Updated' ); ?></tr></thead><tbody>
<?php foreach ( $posts as $post ) :
	$post_id = (int) $post['id'];
	?>
<tr><td><a href="post.php?post=<?php echo $post_id; ?>"><?php echo esc_html( $post['title'] ); ?></a><div class="row-actions">
	<?php if ( 'trash' === $view ) : ?>
<form class="inline-form" method="post"><?php wp_nonce_field( 'content_list' ); ?><input type="hidden" name="list_action" value="restore" /><input type="hidden" name="post_id" value="<?php echo $post_id; ?>" /><button type="submit" class="button-link row-action">Restore</button></form><span aria-hidden="true">·</span><button type="button" class="button-link row-action danger" data-toggle-row="dc-<?php echo $post_id; ?>" aria-expanded="false">Delete permanently</button>
    <?php else : ?>
<a href="post.php?post=<?php echo $post_id; ?>">Edit</a><span aria-hidden="true">·</span><button type="button" class="button-link row-action" data-toggle-row="qe-<?php echo $post_id; ?>" aria-expanded="false">Quick Edit</button><?php if ( 'published' === $post['status'] ) :
	?><span aria-hidden="true">·</span><a href="<?php echo esc_url( wp_post_url( $post ) ); ?>" target="_blank" rel="noopener">View</a><?php 
                       endif; ?><span aria-hidden="true">·</span><form class="inline-form" method="post"><?php wp_nonce_field( 'content_list' ); ?><input type="hidden" name="list_action" value="trash" /><input type="hidden" name="post_id" value="<?php echo $post_id; ?>" /><button type="submit" class="button-link row-action danger">Trash</button></form>
    <?php endif; ?>
</div></td><td><?php echo esc_html( $post['author_name'] ); ?></td><td><span class="status status-<?php echo esc_attr( $post['status'] ); ?>"><?php echo esc_html( ucfirst( $post['status'] ) ); ?></span></td><td><?php echo (int) $post['revision']; ?></td><td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $post['updated_at'] ) ); ?> UTC</td></tr>
	<?php if ( 'trash' === $view ) : ?>
<tr class="panel-row delete-confirm-row" id="dc-<?php echo $post_id; ?>" hidden><td colspan="5"><p><strong>Permanently delete “<?php echo esc_html( $post['title'] ); ?>”?</strong> This removes the content and its revision history and cannot be undone. Referenced media is kept.</p><form class="inline-form" method="post"><?php wp_nonce_field( 'content_list' ); ?><input type="hidden" name="list_action" value="delete" /><input type="hidden" name="post_id" value="<?php echo $post_id; ?>" /><button type="submit" class="button danger">Delete permanently</button> <button type="button" class="button-link" data-close-row="dc-<?php echo $post_id; ?>">Cancel</button></form></td></tr>
    <?php else : ?>
<tr class="panel-row quick-edit-row" id="qe-<?php echo $post_id; ?>" hidden><td colspan="5"><form method="post"><?php wp_nonce_field( 'content_list' ); ?><input type="hidden" name="list_action" value="quickedit" /><input type="hidden" name="post_id" value="<?php echo $post_id; ?>" /><input type="hidden" name="expected_revision" value="<?php echo (int) $post['revision']; ?>" /><div class="qe-grid"><div class="form-field"><label for="qe-title-<?php echo $post_id; ?>">Title</label><input id="qe-title-<?php echo $post_id; ?>" name="post_title" value="<?php echo esc_attr( $post['title'] ); ?>" required /></div><div class="form-field"><label for="qe-slug-<?php echo $post_id; ?>">Slug</label><input id="qe-slug-<?php echo $post_id; ?>" name="post_name" value="<?php echo esc_attr( $post['slug'] ); ?>" /></div><div class="form-field"><label for="qe-status-<?php echo $post_id; ?>">Status</label><select id="qe-status-<?php echo $post_id; ?>" name="post_status"><?php foreach ( [
	'draft'     => 'Draft',
	'published' => 'Published',
	'archived'  => 'Archived'
] as $value => $label ) :
	if ( 'published' === $value && ! current_user_can( 'publish_' . $plural ) && 'published' !== $post['status'] ) {
		continue;
	} ?><option value="<?php echo esc_attr( $value ); ?>" <?php echo $post['status'] === $value ? 'selected' : ''; ?>><?php echo esc_html( $label ); ?></option><?php 
                                            endforeach; ?></select></div></div><div class="qe-actions"><button type="submit">Save</button> <button type="button" class="button-link" data-close-row="qe-<?php echo $post_id; ?>">Cancel</button></div></form></td></tr>
    <?php endif; ?>
<?php endforeach; if ( [] === $posts ) :
	?><tr><td colspan="5">No content found.</td></tr><?php
endif; ?></tbody></table></div>
<nav class="pagination" aria-label="Content pages"><?php for ( $n = max( 1, $page - 1 ); $n <= min( (int) ceil( $total / 20 ), $page + 1 ); ++$n ) :
	?><a href="<?php echo esc_url( 'edit.php?' . $list_params( [ 'page' => $n ] ) ); ?>" <?php echo $n === $page ? 'aria-current="page"' : ''; ?>>Page <?php echo $n; ?></a><?php
						                           endfor; ?></nav>
<?php require __DIR__ . '/admin-footer.php';
