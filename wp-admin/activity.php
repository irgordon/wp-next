<?php
/** Operator-facing queue state and bounded activity listing. */
declare( strict_types=1 );
require_once __DIR__ . '/admin.php';
global $wpdb;
wp_admin_require_cap( 'administrator' );
$admin_page_title = 'Activity';
$admin_section = 'activity';
$jobs = $wpdb->get_results( 'SELECT status,COUNT(*) AS count FROM job_queue GROUP BY status' );
$entries = $wpdb->get_results( 'SELECT a.*,u.username FROM audit_log a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 100' );
require __DIR__ . '/admin-header.php';
?>
<section class="panel"><h2>Background Queue</h2><?php foreach ( $jobs as $job ) :
	?><p><strong><?php echo esc_html( ucfirst( $job['status'] ) ); ?>:</strong> <?php echo (int) $job['count']; ?></p><?php
												endforeach;
												if ( [] === $jobs ) {
													echo '<p>No queued work yet.</p>';
												} ?><p class="description">The site operator should schedule <code>php wp-cron.php</code> every minute. Failed jobs can be retried with <code>php bin/wp queue:retry</code>.</p></section>
<div class="table-scroll"><table><caption>Latest 100 activity records</caption><thead><tr><th scope="col">When (UTC)</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Subject</th></tr></thead><tbody><?php foreach ( $entries as $entry ) :
	?><tr><td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $entry['created_at'] ) ); ?></td><td><?php echo esc_html( $entry['username'] ?? 'System' ); ?></td><td><?php echo esc_html( str_replace( '_', ' ', $entry['action'] ) ); ?></td><td><?php echo esc_html( $entry['subject_id'] ); ?></td></tr><?php
																																																							  endforeach; ?></tbody></table></div>
<?php require __DIR__ . '/admin-footer.php';
