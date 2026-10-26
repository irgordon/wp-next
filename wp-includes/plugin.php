<?php
// Procedural registration facade carried forward from the supplied snippets.
function add_action( string $hook_name, string $service_id, string $method = 'handle' ): void
{
	global $wp_listener_provider;
	$wp_listener_provider->add_listener( $hook_name, $service_id, $method );
}
