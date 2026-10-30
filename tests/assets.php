<?php
/** Asset lifecycle and destructive-cleanup boundaries on disposable storage. */
declare( strict_types=1 );
$code = dirname( __DIR__ );
$temp = sys_get_temp_dir() . '/wp-assets-' . bin2hex( random_bytes( 6 ) );
mkdir( $temp, 0700 );
define( 'WP_ROOT_DIR', $temp . '/site' );
define( 'WP_VAR_DIR', $temp . '/state' );
mkdir( WP_ROOT_DIR . '/wp-content/uploads', 0755, true );
mkdir( WP_VAR_DIR, 0700 );
foreach ( [ 'class-wp-error.php', 'l10n.php', 'formatting.php', 'security.php', 'class-wp-sqlite-db.php', 'schema.php', 'class-wp-authorization-service.php', 'theme.php', 'content.php', 'media.php', 'user.php' ] as $file ) { require_once $code . '/wp-includes/' . $file; }
$wpdb = new \WordPress\Database\WP_SQLite_DB( WP_VAR_DIR . '/site.sqlite' );
$wp_auth_service = new \WordPress\Security\WP_Authorization_Service();
wp_upgrade_schema( $wpdb );
$wpdb->query( 'INSERT INTO users(id,username,email,password_hash,capabilities) VALUES(1,\'owner\',\'owner@example.test\',\'unused\',\'["upload_media"]\'),(2,\'other\',\'other@example.test\',\'unused\',\'["upload_media"]\'),(3,\'manager\',\'manager@example.test\',\'unused\',\'["manage_media","upload_media"]\')' );
wp_set_current_user( 1 );
$count = 0;
function asset_check( bool $ok, string $label ): void { global $count; if ( ! $ok ) { throw new RuntimeException( $label ); } echo 'PASS: ' . $label . "\n"; ++$count; }
function fixture( string $bytes, string $visibility = 'private' ): array {
    $hash = hash( 'sha256', $bytes );
    $source = tempnam( WP_VAR_DIR, 'source-' ); file_put_contents( $source, $bytes );
    $result = wp_asset_stage( $source, $hash ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
    return [ 'author_id' => get_current_user_id(), 'sha256' => $hash, 'filename' => substr( $hash, 0, 2 ) . '/' . $hash, 'mime' => 'text/plain', 'original_name' => 'notes.txt', 'size' => strlen( $bytes ), 'kind' => 'document', 'format' => 'txt', 'visibility' => $visibility ];
}
try {
    asset_check( 9 === wp_schema_version( $wpdb ), 'Blank schema is v9' );
    $data = fixture( 'Private test document' );
    $id = wp_asset_register( $data ); asset_check( is_int( $id ), 'Staged document registers' );
    $pending = $wpdb->get_row( 'SELECT * FROM media WHERE id=:id', [ ':id' => $id ] );
    asset_check( 'pending' === $pending['state'] && wp_asset_usage()['registered_bytes'] === $data['size'], 'Pending record consumes registered quota' );
    asset_check( wp_asset_can_access( $pending ), 'Owner can inspect pending metadata' );
    wp_set_current_user( 2 ); asset_check( ! wp_asset_can_access( $pending ), 'Another uploader cannot inspect private pending metadata' );
    wp_set_current_user( 1 ); asset_check( true === wp_asset_promote( $id ), 'Promotion finishes pending registration' );
    $ready = $wpdb->get_row( 'SELECT * FROM media WHERE id=:id', [ ':id' => $id ] );
    asset_check( 'ready' === $ready['state'] && file_get_contents( wp_asset_blob_path( $ready ) ) === 'Private test document', 'Ready document bytes match in protected storage' );
    asset_check( ( fileperms( wp_asset_blob_path( $ready ) ) & 0777 ) === 0600, 'Protected document bytes use private permissions' );
    wp_set_current_user( 2 ); asset_check( ! wp_asset_can_access( $ready ), 'Private ready document is isolated from another uploader' );
    wp_set_current_user( 3 ); asset_check( wp_asset_can_access( $ready ), 'Media manager can access private document' );
    $GLOBALS['current_user'] = null; asset_check( ! wp_asset_can_access( $ready ), 'Anonymous access to private asset is refused' );
    $public = $ready; $public['visibility'] = 'public'; asset_check( wp_asset_can_access( $public ), 'Public ready metadata is accessible' );
    $public['state'] = 'pending'; asset_check( ! wp_asset_can_access( $public ), 'Anonymous users cannot inspect incomplete public records' );
    wp_set_current_user( 1 );
    $wpdb->query( "INSERT INTO options(option_name,option_value) VALUES('assets_quota_bytes',:quota)", [ ':quota' => (string) $data['size'] ] );
    asset_check( is_wp_error( wp_asset_register( fixture( 'Beyond quota' ) ) ), 'Registration over quota fails atomically' );
    asset_check( 1 === (int) $wpdb->get_row( 'SELECT COUNT(*) AS n FROM media' )['n'], 'Quota failure inserts no partial record' );
    $wpdb->query( "UPDATE options SET option_value='0' WHERE option_name='assets_quota_bytes'" );
    $duplicate = wp_asset_register( fixture( 'Private test document' ) ); asset_check( is_int( $duplicate ) && $duplicate !== $id, 'Shared bytes can have distinct records' );
    asset_check( true === wp_asset_promote( $duplicate ), 'Duplicate promotion reuses existing protected bytes' );
    $recover = wp_asset_register( fixture( 'Recover after commit' ) );
    $lost = fixture( 'Missing after commit' ); $lost_id = wp_asset_register( $lost ); unlink( wp_asset_staged_path( $lost['sha256'] ) );
    $summary = wp_asset_reconcile();
    asset_check( $summary['promoted'] === 1 && $summary['failed'] === 1, 'Recovery promotes valid pending bytes and unwinds missing bytes' );
    asset_check( null === $wpdb->get_row( 'SELECT id FROM media WHERE id=:id', [ ':id' => $lost_id ] ), 'Failed pending registration is removed to release quota' );
    $wpdb->query( 'DELETE FROM media WHERE id=:id', [ ':id' => $duplicate ] ); touch( wp_asset_blob_path( $ready ), time() - 90000 );
    wp_asset_reconcile(); asset_check( is_file( wp_asset_blob_path( $ready ) ), 'Cleanup retains shared bytes while any record references them' );
    $orphan_hash = hash( 'sha256', 'orphan' ); $orphan = wp_assets_dir() . '/' . substr( $orphan_hash, 0, 2 ) . '/' . $orphan_hash;
    mkdir( dirname( $orphan ), 0700, true ); file_put_contents( $orphan, 'orphan' ); touch( $orphan, time() - 90000 );
    $unrelated = wp_assets_dir() . '/operator-note.txt'; file_put_contents( $unrelated, 'preserve' );
    $fresh_hash = hash( 'sha256', 'fresh orphan' ); $fresh = wp_assets_dir() . '/' . substr( $fresh_hash, 0, 2 ) . '/' . $fresh_hash;
    mkdir( dirname( $fresh ), 0700, true ); file_put_contents( $fresh, 'fresh orphan' );
    wp_asset_reconcile();
    asset_check( ! is_file( $orphan ) && is_file( $fresh ) && is_file( $unrelated ), 'Cleanup removes only old canonical orphans and preserves fresh/unknown files' );
    $invalid = $data; $invalid['filename'] = '../../escape'; asset_check( is_wp_error( wp_asset_register( $invalid ) ), 'Noncanonical filenames cannot escape storage roots' );
    $source = tempnam( WP_VAR_DIR, 'hash-' ); file_put_contents( $source, 'wrong');
    asset_check( is_wp_error( wp_asset_stage( $source, str_repeat( 'a', 64 ) ) ), 'Staging rejects mismatched bytes' );
    foreach ( [ [ 'width', '1' ], [ 'format', "'unknown'" ], [ 'visibility', "'unknown'" ], [ 'size', '-1' ] ] as [ $column, $value ] ) {
        $rejected = false; try { $wpdb->query( 'UPDATE media SET ' . $column . '=' . $value . ' WHERE id=:id', [ ':id' => $id ] ); } catch ( Throwable $error ) { $rejected = true; }
        asset_check( $rejected, 'Database rejects invalid ' . $column );
    }
    $deferred_data = fixture( 'Keep staged errors for investigation' ); $deferred_id = wp_asset_register( $deferred_data );
    file_put_contents( wp_asset_staged_path( $deferred_data['sha256'] ), 'corrupted staging' );
    $summary = wp_asset_reconcile();
    asset_check( $summary['deferred'] === 1 && null !== $wpdb->get_row( "SELECT id FROM media WHERE id=:id AND state='pending'", [ ':id' => $deferred_id ] ), 'Recovery reports a deferred failure without discarding an existing staged file or its registration' );
    wp_asset_fail( $deferred_id );
    file_put_contents( wp_asset_blob_path( $ready ), 'corrupt' ); $summary = wp_asset_reconcile();
    asset_check( $summary['corrupt'] === 1 && null !== $wpdb->get_row( 'SELECT id FROM media WHERE id=:id', [ ':id' => $id ] ), 'Corrupt ready bytes are reported without silently deleting their record' );
    asset_check( [] === $wpdb->get_results( 'PRAGMA foreign_key_check' ), 'Asset lifecycle preserves foreign keys' );
    echo $count . " asset checks passed.\n";
} finally {
    unset( $wpdb );
    $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $temp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $iterator as $file ) { $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); }
    rmdir( $temp );
}
