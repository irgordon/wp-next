<?php
/**
 * G2 portability: native export/import and the WordPress WXR importer.
 *
 * One engine serves both formats. Archives are parsed and validated, a
 * preflight report is computed without any content writes, and an explicit
 * confirmed run applies authors, media, terms and posts in that order.
 * Relationships are rebuilt through an explicit source-to-target ID map;
 * source database IDs are never trusted. Imported code is never executed,
 * and remote media fetching does not exist: media bytes come only from the
 * supplied archive. Run state is persisted per archive + mapping so an
 * interrupted import resumes without duplicating completed items.
 */
declare( strict_types=1 );
use WordPress\Database\WP_SQLite_DB;

const WP_PORTABILITY_FORMAT = 'wpnext-portability';
const WP_PORTABILITY_VERSION = 1;
const WP_PORTABILITY_MAX_ENTRIES = 2000;
const WP_PORTABILITY_MAX_ENTRY_BYTES = 67108864;
const WP_PORTABILITY_MAX_TOTAL_BYTES = 536870912;
const WP_PORTABILITY_MAX_MANIFEST_BYTES = 8388608;
const WP_PORTABILITY_MAX_ITEMS = 2000;

require_once __DIR__ . '/portability-references.php';
require_once __DIR__ . '/portability-recovery.php';
require_once __DIR__ . '/portability-export.php';

/** Private scratch directory for archives being imported or exported. */
function wp_portability_workdir(): string
{
	$directory = WP_VAR_DIR . '/portability';
	if ( ! is_dir( $directory ) ) {
		mkdir( $directory, 0700, true );
	}
	return $directory;
}

/** An archive entry name is usable only as a plain relative path. */
function wp_portability_entry_safe( string $name ): bool
{
	if ( '' === $name || str_contains( $name, '\\' ) || str_contains( $name, "\0" ) || str_starts_with( $name, '/' ) || preg_match( '/^[A-Za-z]:/', $name ) ) {
		return false;
	}
	foreach ( explode( '/', $name ) as $segment ) {
		if ( '..' === $segment || '' === $segment ) {
			return false;
		}
	}
	return true;
}

/**
 * Opens a ZIP archive under the hostile-input caps and extracts its entries
 * to a private scratch directory. Returns the entry map (name => path).
 */
function wp_portability_extract_zip( string $path ): array|WP_Error
{
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'zip_unavailable', 'ZIP archive support (ext-zip) is not available on this server.' );
	}
	if ( ! is_file( $path ) || filesize( $path ) < 1 ) {
		return new WP_Error( 'invalid_archive', 'The supplied file is not a readable archive.' );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
		return new WP_Error( 'invalid_archive', 'The supplied file is not a ZIP archive.' );
	}
	try {
		if ( $zip->numFiles > WP_PORTABILITY_MAX_ENTRIES ) {
			return new WP_Error( 'invalid_archive', 'The archive contains too many entries.' );
		}
		$total = 0;
		$names = [];
		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$stat = $zip->statIndex( $index );
			if ( ! is_array( $stat ) ) {
				return new WP_Error( 'invalid_archive', 'The archive contains an unreadable entry.' );
			}
			$name = $stat['name'];
			if ( str_ends_with( $name, '/' ) ) {
				continue;
			}
			if ( ! wp_portability_entry_safe( $name ) ) {
				return new WP_Error( 'invalid_archive', 'The archive contains an unsafe entry path: ' . $name );
			}
			if ( isset( $names[ $name ] ) ) {
				return new WP_Error( 'invalid_archive', 'The archive contains a duplicate entry: ' . $name );
			}
			$size = (int) $stat['size'];
			if ( $size > WP_PORTABILITY_MAX_ENTRY_BYTES ) {
				return new WP_Error( 'invalid_archive', 'An archive entry exceeds the size limit: ' . $name );
			}
			$total += $size;
			if ( $total > WP_PORTABILITY_MAX_TOTAL_BYTES ) {
				return new WP_Error( 'invalid_archive', 'The archive exceeds the total size limit.' );
			}
			$names[ $name ] = $size;
		}
		$directory = wp_portability_workdir() . '/extract-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory, 0700, true );
		$entries = [];
		foreach ( $names as $name => $size ) {
			$bytes = $zip->getFromName( $name );
			if ( ! is_string( $bytes ) || strlen( $bytes ) !== $size ) {
				wp_portability_remove_tree( $directory );
				return new WP_Error( 'invalid_archive', 'An archive entry could not be read intact: ' . $name );
			}
			$target = $directory . '/' . hash( 'sha256', $name ) . '.bin';
			file_put_contents( $target, $bytes );
			chmod( $target, 0600 );
			$entries[ $name ] = $target;
		}
		return [
			'directory' => $directory,
			'entries'   => $entries
		];
	} finally {
		$zip->close();
	}
}

