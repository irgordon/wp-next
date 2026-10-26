<?php
declare( strict_types=1 );
function __( string $text, string $domain = 'default' ): string
{
	return $text;
}
function _e( string $text, string $domain = 'default' ): void
{
	echo __( $text, $domain );
}
function _x( string $text, string $context, string $domain = 'default' ): string
{
	return $text;
}
function esc_html__( string $text, string $domain = 'default' ): string
{
	return esc_html( __( $text, $domain ) );
}
function esc_attr__( string $text, string $domain = 'default' ): string
{
	return esc_attr( __( $text, $domain ) );
}
