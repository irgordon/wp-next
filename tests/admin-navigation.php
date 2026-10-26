<?php
/** Pure navigation selection: no database, session or filesystem mocks. */
declare( strict_types=1 );
require_once dirname( __DIR__ ) . '/wp-admin/admin-functions.php';
$count = 0;
function navigation_check( bool $condition, string $message ): void
{
	global $count;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$count;
	echo 'PASS: ' . $message . "\n";
}
$restricted = [ '' => true, 'administrator' => false, 'edit_posts' => false, 'edit_pages' => true, 'edit_others_posts' => false, 'upload_media' => false ];
$groups = wp_admin_select_navigation( $restricted );
navigation_check( array_keys( $groups ) === [ 'Content', 'Settings' ], 'Groups without accessible destinations are omitted' );
navigation_check( count( $groups['Content'] ) === 1 && 'page' === $groups['Content'][0]['key'], 'Page editors do not gain Post or taxonomy navigation' );
navigation_check( 'profile' === $groups['Settings'][0]['key'], 'Restricted settings retain the permitted profile destination' );
$location = wp_admin_select_location( $groups, 'page' );
$trail = wp_admin_breadcrumb_trail( $groups, $location, 'Edit page' );
navigation_check( array_column( $trail, 'label' ) === [ 'Workspace', 'Content', 'Pages', 'Edit page' ], 'Page detail breadcrumbs retain the complete hierarchy' );
navigation_check( 'edit.php?post_type=page' === $trail[1]['url'], 'Group breadcrumbs lead to a destination the actor can access' );
navigation_check( '' === $trail[3]['url'], 'The current breadcrumb is a location rather than a misleading link' );
$profile = wp_admin_breadcrumb_trail( $groups, wp_admin_select_location( $groups, 'profile' ), 'Your profile' );
navigation_check( 'profile.php' === $profile[1]['url'], 'Restricted profile breadcrumbs do not lead to administrator settings' );
$all = array_fill_keys( array_keys( $restricted ), true );
$groups = wp_admin_select_navigation( $all );
navigation_check( array_keys( $groups ) === [ 'Content', 'Media', 'Design', 'Settings' ], 'Administrator navigation exposes four logical groups' );
navigation_check( array_column( $groups['Media'], 'key' ) === [ 'images', 'documents' ], 'Media navigation includes the implemented asset kinds' );
navigation_check( 'files' === wp_admin_select_location( $groups, 'files' )['key'], 'Managed code files resolve within Design' );
navigation_check( 'permalinks' === wp_admin_select_location( $groups, 'permalinks' )['key'], 'Permalink settings have their own active destination' );
navigation_check( 'overview' === wp_admin_select_location( $groups, 'missing' )['key'], 'Unknown navigation contexts safely fall back to Overview' );
echo $count . " navigation checks passed.\n";