/** Removes a portability scratch directory created by extraction. */
function wp_portability_remove_tree( string $directory ): void
{
	if ( ! is_dir( $directory ) || ! str_contains( $directory, '/portability/' ) ) {
		return;
	}
	foreach ( glob( $directory . '/*' ) ?: [] as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
	rmdir( $directory );
}

/** Scalar-or-default reader for manifest and state records. */
function wp_portability_string( mixed $value, string $default = '' ): string
{
	return is_string( $value ) ? $value : ( is_int( $value ) || is_float( $value ) ? (string) $value : $default );
}

/** Integer-or-default reader for manifest and state records. */
function wp_portability_int( mixed $value, int $default = 0 ): int
{
	return is_int( $value ) ? $value : ( is_string( $value ) && preg_match( '/^-?[0-9]+$/D', $value ) ? (int) $value : $default );
}

/**
 * Parses and validates a native export archive into the shared item model.
 * Media entries whose bytes do not match the manifest hash are flagged on
 * the item instead of aborting the whole import.
 */
function wp_portability_parse_native( string $path ): array|WP_Error
{
	$extracted = wp_portability_extract_zip( $path );
	if ( is_wp_error( $extracted ) ) {
		return $extracted;
	}
	$entries = $extracted['entries'];
	$fail = static function ( string $message ) use ( $extracted ): WP_Error {
		wp_portability_remove_tree( $extracted['directory'] );
		return new WP_Error( 'invalid_manifest', $message );
	};
	if ( ! isset( $entries['manifest.json'] ) ) {
		return $fail( 'The archive has no manifest.json; it is not a WordPress Next export.' );
	}
	if ( filesize( $entries['manifest.json'] ) > WP_PORTABILITY_MAX_MANIFEST_BYTES ) {
		return $fail( 'The manifest exceeds the size limit.' );
	}
	$manifest = json_decode( (string) file_get_contents( $entries['manifest.json'] ), true );
	if ( ! is_array( $manifest ) || WP_PORTABILITY_FORMAT !== ( $manifest['format'] ?? '' ) ) {
		return $fail( 'The manifest does not identify a WordPress Next export.' );
	}
	if ( WP_PORTABILITY_VERSION !== (int) ( $manifest['version'] ?? 0 ) ) {
		return $fail( 'This export format version is not supported by this site.' );
	}
	foreach ( [ 'authors', 'terms', 'media', 'posts' ] as $section ) {
		if ( ! isset( $manifest[ $section ] ) || ! is_array( $manifest[ $section ] ) || count( $manifest[ $section ] ) > WP_PORTABILITY_MAX_ITEMS ) {
			return $fail( 'The manifest section "' . $section . '" is missing or unreasonably large.' );
		}
		if ( isset( $manifest['counts'][ $section ] ) && count( $manifest[ $section ] ) !== (int) $manifest['counts'][ $section ] ) {
			return $fail( 'The manifest counts do not match its contents.' );
		}
	}
	$model = [
		'authors'     => [],
		'terms'       => [],
		'media'       => [],
		'posts'       => [],
		'unsupported' => []
	];
	foreach ( $manifest['authors'] as $author ) {
		if ( ! is_array( $author ) ) {
			return $fail( 'An author record is malformed.' );
		}
		$model['authors'][] = [
			'source_id'    => wp_portability_int( $author['source_id'] ?? null ),
			'username'     => wp_portability_string( $author['username'] ?? null ),
			'email'        => wp_portability_string( $author['email'] ?? null ),
			'display_name' => wp_portability_string( $author['display_name'] ?? null ),
			'first_name'   => wp_portability_string( $author['first_name'] ?? null ),
			'last_name'    => wp_portability_string( $author['last_name'] ?? null ),
			'biography'    => wp_portability_string( $author['biography'] ?? null ),
			'website'      => wp_portability_string( $author['website'] ?? null )
		];
	}
	foreach ( $manifest['terms'] as $term ) {
		if ( ! is_array( $term ) ) {
			return $fail( 'A term record is malformed.' );
		}
		$taxonomy = wp_portability_string( $term['taxonomy'] ?? null );
		if ( ! in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) {
			$model['unsupported'][] = 'Term "' . wp_portability_string( $term['name'] ?? null ) . '" uses the unsupported taxonomy "' . $taxonomy . '".';
			continue;
		}
		$model['terms'][] = [
			'source_id'        => wp_portability_int( $term['source_id'] ?? null ),
			'taxonomy'         => $taxonomy,
			'slug'             => wp_portability_string( $term['slug'] ?? null ),
			'name'             => wp_portability_string( $term['name'] ?? null ),
			'description'      => wp_portability_string( $term['description'] ?? null ),
			'parent_source_id' => wp_portability_int( $term['parent_source_id'] ?? null )
		];
	}
	foreach ( $manifest['media'] as $media ) {
		if ( ! is_array( $media ) ) {
			return $fail( 'A media record is malformed.' );
		}
		$kind = wp_portability_string( $media['kind'] ?? null );
		$format = wp_portability_string( $media['format'] ?? null );
		$hash = wp_portability_string( $media['sha256'] ?? null );
		if ( ! in_array( $kind, [ 'image', 'document' ], true ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			$model['unsupported'][] = 'A media record has an unsupported kind or digest and was not imported.';
			continue;
		}
		$entry = 'files/' . $hash . '.' . $format;
		$file = $entries[ $entry ] ?? null;
		$bytes_error = '';
		if ( null === $file ) {
			$bytes_error = 'The media bytes are missing from the archive.';
		} elseif ( ! hash_equals( $hash, (string) hash_file( 'sha256', $file ) ) ) {
			$bytes_error = 'The media bytes do not match the manifest digest.';
			$file = null;
		}
		$model['media'][] = [
			'source_id'     => wp_portability_int( $media['source_id'] ?? null ),
			'source_url'    => wp_portability_native_source_url( $media, wp_portability_string( $manifest['site']['home'] ?? null ) ),
			'kind'          => $kind,
			'format'        => $format,
			'visibility'    => 'private' === ( $media['visibility'] ?? '' ) ? 'private' : 'public',
			'original_name' => wp_portability_string( $media['original_name'] ?? null, 'media.' . $format ),
			'alt_text'      => wp_portability_string( $media['alt_text'] ?? null ),
			'caption'       => wp_portability_string( $media['caption'] ?? null ),
			'sha256'        => $hash,
			'file'          => $file,
			'filename'      => basename( wp_portability_string( $media['original_name'] ?? null, 'media.' . $format ) ),
			'bytes_error'   => $bytes_error
		];
	}
	foreach ( $manifest['posts'] as $post ) {
		if ( ! is_array( $post ) ) {
			return $fail( 'A post record is malformed.' );
		}
		$type = wp_portability_string( $post['type'] ?? null );
		$status = wp_portability_string( $post['status'] ?? null );
		if ( ! in_array( $type, [ 'post', 'page' ], true ) || ! in_array( $status, [ 'draft', 'published', 'archived' ], true ) ) {
			$model['unsupported'][] = 'Content "' . wp_portability_string( $post['title'] ?? null ) . '" has an unsupported type or status and was not imported.';
			continue;
		}
		$term_ids = [];
		foreach ( is_array( $post['term_source_ids'] ?? null ) ? $post['term_source_ids'] : [] as $term_id ) {
			$term_ids[] = wp_portability_int( $term_id );
		}
		$model['posts'][] = [
			'source_id'          => wp_portability_int( $post['source_id'] ?? null ),
			'type'               => $type,
			'status'             => $status,
			'author_source_id'   => wp_portability_int( $post['author_source_id'] ?? null ),
			'slug'               => wp_portability_string( $post['slug'] ?? null ),
			'title'              => wp_portability_string( $post['title'] ?? null ),
			'content_raw'        => wp_portability_string( $post['content_raw'] ?? null ),
			'excerpt'            => wp_portability_string( $post['excerpt'] ?? null ),
			'metadata'           => $post['metadata'] ?? null,
			'featured_source_id' => wp_portability_int( $post['featured_source_id'] ?? null ),
			'term_source_ids'    => $term_ids,
			'published_at'       => wp_portability_int( $post['published_at'] ?? null ),
			'created_at'         => wp_portability_int( $post['created_at'] ?? null ),
			'updated_at'         => wp_portability_int( $post['updated_at'] ?? null ),
			'notes'              => []
		];
	}
	return [
		'format'    => 'native',
		'model'     => $model,
		'directory' => $extracted['directory'],
		'sha256'    => (string) hash_file( 'sha256', $path )
	];
}

/**
 * Builds a native export archive for the current site and returns its path.
 * Media masters only: variants are derived data and regenerate on import.
 * Private document bytes are included; this is an administrator,
 * backup-class operation and the admin screen says so.
 */
function wp_portability_build_export(): array|WP_Error
{
	global $wpdb;
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'zip_unavailable', 'ZIP archive support (ext-zip) is not available on this server.' );
	}
	if ( ! current_user_can( 'administrator' ) ) {
		return new WP_Error( 'unauthorized', 'Only an administrator can export the site.' );
	}
	$limit_error = wp_portability_export_count_error();
	if ( null !== $limit_error ) {
		return $limit_error;
	}
	$authors = [];
	foreach ( $wpdb->get_results( 'SELECT * FROM users ORDER BY id' ) as $user ) {
		$authors[] = [
			'source_id'    => (int) $user['id'],
			'username'     => (string) $user['username'],
			'email'        => (string) $user['email'],
			'display_name' => (string) ( $user['display_name'] ?? '' ),
			'first_name'   => (string) ( $user['first_name'] ?? '' ),
			'last_name'    => (string) ( $user['last_name'] ?? '' ),
			'biography'    => (string) ( $user['biography'] ?? '' ),
			'website'      => (string) ( $user['website'] ?? '' )
		];
	}
	$terms = [];
	foreach ( $wpdb->get_results( 'SELECT * FROM terms ORDER BY id' ) as $term ) {
		$terms[] = [
			'source_id'        => (int) $term['id'],
			'taxonomy'         => (string) $term['taxonomy'],
			'slug'             => (string) $term['slug'],
			'name'             => (string) $term['name'],
			'description'      => (string) $term['description'],
			'parent_source_id' => (int) ( $term['parent_id'] ?? 0 )
		];
	}
	$media = [];
	$files = [];
	foreach ( $wpdb->get_results( "SELECT * FROM media WHERE state='ready' AND variant='' ORDER BY id" ) as $asset ) {
		try {
			$blob = wp_asset_blob_path( $asset );
		} catch ( Throwable ) {
			return new WP_Error( 'export_failed', 'Media #' . (int) $asset['id'] . ' has no verifiable stored path; repair storage before exporting.' );
		}
		if ( ! is_file( $blob ) || ! hash_equals( (string) $asset['sha256'], (string) hash_file( 'sha256', $blob ) ) ) {
			return new WP_Error( 'export_failed', 'Media #' . (int) $asset['id'] . ' is missing or corrupt in storage; repair it before exporting.' );
		}
		$entry = 'files/' . $asset['sha256'] . '.' . $asset['format'];
		$files[ $entry ] = $blob;
		$media[] = [
			'source_id'     => (int) $asset['id'],
			'source_url'    => wp_portability_asset_url( $asset ),
			'kind'          => (string) $asset['kind'],
			'format'        => (string) $asset['format'],
			'visibility'    => (string) $asset['visibility'],
			'original_name' => (string) $asset['original_name'],
			'alt_text'      => (string) $asset['alt_text'],
			'caption'       => (string) $asset['caption'],
			'sha256'        => (string) $asset['sha256'],
			'path'          => $entry
		];
	}
	$posts = [];
	foreach ( $wpdb->get_results( "SELECT * FROM posts WHERE status<>'trashed' ORDER BY id" ) as $post ) {
		$metadata = isset( $post['metadata'] ) ? json_decode( (string) $post['metadata'], true ) : null;
		if ( is_array( $metadata ) ) {
			unset( $metadata['render_epoch'], $metadata[ WP_PORTABILITY_IMPORT_RECEIPT ] );
		}
		$posts[] = [
			'source_id'          => (int) $post['id'],
			'type'               => (string) $post['post_type'],
			'status'             => (string) $post['status'],
			'author_source_id'   => (int) $post['author_id'],
			'slug'               => (string) $post['slug'],
			'title'              => (string) $post['title'],
			'content_raw'        => (string) $post['content_raw'],
			'excerpt'            => (string) $post['excerpt'],
			'metadata'           => $metadata,
			'featured_source_id' => (int) ( $post['featured_media_id'] ?? 0 ),
			'term_source_ids'    => wp_post_term_ids( (int) $post['id'], $wpdb ),
			'published_at'       => (int) ( $post['published_at'] ?? 0 ),
			'created_at'         => (int) $post['created_at'],
			'updated_at'         => (int) $post['updated_at']
		];
	}
	$manifest = [
		'format'      => WP_PORTABILITY_FORMAT,
		'version'     => WP_PORTABILITY_VERSION,
		'generator'   => 'WordPress Next',
		'exported_at' => gmdate( DATE_ATOM ),
		'site'        => [
			'name' => (string) get_option( 'site_title', '' ),
			'home' => wp_home_url()
		],
		'counts'      => [
			'authors' => count( $authors ),
			'terms'   => count( $terms ),
			'media'   => count( $media ),
			'posts'   => count( $posts )
		],
		'authors'     => $authors,
		'terms'       => $terms,
		'media'       => $media,
		'posts'       => $posts
	];
	$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT );
	$limit_error = wp_portability_export_size_error( $manifest_json, $files );
	if ( null !== $limit_error ) {
		return $limit_error;
	}
	try {
		$path = wp_portability_write_archive( $manifest_json, $files );
	} catch ( Throwable $error ) {
		return wp_failure( 'export_failed', $error, 'The export archive could not be completed.' );
	}
	return [
		'path'     => $path,
		'filename' => 'wordpress-next-export-' . gmdate( 'Ymd-His' ) . '.zip',
		'manifest' => $manifest
	];
}

