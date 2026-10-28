<?php
/** Content writes, revision snapshots, permissions, and jobs share one commit. */
declare( strict_types=1 );
namespace WordPress\Post;
use WordPress\Database\WP_SQLite_DB;
use WordPress\Events\WP_Post_Saving_Event;
use WordPress\Queue\WP_Job_Queue;
use WordPress\Security\WP_Authorization_Service;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Throwable;
use WP_Error;
class Concurrency_Conflict_Exception extends RuntimeException {
}
class Authorization_Exception extends RuntimeException {
}
class WP_Post_Mutation_Service {
	public function __construct( protected WP_SQLite_DB $db, protected WP_Job_Queue $queue, protected EventDispatcherInterface $dispatcher, protected WP_Authorization_Service $auth )
	{
	}
	private function candidate( array $input, array $snapshot ): array|WP_Error
	{
		$title = trim( (string) ( $input['post_title'] ?? $snapshot['title'] ?? '' ) );
		if ( '' === $title ) {
			return new WP_Error( 'empty_title', 'Title cannot be empty.' );
		}
		$type = (string) ( $snapshot['post_type'] ?? $input['post_type'] ?? 'post' );
		$status = (string) ( $input['post_status'] ?? $snapshot['status'] ?? 'draft' );
		if ( ! in_array( $type, [ 'post', 'page' ], true ) || ! in_array( $status, [ 'draft', 'published', 'archived' ], true ) ) {
			return new WP_Error( 'invalid_status', 'Invalid content type or status.' );
		}
		$content = $input['post_content'] ?? $snapshot['content_raw'] ?? '';
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'invalid_content', 'Content must be a block document or HTML string.' );
		}
		$content = wp_normalize_content( $content );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		$metadata = isset( $snapshot['metadata'] ) ? json_decode( $snapshot['metadata'], true ) : null;
		if ( array_key_exists( 'metadata', $input ) ) {
			if ( is_array( $input['metadata'] ) ) {
				$metadata = is_array( $metadata ) ? $metadata : [];
				foreach ( $input['metadata'] as $key => $value ) {
					if ( null === $value ) {
						unset( $metadata[ $key ] );
					} else {
						$metadata[ $key ] = $value;
					}
				}
			} elseif ( null === $input['metadata'] || is_scalar( $input['metadata'] ) ) {
				$metadata = $input['metadata'];
			} else {
				return new WP_Error( 'invalid_metadata', 'Metadata must be an array, scalar, or null.' );
			}
		}
        if ( 'page' === $type && isset($input['page_template']) ) {
            if ( ! is_string($input['page_template']) || ! isset(wp_curated_templates()[ $input['page_template'] ]) ) {
				return new WP_Error('invalid_template','Choose an approved Page template.'); 
			}
            $metadata = is_array($metadata) ? $metadata : [];
			$metadata['page_template'] = $input['page_template'];
        }
        if ( 'page' === $type && is_array($metadata) && isset($metadata['page_template']) && ( ! is_string($metadata['page_template']) || ! isset(wp_curated_templates()[ $metadata['page_template'] ])) ) {
			return new WP_Error('invalid_template','Choose an approved Page template.'); 
		}
		if ( array_key_exists( 'social_image_id', $input ) ) {
			$social = (int) $input['social_image_id'];
			if ( $social > 0 && null === $this->db->get_row( "SELECT id FROM media WHERE id=:id AND kind='image' AND state='ready'", [ ':id' => $social ] ) ) {
				return new WP_Error( 'invalid_media', 'Select an existing social image.' );
			}
			$metadata = is_array( $metadata ) ? $metadata : [];
			if ( $social > 0 ) {
				$metadata['social_image_id'] = $social;
			} else {
				unset( $metadata['social_image_id'] );
			}
		}
		$featured = (int) ( $input['featured_media_id'] ?? $snapshot['featured_media_id'] ?? 0 );
		if ( $featured > 0 && null === $this->db->get_row( "SELECT id FROM media WHERE id=:id AND kind='image' AND state='ready'", [ ':id' => $featured ] ) ) {
			return new WP_Error( 'invalid_media', 'Select an existing featured image.' );
		}
		if ( isset( $input['term_ids'] ) && ( ! is_array( $input['term_ids'] ) || ( 'page' === $type && [] !== $input['term_ids'] ) ) ) {
			return new WP_Error( 'invalid_terms', 'Terms must be a list of IDs assigned to a Post.' );
		}
		return [
			'term_ids'          => $input['term_ids'] ?? null,
			'id'                => (int) ( $snapshot['id'] ?? 0 ),
			'author_id'         => (int) ( $input['post_author'] ?? $snapshot['author_id'] ?? 0 ),
			'title'             => $title,
			'slug'              => wp_sanitize_slug( (string) ( $input['post_name'] ?? $snapshot['slug'] ?? $title ) ) ?: wp_sanitize_slug( $title ),
			'content_raw'       => $content,
			'status'            => $status,
			'post_type'         => $type,
			'metadata'          => $metadata,
			'excerpt'           => sanitize_text_field( $input['post_excerpt'] ?? $snapshot['excerpt'] ?? '' ),
			'featured_media_id' => $featured ?: null
		];
	}
	private function verify_media( array $candidate, WP_SQLite_DB $db, int $actor = 0 ): void
	{
        if ( 'page' === $candidate['post_type'] && is_array($candidate['metadata']) && isset($candidate['metadata']['page_template']) && ! isset(wp_curated_templates()[ $candidate['metadata']['page_template'] ]) ) {
			throw new RuntimeException('The Page template changed before saving.'); 
		}
		$document = json_decode( $candidate['content_raw'], true );
		$visit = static function ( mixed $value ) use ( &$visit, $db, $candidate, $actor ): void {
			if ( ! is_array( $value ) ) {
				return;
			}
			if ( isset( $value['media_id'] ) ) {
				if ( 'file' === ( $value['type'] ?? '' ) ) {
					$asset = $db->get_row( "SELECT * FROM media WHERE id=:id AND kind='document' AND state='ready'", [ ':id' => (int) $value['media_id'] ] );
					if ( null === $asset ) {
						throw new RuntimeException( 'A selected document was removed before saving.' );
					}
					// Access is rechecked here, inside the write transaction: a visibility change after normalization cannot slip a reference past save validation.
					if ( ! wp_asset_can_access_as( $asset, $actor ) ) {
						throw new RuntimeException( 'A selected document is not accessible to you.' );
					}
					if ( 'published' === $candidate['status'] && 'public' !== $asset['visibility'] ) {
						throw new RuntimeException( 'Published content can only reference public documents. Make the document public or remove the file block.' );
					}
				} elseif ( null === $db->get_row( "SELECT id FROM media WHERE id=:id AND kind='image' AND state='ready'", [ ':id' => (int) $value['media_id'] ] ) ) {
					throw new RuntimeException( 'A selected image was removed before saving.' );
				}
			}
			foreach ( $value as $item ) {
				if ( is_array( $item ) ) {
					$visit( $item );
				}
			}
		};
		$visit( $document );
		if ( null !== $candidate['featured_media_id'] && null === $db->get_row( "SELECT id FROM media WHERE id=:id AND kind='image' AND state='ready'", [ ':id' => $candidate['featured_media_id'] ] ) ) {
			throw new RuntimeException( 'The featured image was removed before saving.' );
		}
	}
	private function preflight( int $actor, array $candidate, array $snapshot ): true|WP_Error
	{
		$result = $this->auth->validate_mutation_permissions( $actor, $candidate, $snapshot, $this->db );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$event = new WP_Post_Saving_Event( $candidate, (int) $candidate['id'] > 0 ? $snapshot : null );
		$this->dispatcher->dispatch( $event );
		return $event->is_rejected() ? new WP_Error( 'validation_failed', $event->get_rejection_reason() ?? 'Content validation failed.' ) : true;
	}
	private function enqueue( int $id, int $revision, string $old, string $new, int $actor ): void
	{
		$this->queue->enqueue_in_transaction( 'WordPress\Jobs\Render_Post_Job', [
			'post_id'         => $id,
			'target_revision' => $revision
		] );
		if ( $old !== $new ) {
			$this->queue->enqueue_in_transaction( 'WordPress\Jobs\Post_Transition_Job', [
				'post_id'    => $id,
				'old_status' => $old,
				'new_status' => $new,
				'revision'   => $revision,
				'actor_id'   => $actor
			] );
		}
	}
	public function insert_post( array $input, int $acting_actor ): int|WP_Error
	{
		try {
			$input['post_author'] = $acting_actor;
			$candidate = $this->candidate( $input, [] );
			if ( is_wp_error( $candidate ) ) {
				return $candidate;
			}
			$snapshot = [
				'author_id' => $acting_actor,
				'status'    => 'draft',
				'post_type' => $candidate['post_type']
			];
			$result = $this->preflight( $acting_actor, $candidate, $snapshot );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return $this->db->transaction( function ( WP_SQLite_DB $db ) use ( $candidate, $snapshot, $acting_actor ): int {
				$permission = $this->auth->validate_mutation_permissions( $acting_actor, $candidate, $snapshot, $db );
				if ( is_wp_error( $permission ) ) {
					throw new Authorization_Exception( $permission->get_error_message() );
				}
				$this->verify_media( $candidate, $db, $acting_actor );
				$candidate['slug'] = wp_unique_slug( $candidate['slug'], 0, $db );
				$db->query( 'INSERT INTO posts(author_id,slug,title,content_raw,status,post_type,excerpt,featured_media_id,metadata,published_at) VALUES(:author,:slug,:title,:content,:status,:type,:excerpt,:media,:metadata,:date)', [
					':author'   => $acting_actor,
					':slug'     => $candidate['slug'],
					':title'    => $candidate['title'],
					':content'  => $candidate['content_raw'],
					':status'   => $candidate['status'],
					':type'     => $candidate['post_type'],
					':excerpt'  => $candidate['excerpt'],
					':media'    => $candidate['featured_media_id'],
					':metadata' => null === $candidate['metadata'] ? null : wp_json_encode( $candidate['metadata'] ),
					':date'     => 'published' === $candidate['status'] ? time() : null
				] );
				$id = (int) $db->get_row( 'SELECT last_insert_rowid() AS id' )['id'];
				if ( 'post' === $candidate['post_type'] ) {
					wp_assign_post_terms( $id, $candidate['term_ids'] ?? [], $db ); 
				}
				$this->enqueue( $id, 1, 'new', $candidate['status'], $acting_actor );
				// A new post can take ownership of a /documents path; cached File links elsewhere must be regenerated.
				wp_invalidate_file_link_renders( $db );
				return $id;
			} );
		} catch ( Authorization_Exception $error ) {
			return new WP_Error( 'unauthorized', $error->getMessage() );
		} catch ( Throwable $error ) {
			return wp_failure( 'insert_failed', $error, 'Content could not be created. Your input has been retained.' );
		}
	}
	public function update_post( array $input, int $acting_actor ): int|WP_Error
	{
		$id = (int) ( $input['ID'] ?? 0 );
		$expected = (int) ( $input['expected_revision'] ?? 0 );
		if ( $id <= 0 || $expected <= 0 ) {
			return new WP_Error( 'invalid_arguments', 'Content ID and expected revision are required.' );
		}
		try {
			$snapshot = $this->db->get_row( 'SELECT * FROM posts WHERE id=:id', [ ':id' => $id ] );
			if ( null === $snapshot ) {
				return new WP_Error( 'not_found', 'Content was not found.' );
			}
			$candidate = $this->candidate( $input, $snapshot );
			if ( is_wp_error( $candidate ) ) {
				return $candidate;
			}
			$permission = $this->preflight( $acting_actor, $candidate, $snapshot );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}
			return $this->db->transaction( function ( WP_SQLite_DB $db ) use ( $id, $expected, $acting_actor, $candidate ): int {
				// Re-read ownership and status under the write lock, not from the preflight snapshot.
				$current = $db->get_row( 'SELECT * FROM posts WHERE id=:id', [ ':id' => $id ] );
				if ( null === $current || (int) $current['revision'] !== $expected ) {
					throw new Concurrency_Conflict_Exception( 'This content changed in another session. Your edits are retained below. Reload the current version before applying them.' );
				}
				$permission = $this->auth->validate_mutation_permissions( $acting_actor, $candidate, $current, $db );
				if ( is_wp_error( $permission ) ) {
					throw new Authorization_Exception( $permission->get_error_message() );
				}
				if ( 'trashed' === $current['status'] ) {
					throw new Authorization_Exception( 'Restore content from Trash before editing.' ); 
				}
				$current['term_ids'] = wp_post_term_ids( $id, $db );
				$this->verify_media( $candidate, $db, $acting_actor );
				$slug = wp_unique_slug( $candidate['slug'], $id, $db );
				$db->query( 'INSERT INTO post_revisions(post_id,revision,snapshot,actor_id) VALUES(:id,:revision,:snapshot,:actor)', [
					':id'       => $id,
					':revision' => $expected,
					':snapshot' => wp_json_encode( $current ),
					':actor'    => $acting_actor
				] );
				$stmt = $db->query( 'UPDATE posts SET author_id=:author,slug=:slug,title=:title,content_raw=:content,status=:status,excerpt=:excerpt,featured_media_id=:media,metadata=:metadata,published_at=:date,revision=revision+1,updated_at=unixepoch() WHERE id=:id AND revision=:expected', [
					':author'   => $candidate['author_id'],
					':slug'     => $slug,
					':title'    => $candidate['title'],
					':content'  => $candidate['content_raw'],
					':status'   => $candidate['status'],
					':excerpt'  => $candidate['excerpt'],
					':media'    => $candidate['featured_media_id'],
					':metadata' => null === $candidate['metadata'] ? null : wp_json_encode( $candidate['metadata'] ),
					':date'     => 'published' === $candidate['status'] ? ( $current['published_at'] ?? time() ) : null,
					':id'       => $id,
					':expected' => $expected
				] );
				if ( 1 !== $stmt->rowCount() ) {
					throw new Concurrency_Conflict_Exception( 'The content changed. Reload and retry.' );
				}
				if ( 'post' === $candidate['post_type'] && null !== $candidate['term_ids'] ) {
					wp_assign_post_terms( $id, $candidate['term_ids'], $db ); 
				}
				$this->enqueue( $id, $expected + 1, $current['status'], $candidate['status'], $acting_actor );
				// Slug, author, category or status changes can move /documents route ownership; cached File links elsewhere must be regenerated.
				wp_invalidate_file_link_renders( $db );
				return $id;
			} );
		} catch ( Concurrency_Conflict_Exception $error ) {
			return new WP_Error( 'concurrency_conflict', $error->getMessage() );
		} catch ( Authorization_Exception $error ) {
			return new WP_Error( 'unauthorized', $error->getMessage() );
		} catch ( Throwable $error ) {
			return wp_failure( 'save_failed', $error, 'Content could not be saved. Your input has been retained.' );
		}
	}
	private function audit( WP_SQLite_DB $db, int $actor, string $action, int $id, array $payload ): void
	{
		$db->query( 'INSERT OR IGNORE INTO audit_log(actor_id,action,subject_id,payload,event_uuid) VALUES(:actor,:action,:id,:payload,:uuid)', [
			':actor'   => $actor,
			':action'  => $action,
			':id'      => $id,
			':payload' => wp_json_encode( $payload ),
			':uuid'    => bin2hex( random_bytes( 16 ) )
		] );
	}
	private function set_status( int $id, int $acting_actor, string $target ): bool|WP_Error
	{
		try {
			return $this->db->transaction( function ( WP_SQLite_DB $db ) use ( $id, $acting_actor, $target ): bool {
				$current = $db->get_row( 'SELECT * FROM posts WHERE id=:id', [ ':id' => $id ] );
				if ( null === $current ) {
					throw new Authorization_Exception( 'Content was not found.' );
				}
				if ( ( 'trashed' === $target ) === ( 'trashed' === $current['status'] ) ) {
					throw new Authorization_Exception( 'The content status changed. Reload before retrying.' );
				}
				$metadata = isset( $current['metadata'] ) ? json_decode( (string) $current['metadata'], true ) : null;
				$metadata = is_array( $metadata ) ? $metadata : [];
				if ( 'trashed' === $target ) {
					$metadata['pre_trash_status'] = (string) $current['status'];
				} else {
					$target = in_array( $metadata['pre_trash_status'] ?? '', [ 'draft', 'published', 'archived' ], true ) ? $metadata['pre_trash_status'] : 'draft';
					unset( $metadata['pre_trash_status'] );
				}
				$candidate = [
					'id'        => $id,
					'author_id' => (int) $current['author_id'],
					'post_type' => (string) $current['post_type'],
					'status'    => $target
				];
				$permission = $this->auth->validate_mutation_permissions( $acting_actor, $candidate, $current, $db );
				if ( is_wp_error( $permission ) ) {
					throw new Authorization_Exception( $permission->get_error_message() );
				}
				$current['term_ids'] = wp_post_term_ids( $id, $db );
				$db->query( 'INSERT INTO post_revisions(post_id,revision,snapshot,actor_id) VALUES(:id,:revision,:snapshot,:actor)', [
					':id'       => $id,
					':revision' => (int) $current['revision'],
					':snapshot' => wp_json_encode( $current ),
					':actor'    => $acting_actor
				] );
				$db->query( 'UPDATE posts SET status=:status,metadata=:metadata,revision=revision+1,updated_at=unixepoch() WHERE id=:id', [
					':status'   => $target,
					':metadata' => [] === $metadata ? null : wp_json_encode( $metadata ),
					':id'       => $id
				] );
				$this->enqueue( $id, (int) $current['revision'] + 1, (string) $current['status'], $target, $acting_actor );
				// Trash and restore change which posts resolve; cached File links elsewhere must be regenerated.
				wp_invalidate_file_link_renders( $db );
				$this->audit( $db, $acting_actor, 'trashed' === $target ? 'post_trashed' : 'post_restored', $id, [
					'title' => $current['title'],
					'from'  => $current['status'],
					'to'    => $target
				] );
				return true;
			} );
		} catch ( Authorization_Exception $error ) {
			return new WP_Error( 'unauthorized', $error->getMessage() );
		} catch ( Throwable $error ) {
			return wp_failure( 'status_failed', $error, 'The status change could not be completed.' );
		}
	}
	public function trash_post( int $id, int $acting_actor ): bool|WP_Error
	{
		$post = $this->db->get_row( 'SELECT status FROM posts WHERE id=:id', [ ':id' => $id ] );
		if ( null === $post ) {
			return new WP_Error( 'not_found', 'Content was not found.' );
		}
		if ( 'trashed' === $post['status'] ) {
			return new WP_Error( 'already_trashed', 'This content is already in the Trash.' );
		}
		return $this->set_status( $id, $acting_actor, 'trashed' );
	}
	public function restore_post( int $id, int $acting_actor ): bool|WP_Error
	{
		$post = $this->db->get_row( 'SELECT status,metadata FROM posts WHERE id=:id', [ ':id' => $id ] );
		if ( null === $post ) {
			return new WP_Error( 'not_found', 'Content was not found.' );
		}
		if ( 'trashed' !== $post['status'] ) {
			return new WP_Error( 'not_trashed', 'This content is not in the Trash.' );
		}
		$metadata = isset( $post['metadata'] ) ? json_decode( (string) $post['metadata'], true ) : null;
		$target = is_array( $metadata ) ? (string) ( $metadata['pre_trash_status'] ?? 'draft' ) : 'draft';
		if ( ! in_array( $target, [ 'draft', 'published', 'archived' ], true ) ) {
			$target = 'draft';
		}
		return $this->set_status( $id, $acting_actor, $target );
	}
	public function delete_post( int $id, int $acting_actor ): bool|WP_Error
	{
		try {
			return $this->db->transaction( function ( WP_SQLite_DB $db ) use ( $id, $acting_actor ): bool {
				$current = $db->get_row( 'SELECT * FROM posts WHERE id=:id', [ ':id' => $id ] );
				if ( null === $current ) {
					throw new Authorization_Exception( 'Content was not found.' );
				}
				if ( 'trashed' !== $current['status'] ) {
					throw new Authorization_Exception( 'Only trashed content can be permanently deleted. Move it to the Trash first.' );
				}
				$candidate = [
					'id'        => $id,
					'author_id' => (int) $current['author_id'],
					'post_type' => (string) $current['post_type'],
					'status'    => 'trashed'
				];
				$permission = $this->auth->validate_mutation_permissions( $acting_actor, $candidate, $current, $db );
				if ( is_wp_error( $permission ) ) {
					throw new Authorization_Exception( $permission->get_error_message() );
				}
				$this->audit( $db, $acting_actor, 'post_deleted', $id, [
					'title'     => $current['title'],
					'slug'      => $current['slug'],
					'post_type' => $current['post_type']
				] );
				$db->query( 'DELETE FROM post_revisions WHERE post_id=:id', [ ':id' => $id ] );
				$db->query( "DELETE FROM options WHERE option_name='homepage_id' AND option_value=:id", [ ':id' => (string) $id ] );
				$db->query( 'DELETE FROM posts WHERE id=:id', [ ':id' => $id ] );
				return true;
			} );
		} catch ( Authorization_Exception $error ) {
			return new WP_Error( 'unauthorized', $error->getMessage() );
		} catch ( Throwable $error ) {
			return wp_failure( 'delete_failed', $error, 'The content could not be deleted.' );
		}
	}
}
