<?php
/** Publishing overview, scoped to the current editor. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
$admin_page_title = 'Overview';
$admin_section = 'overview';
require __DIR__ . '/admin-header.php';
?>
<div class="metrics">
<?php foreach ( [
	'post' => 'Posts',
	'page' => 'Pages'
] as $type => $label ) :
	$plural = 'page' === $type ? 'pages' : 'posts';
	if ( ! current_user_can( 'edit_' . $plural ) ) {
		continue;
	}
	$where = 'post_type=:type';
	$params = [ ':type' => $type ];
	if ( ! current_user_can( 'edit_others_' . $plural ) ) {
		$where .= ' AND author_id=:author';
		$params[':author'] = get_current_user_id();
	}
	$total = (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM posts WHERE ' . $where, $params )['n'];
	?>
<section class="panel metric"><h2><?php echo esc_html( $label ); ?></h2><strong><?php echo $total; ?></strong><a href="edit.php?post_type=<?php echo esc_attr( $type ); ?>">Manage <?php echo esc_html( strtolower( $label ) ); ?></a></section>
<?php endforeach; ?>
<?php if ( current_user_can( 'administrator' ) ) :
	$pending = (int) $wpdb->get_row( "SELECT COUNT(*) AS n FROM job_queue WHERE status IN ('pending','reserved','failed')" )['n']; ?>
<section class="panel metric"><h2>Background work</h2><strong><?php echo $pending; ?></strong><a href="activity.php">View queue status</a></section>
<?php endif; ?></div>
<section class="panel"><h2>Your publishing workspace</h2><p>Write content, build pages, and keep the public site consistent with your chosen design.</p><div class="actions">
<?php if ( current_user_can( 'edit_posts' ) ) :
	?><a class="button" href="post-new.php?post_type=post">Write a post</a><?php
endif; ?>
<?php if ( current_user_can( 'edit_pages' ) ) :
	?><a class="button button-secondary" href="post-new.php?post_type=page">Build a page</a><?php
endif; ?>
<?php if ( current_user_can( 'administrator' ) ) :
	?><a href="themes.php">Adjust site design</a><?php
endif; ?></div></section>
<?php require __DIR__ . '/admin-footer.php';
