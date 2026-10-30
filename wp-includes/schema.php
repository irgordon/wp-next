<?php
/** Versioned, transactional schema creation and upgrades. */
declare( strict_types=1 );
use WordPress\Database\WP_SQLite_DB;
const WP_SCHEMA_VERSION = 9;
function wp_schema_version( WP_SQLite_DB $db ): int
{
	if ( null === $db->get_row( "SELECT name FROM sqlite_master WHERE type='table' AND name='options'" ) ) {
		return 0;
	}
	$row = $db->get_row( "SELECT option_value FROM options WHERE option_name='schema_version'" );
	return (int) ( $row['option_value'] ?? 0 );
}
function wp_upgrade_schema( WP_SQLite_DB $db ): void
{
	$db->transaction( static function ( WP_SQLite_DB $db ): void {
		$db->query( 'CREATE TABLE IF NOT EXISTS options (option_name TEXT PRIMARY KEY,option_value TEXT NOT NULL) STRICT' );
		$db->query( "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,capabilities TEXT NOT NULL CHECK(json_valid(capabilities)),created_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT" );
		$db->query( "CREATE TABLE IF NOT EXISTS posts (id INTEGER PRIMARY KEY AUTOINCREMENT,author_id INTEGER NOT NULL REFERENCES users(id),slug TEXT NOT NULL UNIQUE,title TEXT NOT NULL,content_raw TEXT NOT NULL,content_rendered TEXT,status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','archived','trashed')),post_type TEXT NOT NULL DEFAULT 'post' CHECK(post_type IN ('post','page')),revision INTEGER NOT NULL DEFAULT 1,rendered_revision INTEGER NOT NULL DEFAULT 0,metadata TEXT CHECK(metadata IS NULL OR json_valid(metadata)),published_at INTEGER,created_at INTEGER NOT NULL DEFAULT(unixepoch()),updated_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT" );
		$columns = array_column( $db->get_results( 'PRAGMA table_info(users)' ), 'name' );
		if ( ! in_array( 'disabled', $columns, true ) ) {
			$db->query( 'ALTER TABLE users ADD COLUMN disabled INTEGER NOT NULL DEFAULT 0' );
		}
		$columns = array_column( $db->get_results( 'PRAGMA table_info(posts)' ), 'name' );
		if ( ! in_array( 'post_type', $columns, true ) ) {
			$db->query( "ALTER TABLE posts ADD COLUMN post_type TEXT NOT NULL DEFAULT 'post'" );
		}
		if ( ! in_array( 'excerpt', $columns, true ) ) {
			$db->query( "ALTER TABLE posts ADD COLUMN excerpt TEXT NOT NULL DEFAULT ''" );
		}
		if ( ! in_array( 'featured_media_id', $columns, true ) ) {
			$db->query( 'ALTER TABLE posts ADD COLUMN featured_media_id INTEGER' );
		}
		$db->query( "CREATE TABLE IF NOT EXISTS job_queue (id INTEGER PRIMARY KEY AUTOINCREMENT,uuid TEXT NOT NULL UNIQUE,queue TEXT NOT NULL DEFAULT 'default',task TEXT NOT NULL,payload TEXT NOT NULL CHECK(json_valid(payload)),status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','reserved','completed','failed')),attempts INTEGER NOT NULL DEFAULT 0,max_attempts INTEGER NOT NULL DEFAULT 5,lease_token TEXT,reserved_at INTEGER,lease_expires_at INTEGER,available_at INTEGER NOT NULL DEFAULT(unixepoch()),created_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT" );
		$db->query( "CREATE TABLE IF NOT EXISTS plugin_activations (plugin_slug TEXT PRIMARY KEY,version TEXT NOT NULL,state TEXT NOT NULL CHECK(state IN ('migrating','migrated','active','failed_migration','failed_compilation')),error_message TEXT,created_at INTEGER NOT NULL DEFAULT(unixepoch()),updated_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT" );
		$db->query( 'CREATE TABLE IF NOT EXISTS user_sessions (token_hash TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id),expires_at INTEGER NOT NULL,created_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT' );
		$db->query( 'CREATE TABLE IF NOT EXISTS account_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,email TEXT NOT NULL,purpose TEXT NOT NULL CHECK(purpose IN (\'invite\',\'reset\',\'magic\')),token_hash TEXT NOT NULL UNIQUE,role TEXT NOT NULL DEFAULT \'\',expires_at INTEGER NOT NULL,consumed_at INTEGER,created_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT' );
		$db->query( "CREATE TABLE IF NOT EXISTS design_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id INTEGER NOT NULL, base_version INTEGER NOT NULL DEFAULT 0, config TEXT NOT NULL CHECK(json_valid(config)), published INTEGER NOT NULL DEFAULT 0 CHECK(published IN (0,1)), created_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT" );
		$db->query( "CREATE TABLE IF NOT EXISTS managed_file_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT NOT NULL CHECK(filename IN ('custom.css','site-notice.txt')), content TEXT NOT NULL, actor_id INTEGER NOT NULL, created_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT" );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_sessions_user ON user_sessions(user_id)' );
		$db->query( 'CREATE TABLE IF NOT EXISTS login_attempts (attempt_key TEXT PRIMARY KEY,failures INTEGER NOT NULL,expires_at INTEGER NOT NULL) STRICT' );
		$db->query( 'CREATE TABLE IF NOT EXISTS post_revisions (id INTEGER PRIMARY KEY,post_id INTEGER NOT NULL REFERENCES posts(id),revision INTEGER NOT NULL,snapshot TEXT NOT NULL CHECK(json_valid(snapshot)),actor_id INTEGER NOT NULL,created_at INTEGER NOT NULL DEFAULT(unixepoch()),UNIQUE(post_id,revision)) STRICT' );
		$db->query( 'CREATE TABLE IF NOT EXISTS media (id INTEGER PRIMARY KEY,author_id INTEGER NOT NULL REFERENCES users(id),sha256 TEXT NOT NULL,filename TEXT NOT NULL,mime TEXT NOT NULL,original_name TEXT NOT NULL,alt_text TEXT NOT NULL DEFAULT \'\',caption TEXT NOT NULL DEFAULT \'\',width INTEGER,height INTEGER,size INTEGER NOT NULL CHECK(size>=0),created_at INTEGER NOT NULL DEFAULT(unixepoch()),parent_id INTEGER,variant TEXT NOT NULL DEFAULT \'\',focal_x REAL,focal_y REAL,kind TEXT NOT NULL DEFAULT \'image\' CHECK(kind IN (\'image\',\'document\')),format TEXT NOT NULL DEFAULT \'\',visibility TEXT NOT NULL DEFAULT \'public\' CHECK(visibility IN (\'public\',\'private\')),state TEXT NOT NULL DEFAULT \'ready\' CHECK(state IN (\'pending\',\'ready\')),CHECK((kind=\'image\' AND width>0 AND height>0 AND width IS NOT NULL AND height IS NOT NULL AND visibility=\'public\' AND format IN (\'jpg\',\'png\',\'webp\',\'gif\')) OR (kind=\'document\' AND width IS NULL AND height IS NULL AND format IN (\'pdf\',\'txt\',\'md\',\'csv\')))) STRICT' );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_media_sha256 ON media(sha256)' );
		$db->query( 'CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY,actor_id INTEGER NOT NULL,action TEXT NOT NULL,subject_id INTEGER,payload TEXT NOT NULL DEFAULT \'{}\',event_uuid TEXT UNIQUE,created_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT' );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_posts_public ON posts(post_type,status,published_at DESC)' );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_job_claim ON job_queue(queue,status,available_at)' );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_job_lease ON job_queue(status,lease_expires_at)' );
		// Migrate the original primary administrator to the role marker only.
		$db->query( "UPDATE users SET capabilities='[\"administrator\"]' WHERE id=1 AND capabilities LIKE '%\"administrator\"%'" );

	} );
	wp_migrate_posts_status_check( $db );
	wp_migrate_media_asset_model( $db );
	wp_migrate_image_lineage( $db );
	wp_migrate_accounts( $db );
	wp_migrate_api( $db );
	$db->transaction( static function ( WP_SQLite_DB $db ): void {
		$columns = array_column( $db->get_results( 'PRAGMA table_info(users)' ), 'name' );
		foreach ( [ 'display_name', 'first_name', 'last_name', 'biography', 'website' ] as $column ) {
			if ( ! in_array( $column, $columns, true ) ) {
				$db->query( "ALTER TABLE users ADD COLUMN $column TEXT NOT NULL DEFAULT ''" );
			}
		}
		foreach ( [
			'avatar_media_id'  => 'INTEGER',
			'public_profile'   => 'INTEGER NOT NULL DEFAULT 0',
			'profile_revision' => 'INTEGER NOT NULL DEFAULT 1'
		] as $column => $type ) {
			if ( ! in_array( $column, $columns, true ) ) {
				$db->query( "ALTER TABLE users ADD COLUMN $column $type" );
			}
		}
		$db->query( "UPDATE users SET display_name=username WHERE display_name=''" );
		$db->query( "CREATE TABLE IF NOT EXISTS terms (id INTEGER PRIMARY KEY,taxonomy TEXT NOT NULL CHECK(taxonomy IN ('category','post_tag')),name TEXT NOT NULL,slug TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',parent_id INTEGER REFERENCES terms(id) ON DELETE SET NULL,revision INTEGER NOT NULL DEFAULT 1,UNIQUE(taxonomy,slug),CHECK(taxonomy='category' OR parent_id IS NULL)) STRICT" );
		$db->query( 'CREATE TABLE IF NOT EXISTS post_terms (post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,term_id INTEGER NOT NULL REFERENCES terms(id) ON DELETE CASCADE,PRIMARY KEY(post_id,term_id)) STRICT' );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_post_terms_term ON post_terms(term_id,post_id)' );
		$db->query( "INSERT INTO terms(taxonomy,name,slug) VALUES('category','Uncategorized','uncategorized') ON CONFLICT(taxonomy,slug) DO NOTHING" );
		$db->query( "INSERT OR IGNORE INTO post_terms(post_id,term_id) SELECT p.id,t.id FROM posts p JOIN terms t ON t.taxonomy='category' AND t.slug='uncategorized' WHERE p.post_type='post' AND NOT EXISTS (SELECT 1 FROM post_terms pt JOIN terms c ON c.id=pt.term_id WHERE pt.post_id=p.id AND c.taxonomy='category')" );
		if ( [] !== $db->get_results( 'PRAGMA foreign_key_check' ) ) {
			throw new RuntimeException( 'Upgrade left foreign key violations.' );
		}
		$db->query( "INSERT INTO options(option_name,option_value) VALUES('schema_version',:version) ON CONFLICT(option_name) DO UPDATE SET option_value=:version", [ ':version' => (string) WP_SCHEMA_VERSION ] );
	} );
}
/** Rebuild the media table once for the asset model (schema v5): kind, format, visibility and state columns with their combination checks, and the sha256 uniqueness relaxed to an index so one blob can carry several records. Existing rows are images: public, ready, ids and filenames preserved. */
function wp_migrate_media_asset_model( WP_SQLite_DB $db ): void
{
	$table = $db->get_row( "SELECT sql FROM sqlite_master WHERE type='table' AND name='media'" );
	if ( null === $table || str_contains( (string) $table['sql'], 'kind' ) ) {
		return;
	}
	$foreign_keys = (int) ( $db->get_row( 'PRAGMA foreign_keys' )['foreign_keys'] ?? 0 );
	$db->query( 'PRAGMA foreign_keys=OFF' );
	$db->query( 'PRAGMA legacy_alter_table=ON' );
	try {
		$db->transaction( static function ( WP_SQLite_DB $db ): void {
			$db->query( 'ALTER TABLE media RENAME TO media_legacy' );
			$db->query( 'CREATE TABLE media (id INTEGER PRIMARY KEY,author_id INTEGER NOT NULL REFERENCES users(id),sha256 TEXT NOT NULL,filename TEXT NOT NULL,mime TEXT NOT NULL,original_name TEXT NOT NULL,alt_text TEXT NOT NULL DEFAULT \'\',caption TEXT NOT NULL DEFAULT \'\',width INTEGER,height INTEGER,size INTEGER NOT NULL CHECK(size>=0),created_at INTEGER NOT NULL DEFAULT(unixepoch()),parent_id INTEGER,variant TEXT NOT NULL DEFAULT \'\',focal_x REAL,focal_y REAL,kind TEXT NOT NULL DEFAULT \'image\' CHECK(kind IN (\'image\',\'document\')),format TEXT NOT NULL DEFAULT \'\',visibility TEXT NOT NULL DEFAULT \'public\' CHECK(visibility IN (\'public\',\'private\')),state TEXT NOT NULL DEFAULT \'ready\' CHECK(state IN (\'pending\',\'ready\')),CHECK((kind=\'image\' AND width>0 AND height>0 AND width IS NOT NULL AND height IS NOT NULL AND visibility=\'public\' AND format IN (\'jpg\',\'png\',\'webp\',\'gif\')) OR (kind=\'document\' AND width IS NULL AND height IS NULL AND format IN (\'pdf\',\'txt\',\'md\',\'csv\')))) STRICT' );
			$db->query( "INSERT INTO media(id,author_id,sha256,filename,mime,original_name,alt_text,caption,width,height,size,created_at,kind,format,visibility,state) SELECT id,author_id,sha256,filename,mime,original_name,alt_text,caption,width,height,size,created_at,'image',CASE WHEN filename LIKE '%.jpg' THEN 'jpg' WHEN filename LIKE '%.png' THEN 'png' WHEN filename LIKE '%.webp' THEN 'webp' WHEN filename LIKE '%.gif' THEN 'gif' ELSE '' END,'public','ready' FROM media_legacy" );
			$db->query( 'DROP TABLE media_legacy' );
			$db->query( 'CREATE INDEX IF NOT EXISTS idx_media_sha256 ON media(sha256)' );
		} );
	} finally {
		$db->query( 'PRAGMA legacy_alter_table=OFF' );
		$db->query( 'PRAGMA foreign_keys=' . ( 1 === $foreign_keys ? 'ON' : 'OFF' ) );
	}
}

