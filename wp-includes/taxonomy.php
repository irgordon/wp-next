<?php
/** Categories/tags and Post assignments. All mutation checks run under the write lock. */
declare( strict_types=1 );
use WordPress\Database\WP_SQLite_DB;
function wp_can_manage_terms(): bool
{
	return current_user_can( 'edit_others_posts' );
}
function wp_get_terms( string $taxonomy ): array
{
	global $wpdb;
	return $wpdb->get_results( 'SELECT t.*,(SELECT COUNT(*) FROM post_terms pt JOIN posts p ON p.id=pt.post_id WHERE pt.term_id=t.id AND p.status=\'published\' AND p.post_type=\'post\') AS post_count FROM terms t WHERE taxonomy=:taxonomy ORDER BY name COLLATE NOCASE,id', [ ':taxonomy' => $taxonomy ] );
}
function wp_post_term_ids( int $id, WP_SQLite_DB $db ): array
{
	return array_map( 'intval', array_column( $db->get_results( 'SELECT term_id FROM post_terms WHERE post_id=:id ORDER BY term_id', [ ':id' => $id ] ), 'term_id' ) );
}
function wp_post_terms( int $id, string $taxonomy ): array
{
	global $wpdb;
	return $wpdb->get_results( 'SELECT t.* FROM terms t JOIN post_terms pt ON pt.term_id=t.id WHERE pt.post_id=:id AND t.taxonomy=:taxonomy ORDER BY t.name COLLATE NOCASE,t.id', [
		':id'       => $id,
		':taxonomy' => $taxonomy
	] );
}
/** Caller owns the content transaction and has rechecked its mutation permissions. */
function wp_assign_post_terms( int $id, array $ids, WP_SQLite_DB $db ): void
{
	if ( count( $ids ) > 100 ) {
		throw new RuntimeException( 'Select no more than 100 terms.' );
	}
	foreach ( $ids as $value ) {
		if ( ! is_scalar( $value ) ) {
			throw new RuntimeException( 'Invalid term selection.' ); 
		}
	}
	$terms = [];
	$category = false;
	foreach ( array_unique( $ids ) as $value ) {
		if ( ! preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ) {
			throw new RuntimeException( 'Invalid term selection.' );
		}
		$term = $db->get_row( 'SELECT id,taxonomy FROM terms WHERE id=:id', [ ':id' => (int) $value ] );
		if ( null === $term ) {
			throw new RuntimeException( 'A selected term no longer exists.' );
		}
		$terms[] = (int) $term['id'];
		$category = $category || 'category' === $term['taxonomy'];
	}
	if ( ! $category ) {
		$terms[] = (int) $db->get_row( "SELECT id FROM terms WHERE taxonomy='category' AND slug='uncategorized'" )['id'];
	}
	$db->query( 'DELETE FROM post_terms WHERE post_id=:id', [ ':id' => $id ] );
	foreach ( $terms as $term ) {
		$db->query( 'INSERT INTO post_terms(post_id,term_id) VALUES(:post,:term)', [
			':post' => $id,
			':term' => $term
		] );
	}
}
function wp_save_term( array $input ): int|WP_Error
{
	global $wpdb;
	try {
		return $wpdb->transaction( static function ( $db ) use ( $input ): int {
			if ( ! wp_can_manage_terms() ) {
				throw new RuntimeException( 'Term management permission required.' ); 
			}
			$id = (int) ( $input['id'] ?? 0 );
			$current = $id > 0 ? $db->get_row( 'SELECT * FROM terms WHERE id=:id', [ ':id' => $id ] ) : null;
			if ( null !== $current && isset( $input['taxonomy'] ) && $input['taxonomy'] !== $current['taxonomy'] ) {
				throw new RuntimeException( 'Taxonomy does not match.' ); 
			}
			$taxonomy = (string) ( $current['taxonomy'] ?? $input['taxonomy'] ?? '' );
			$name = trim( sanitize_text_field( $input['name'] ?? '' ) );
			$slug = wp_sanitize_slug( (string) ( $input['slug'] ?? '' ) ) ?: wp_sanitize_slug( $name );
			$expected = (int) ( $input['expected_revision'] ?? 0 );
				$parent = (int) ( $input['parent_id'] ?? 0 );
			if ( ! in_array( $taxonomy, [ 'category', 'post_tag' ], true ) || '' === $name || strlen( $name ) > 200 || '' === $slug || ( $id > 0 && ( null === $current || (int) $current['revision'] !== $expected ) ) ) {
				throw new RuntimeException( 'Invalid term or stale revision.' ); 
			}
			if ( null !== $current && 'category' === $taxonomy && 'uncategorized' === $current['slug'] && ( 'uncategorized' !== $slug || 0 !== $parent ) ) {
				throw new RuntimeException( 'Keep the default category slug and root position.' ); 
			}
			if ( 'post_tag' === $taxonomy && $parent > 0 ) {
				throw new RuntimeException( 'Tags do not have parents.' ); 
			}
			$seen = [ $id ];
			for ( $ancestor = $parent; $ancestor > 0; ) {
				if ( in_array( $ancestor, $seen, true ) ) {
					throw new RuntimeException( 'Category cycle rejected.' ); 
				}
				$seen[] = $ancestor;
				$row = $db->get_row( "SELECT parent_id FROM terms WHERE id=:id AND taxonomy='category'", [ ':id' => $ancestor ] );
				if ( null === $row || count( $seen ) > 20 ) {
					throw new RuntimeException( 'Invalid category hierarchy.' ); 
				}
				$ancestor = (int) $row['parent_id'];
			}
			$params = [
				':name'        => $name,
				':slug'        => $slug,
				':description' => sanitize_text_field( $input['description'] ?? '' ),
				':parent'      => $parent ?: null
			];
			if ( null === $current ) {
				$db->query( 'INSERT INTO terms(taxonomy,name,slug,description,parent_id) VALUES(:taxonomy,:name,:slug,:description,:parent)', $params + [ ':taxonomy' => $taxonomy ] );
				$id = (int) $db->get_row( 'SELECT last_insert_rowid() AS id' )['id'];
			} else {
				$db->query( 'UPDATE terms SET name=:name,slug=:slug,description=:description,parent_id=:parent,revision=revision+1 WHERE id=:id', $params + [ ':id' => $id ] );
				if ( (string) $current['slug'] !== $slug ) {
					// A category slug change can move /documents route ownership for its posts; cached File links must be regenerated.
					wp_invalidate_file_link_renders( $db );
				}
			}
			wp_audit( 'term_saved', $id, [ 'taxonomy' => $taxonomy ] );
			return $id;
		} );
	} catch ( Throwable $error ) {
		return wp_failure( 'term_failed', $error, 'Term could not be saved. Check its name, unique slug, parent and current revision.' ); 
	}
}
function wp_delete_term( int $id, int $revision ): true|WP_Error
{
	global $wpdb;
	try {
		return $wpdb->transaction( static function ( $db ) use ( $id, $revision ): true {
			if ( ! wp_can_manage_terms() ) {
				throw new RuntimeException( 'Term management permission required.' ); 
			}
			$term = $db->get_row( 'SELECT * FROM terms WHERE id=:id', [ ':id' => $id ] );
			if ( null === $term || (int) $term['revision'] !== $revision || ( 'category' === $term['taxonomy'] && 'uncategorized' === $term['slug'] ) ) {
				throw new RuntimeException( 'Default, stale or missing term.' ); 
			}
			$db->query( 'DELETE FROM terms WHERE id=:id', [ ':id' => $id ] );
			$db->query( "INSERT OR IGNORE INTO post_terms(post_id,term_id) SELECT p.id,t.id FROM posts p JOIN terms t ON t.taxonomy='category' AND t.slug='uncategorized' WHERE p.post_type='post' AND NOT EXISTS (SELECT 1 FROM post_terms pt JOIN terms c ON c.id=pt.term_id WHERE pt.post_id=p.id AND c.taxonomy='category')" );
			wp_audit( 'term_deleted', $id, [ 'taxonomy' => $term['taxonomy'] ] );
			return true;
		} );
	} catch ( Throwable $error ) {
		return wp_failure( 'term_delete_failed', $error, 'Term could not be deleted. The default category is protected; reload before retrying.' ); 
	}
}
function wp_category_path( array $term ): string
{
	global $wpdb;
	$parts = [ $term['slug'] ];
	$seen = [ (int) $term['id'] ];
	while ( (int) ( $term['parent_id'] ?? 0 ) > 0 ) {
		$id = (int) $term['parent_id'];
		if ( in_array( $id, $seen, true ) || count( $seen ) > 20 ) {
			break; 
		}
		$seen[] = $id;
		$term = $wpdb->get_row( "SELECT * FROM terms WHERE id=:id AND taxonomy='category'", [ ':id' => $id ] );
		if ( null === $term ) {
			break; 
		}
		array_unshift( $parts, $term['slug'] );
	}
	return implode( '/', $parts );
}
function wp_term_url( array $term ): string
{
	$path = 'category' === $term['taxonomy'] ? 'category/' . wp_category_path( $term ) : 'tag/' . $term['slug'];
	return wp_home_url( implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) ) . '/' );
}

function wp_editor_term_fields( string $type, array $selected ): void
{
	if ( 'post' !== $type ) {
		return; 
	}
	echo '<input type="hidden" name="terms_present" value="1" />';
	foreach ( [
		'category' => 'Categories',
		'post_tag' => 'Tags'
	] as $taxonomy => $label ) {
		echo '<fieldset class="term-picker"><legend>' . $label . '</legend>';
		foreach ( wp_get_terms( $taxonomy ) as $term ) {
			$checked = in_array( (int) $term['id'], array_map( 'intval', $selected ), true ) ? 'checked' : '';
			echo '<label class="inline-checkbox"><input type="checkbox" name="term_ids[]" value="' . (int) $term['id'] . '" ' . $checked . ' />' . esc_html( $term['name'] ) . '</label>';
		}
		echo '</fieldset>';
	}
	echo '<p class="description">No category selected uses Uncategorized. Editors can manage categories and tags from the sidebar.</p>';
}
