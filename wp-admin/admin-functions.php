<?php
/** Small shared UI helpers. All labels and values are escaped at output. */
declare( strict_types=1 );
function wp_admin_require_cap( string $capability ): void
{
	if ( ! current_user_can( $capability ) ) {
		wp_die( 'You do not have permission to use this screen.', 'Access denied', [ 'response' => 403 ] );
	}
}
function wp_admin_notice( string $message, bool $error = false ): void
{
	if ( '' === $message ) {
		return;
	}
	echo '<div class="notice ' . ( $error ? 'notice-error' : 'notice-success' ) . '" role="' . ( $error ? 'alert' : 'status' ) . '" tabindex="-1">' . esc_html( $message ) . '</div>';
}
function wp_admin_field( string $name, string $label, string $value, string $type = 'text', string $description = '' ): void
{
	echo '<div class="form-field"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" />';
	if ( '' !== $description ) {
		echo '<p class="description">' . esc_html( $description ) . '</p>';
	}
	echo '</div>';
}
function wp_admin_auth_header( string $title ): void
{
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width,initial-scale=1" /><title>' . esc_html( $title . ' · WordPress Next' ) . '</title><link rel="stylesheet" href="css/admin.css" /></head><body class="auth-page"><a class="skip-link" href="#main-content">Skip to content</a><main id="main-content" class="auth-panel"><p class="eyebrow">WordPress Next</p><h1>' . esc_html( $title ) . '</h1>';
}
function wp_admin_auth_footer(): void
{
	echo '</main></body></html>';
}

/** Administration navigation data; permission checks stay at the normal capability boundary. */
const WP_ADMIN_MENU_GROUPS = [
	'Content'  => [
		[
			'key'        => 'post',
			'label'      => 'Posts',
			'url'        => 'edit.php?post_type=post',
			'capability' => 'edit_posts'
		],
		[
			'key'        => 'page',
			'label'      => 'Pages',
			'url'        => 'edit.php?post_type=page',
			'capability' => 'edit_pages'
		],
		[
			'key'        => 'category',
			'label'      => 'Categories',
			'url'        => 'edit-tags.php?taxonomy=category',
			'capability' => 'edit_others_posts'
		],
		[
			'key'        => 'post_tag',
			'label'      => 'Tags',
			'url'        => 'edit-tags.php?taxonomy=post_tag',
			'capability' => 'edit_others_posts'
		]
	],
	'Media'    => [
		[
			'key'        => 'images',
			'label'      => 'Images',
			'url'        => 'media.php',
			'capability' => 'upload_media'
		],
		[
			'key'        => 'documents',
			'label'      => 'Documents',
			'url'        => 'media.php?kind=documents',
			'capability' => 'upload_media'
		]
	],
	'Design'   => [
		[
			'key'        => 'design',
			'label'      => 'Site design',
			'url'        => 'themes.php',
			'capability' => 'administrator'
		],
		[
			'key'        => 'files',
			'label'      => 'Code & files',
			'url'        => 'file-editor.php',
			'capability' => 'administrator'
		]
	],
	'Settings' => [
		[
			'key'        => 'settings',
			'label'      => 'General',
			'url'        => 'options-general.php',
			'capability' => 'administrator'
		],
		[
			'key'        => 'permalinks',
			'label'      => 'Permalinks',
			'url'        => 'options-permalink.php',
			'capability' => 'administrator'
		],
		[
			'key'        => 'users',
			'label'      => 'People',
			'url'        => 'users.php',
			'capability' => 'administrator'
		],
		[
			'key'        => 'activity',
			'label'      => 'Activity',
			'url'        => 'activity.php',
			'capability' => 'administrator'
		],
		[
			'key'        => 'portability',
			'label'      => 'Export / Import',
			'url'        => 'portability.php',
			'capability' => 'administrator'
		],
		[
			'key'        => 'profile',
			'label'      => 'Your profile',
			'url'        => 'profile.php',
			'capability' => ''
		]
	]
];
const WP_ADMIN_HOME_CRUMB = [
	'label' => 'Workspace',
	'url'   => 'index.php'
];
const WP_ADMIN_OVERVIEW = [
	'key'   => 'overview',
	'label' => 'Overview',
	'url'   => 'index.php',
	'group' => ''
];
const WP_ADMIN_CONTENT_SECTIONS = [ 'post', 'page' ];
const WP_ADMIN_EMPTY_LABEL = '';