/** Rebuild the posts table once so its status check admits the trash state (schema v3). */
function wp_migrate_posts_status_check( WP_SQLite_DB $db ): void
{
	$table = $db->get_row( "SELECT sql FROM sqlite_master WHERE type='table' AND name='posts'" );
	if ( null === $table || str_contains( (string) $table['sql'], "'trashed'" ) ) {
		return;
	}
	$columns = array_column( $db->get_results( 'PRAGMA table_info(posts)' ), 'name' );
	$list = implode( ',', array_map( static fn( string $column ): string => '"' . str_replace( '"', '""', $column ) . '"', $columns ) );
	$foreign_keys = (int) ( $db->get_row( 'PRAGMA foreign_keys' )['foreign_keys'] ?? 0 );
	$db->query( 'PRAGMA foreign_keys=OFF' );
	$db->query( 'PRAGMA legacy_alter_table=ON' );
	try {
		$db->transaction( static function ( WP_SQLite_DB $db ) use ( $list ): void {
			$sequence = null === $db->get_row( "SELECT name FROM sqlite_master WHERE name='sqlite_sequence'" ) ? 0 : (int) ( $db->get_row( "SELECT seq FROM sqlite_sequence WHERE name='posts'" )['seq'] ?? 0 );
			$db->query( 'ALTER TABLE posts RENAME TO posts_legacy' );
			$db->query( "CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT,author_id INTEGER NOT NULL REFERENCES users(id),slug TEXT NOT NULL UNIQUE,title TEXT NOT NULL,content_raw TEXT NOT NULL,content_rendered TEXT,status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','archived','trashed')),post_type TEXT NOT NULL DEFAULT 'post' CHECK(post_type IN ('post','page')),revision INTEGER NOT NULL DEFAULT 1,rendered_revision INTEGER NOT NULL DEFAULT 0,metadata TEXT CHECK(metadata IS NULL OR json_valid(metadata)),published_at INTEGER,created_at INTEGER NOT NULL DEFAULT(unixepoch()),updated_at INTEGER NOT NULL DEFAULT(unixepoch()),excerpt TEXT NOT NULL DEFAULT '',featured_media_id INTEGER) STRICT" );
			$db->query( "INSERT INTO posts($list) SELECT $list FROM posts_legacy" );
			$db->query( 'DROP TABLE posts_legacy' );
			$db->query( "UPDATE sqlite_sequence SET seq=max(seq,:sequence) WHERE name='posts'", [ ':sequence' => $sequence ] );
			$db->query( 'CREATE INDEX IF NOT EXISTS idx_posts_public ON posts(post_type,status,published_at DESC)' );
		} );
	} finally {
		$db->query( 'PRAGMA legacy_alter_table=OFF' );
		$db->query( 'PRAGMA foreign_keys=' . ( 1 === $foreign_keys ? 'ON' : 'OFF' ) );
	}
}
/** Schema v7: image lineage — variants and edited copies point at their master; focal point lives on the master. */
function wp_migrate_image_lineage( WP_SQLite_DB $db ): void
{
	$columns = array_column( $db->get_results( 'PRAGMA table_info(media)' ), 'name' );
	if ( in_array( 'parent_id', $columns, true ) ) {
		return;
	}
	$db->transaction( static function ( WP_SQLite_DB $db ): void {
		$db->query( 'ALTER TABLE media ADD COLUMN parent_id INTEGER' );
		$db->query( "ALTER TABLE media ADD COLUMN variant TEXT NOT NULL DEFAULT ''" );
		$db->query( 'ALTER TABLE media ADD COLUMN focal_x REAL' );
		$db->query( 'ALTER TABLE media ADD COLUMN focal_y REAL' );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_media_parent ON media(parent_id)' );
	} );
}