/** Text of the first child element with the given namespace and local name. */
function wp_portability_child_text( SimpleXMLElement $node, string $namespace, string $local ): string
{
	$children = $node->children( $namespace );
	return isset( $children->$local ) ? trim( (string) $children->$local ) : '';
}

/**
 * Parses a WordPress WXR export into the shared item model. Attachment bytes
 * are matched by file name from a separately supplied media ZIP; nothing is
 * fetched from the network. Content is kept as HTML and sanitized by the
 * normal legacy-content path when the post is saved.
 */
function wp_portability_parse_wxr( string $xml_path, ?string $media_zip_path ): array|WP_Error
{
	$bytes = is_file( $xml_path ) ? file_get_contents( $xml_path ) : false;
	if ( ! is_string( $bytes ) || '' === trim( $bytes ) || strlen( $bytes ) > 33554432 ) {
		return new WP_Error( 'invalid_wxr', 'The supplied file is not a readable WXR export.' );
	}
	if ( preg_match( '/<!\s*(ENTITY|DOCTYPE[^>]*\[)/i', $bytes ) ) {
		return new WP_Error( 'invalid_wxr', 'The WXR file declares XML entities and was refused.' );
	}
	$media_files = [];
	$directory = '';
	if ( null !== $media_zip_path ) {
		$extracted = wp_portability_extract_zip( $media_zip_path );
		if ( is_wp_error( $extracted ) ) {
			return $extracted;
		}
		$directory = $extracted['directory'];
		foreach ( $extracted['entries'] as $name => $file ) {
			$base = basename( $name );
			if ( ! isset( $media_files[ $base ] ) ) {
				$media_files[ $base ] = $file;
			}
		}
	}
	$fail = static function ( string $message ) use ( $directory ): WP_Error {
		if ( '' !== $directory ) {
			wp_portability_remove_tree( $directory );
		}
		return new WP_Error( 'invalid_wxr', $message );
	};
	$previous = libxml_use_internal_errors( true );
	$xml = simplexml_load_string( $bytes, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	if ( ! $xml instanceof SimpleXMLElement || ! isset( $xml->channel ) ) {
		return $fail( 'The supplied file is not valid WXR XML.' );
	}
	$wp = 'http://wordpress.org/export/1.2/';
	$content_ns = 'http://purl.org/rss/1.0/modules/content/';
	$excerpt_ns = 'http://wordpress.org/export/1.2/excerpt/';
	$dc = 'http://purl.org/dc/elements/1.1/';
	$channel = $xml->channel;
	$model = [
		'authors'     => [],
		'terms'       => [],
		'media'       => [],
		'posts'       => [],
		'unsupported' => []
	];
	$author_ids = [];
	foreach ( $channel->children( $wp )->author as $author ) {
		$id = (int) wp_portability_child_text( $author, $wp, 'author_id' );
		$login = wp_portability_child_text( $author, $wp, 'author_login' );
		if ( $id <= 0 || '' === $login ) {
			continue;
		}
		$author_ids[ $login ] = $id;
		$model['authors'][] = [
			'source_id'    => $id,
			'username'     => $login,
			'email'        => wp_portability_child_text( $author, $wp, 'author_email' ),
			'display_name' => wp_portability_child_text( $author, $wp, 'author_display_name' ),
			'first_name'   => wp_portability_child_text( $author, $wp, 'author_first_name' ),
			'last_name'    => wp_portability_child_text( $author, $wp, 'author_last_name' ),
			'biography'    => '',
			'website'      => ''
		];
	}
	$term_ids = [];
	$term_slugs = [];
	foreach ( $channel->children( $wp )->category as $category ) {
		$id = (int) wp_portability_child_text( $category, $wp, 'term_id' );
		$slug = wp_portability_child_text( $category, $wp, 'category_nicename' );
		if ( $id <= 0 || '' === $slug ) {
			continue;
		}
		$term_ids[ 'category:' . $slug ] = $id;
		$term_slugs[ $id ] = [
			'taxonomy' => 'category',
			'slug'     => $slug,
			'parent'   => wp_portability_child_text( $category, $wp, 'category_parent' )
		];
		$model['terms'][] = [
			'source_id'        => $id,
			'taxonomy'         => 'category',
			'slug'             => $slug,
			'name'             => wp_portability_child_text( $category, $wp, 'cat_name' ),
			'description'      => wp_portability_child_text( $category, $wp, 'category_description' ),
			'parent_source_id' => 0
		];
	}
	foreach ( $channel->children( $wp )->tag as $tag ) {
		$id = (int) wp_portability_child_text( $tag, $wp, 'term_id' );
		$slug = wp_portability_child_text( $tag, $wp, 'tag_slug' );
		if ( $id <= 0 || '' === $slug ) {
			continue;
		}
		$term_ids[ 'post_tag:' . $slug ] = $id;
		$model['terms'][] = [
			'source_id'        => $id,
			'taxonomy'         => 'post_tag',
			'slug'             => $slug,
			'name'             => wp_portability_child_text( $tag, $wp, 'tag_name' ),
			'description'      => '',
			'parent_source_id' => 0
		];
	}
	foreach ( $model['terms'] as $index => $term ) {
		$parent_slug = $term_slugs[ $term['source_id'] ]['parent'] ?? '';
		if ( '' !== $parent_slug && isset( $term_ids[ 'category:' . $parent_slug ] ) ) {
			$model['terms'][ $index ]['parent_source_id'] = $term_ids[ 'category:' . $parent_slug ];
		}
	}
	$synthetic = 900000;
	$items = [];
	foreach ( $channel->item as $item ) {
		$items[] = $item;
		if ( count( $items ) > WP_PORTABILITY_MAX_ITEMS * 2 ) {
			return $fail( 'The WXR file contains too many items.' );
		}
	}
	$attachments = [];
	foreach ( $items as $item ) {
		$type = wp_portability_child_text( $item, $wp, 'post_type' );
		if ( 'attachment' !== $type ) {
			continue;
		}
		$id = (int) wp_portability_child_text( $item, $wp, 'post_id' );
		$url = wp_portability_child_text( $item, $wp, 'attachment_url' );
		$filename = basename( (string) parse_url( $url, PHP_URL_PATH ) );
		$format = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$kind = in_array( $format, [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ], true ) ? 'image' : ( isset( wp_document_formats()[ $format ] ) ? 'document' : '' );
		if ( $id <= 0 || '' === $kind ) {
			$model['unsupported'][] = 'Attachment "' . (string) $item->title . '" has an unsupported file type and was not imported.';
			continue;
		}
		$alt = '';
		foreach ( $item->children( $wp )->postmeta as $meta ) {
			if ( '_wp_attachment_image_alt' === wp_portability_child_text( $meta, $wp, 'meta_key' ) ) {
				$alt = wp_portability_child_text( $meta, $wp, 'meta_value' );
			}
		}
		$file = $media_files[ $filename ] ?? null;
		$attachments[ $id ] = count( $model['media'] );
		$model['media'][] = [
			'source_id'     => $id,
			'source_url'    => $url,
			'kind'          => $kind,
			'format'        => 'jpg' === $format || 'jpeg' === $format ? 'jpg' : $format,
			'visibility'    => 'public',
			'original_name' => $filename,
			'alt_text'      => $alt,
			'caption'       => trim( wp_portability_child_text( $item, $excerpt_ns, 'encoded' ) ),
			'sha256'        => null !== $file ? (string) hash_file( 'sha256', $file ) : '',
			'file'          => $file,
			'filename'      => $filename,
			'bytes_error'   => null === $file ? 'No matching file was supplied in the media archive.' : ''
		];
	}
	$core_blocks = [ 'core/paragraph', 'core/heading', 'core/list', 'core/quote', 'core/code', 'core/image', 'core/file', 'core/columns', 'core/separator', 'core/freeform', 'core/html', 'core/gallery', 'core/audio', 'core/video', 'core/embed', 'core/table', 'core/verse', 'core/preformatted', 'core/pullquote', 'core/spacer', 'core/group', 'core/cover', 'core/media-text', 'core/buttons', 'core/button', 'core/more', 'core/nextpage', 'core/shortcode' ];
	foreach ( $items as $item ) {
		$type = wp_portability_child_text( $item, $wp, 'post_type' );
		$title = (string) $item->title;
		if ( 'attachment' === $type ) {
			continue;
		}
		if ( ! in_array( $type, [ 'post', 'page' ], true ) ) {
			$model['unsupported'][] = 'Item "' . $title . '" uses the unsupported post type "' . $type . '" and was not imported.';
			continue;
		}
		$status_raw = wp_portability_child_text( $item, $wp, 'status' );
		$notes = [];
		if ( 'trash' === $status_raw ) {
			continue;
		}
		$status = match ( $status_raw ) {
			'publish' => 'published',
			'draft' => 'draft',
			default => 'draft'
		};
		if ( 'published' !== $status && 'draft' !== $status_raw ) {
			$notes[] = 'Source status "' . $status_raw . '" has no equivalent here and was imported as a draft.';
		}
		$html = wp_portability_child_text( $item, $content_ns, 'encoded' );
		if ( preg_match( '/\[[a-zA-Z][a-zA-Z0-9_-]*[\s\]]/', $html ) ) {
			$notes[] = 'Content contains shortcode-style markup; it was imported as inert text and never executed.';
		}
		if ( preg_match_all( '/<!--\s*wp:([a-z0-9-]+\/[a-z0-9-]+)/', $html, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $block_name ) {
				if ( ! in_array( $block_name, $core_blocks, true ) ) {
					$notes[] = 'Content uses the unsupported block "' . $block_name . '"; its markup was kept only as sanitized HTML.';
				}
			}
		}
		$post_terms = [];
		foreach ( $item->category as $category ) {
			$attributes = $category->attributes();
			$domain = (string) ( $attributes['domain'] ?? '' );
			$nicename = (string) ( $attributes['nicename'] ?? '' );
			$taxonomy = 'category' === $domain ? 'category' : ( 'post_tag' === $domain ? 'post_tag' : '' );
			if ( '' === $taxonomy || '' === $nicename ) {
				if ( '' !== $domain ) {
					$notes[] = 'A term from the unsupported taxonomy "' . $domain . '" was not imported.';
				}
				continue;
			}
			$key = $taxonomy . ':' . $nicename;
			if ( ! isset( $term_ids[ $key ] ) ) {
				$term_ids[ $key ] = ++$synthetic;
				$model['terms'][] = [
					'source_id'        => $synthetic,
					'taxonomy'         => $taxonomy,
					'slug'             => $nicename,
					'name'             => trim( (string) $category ),
					'description'      => '',
					'parent_source_id' => 0
				];
			}
			$post_terms[] = $term_ids[ $key ];
		}
		$featured = 0;
		$metadata = [];
		foreach ( $item->children( $wp )->postmeta as $meta ) {
			$key = wp_portability_child_text( $meta, $wp, 'meta_key' );
			$value = wp_portability_child_text( $meta, $wp, 'meta_value' );
			if ( '_thumbnail_id' === $key ) {
				$featured = (int) $value;
			} elseif ( str_starts_with( $key, '_' ) || str_contains( $key, ':' ) ) {
				$notes[] = 'Post meta "' . $key . '" was not imported.';
			} elseif ( '' !== $key ) {
				$metadata[ $key ] = $value;
			}
		}
		$date = strtotime( wp_portability_child_text( $item, $wp, 'post_date_gmt' ) . ' UTC' );
		$creator = wp_portability_child_text( $item, $dc, 'creator' );
		$model['posts'][] = [
			'source_id'          => (int) wp_portability_child_text( $item, $wp, 'post_id' ),
			'type'               => $type,
			'status'             => $status,
			'author_source_id'   => $author_ids[ $creator ] ?? 0,
			'slug'               => wp_portability_child_text( $item, $wp, 'post_name' ),
			'title'              => $title,
			'content_raw'        => $html,
			'excerpt'            => wp_portability_child_text( $item, $excerpt_ns, 'encoded' ),
			'metadata'           => [] === $metadata ? null : $metadata,
			'featured_source_id' => $featured,
			'term_source_ids'    => $post_terms,
			'published_at'       => false !== $date ? (int) $date : 0,
			'created_at'         => false !== $date ? (int) $date : 0,
			'updated_at'         => 0,
			'notes'              => $notes
		];
	}
	return [
		'format'    => 'wxr',
		'model'     => $model,
		'directory' => $directory,
		'sha256'    => hash( 'sha256', (string) hash_file( 'sha256', $xml_path ) . '|' . ( null !== $media_zip_path ? (string) hash_file( 'sha256', $media_zip_path ) : '' ) )
	];
}

/** Finds an existing user for an author record: explicit map, then email. */
function wp_portability_author_target( array $author, array $mapping ): ?array
{
	global $wpdb;
	$explicit = (int) ( $mapping[ (string) $author['source_id'] ] ?? $mapping[ $author['source_id'] ] ?? 0 );
	if ( $explicit > 0 ) {
		$user = $wpdb->get_row( 'SELECT id,username FROM users WHERE id=:id', [ ':id' => $explicit ] );
		if ( null !== $user ) {
			return $user;
		}
	}
	$email = trim( (string) $author['email'] );
	if ( '' !== $email ) {
		$user = $wpdb->get_row( 'SELECT id,username FROM users WHERE LOWER(email)=LOWER(:email)', [ ':email' => $email ] );
		if ( null !== $user ) {
			return $user;
		}
	}
	return null;
}

/** A username that is valid here and free, derived from the source record. */
function wp_portability_free_username( string $preferred, string $email, array $reserved = [] ): string
{
	global $wpdb;
	$candidate = preg_match( '/^[\pL\pN_.-]{2,60}$/u', $preferred ) ? $preferred : '';
	if ( '' === $candidate ) {
		$base = (string) preg_replace( '/[^\pL\pN_.-]+/u', '-', '' !== $email ? (string) strstr( $email, '@', true ) : $preferred );
		$candidate = trim( $base, '-.' ) ?: 'imported-user';
		if ( strlen( $candidate ) < 2 ) {
			$candidate .= 'user';
		}
		$candidate = substr( $candidate, 0, 60 );
	}
	$base = $candidate;
	$suffix = 2;
	while ( in_array( strtolower( $candidate ), $reserved, true ) || null !== $wpdb->get_row( 'SELECT id FROM users WHERE username=:name COLLATE NOCASE', [ ':name' => $candidate ] ) ) {
		$candidate = substr( $base, 0, 55 ) . '-' . $suffix++;
	}
	return $candidate;
}

/** Normalized content used for exact-duplicate detection, or null if invalid. */
function wp_portability_normalized( string $content ): ?string
{
	$normalized = wp_normalize_content( $content );
	return is_wp_error( $normalized ) ? null : $normalized;
}

/**
 * Computes the import plan without writing anything: author mapping plan,
 * media availability and quota projection, term reuse/creation, duplicate
 * and slug-collision outcomes, and the unsupported/missing lists.
 */
function wp_portability_preflight( array $model, array $mapping = [] ): array
{
	global $wpdb;
	$plan = [
		'authors'     => [],
		'media'       => [],
		'terms'       => [],
		'posts'       => [],
		'unsupported' => $model['unsupported'],
		'quota'       => [
			'limit'    => (int) get_option( 'assets_quota_bytes', 0 ),
			'used'     => (int) $wpdb->get_row( 'SELECT COALESCE(SUM(size),0) AS total FROM media' )['total'],
			'reserved' => 0
		]
	];
	$reserved_names = [];
	foreach ( $model['authors'] as $author ) {
		$target = wp_portability_author_target( $author, $mapping );
		if ( null !== $target ) {
			$plan['authors'][ $author['source_id'] ] = [
				'action'    => 'existing',
				'target_id' => (int) $target['id'],
				'note'      => 'Maps to existing account "' . $target['username'] . '".'
			];
		} else {
			$username = wp_portability_free_username( (string) $author['username'], (string) $author['email'], $reserved_names );
			$reserved_names[] = strtolower( $username );
			$plan['authors'][ $author['source_id'] ] = [
				'action'    => 'create',
				'target_id' => 0,
				'username'  => $username,
				'note'      => 'A new ordinary account "' . $username . '" will be created with an unusable password.'
			];
		}
	}
	$seen_hashes = [];
	foreach ( $model['media'] as $media ) {
		$key = $media['source_id'];
		if ( null === $media['file'] ) {
			$plan['media'][ $key ] = [
				'action' => 'missing',
				'note'   => $media['bytes_error']
			];
			continue;
		}
		if ( 'image' === $media['kind'] ) {
			$existing = $wpdb->get_row( "SELECT id FROM media WHERE sha256=:hash AND kind='image' AND state='ready' AND variant=''", [ ':hash' => $media['sha256'] ] );
		} else {
			$existing = $wpdb->get_row( "SELECT id FROM media WHERE sha256=:hash AND kind='document' AND format=:format AND visibility=:visibility AND state='ready'", [
				':hash'       => $media['sha256'],
				':format'     => $media['format'],
				':visibility' => $media['visibility']
			] );
		}
		$size = (int) filesize( $media['file'] );
		if ( null !== $existing || isset( $seen_hashes[ $media['sha256'] . ':' . $media['kind'] ] ) ) {
			$plan['media'][ $key ] = [
				'action'    => 'reuse',
				'target_id' => null !== $existing ? (int) $existing['id'] : 0,
				'note'      => 'Identical bytes are already in the library.'
			];
			continue;
		}
		$seen_hashes[ $media['sha256'] . ':' . $media['kind'] ] = true;
		$limit = $plan['quota']['limit'];
		if ( $limit > 0 && $plan['quota']['used'] + $plan['quota']['reserved'] + $size > $limit ) {
			$plan['media'][ $key ] = [
				'action' => 'quota',
				'note'   => 'Importing this file would exceed the asset storage quota.'
			];
			continue;
		}
		$plan['quota']['reserved'] += $size;
		$plan['media'][ $key ] = [
			'action' => 'import',
			'note'   => 'Bytes are present and will pass the normal intake validators.'
		];
	}
	foreach ( $model['terms'] as $term ) {
		$existing = $wpdb->get_row( 'SELECT id FROM terms WHERE taxonomy=:taxonomy AND slug=:slug', [
			':taxonomy' => $term['taxonomy'],
			':slug'     => $term['slug']
		] );
		$plan['terms'][ $term['source_id'] ] = null !== $existing ? [
			'action'    => 'reuse',
			'target_id' => (int) $existing['id'],
			'note'      => 'A term with this slug already exists.'
		] : [
			'action' => 'create',
			'note'   => 'Will be created.'
		];
	}
	$planned_slugs = [];
	foreach ( $model['posts'] as $post ) {
		$slug = wp_sanitize_slug( '' !== $post['slug'] ? $post['slug'] : $post['title'] );
		$duplicate = null;
		if ( '' !== $slug ) {
			$row = $wpdb->get_row( 'SELECT id,content_raw FROM posts WHERE post_type=:type AND slug=:slug AND title=:title', [
				':type'  => $post['type'],
				':slug'  => $slug,
				':title' => $post['title']
			] );
			if ( null !== $row && wp_portability_normalized( $post['content_raw'] ) === $row['content_raw'] ) {
				$duplicate = (int) $row['id'];
			}
		}
		if ( null !== $duplicate ) {
			$plan['posts'][ $post['source_id'] ] = [
				'action'    => 'skip-duplicate',
				'target_id' => $duplicate,
				'slug'      => $slug,
				'note'      => 'An identical item (type, slug, title and content) already exists.'
			];
			continue;
		}
		$final = $slug;
		if ( '' !== $slug && ( isset( $planned_slugs[ $slug ] ) || null !== $wpdb->get_row( 'SELECT id FROM posts WHERE slug=:slug', [ ':slug' => $slug ] ) ) ) {
			$final = wp_portability_collision_slug( $slug, $planned_slugs );
		}
		$planned_slugs[ $final ] = true;
		$plan['posts'][ $post['source_id'] ] = [
			'action' => 'import',
			'slug'   => $final,
			'note'   => $final !== $slug ? 'Slug collides and will be suffixed to "' . $final . '".' : 'Will be imported.'
		];
	}
	return $plan;
}

/** First free collision slug: base-imported, then base-imported-2, -3, ... */
function wp_portability_collision_slug( string $base, array $planned = [] ): string
{
	global $wpdb;
	$candidates = [ $base . '-imported' ];
	for ( $index = 2; $index < 100; ++$index ) {
		$candidates[] = $base . '-imported-' . $index;
	}
	foreach ( $candidates as $candidate ) {
		if ( isset( $planned[ $candidate ] ) ) {
			continue;
		}
		if ( null === $wpdb->get_row( 'SELECT id FROM posts WHERE slug=:slug', [ ':slug' => $candidate ] ) ) {
			return $candidate;
		}
	}
	return $base . '-imported-' . bin2hex( random_bytes( 3 ) );
}

/** Option name holding one import run's per-item state. */
function wp_portability_state_key( string $archive_sha256, array $mapping ): string
{
	$canonical = $mapping;
	ksort( $canonical );
	return 'portability_run_' . hash( 'sha256', $archive_sha256 . '|' . wp_json_encode( $canonical ) );
}

/** Loads persisted run state, or an empty record set. */
function wp_portability_state_load( string $key ): array
{
	$state = json_decode( (string) get_option( $key, '' ), true );
	return is_array( $state ) && is_array( $state['items'] ?? null ) ? $state : [ 'items' => [] ];
}

/** Persists run state after each item so an interruption keeps progress. */
function wp_portability_state_save( string $key, array $state ): void
{
	global $wpdb;
	$state['updated_at'] = time();
	$wpdb->query( 'INSERT INTO options(option_name,option_value) VALUES(:key,:value) ON CONFLICT(option_name) DO UPDATE SET option_value=:value', [
		':key'   => $key,
		':value' => wp_json_encode( $state )
	] );
}

/**
 * Imports one image from a materialized archive file through the same
 * validation, dedupe, quota and variant pipeline as an upload. The upload
 * handlers themselves require an HTTP-uploaded file, so archive intake
 * reuses their steps (normalize, content address, transactional register,
 * variant generation) rather than the $_FILES gate.
 */
function wp_portability_import_image( string $path, string $name, string $alt, string $caption ): int|WP_Error
{
	global $wpdb;
	if ( ! current_user_can( 'upload_media' ) ) {
		return new WP_Error( 'unauthorized', 'You cannot upload media.' );
	}
	$normalized = wp_normalize_image( $path );
	if ( is_wp_error( $normalized ) ) {
		return $normalized;
	}
	$file = $normalized['path'];
	$hash = (string) hash_file( 'sha256', $file );
	$existing = $wpdb->get_row( "SELECT id FROM media WHERE sha256=:hash AND kind='image' AND state='ready' AND variant=''", [ ':hash' => $hash ] );
	if ( null !== $existing ) {
		unlink( $file );
		wp_image_generate_variants( (int) $existing['id'] );
		return (int) $existing['id'];
	}
	$relative = substr( $hash, 0, 2 ) . '/' . $hash . '.' . $normalized['extension'];
	$destination = WP_ROOT_DIR . '/wp-content/uploads/' . $relative;
	$directory = dirname( $destination );
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) ) {
		unlink( $file );
		return new WP_Error( 'upload_failed', 'The uploads directory is not writable.' );
	}
	if ( ! rename( $file, $destination ) ) {
		unlink( $file );
		return new WP_Error( 'upload_failed', 'The image could not be stored.' );
	}
	chmod( $destination, 0644 );
	try {
		$id = $wpdb->transaction( static function ( $db ) use ( $relative, $hash, $normalized, $name, $alt, $caption ): int {
			if ( ! current_user_can( 'upload_media' ) ) {
				throw new RuntimeException( 'Upload permission was revoked.' );
			}
			wp_asset_check_quota( (int) $normalized['size'], $db );
			$db->query( 'INSERT INTO media(author_id,sha256,filename,mime,original_name,width,height,size,format,alt_text,caption) VALUES(:author,:hash,:filename,:mime,:name,:width,:height,:size,:format,:alt,:caption)', [
				':author'   => get_current_user_id(),
				':hash'     => $hash,
				':filename' => $relative,
				':mime'     => $normalized['mime'],
				':name'     => sanitize_text_field( $name ),
				':width'    => $normalized['width'],
				':height'   => $normalized['height'],
				':size'     => $normalized['size'],
				':format'   => $normalized['extension'],
				':alt'      => sanitize_text_field( $alt ),
				':caption'  => sanitize_text_field( $caption )
			] );
			$id = (int) $db->get_row( "SELECT id FROM media WHERE sha256=:hash AND kind='image' AND state='ready' AND variant=''", [ ':hash' => $hash ] )['id'];
			wp_audit( 'media_imported', $id );
			return $id;
		} );
	} catch ( Throwable $error ) {
		if ( 'Storage quota exceeded.' === $error->getMessage() ) {
			return new WP_Error( 'quota_exceeded', 'Storage quota exceeded.' );
		}
		return wp_failure( 'upload_failed', $error, 'The image could not be registered. Check storage and retry.' );
	}
	wp_image_generate_variants( $id );
	return $id;
}