function wp_admin_navigation_state( string $section, string $title ): array
{
	$access = wp_admin_navigation_access();
	$groups = wp_admin_select_navigation( $access );
	$location = wp_admin_select_location( $groups, $section );
	$trail = wp_admin_breadcrumb_trail( $groups, $location, $title );
	return [
		'groups'   => $groups,
		'location' => $location,
		'trail'    => $trail
	];
}
function wp_admin_breadcrumb_trail( array $groups, array $location, string $title ): array
{
	$trail = wp_admin_base_trail( $groups, $location );
	$trail = wp_admin_content_trail( $trail, $location, $title );
	return wp_admin_finish_trail( $trail, $title );
}
function wp_admin_navigation_access(): array
{
	return wp_admin_read_navigation_access();
}
function wp_admin_select_navigation( array $access ): array
{
	$groups = [];
	foreach ( WP_ADMIN_MENU_GROUPS as $label => $entries ) {
		$visible = wp_admin_filter_navigation( $entries, $access );
		if ( [] !== $visible ) {
			$groups[ $label ] = $visible;
		}
	}
	return $groups;
}
function wp_admin_select_location( array $groups, string $section ): array
{
	foreach ( wp_admin_flat_navigation( $groups ) as $entry ) {
		if ( $section === $entry['key'] ) {
			return $entry;
		}
	}
	return WP_ADMIN_OVERVIEW;
}
function wp_admin_base_trail( array $groups, array $location ): array
{
	$trail = [ WP_ADMIN_HOME_CRUMB ];
	if ( WP_ADMIN_EMPTY_LABEL !== $location['group'] ) {
		$trail[] = [
			'label' => $location['group'],
			'url'   => wp_admin_first_navigation_url( $groups[ $location['group'] ] )
		];
	}
	return $trail;
}
function wp_admin_content_trail( array $trail, array $location, string $title ): array
{
	if ( wp_admin_is_content_section( $location['key'] ) ) {
		if ( $title !== $location['label'] ) {
			$trail[] = [
				'label' => $location['label'],
				'url'   => $location['url']
			];
		}
	}
	return $trail;
}
function wp_admin_finish_trail( array $trail, string $title ): array
{
	$trail[] = [
		'label' => $title,
		'url'   => WP_ADMIN_EMPTY_LABEL
	];
	return $trail;
}
function wp_admin_render_navigation( array $navigation ): void
{
	wp_admin_render_navigation_link( WP_ADMIN_OVERVIEW, $navigation['location']['key'] );
	foreach ( $navigation['groups'] as $label => $entries ) {
		wp_admin_render_navigation_group( $label, $entries, $navigation['location']['key'] );
	}
}
function wp_admin_render_navigation_group( string $label, array $entries, string $active ): void
{
	echo '<details class="nav-group" open><summary>' . esc_html( $label ) . '<span aria-hidden="true" class="nav-group-chevron">⌄</span></summary><ul>';
	foreach ( $entries as $entry ) {
		echo '<li>';
		wp_admin_render_navigation_link( $entry, $active );
		echo '</li>';
	}
	echo '</ul></details>';
}
function wp_admin_render_navigation_link( array $entry, string $active ): void
{
	$current = $entry['key'] === $active ? ' aria-current="page"' : '';
	echo '<a class="admin-nav-link" href="' . esc_url( $entry['url'] ) . '"' . $current . '>' . esc_html( $entry['label'] ) . '</a>';
}
function wp_admin_render_breadcrumbs( array $trail ): void
{
	echo '<nav class="admin-breadcrumbs" aria-label="Breadcrumb"><ol>';
	foreach ( $trail as $index => $crumb ) {
		wp_admin_render_breadcrumb_item( $crumb, $index, count( $trail ) );
		if ( 0 === $index && count( $trail ) > 2 ) {
			wp_admin_render_breadcrumb_overflow( array_slice( $trail, 1, -1 ) );
		}
	}
	echo '</ol></nav>';
}
function wp_admin_render_breadcrumb_item( array $crumb, int $index, int $count ): void
{
	$current = $index === $count - 1;
	$class = $current ? 'crumb-current' : ( 0 === $index ? 'crumb-root' : 'crumb-middle' );
	echo '<li class="' . $class . '">' . wp_admin_crumb_separator( $index > 0 );
	if ( $current ) {
		echo '<span aria-current="page">' . esc_html( $crumb['label'] ) . '</span>';
	} else {
		echo '<a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['label'] ) . '</a>';
	}
	echo '</li>';
}
function wp_admin_render_breadcrumb_overflow( array $ancestors ): void
{
	echo '<li class="breadcrumb-overflow"><span class="crumb-separator" aria-hidden="true">›</span><details><summary aria-label="Show parent pages">…</summary><ul class="breadcrumb-menu">';
	foreach ( $ancestors as $crumb ) {
		echo '<li><a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['label'] ) . '</a></li>';
	}
	echo '</ul></details></li>';
}
function wp_admin_read_navigation_access(): array
{
	$access = [ '' => true ];
	foreach ( WP_ADMIN_MENU_GROUPS as $entries ) {
		foreach ( $entries as $entry ) {
			$capability = $entry['capability'];
			if ( '' !== $capability ) {
				$access[ $capability ] = current_user_can( $capability );
			}
		}
	}
	return $access;
}
function wp_admin_filter_navigation( array $entries, array $access ): array
{
	return array_values( array_filter( $entries, static fn( array $entry ): bool => $access[ $entry['capability'] ] ) );
}
function wp_admin_flat_navigation( array $groups ): array
{
	$output = [];
	foreach ( $groups as $label => $entries ) {
		$output = array_merge( $output, array_map( static fn( array $entry ): array => $entry + [ 'group' => $label ], $entries ) );
	}
	return $output;
}
function wp_admin_first_navigation_url( array $entries ): string
{
	return $entries[0]['url'];
}
function wp_admin_is_content_section( string $key ): bool
{
	return in_array( $key, WP_ADMIN_CONTENT_SECTIONS, true );
}


function wp_admin_crumb_separator( bool $visible ): string
{
	return $visible ? '<span class="crumb-separator" aria-hidden="true">›</span>' : '';
}