/** Account tokens for invitations, password recovery and magic sign-in links (schema 8). */
function wp_migrate_accounts( WP_SQLite_DB $db ): void
{
	$db->query( 'CREATE TABLE IF NOT EXISTS account_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,email TEXT NOT NULL,purpose TEXT NOT NULL,token_hash TEXT NOT NULL UNIQUE,role TEXT NOT NULL DEFAULT \'\',expires_at INTEGER NOT NULL,consumed_at INTEGER,created_at INTEGER NOT NULL DEFAULT(unixepoch())) STRICT' );
}

/** Editorial API state, credentials, retry receipts and rate windows (schema 9). The API starts disabled and unbound; no epoch file is created here. */
function wp_migrate_api( WP_SQLite_DB $db ): void
{
	$db->transaction( static function ( WP_SQLite_DB $db ): void {
		$db->query( "CREATE TABLE IF NOT EXISTS api_security_state (singleton INTEGER PRIMARY KEY CHECK (singleton = 1),enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),bound_epoch TEXT CHECK (bound_epoch IS NULL OR (length(bound_epoch) = 64 AND bound_epoch NOT GLOB '*[^0-9a-f]*')),CHECK (enabled = 0 OR bound_epoch IS NOT NULL)) STRICT" );
		$db->query( 'INSERT INTO api_security_state(singleton, enabled, bound_epoch) VALUES (1, 0, NULL) ON CONFLICT(singleton) DO NOTHING' );
		$db->query( "CREATE TABLE IF NOT EXISTS api_tokens (id TEXT PRIMARY KEY NOT NULL CHECK (length(id) = 32 AND id NOT GLOB '*[^0-9a-f]*'),user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,issued_by INTEGER NOT NULL,name TEXT NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 80),secret_hash TEXT NOT NULL UNIQUE CHECK (length(secret_hash) = 64 AND secret_hash NOT GLOB '*[^0-9a-f]*'),credential_epoch TEXT NOT NULL CHECK (length(credential_epoch) = 64 AND credential_epoch NOT GLOB '*[^0-9a-f]*'),created_at INTEGER NOT NULL DEFAULT (unixepoch()),expires_at INTEGER NOT NULL,revoked_at INTEGER,revoke_reason TEXT CHECK (revoke_reason IS NULL OR revoke_reason IN ('manual', 'account_change', 'api_disabled', 'epoch_rotation', 'backup_snapshot', 'restore')),last_used_at INTEGER,CHECK (expires_at > created_at AND expires_at <= created_at + 7776000),CHECK (revoked_at IS NULL OR revoked_at >= created_at),CHECK ((revoked_at IS NULL) = (revoke_reason IS NULL)),CHECK (last_used_at IS NULL OR last_used_at >= created_at)) STRICT" );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_api_tokens_user ON api_tokens(user_id)' );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_api_tokens_expiry ON api_tokens(expires_at)' );
		$db->query( "CREATE TABLE IF NOT EXISTS api_token_scopes (token_id TEXT NOT NULL REFERENCES api_tokens(id) ON DELETE CASCADE,scope TEXT NOT NULL CHECK (scope IN ('posts:read', 'posts:write', 'posts:publish','pages:read', 'pages:write', 'pages:publish')),PRIMARY KEY (token_id, scope)) STRICT" );
		$db->query( "CREATE TABLE IF NOT EXISTS api_mutation_receipts (token_id TEXT NOT NULL REFERENCES api_tokens(id) ON DELETE CASCADE,key_hash TEXT NOT NULL CHECK (length(key_hash) = 64 AND key_hash NOT GLOB '*[^0-9a-f]*'),request_hash TEXT NOT NULL CHECK (length(request_hash) = 64 AND request_hash NOT GLOB '*[^0-9a-f]*'),resource_kind TEXT NOT NULL CHECK (resource_kind IN ('post', 'page')),operation TEXT NOT NULL CHECK (operation IN ('create', 'update_draft', 'publish')),resource_id INTEGER NOT NULL CHECK (resource_id > 0),committed_revision INTEGER NOT NULL CHECK (committed_revision > 0),committed_status TEXT NOT NULL CHECK (committed_status IN ('draft', 'published')),mutation_id TEXT NOT NULL UNIQUE CHECK (length(mutation_id) = 36),http_status INTEGER NOT NULL CHECK (http_status IN (200, 201)),response_json TEXT,location TEXT CHECK (location IS NULL OR (length(location) BETWEEN 1 AND 2048 AND substr(location, 1, 1) = '/' AND substr(location, 1, 2) <> '//')),state TEXT NOT NULL DEFAULT 'replayable' CHECK (state IN ('replayable', 'expired')),created_at INTEGER NOT NULL DEFAULT (unixepoch()),replay_until INTEGER NOT NULL,PRIMARY KEY (token_id, key_hash),CHECK (replay_until = created_at + 86400),CHECK ((operation = 'create' AND http_status = 201) OR (operation <> 'create' AND http_status = 200)),CHECK ((operation = 'publish' AND committed_status = 'published') OR (operation <> 'publish' AND committed_status = 'draft')),CHECK ((state = 'replayable' AND response_json IS NOT NULL) OR (state = 'expired' AND response_json IS NULL AND location IS NULL)),CHECK (response_json IS NULL OR CASE WHEN json_valid(response_json) THEN json_type(response_json) = 'object' AND length(CAST(response_json AS BLOB)) <= 4096 AND COALESCE(json_type(response_json, '$.id') = 'integer' AND json_extract(response_json, '$.id') = resource_id AND json_extract(response_json, '$.type') = resource_kind AND json_type(response_json, '$.revision') = 'integer' AND json_extract(response_json, '$.revision') = committed_revision AND json_extract(response_json, '$.status') = committed_status AND json_extract(response_json, '$.mutation_id') = mutation_id AND json_type(response_json, '$.slug') = 'text' AND json_type(response_json, '$.url') IN ('text', 'null'), 0) ELSE 0 END)) STRICT" );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_api_receipts_cleanup ON api_mutation_receipts(state, replay_until)' );
		$db->query( "CREATE TABLE IF NOT EXISTS api_rate_windows (budget_key TEXT PRIMARY KEY NOT NULL CHECK (length(budget_key) = 64 AND budget_key NOT GLOB '*[^0-9a-f]*'),budget_kind TEXT NOT NULL CHECK (budget_kind IN ('peer_auth', 'token_read', 'token_write')),used INTEGER NOT NULL CHECK (used BETWEEN 1 AND 60),window_start INTEGER NOT NULL,expires_at INTEGER NOT NULL,CHECK (expires_at = window_start + 60),CHECK (budget_kind <> 'token_write' OR used <= 20)) STRICT" );
		$db->query( 'CREATE INDEX IF NOT EXISTS idx_api_rate_expiry ON api_rate_windows(expires_at)' );
	} );
}