/**
 * Imports one document through the asset lifecycle used by uploads:
 * validate bytes, stage, register (quota inside), promote to ready.
 */
function wp_portability_import_document( array $media ): int|WP_Error
{
	if ( ! current_user_can( 'upload_media' ) ) {
		return new WP_Error( 'unauthorized', 'You cannot upload media.' );
	}
	$format = (string) $media['format'];
	if ( ! isset( wp_document_formats()[ $format ] ) ) {
		return new WP_Error( 'unsupported_format', 'Unsupported document format.' );
	}
	global $wpdb;
	foreach ( $wpdb->get_results( "SELECT * FROM media WHERE sha256=:hash AND kind='document' AND format=:format AND visibility=:visibility AND state='ready'", [
		':hash'       => $media['sha256'],
		':format'     => $format,
		':visibility' => $media['visibility']
	] ) as $existing ) {
		if ( wp_asset_can_access( $existing ) ) {
			return (int) $existing['id'];
		}
	}
	try {
		$mime = wp_document_validate_bytes( (string) $media['file'], $format );
	} catch ( Throwable $error ) {
		return wp_failure( 'invalid_document', $error, $error->getMessage() );
	}
	$hash = (string) hash_file( 'sha256', (string) $media['file'] );
	$copy = wp_portability_workdir() . '/intake-' . bin2hex( random_bytes( 8 ) ) . '.bin';
	copy( (string) $media['file'], $copy );
	$staged = wp_asset_stage( $copy, $hash );
	if ( is_wp_error( $staged ) ) {
		return $staged;
	}
	$id = wp_asset_register( [
		'author_id'     => get_current_user_id(),
		'sha256'        => $hash,
		'filename'      => substr( $hash, 0, 2 ) . '/' . $hash,
		'mime'          => $mime,
		'original_name' => (string) $media['original_name'],
		'size'          => (int) filesize( (string) $media['file'] ),
		'kind'          => 'document',
		'format'        => $format,
		'visibility'    => (string) $media['visibility']
	] );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$promoted = wp_asset_promote( $id );
	if ( is_wp_error( $promoted ) ) {
		return $promoted;
	}
	$wpdb->query( 'UPDATE media SET alt_text=:alt,caption=:caption WHERE id=:id', [
		':alt'     => sanitize_text_field( (string) $media['alt_text'] ),
		':caption' => sanitize_text_field( (string) $media['caption'] ),
		':id'      => $id
	] );
	wp_audit( 'document_imported', $id );
	return $id;
}

