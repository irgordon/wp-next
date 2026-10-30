<?php
/** Transaction-time namespace protection, including changes outside the API. */
declare( strict_types=1 );
class WP_API_Route_Conflict extends RuntimeException {
}

function wp_api_routes_snapshot( \WordPress\Database\WP_SQLite_DB $db ): ?array
{
	$state = null === $db->get_row( "SELECT name FROM sqlite_master WHERE type='table' AND name='api_security_state'" )
	? null : $db->get_row( 'SELECT * FROM api_security_state WHERE singleton=1' );
	if ( null === $state || ( empty( $state['enabled'] ) && empty( $state['bound_epoch'] ) ) ) {
		return null;
	}
	if ( ! function_exists( 'wp_api_route_conflicts' ) ) {
		throw new RuntimeException( 'The active API namespace guard is unavailable.' );
	}
	return wp_api_route_conflicts( $db, null );
}

function wp_api_routes_assert( \WordPress\Database\WP_SQLite_DB $db, ?array $before ): void
{
	$after = wp_api_routes_snapshot( $db );
	if ( [] !== array_diff( $after ?? [], $before ?? [] ) ) {
		throw new WP_API_Route_Conflict( 'This change would claim the editorial API namespace. Choose a different route.' );
	}
}

function wp_api_path_claims_mount( string $path ): bool
{
	return 'wp-next/v1' === $path || str_starts_with( $path, 'wp-next/v1/' );
}
