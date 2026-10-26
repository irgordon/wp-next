<?php
declare( strict_types=1 );
use WordPress\Post\WP_Post_Mutation_Service;
function wp_insert_post( array $postarr ): int|WP_Error
{
	global $wp_post_mutation_service;
	if ( ! ( $wp_post_mutation_service instanceof WP_Post_Mutation_Service ) ) {
		return new WP_Error( 'service_unavailable', __( 'Post mutation service is uninitialized.' ) );
	}
	$acting_user_id = get_current_user_id();
	return $wp_post_mutation_service->insert_post( $postarr, $acting_user_id );
}
function wp_update_post( array $postarr ): int|WP_Error
{
	global $wp_post_mutation_service;
	if ( ! ( $wp_post_mutation_service instanceof WP_Post_Mutation_Service ) ) {
		return new WP_Error( 'service_unavailable', __( 'Post mutation service is uninitialized.' ) );
	}
	$acting_user_id = get_current_user_id();
	return $wp_post_mutation_service->update_post( $postarr, $acting_user_id );
}
function wp_trash_post( int $id ): bool|WP_Error
{
	global $wp_post_mutation_service;
	if ( ! ( $wp_post_mutation_service instanceof WP_Post_Mutation_Service ) ) {
		return new WP_Error( 'service_unavailable', __( 'Post mutation service is uninitialized.' ) );
	}
	return $wp_post_mutation_service->trash_post( $id, get_current_user_id() );
}
function wp_restore_post( int $id ): bool|WP_Error
{
	global $wp_post_mutation_service;
	if ( ! ( $wp_post_mutation_service instanceof WP_Post_Mutation_Service ) ) {
		return new WP_Error( 'service_unavailable', __( 'Post mutation service is uninitialized.' ) );
	}
	return $wp_post_mutation_service->restore_post( $id, get_current_user_id() );
}
function wp_delete_post( int $id ): bool|WP_Error
{
	global $wp_post_mutation_service;
	if ( ! ( $wp_post_mutation_service instanceof WP_Post_Mutation_Service ) ) {
		return new WP_Error( 'service_unavailable', __( 'Post mutation service is uninitialized.' ) );
	}
	return $wp_post_mutation_service->delete_post( $id, get_current_user_id() );
}