/** Creates the ordinary, unusable-password account for an unmapped author. */
function wp_portability_create_author( array $author, string $username ): int|WP_Error
{
	global $wpdb;
	$email = trim( (string) $author['email'] );
	if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) || null !== $wpdb->get_row( 'SELECT id FROM users WHERE LOWER(email)=LOWER(:email)', [ ':email' => $email ] ) ) {
		$email = $username . '@imported.invalid';
		$suffix = 2;
		while ( null !== $wpdb->get_row( 'SELECT id FROM users WHERE LOWER(email)=LOWER(:email)', [ ':email' => $email ] ) ) {
			$email = $username . '-' . $suffix++ . '@imported.invalid';
		}
	}
	try {
		return $wpdb->transaction( static function ( $db ) use ( $author, $username, $email ): int {
			$api_routes = wp_api_routes_snapshot( $db );
			if ( ! current_user_can( 'administrator' ) ) {
				throw new RuntimeException( 'Account permission revoked.' );
			}
			$db->query( 'INSERT INTO users(username,email,password_hash,capabilities,display_name,first_name,last_name,biography,website) VALUES(:username,:email,:hash,:caps,:display,:first,:last,:bio,:site)', [
				':username' => $username,
				':email'    => $email,
				':hash'     => password_hash( bin2hex( random_bytes( 32 ) ), PASSWORD_DEFAULT ),
				':caps'     => wp_json_encode( wp_roles()['author'] ),
				':display'  => '' !== $author['display_name'] ? $author['display_name'] : $username,
				':first'    => (string) $author['first_name'],
				':last'     => (string) $author['last_name'],
				':bio'      => (string) $author['biography'],
				':site'     => (string) $author['website']
			] );
			$id = (int) $db->get_row( 'SELECT last_insert_rowid() AS id' )['id'];

			wp_api_routes_assert( $db, $api_routes );
			wp_audit( 'user_created_from_import', $id );
			return $id;
		} );
	} catch ( Throwable $error ) {
		return wp_failure( 'author_failed', $error, 'The author account could not be created.' );
	}
}

