<?php
declare( strict_types=1 );
class WP_Error {
	protected array $errors = [];
	protected array $error_data = [];
	public function __construct( string|int $code = '', string $message = '', mixed $data = '' )
	{
		if ( '' !== (string) $code ) {
			$this->add( (string) $code, $message, $data );
		}
	}
	public function add( string|int $code, string $message, mixed $data = '' ): void
	{
		$code_str = (string) $code;
		$this->errors[ $code_str ][] = $message;
		if ( '' !== $data ) {
			$this->error_data[ $code_str ] = $data;
		}
	}
	public function get_error_code(): string
	{
		$codes = array_keys( $this->errors );
		return empty( $codes ) ? '' : (string) $codes[0];
	}
	public function get_error_codes(): array
	{
		return array_map( 'strval', array_keys( $this->errors ) );
	}
	public function get_error_message( string|int $code = '' ): string
	{
		$target_code = '' === (string) $code ? $this->get_error_code() : (string) $code;
		if ( '' === $target_code || ! isset( $this->errors[ $target_code ] ) ) {
			return '';
		}
		return $this->errors[ $target_code ][0] ?? '';
	}
	public function get_error_data( string|int $code = '' ): mixed
	{
		$target_code = '' === (string) $code ? $this->get_error_code() : (string) $code;
		return $this->error_data[ $target_code ] ?? null;
	}
	public function has_errors(): bool
	{
		return ! empty( $this->errors );
	}
}
function is_wp_error( mixed $thing ): bool
{
	return $thing instanceof WP_Error;
}
