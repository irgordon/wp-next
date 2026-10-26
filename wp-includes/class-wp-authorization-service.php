<?php
/** One capability evaluator for preflight and transactional authorization. */
declare( strict_types=1 );
namespace WordPress\Security;
use WordPress\Database\WP_SQLite_DB;
use WP_Error;
class WP_Authorization_Service {
	public function has_capability( int $user_id, string $capability, WP_SQLite_DB $db ): bool
	{
		$row = $db->get_row( 'SELECT capabilities,disabled FROM users WHERE id=:id', [ ':id' => $user_id ] );
		if ( null === $row || 0 !== (int) $row['disabled'] ) {
			return false;
		}
		$caps = json_decode( $row['capabilities'], true ) ?: [];
		return in_array( 'administrator', $caps, true ) || in_array( $capability, $caps, true );
	}
	public function validate_mutation_permissions( int $user_id, array $candidate, array $snapshot, WP_SQLite_DB $db ): true|WP_Error
	{
		$plural = 'page' === ( $candidate['post_type'] ?? $snapshot['post_type'] ?? 'post' ) ? 'pages' : 'posts';
		$required = [ 'edit_' . $plural ];
		if ( (int) $snapshot['author_id'] !== $user_id || (int) $candidate['author_id'] !== (int) $snapshot['author_id'] ) {
			$required[] = 'edit_others_' . $plural;
		}
		if ( 'published' === $snapshot['status'] ) {
			$required[] = 'edit_published_' . $plural;
		}
		if ( 'published' === $candidate['status'] && 'published' !== $snapshot['status'] ) {
			$required[] = 'publish_' . $plural;
		}
		foreach ( $required as $capability ) {
			if ( ! $this->has_capability( $user_id, $capability, $db ) ) {
				return new WP_Error( 'unauthorized', 'You do not have permission to edit or publish this content.' );
			}
		}
		return true;
	}
}