/**
 * Rewrites block-document media references through the media map. Blocks
 * whose media did not import are dropped and reported; other content is
 * untouched. Legacy HTML carries no media IDs and passes through unchanged.
 */
function wp_portability_remap_content( string $content, array $media_map, array $media_source_ids, array $targets = [] ): array
{
	if ( ! str_starts_with( ltrim( $content ), '{' ) ) {
		return wp_portability_remap_legacy( $content, $targets );
	}
	$document = json_decode( $content, true );
	if ( ! is_array( $document ) || ! is_array( $document['blocks'] ?? null ) ) {
		return [ $content, [] ];
	}
	$dropped = [];
	$walk = static function ( array $blocks ) use ( &$walk, &$dropped, $media_map, $targets ): array {
		$output = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				$output[] = $block;
				continue;
			}
			if ( 'columns' === ( $block['type'] ?? '' ) && is_array( $block['columns'] ?? null ) ) {
				foreach ( $block['columns'] as $index => $children ) {
					if ( is_array( $children ) ) {
						$block['columns'][ $index ] = $walk( $children );
					}
				}
				$output[] = $block;
				continue;
			}
			if ( 'html' === ( $block['type'] ?? '' ) ) {
				[ $block['html'], $missing ] = wp_portability_remap_legacy( (string) ( $block['html'] ?? '' ), $targets );
				$dropped = array_merge( $dropped, $missing );
			}
			if ( in_array( $block['type'] ?? '', [ 'image', 'file' ], true ) && isset( $block['media_id'] ) ) {
				$source = (int) $block['media_id'];
				if ( isset( $media_map[ $source ] ) ) {
					$block['media_id'] = $media_map[ $source ];
					$output[] = $block;
					continue;
				}
				$dropped[] = $source;
				continue;
			}
			$output[] = $block;
		}
		return $output;
	};
	$document['blocks'] = $walk( $document['blocks'] );
	return [ wp_json_encode( $document ), $dropped ];
}

