<?php
/** Native design-form input adapter shared by preview and persistence. */
declare( strict_types=1 );
const WP_DESIGN_INPUT_DEFAULTS = [
	'accent'     => '#3858e9',
	'background' => '#ffffff',
	'width'      => 960,
	'font'       => 'sans',
	'body_size'  => 16,
	'spacing'    => 32
];

function wp_design_submitted_config( array $input, array $baseline ): array
{
	return [
		'active_theme'     => wp_design_input_text( $input, 'active_theme', $baseline['active_theme'] ),
		'tokens'           => wp_design_input_tokens( $input, $baseline['tokens'] ),
		'homepage_id'      => (int) wp_design_input_text( $input, 'homepage_id', $baseline['homepage_id'] ),
		'site_logo_id'     => (int) wp_design_input_text( $input, 'site_logo_id', $baseline['site_logo_id'] ),
		'navigation_pages' => wp_design_input_navigation( $input ),
		'menus'            => wp_design_input_menus( $input ),
		'sections'         => wp_design_input_sections( $input, $baseline['sections'] )
	];
}
function wp_design_input_tokens( array $input, array $baseline ): array
{
	$output = [];
	foreach ( [ 'accent', 'background', 'width', 'font', 'body_size', 'spacing' ] as $name ) {
		$output[ $name ] = wp_design_input_text( $input, $name, $baseline[ $name ] ?? WP_DESIGN_INPUT_DEFAULTS[ $name ] );
	}
	return $output;
}
function wp_design_input_navigation( array $input ): array
{
	$ids = array_values( array_map( 'intval', is_array( $input['navigation'] ?? null ) ? $input['navigation'] : [] ) );
	$orders = is_array( $input['nav_order'] ?? null ) ? $input['nav_order'] : [];
	usort( $ids, static fn( int $a, int $b ): int => (int) ( $orders[ $a ] ?? 0 ) <=> (int) ( $orders[ $b ] ?? 0 ) );
	return $ids;
}
function wp_design_input_menus( array $input ): array
{
	$output = [];
	foreach ( [ 'header', 'footer' ] as $location ) {
		$rows = is_array( $input['menu'][ $location ] ?? null ) ? $input['menu'][ $location ] : [];
		$output[ $location ] = wp_design_input_menu_rows( $rows );
	}
	return $output;
}
function wp_design_input_sections( array $input, array $baseline ): array
{
	$output = [];
	foreach ( [ 'header', 'footer' ] as $location ) {
		$output[ $location ] = wp_design_input_text( $input, 'section_' . $location, $baseline[ $location ] ?? '' );
	}
	return $output;
}
function wp_design_input_menu_rows( array $rows ): array
{
	$items = [];
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$item = wp_design_input_menu_row( $row );
		if ( '' !== $item['label'] || '' !== $item['url'] ) {
			$items[] = $item;
		}
	}
	usort( $items, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
	return array_map( static fn( array $item ): array => array_intersect_key( $item, array_flip( [ 'label', 'url', 'level' ] ) ), $items );
}
function wp_design_input_menu_row( array $row ): array
{
	return [
		'label' => trim( wp_design_input_text( $row, 'label', '' ) ),
		'url'   => trim( wp_design_input_text( $row, 'url', '' ) ),
		'level' => max( 1, min( 3, (int) ( $row['level'] ?? 1 ) ) ),
		'order' => (int) ( $row['order'] ?? 0 )
	];
}
function wp_design_input_text( array $input, string $key, mixed $default ): string
{
	return wp_input( $input, $key, (string) $default );
}