/**
 * Runs a confirmed import. Authors, media, terms, then posts. State is
 * saved after every item; re-running the same archive with the same author
 * mapping reports completed items as already imported and does not
 * duplicate them.
 */
function wp_portability_apply_run( array $parsed, array $mapping = [] ): array
{
	global $wpdb;
	$model = $parsed['model'];
	$key = wp_portability_state_key( (string) $parsed['sha256'], $mapping );
	$state = wp_portability_state_load( $key );
	$results = [];
	$record = static function ( string $kind, int $source_id, string $label, string $status, string $detail, int $target_id = 0 ) use ( &$results, &$state, $key ): void {
		$item_key = $kind . ':' . $source_id;
		$state['items'][ $item_key ] = [
			'status'    => $status,
			'detail'    => $detail,
			'target_id' => $target_id
		];
		wp_portability_state_save( $key, $state );
		$results[] = [
			'key'       => $item_key,
			'kind'      => $kind,
			'label'     => $label,
			'status'    => $status,
			'detail'    => $detail,
			'target_id' => $target_id
		];
	};
	$done = static function ( string $kind, int $source_id ) use ( &$state ): ?array {
		return $state['items'][ $kind . ':' . $source_id ] ?? null;
	};
	$report_done = static function ( string $kind, int $source_id, string $label ) use ( &$results, $done ): ?int {
		$previous = $done( $kind, $source_id );
		if ( null === $previous || in_array( $previous['status'], [ 'failed', 'missing', 'quota' ], true ) ) {
			return null;
		}
		$results[] = [
			'key'       => $kind . ':' . $source_id,
			'kind'      => $kind,
			'label'     => $label,
			'status'    => 'already-imported',
			'detail'    => 'Completed by an earlier run of this archive: ' . $previous['detail'],
			'target_id' => (int) $previous['target_id']
		];
		return (int) $previous['target_id'];
	};
	// Authors first: posts are attributed through this map only.
	$author_map = [];
	foreach ( $model['authors'] as $author ) {
		$label = '' !== $author['username'] ? $author['username'] : 'author #' . $author['source_id'];
		$existing = $report_done( 'author', $author['source_id'], $label );
		if ( null !== $existing ) {
			$author_map[ $author['source_id'] ] = $existing;
			continue;
		}
		$target = wp_portability_author_target( $author, $mapping );
		if ( null !== $target ) {
			$author_map[ $author['source_id'] ] = (int) $target['id'];
			$record( 'author', $author['source_id'], $label, 'mapped', 'Mapped to existing account "' . $target['username'] . '".', (int) $target['id'] );
			continue;
		}
		$username = wp_portability_free_username( (string) $author['username'], (string) $author['email'] );
		$created = wp_portability_create_author( $author, $username );
		if ( is_wp_error( $created ) ) {
			$record( 'author', $author['source_id'], $label, 'failed', $created->get_error_message() );
			continue;
		}
		$author_map[ $author['source_id'] ] = $created;
		$record( 'author', $author['source_id'], $label, 'created', 'Created ordinary account "' . $username . '" with an unusable password.', $created );
	}
	// Media next: posts reference the mapped IDs.
	$media_map = [];
	$media_source_ids = array_map( static fn( array $media ): int => (int) $media['source_id'], $model['media'] );
	foreach ( $model['media'] as $media ) {
		$label = (string) $media['original_name'];
		$existing = $report_done( 'media', $media['source_id'], $label );
		if ( null !== $existing ) {
			$media_map[ $media['source_id'] ] = $existing;
			continue;
		}
		if ( null === $media['file'] ) {
			$record( 'media', $media['source_id'], $label, 'missing', (string) $media['bytes_error'] );
			continue;
		}
		$imported = 'image' === $media['kind']
		? wp_portability_import_image( (string) $media['file'], (string) $media['original_name'], (string) $media['alt_text'], (string) $media['caption'] )
		: wp_portability_import_document( $media );
		if ( is_wp_error( $imported ) ) {
			$status = 'quota_exceeded' === $imported->get_error_code() || str_contains( $imported->get_error_message(), 'quota' ) ? 'quota' : 'failed';
			$record( 'media', $media['source_id'], $label, $status, $imported->get_error_message() );
			continue;
		}
		$media_map[ $media['source_id'] ] = $imported;
		$record( 'media', $media['source_id'], $label, 'imported', 'Imported through the normal intake pipeline.', $imported );
	}
	// Terms, parents first.
	$term_map = [];
	$pending_terms = $model['terms'];
	for ( $pass = 0; $pass < 10 && [] !== $pending_terms; ++$pass ) {
		$remaining = [];
		foreach ( $pending_terms as $term ) {
			$label = $term['taxonomy'] . ':' . $term['slug'];
			$existing = $report_done( 'term', $term['source_id'], $label );
			if ( null !== $existing ) {
				$term_map[ $term['source_id'] ] = $existing;
				continue;
			}
			$parent_source = (int) $term['parent_source_id'];
			if ( $parent_source > 0 && ! isset( $term_map[ $parent_source ] ) ) {
				$remaining[] = $term;
				continue;
			}
			$row = $wpdb->get_row( 'SELECT id FROM terms WHERE taxonomy=:taxonomy AND slug=:slug', [
				':taxonomy' => $term['taxonomy'],
				':slug'     => $term['slug']
			] );
			if ( null !== $row ) {
				$term_map[ $term['source_id'] ] = (int) $row['id'];
				$record( 'term', $term['source_id'], $label, 'reused', 'A term with this slug already exists.', (int) $row['id'] );
				continue;
			}
			$saved = wp_save_term( [
				'taxonomy'    => $term['taxonomy'],
				'name'        => '' !== $term['name'] ? $term['name'] : $term['slug'],
				'slug'        => $term['slug'],
				'description' => $term['description'],
				'parent_id'   => $parent_source > 0 ? $term_map[ $parent_source ] : 0
			] );
			if ( is_wp_error( $saved ) ) {
				$record( 'term', $term['source_id'], $label, 'failed', $saved->get_error_message() );
				continue;
			}
			$term_map[ $term['source_id'] ] = $saved;
			$record( 'term', $term['source_id'], $label, 'imported', 'Term created.', $saved );
		}
		$pending_terms = $remaining;
	}
	foreach ( $pending_terms as $term ) {
		$record( 'term', $term['source_id'], $term['taxonomy'] . ':' . $term['slug'], 'failed', 'Its parent term could not be imported.' );
	}
	$media_targets = wp_portability_media_targets( $model['media'], $media_map );
	// Posts last, through the existing mutation path.
	foreach ( $model['posts'] as $post ) {
		$label = '' !== $post['title'] ? $post['title'] : 'content #' . $post['source_id'];
		$existing = $report_done( 'post', $post['source_id'], $label );
		if ( null !== $existing ) {
			continue;
		}
		$recovered = wp_portability_find_receipt( $key, (int) $post['source_id'] );
		if ( null !== $recovered ) {
			wp_portability_complete_post( $recovered, $post, $author_map );
			$record( 'post', $post['source_id'], $label, 'imported', 'Recovered the previously committed item.', $recovered );
			continue;
		}
		[ $content, $dropped ] = wp_portability_remap_content( (string) $post['content_raw'], $media_map, $media_source_ids, $media_targets );
		$notes = $post['notes'];
		foreach ( $dropped as $dropped_id ) {
			$notes[] = 'A media block referencing source media #' . $dropped_id . ' was removed because that media did not import.';
		}
		$slug = wp_sanitize_slug( '' !== $post['slug'] ? $post['slug'] : $post['title'] );
		$normalized = wp_portability_normalized( $content );
		$duplicate = null;
		if ( '' !== $slug && null !== $normalized ) {
			$row = $wpdb->get_row( 'SELECT id FROM posts WHERE post_type=:type AND slug=:slug AND title=:title AND content_raw=:content', [
				':type'    => $post['type'],
				':slug'    => $slug,
				':title'   => $post['title'],
				':content' => $normalized
			] );
			$duplicate = null !== $row ? (int) $row['id'] : null;
		}
		if ( null !== $duplicate ) {
			$record( 'post', $post['source_id'], $label, 'skipped-duplicate', 'An identical item already exists.', $duplicate );
			continue;
		}
		$final_slug = $slug;
		if ( '' !== $slug && null !== $wpdb->get_row( 'SELECT id FROM posts WHERE slug=:slug', [ ':slug' => $slug ] ) ) {
			$final_slug = wp_portability_collision_slug( $slug );
			$notes[] = 'Slug "' . $slug . '" collided and was suffixed to "' . $final_slug . '".';
		}
		$term_ids = [];
		if ( 'post' === $post['type'] ) {
			foreach ( $post['term_source_ids'] as $term_source ) {
				if ( isset( $term_map[ $term_source ] ) ) {
					$term_ids[] = $term_map[ $term_source ];
				}
			}
		}
		$featured = 0;
		if ( $post['featured_source_id'] > 0 ) {
			if ( isset( $media_map[ $post['featured_source_id'] ] ) ) {
				$featured = $media_map[ $post['featured_source_id'] ];
			} else {
				$notes[] = 'The featured media did not import and was left unset.';
			}
		}
		$insert = [
			'post_type'    => $post['type'],
			'post_title'   => $post['title'],
			'post_name'    => $final_slug,
			'post_content' => $content,
			'post_status'  => $post['status'],
			'post_excerpt' => $post['excerpt']
		];
		if ( $featured > 0 ) {
			$insert['featured_media_id'] = $featured;
		}
		if ( 'post' === $post['type'] ) {
			$insert['term_ids'] = $term_ids;
		}
		$insert['metadata'] = wp_portability_import_metadata( $post['metadata'] ?? null, $media_targets, $key, (int) $post['source_id'] );
		$notes = array_merge( $notes, wp_portability_metadata_notes( $post['metadata'] ?? null, $insert['metadata'] ) );
		$created = wp_insert_post( $insert );
		if ( is_wp_error( $created ) ) {
			$record( 'post', $post['source_id'], $label, 'failed', $created->get_error_message() . ( [] !== $notes ? ' ' . implode( ' ', $notes ) : '' ) );
			continue;
		}
		wp_portability_complete_post( $created, $post, $author_map );
		$record( 'post', $post['source_id'], $label, 'imported', [] !== $notes ? implode( ' ', $notes ) : 'Imported.', $created );
	}
	wp_audit( 'portability_import', 0, [
		'format' => (string) $parsed['format'],
		'items'  => count( $results )
	] );
	return [
		'state_key' => $key,
		'results'   => $results,
		'maps'      => [
			'authors' => $author_map,
			'media'   => $media_map,
			'terms'   => $term_map
		]
	];
}
