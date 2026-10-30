<?php
/** Editorial API (v0.1.0 contract): integration credentials bound to an external epoch, scoped Bearer tokens, browser-equivalent editorial visibility, and idempotent mutations whose receipts commit with the content. The API is disabled until an operator initializes the epoch and enables it. */
declare( strict_types=1 );
use WordPress\Database\WP_SQLite_DB;
use WordPress\Post\WP_Post_Mutation_Service;
const WP_API_CONTRACT_VERSION = '1';
const WP_API_TOKEN_SCOPES = [ 'posts:read', 'posts:write', 'posts:publish', 'pages:read', 'pages:write', 'pages:publish' ];
const WP_API_RATE_LIMITS = [
	'peer_auth'   => 60,
	'token_read'  => 60,
	'token_write' => 20
];
const WP_API_MAX_TOKENS_PER_USER = 10;
const WP_API_MAX_TOKENS_PER_SITE = 100;
const WP_API_MAX_RECEIPTS_PER_TOKEN = 1000;
const WP_API_MAX_RECEIPTS_PER_SITE = 10000;
const WP_API_MAX_RATE_BUCKETS = 10000;
const WP_API_ENVELOPE_LIMIT = 3145728;

/* ------------------------------------------------------------------ config */

function wp_api_base_path(): string
{
	return defined( 'WP_API_BASE_PATH' ) ? (string) WP_API_BASE_PATH : '';
}

function wp_api_mount_path(): string
{
	return wp_api_base_path() . '/wp-next/v1';
}

/** The mount is consistent when the operator base path matches the configured site base path. */
function wp_api_base_path_consistent(): bool
{
	$site_base = rtrim( (string) parse_url( (string) get_option( 'site_url', '' ), PHP_URL_PATH ), '/' );
	return wp_api_base_path() === $site_base;
}

function wp_api_state( WP_SQLite_DB $db ): ?array
{
	if ( null === $db->get_row( "SELECT name FROM sqlite_master WHERE type='table' AND name='api_security_state'" ) ) {
		return null;
	}
	return $db->get_row( 'SELECT * FROM api_security_state WHERE singleton=1' );
}

function wp_api_tables_exist( WP_SQLite_DB $db ): bool
{
	return null !== $db->get_row( "SELECT name FROM sqlite_master WHERE type='table' AND name='api_tokens'" );
}

/* ------------------------------------------------------------------- epoch */

function wp_api_epoch_path(): string
{
	return defined( 'WP_API_EPOCH_PATH' ) ? trim( (string) WP_API_EPOCH_PATH ) : '';
}

/**
 * Read and validate the external credential epoch. The file must be a regular
 * non-symlink file with no group/other permission bits, holding one JSON
 * object with a 256-bit hex epoch. Anything else is unavailable, never a guess.
 */
function wp_api_epoch_read(): string|WP_Error
{
	$path = wp_api_epoch_path();
	if ( '' === $path || ! str_starts_with( $path, '/' ) ) {
		return new WP_Error( 'epoch_unconfigured', 'WP_API_EPOCH_PATH is not configured.' );
	}
	if ( is_link( $path ) || ! is_file( $path ) ) {
		return new WP_Error( 'epoch_missing', 'The API epoch file is missing.' );
	}
	$perms = fileperms( $path );
	if ( false === $perms || 0 !== ( $perms & 0077 ) ) {
		return new WP_Error( 'epoch_permissions', 'The API epoch file permissions are unsafe.' );
	}
	$raw = file_get_contents( $path );
	$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
	$epoch = is_array( $data ) ? (string) ( $data['epoch'] ?? '' ) : '';
	if ( 1 !== (int) ( $data['version'] ?? 0 ) || ! preg_match( '/^[0-9a-f]{64}$/D', $epoch ) ) {
		return new WP_Error( 'epoch_invalid', 'The API epoch file is invalid.' );
	}
	return $epoch;
}

/** Shared (reader) or exclusive (rotation/restore) lock on a stable sibling of the epoch file, so atomic replacement never changes the lock identity. */
function wp_api_epoch_lock( bool $exclusive ): mixed
{
	$path = wp_api_epoch_path();
	if ( '' === $path ) {
		return false;
	}
	$handle = fopen( $path . '.lock', 'c' );
	if ( false === $handle ) {
		return false;
	}
	chmod( $path . '.lock', 0600 );
	if ( ! flock( $handle, $exclusive ? LOCK_EX : LOCK_SH ) ) {
		fclose( $handle );
		return false;
	}
	return $handle;
}

function wp_api_epoch_unlock( mixed $handle ): void
{
	if ( is_resource( $handle ) ) {
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}
}

function wp_api_epoch_generate(): string
{
	return bin2hex( random_bytes( 32 ) );
}

/** Write a new epoch atomically: temp file in the same directory, flush, rename, directory flush. */
function wp_api_epoch_write( string $epoch ): bool
{
	$path = wp_api_epoch_path();
	if ( '' === $path || ! preg_match( '/^[0-9a-f]{64}$/D', $epoch ) ) {
		return false;
	}
	$directory = dirname( $path );
	if ( ! is_dir( $directory ) ) {
		return false;
	}
	$temporary = $path . '.tmp-' . bin2hex( random_bytes( 6 ) );
	$handle = fopen( $temporary, 'x' );
	if ( false === $handle ) {
		return false;
	}
	chmod( $temporary, 0600 );
	$payload = wp_json_encode( [
		'version' => 1,
		'epoch'   => $epoch
	] );
	if ( false === fwrite( $handle, (string) $payload ) || ! fflush( $handle ) ) {
		fclose( $handle );
		unlink( $temporary );
		return false;
	}
	if ( function_exists( 'fsync' ) ) {
		fsync( $handle );
	}
	fclose( $handle );
	if ( ! rename( $temporary, $path ) ) {
		unlink( $temporary );
		return false;
	}
	$dir_handle = fopen( $directory, 'r' );
	if ( false !== $dir_handle ) {
		if ( function_exists( 'fsync' ) ) {
			fsync( $dir_handle );
		}
		fclose( $dir_handle );
	}
	return true;
}

/* ------------------------------------------------------------- rate limits */

/**
 * One fixed-window admission, reserved atomically like the login budget.
 * Returns true when admitted; a WP_Error(429) with the retry delay otherwise.
 */
function wp_api_rate_admit( WP_SQLite_DB $db, string $kind, string $material ): true|WP_Error
{
	$limit = WP_API_RATE_LIMITS[ $kind ] ?? 0;
	if ( $limit <= 0 || ! wp_api_tables_exist( $db ) ) {
		return new WP_Error( 'rate_unavailable', 'Rate admission is unavailable.' );
	}
	$key = hash( 'sha256', $kind . '|' . $material );
	$existing = $db->get_row( 'SELECT budget_key FROM api_rate_windows WHERE budget_key=:key', [ ':key' => $key ] );
	if ( null === $existing ) {
		$count = (int) $db->get_row( 'SELECT COUNT(*) AS n FROM api_rate_windows' )['n'];
		if ( $count >= WP_API_MAX_RATE_BUCKETS ) {
			$db->query( 'DELETE FROM api_rate_windows WHERE expires_at<=unixepoch()' );
			$count = (int) $db->get_row( 'SELECT COUNT(*) AS n FROM api_rate_windows' )['n'];
			if ( $count >= WP_API_MAX_RATE_BUCKETS ) {
				return new WP_Error( 'rate_unavailable', 'Rate admission capacity is exhausted.' );
			}
		}
	}
	$row = $db->get_row( 'INSERT INTO api_rate_windows(budget_key,budget_kind,used,window_start,expires_at) VALUES(:key,:kind,1,unixepoch(),unixepoch()+60) ON CONFLICT(budget_key) DO UPDATE SET used=CASE WHEN expires_at<=unixepoch() THEN 1 ELSE used+1 END,window_start=CASE WHEN expires_at<=unixepoch() THEN unixepoch() ELSE window_start END,expires_at=CASE WHEN expires_at<=unixepoch() THEN unixepoch()+60 ELSE expires_at END WHERE expires_at<=unixepoch() OR used<:limit RETURNING used,expires_at', [
		':key'   => $key,
		':kind'  => $kind,
		':limit' => $limit
	] );
	if ( null === $row ) {
		$window = $db->get_row( 'SELECT expires_at FROM api_rate_windows WHERE budget_key=:key', [ ':key' => $key ] );
		$retry = null !== $window ? max( 1, (int) $window['expires_at'] - time() ) : 60;
		return new WP_Error( 'rate_limited', 'Too many requests. Retry after the current window.', $retry );
	}
	return true;
}

function wp_api_peer_material(): string
{
	$address = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'cli' );
	$packed = @inet_pton( $address );
	return false === $packed ? $address : bin2hex( $packed );
}

/* ------------------------------------------------------------------ tokens */

function wp_api_token_active_count( WP_SQLite_DB $db, ?int $user_id = null ): int
{
	$sql = 'SELECT COUNT(*) AS n FROM api_tokens WHERE revoked_at IS NULL AND expires_at>unixepoch()';
	$params = [];
	if ( null !== $user_id ) {
		$sql .= ' AND user_id=:user';
		$params[':user'] = $user_id;
	}
	return (int) $db->get_row( $sql, $params )['n'];
}

/**
 * Issue a named credential. The wire token is returned once; only its public
 * ID and the SHA-256 of the decoded secret are stored.
 */
function wp_api_token_issue( WP_SQLite_DB $db, int $user_id, int $issued_by, string $name, array $scopes, int $days ): array|WP_Error
{
	$name = trim( $name );
	if ( '' === $name || strlen( $name ) > 80 ) {
		return new WP_Error( 'invalid_name', 'Give the credential a name of at most 80 characters.' );
	}
	$scopes = array_values( array_unique( array_map( 'strval', $scopes ) ) );
	if ( [] === $scopes || [] !== array_diff( $scopes, WP_API_TOKEN_SCOPES ) ) {
		return new WP_Error( 'invalid_scope', 'Choose at least one valid scope.' );
	}
	$has_posts = [] !== array_filter( $scopes, static fn( string $s ): bool => str_starts_with( $s, 'posts:' ) );
	$has_pages = [] !== array_filter( $scopes, static fn( string $s ): bool => str_starts_with( $s, 'pages:' ) );
	foreach ( [ 'posts', 'pages' ] as $kind ) {
		if ( in_array( $kind . ':publish', $scopes, true ) && ! in_array( $kind . ':write', $scopes, true ) ) {
			return new WP_Error( 'invalid_scope', 'A publish grant requires the matching write grant.' );
		}
	}
	if ( ! $has_posts && ! $has_pages ) {
		return new WP_Error( 'invalid_scope', 'Choose at least one resource scope.' );
	}
	if ( $days < 1 || $days > 90 ) {
		return new WP_Error( 'invalid_expiry', 'Expiry must be between 1 and 90 days.' );
	}
	$user = $db->get_row( 'SELECT id FROM users WHERE id=:id AND disabled=0', [ ':id' => $user_id ] );
	if ( null === $user ) {
		return new WP_Error( 'invalid_user', 'Credentials can only be issued to an enabled account.' );
	}
	$epoch = wp_api_epoch_read();
	if ( is_wp_error( $epoch ) ) {
		return new WP_Error( 'epoch_unavailable', 'Initialize the API epoch before issuing credentials.' );
	}
	$state = wp_api_state( $db );
	if ( null === $state || (string) ( $state['bound_epoch'] ?? '' ) !== $epoch ) {
		return new WP_Error( 'epoch_unbound', 'The API epoch is not bound. Run the initialization command first.' );
	}
	if ( wp_api_token_active_count( $db, $user_id ) >= WP_API_MAX_TOKENS_PER_USER ) {
		return new WP_Error( 'token_capacity', 'This account already has the maximum number of active credentials.' );
	}
	if ( wp_api_token_active_count( $db ) >= WP_API_MAX_TOKENS_PER_SITE ) {
		return new WP_Error( 'token_capacity', 'This site already has the maximum number of active credentials.' );
	}
	$public_id = bin2hex( random_bytes( 16 ) );
	$secret = random_bytes( 32 );
	$secret_part = rtrim( strtr( base64_encode( $secret ), '+/', '-_' ), '=' );
	$expires = time() + $days * 86400;
	$db->transaction( static function ( WP_SQLite_DB $db ) use ( $public_id, $user_id, $issued_by, $name, $scopes, $secret, $epoch, $expires ): void {
		$db->query( 'INSERT INTO api_tokens(id,user_id,issued_by,name,secret_hash,credential_epoch,expires_at) VALUES(:id,:user,:issuer,:name,:hash,:epoch,:expires)', [
			':id'      => $public_id,
			':user'    => $user_id,
			':issuer'  => $issued_by,
			':name'    => $name,
			':hash'    => hash( 'sha256', $secret ),
			':epoch'   => $epoch,
			':expires' => $expires
		] );
		foreach ( $scopes as $scope ) {
			$db->query( 'INSERT INTO api_token_scopes(token_id,scope) VALUES(:token,:scope)', [
				':token' => $public_id,
				':scope' => $scope
			] );
		}
	} );
	wp_audit( 'api_token_issued', $user_id, [
		'token_id'   => $public_id,
		'name'       => $name,
		'scopes'     => $scopes,
		'expires_at' => $expires
	], $issued_by );
	return [
		'token'      => 'wpn1.' . $public_id . '.' . $secret_part,
		'id'         => $public_id,
		'expires_at' => $expires
	];
}

/** Strict wire-format parse: canonical base64url secret of exactly 32 bytes. */
function wp_api_token_parse( string $token ): ?array
{
	if ( ! preg_match( '/^wpn1\.([0-9a-f]{32})\.([A-Za-z0-9_-]{43})$/D', $token, $matches ) ) {
		return null;
	}
	$secret = base64_decode( strtr( $matches[2], '-_', '+/' ), true );
	if ( false === $secret || 32 !== strlen( $secret ) ) {
		return null;
	}
	if ( rtrim( strtr( base64_encode( $secret ), '+/', '-_' ), '=' ) !== $matches[2] ) {
		return null;
	}
	return [
		'id'     => $matches[1],
		'secret' => $secret
	];
}

function wp_api_token_scopes( WP_SQLite_DB $db, string $token_id ): array
{
	return array_map( static fn( array $row ): string => (string) $row['scope'], $db->get_results( 'SELECT scope FROM api_token_scopes WHERE token_id=:token ORDER BY scope', [ ':token' => $token_id ] ) );
}

/**
 * Authenticate a Bearer credential against current state. Returns the API
 * context (token, account, scopes, epoch) or a WP_Error coded for the problem
 * mapping: authentication_required, invalid_token, epoch_mismatch.
 */
function wp_api_authenticate( WP_SQLite_DB $db, string $epoch ): array|WP_Error
{
	$header = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' );
	if ( '' === $header && function_exists( 'apache_request_headers' ) ) {
		foreach ( (array) apache_request_headers() as $name => $value ) {
			if ( 'authorization' === strtolower( (string) $name ) ) {
				$header = (string) $value;
				break;
			}
		}
	}
	if ( ! preg_match( '/^Bearer (\S+)$/D', trim( $header ), $matches ) ) {
		return new WP_Error( 'authentication_required', 'A Bearer integration token is required.' );
	}
	$parsed = wp_api_token_parse( $matches[1] );
	if ( null === $parsed ) {
		return new WP_Error( 'invalid_token', 'The integration token is not valid.' );
	}
	$row = $db->get_row( 'SELECT * FROM api_tokens WHERE id=:id', [ ':id' => $parsed['id'] ] );
	if ( null === $row || ! hash_equals( (string) $row['secret_hash'], hash( 'sha256', $parsed['secret'] ) ) ) {
		return new WP_Error( 'invalid_token', 'The integration token is not valid.' );
	}
	if ( null !== $row['revoked_at'] || (int) $row['expires_at'] <= time() ) {
		return new WP_Error( 'invalid_token', 'The integration token is not valid.' );
	}
	if ( (string) $row['credential_epoch'] !== $epoch ) {
		return new WP_Error( 'invalid_token', 'The integration token is not valid.' );
	}
	$user = $db->get_row( 'SELECT id,disabled FROM users WHERE id=:id', [ ':id' => (int) $row['user_id'] ] );
	if ( null === $user || 0 !== (int) $user['disabled'] ) {
		return new WP_Error( 'invalid_token', 'The integration token is not valid.' );
	}
	return [
		'token_id' => (string) $row['id'],
		'user_id'  => (int) $row['user_id'],
		'scopes'   => wp_api_token_scopes( $db, (string) $row['id'] ),
		'epoch'    => $epoch,
		'token'    => $row
	];
}

/**
 * The in-transaction authority recheck for mutations: enabled state, epoch
 * binding, token validity and scopes, and the account's enabled state are all
 * re-read on the transaction's own connection, so a revocation that commits
 * first wins and this mutation rolls back.
 */
function wp_api_authority_recheck( WP_SQLite_DB $db, array $ctx, array $required_scopes ): true|WP_Error
{
	$state = $db->get_row( 'SELECT * FROM api_security_state WHERE singleton=1' );
	if ( null === $state || 1 !== (int) $state['enabled'] ) {
		return new WP_Error( 'api_disabled', 'The editorial API is disabled.' );
	}
	if ( (string) $state['bound_epoch'] !== (string) $ctx['epoch'] ) {
		return new WP_Error( 'invalid_token', 'The integration token is not valid.' );
	}
	$row = $db->get_row( 'SELECT * FROM api_tokens WHERE id=:id', [ ':id' => (string) $ctx['token_id'] ] );
	if ( null === $row || null !== $row['revoked_at'] || (int) $row['expires_at'] <= time() || (string) $row['credential_epoch'] !== (string) $ctx['epoch'] ) {
		return new WP_Error( 'invalid_token', 'The integration token is not valid.' );
	}
	$scopes = wp_api_token_scopes( $db, (string) $row['id'] );
	if ( [] !== array_diff( $required_scopes, $scopes ) ) {
		return new WP_Error( 'api_scope', 'The integration token lacks a required scope.' );
	}
	$user = $db->get_row( 'SELECT id FROM users WHERE id=:id AND disabled=0', [ ':id' => (int) $row['user_id'] ] );
	if ( null === $user || (int) $user['id'] !== (int) $ctx['user_id'] ) {
		return new WP_Error( 'invalid_token', 'The integration token is not valid.' );
	}
	return true;
}

function wp_api_revoke_token( WP_SQLite_DB $db, string $token_id, string $reason, int $actor ): bool
{
	$row = $db->get_row( 'SELECT id FROM api_tokens WHERE id=:id AND revoked_at IS NULL', [ ':id' => $token_id ] );
	if ( null === $row ) {
		return false;
	}
	$db->query( 'UPDATE api_tokens SET revoked_at=unixepoch(),revoke_reason=:reason WHERE id=:id', [
		':reason' => $reason,
		':id'     => $token_id
	] );
	wp_audit( 'api_token_revoked', null, [
		'token_id' => $token_id,
		'reason'   => $reason
	], $actor );
	return true;
}

function wp_api_revoke_user_tokens( WP_SQLite_DB $db, int $user_id, string $reason ): void
{
	$db->query( 'UPDATE api_tokens SET revoked_at=unixepoch(),revoke_reason=:reason WHERE user_id=:user AND revoked_at IS NULL', [
		':reason' => $reason,
		':user'   => $user_id
	] );
}

function wp_api_revoke_all_tokens( WP_SQLite_DB $db, string $reason ): void
{
	$db->query( 'UPDATE api_tokens SET revoked_at=unixepoch(),revoke_reason=:reason WHERE revoked_at IS NULL', [ ':reason' => $reason ] );
}

/* ----------------------------------------------------------------- receipts */

/** Length-framed fingerprint of the exact request bytes and routing identity. Byte-different retries conflict even when the JSON is equivalent. */
function wp_api_request_fingerprint( string $method, string $operation, string $kind, string $target, string $raw_body ): string
{
	$frame = static fn( string $part ): string => strlen( $part ) . ':' . $part;
	return hash( 'sha256', implode( '|', array_map( $frame, [ WP_API_CONTRACT_VERSION, $method, $operation, $kind, $target, $raw_body ] ) ) );
}

function wp_api_receipt_find( WP_SQLite_DB $db, string $token_id, string $key_hash ): ?array
{
	return $db->get_row( 'SELECT * FROM api_mutation_receipts WHERE token_id=:token AND key_hash=:key', [
		':token' => $token_id,
		':key'   => $key_hash
	] );
}

/**
 * Decide a stored receipt for this request before any new mutation work:
 * returns 'replay' with the stored response, or a WP_Error for a key conflict
 * or an expired replay window. Read authority is enforced by the caller first.
 */
function wp_api_receipt_gate( array $receipt, string $request_hash ): array|WP_Error
{
	if ( ! hash_equals( (string) $receipt['request_hash'], $request_hash ) ) {
		return new WP_Error( 'idempotency_conflict', 'This Idempotency-Key was already used with a different request.' );
	}
	if ( 'replayable' !== (string) $receipt['state'] || (int) $receipt['replay_until'] <= time() || null === $receipt['response_json'] ) {
		return new WP_Error( 'idempotency_expired', 'The replay window for this Idempotency-Key has expired. Reconcile the resource state before retrying under a new key.' );
	}
	$body = json_decode( (string) $receipt['response_json'], true );
	if ( ! is_array( $body ) ) {
		return new WP_Error( 'idempotency_expired', 'The stored receipt for this Idempotency-Key is no longer readable.' );
	}
	return [
		'replayed' => true,
		'status'   => (int) $receipt['http_status'],
		'location' => $receipt['location'],
		'result'   => $body
	];
}

function wp_api_receipt_capacity_error( WP_SQLite_DB $db, string $token_id ): ?WP_Error
{
	$per_token = (int) $db->get_row( 'SELECT COUNT(*) AS n FROM api_mutation_receipts WHERE token_id=:token', [ ':token' => $token_id ] )['n'];
	if ( $per_token >= WP_API_MAX_RECEIPTS_PER_TOKEN ) {
		return new WP_Error( 'receipt_capacity', 'The receipt capacity for this credential is exhausted.' );
	}
	$per_site = (int) $db->get_row( 'SELECT COUNT(*) AS n FROM api_mutation_receipts' )['n'];
	if ( $per_site >= WP_API_MAX_RECEIPTS_PER_SITE ) {
		return new WP_Error( 'receipt_capacity', 'The receipt capacity for this site is exhausted.' );
	}
	return null;
}

function wp_api_receipt_store( WP_SQLite_DB $db, array $ctx, array $result, int $http_status, ?string $location ): void
{
	$json = (string) wp_json_encode( $result );
	if ( strlen( $json ) > 4096 ) {
		throw new RuntimeException( 'The committed-result envelope exceeds the receipt limit.' );
	}
	$db->query( 'INSERT INTO api_mutation_receipts(token_id,key_hash,request_hash,resource_kind,operation,resource_id,committed_revision,committed_status,mutation_id,http_status,response_json,location,replay_until) VALUES(:token,:key,:request,:kind,:operation,:resource,:revision,:status,:mutation,:http,:response,:location,unixepoch()+86400)', [
		':token'     => (string) $ctx['token_id'],
		':key'       => (string) $ctx['key_hash'],
		':request'   => (string) $ctx['request_hash'],
		':kind'      => (string) $ctx['kind'],
		':operation' => (string) $ctx['operation'],
		':resource'  => (int) $result['id'],
		':revision'  => (int) $result['revision'],
		':status'    => (string) $result['status'],
		':mutation'  => (string) $result['mutation_id'],
		':http'      => $http_status,
		':response'  => $json,
		':location'  => $location
	] );
}

/* ----------------------------------------------------------------- problems */

function wp_api_problem( int $status, string $code, string $title, ?string $detail = null, ?array $errors = null, array $headers = [] ): array
{
	$body = [
		'type'       => 'about:blank',
		'title'      => $title,
		'status'     => $status,
		'code'       => $code,
		'request_id' => (string) ( $GLOBALS['wp_api_request_id'] ?? wp_api_gateway_uuid() )
	];
	if ( null !== $detail && '' !== $detail ) {
		$body['detail'] = $detail;
	}
	if ( null !== $errors && [] !== $errors ) {
		$body['errors'] = array_slice( $errors, 0, 50 );
	}
	return [
		'status'  => $status,
		'body'    => $body,
		'headers' => $headers,
		'problem' => true
	];
}

function wp_api_success( int $status, array $body, array $headers = [] ): array
{
	return [
		'status'  => $status,
		'body'    => $body,
		'headers' => $headers,
		'problem' => false
	];
}

/** Map a WP_Error from the API/domain layer onto the stable problem contract. */
function wp_api_problem_from_error( WP_Error $error ): array
{
	$code = $error->get_error_code();
	$message = $error->get_error_message();
	return match ( $code ) {
		'authentication_required' => wp_api_problem( 401, 'authentication-required', 'Authentication is required.', $message, null, [ 'WWW-Authenticate' => 'Bearer' ] ),
		'invalid_token', 'api_epoch' => wp_api_problem( 401, 'invalid-token', 'The integration token is not valid.', null, null, [ 'WWW-Authenticate' => 'Bearer' ] ),
		'api_scope' => wp_api_problem( 403, 'insufficient-scope', 'The integration token lacks a required scope.', $message ),
		'unauthorized' => wp_api_problem( 403, 'insufficient-scope', 'The account does not have permission for this operation.', $message ),
		'not_found' => wp_api_problem( 404, 'not-found', 'The resource was not found.' ),
		'concurrency_conflict' => wp_api_problem( 409, 'revision-conflict', 'The resource changed since the expected revision.', $message ),
		'slug_conflict' => wp_api_problem( 409, 'slug-conflict', 'The requested slug is already in use.', $message ),
		'status_conflict' => wp_api_problem( 409, 'status-conflict', 'The resource status does not allow this operation.', $message ),
		'idempotency_conflict' => wp_api_problem( 409, 'idempotency-key-conflict', 'The Idempotency-Key conflicts with an earlier request.', $message ),
		'idempotency_expired' => wp_api_problem( 409, 'idempotency-window-expired', 'The replay window for this Idempotency-Key has expired.', $message ),
		'receipt_capacity' => wp_api_problem( 429, 'receipt-capacity-exceeded', 'Receipt capacity is exhausted.', $message ),
		'rate_limited' => wp_api_problem( 429, 'rate-limited', 'Too many requests.', $message, null, [ 'Retry-After' => (string) max( 1, (int) $error->get_error_data() ) ] ),
		'api_disabled' => wp_api_problem( 503, 'credentials-unavailable', 'The editorial API is disabled.' ),
		'rate_unavailable' => wp_api_problem( 503, 'storage-unavailable', 'Request admission is unavailable.', $message ),
		'empty_title', 'invalid_status', 'invalid_content', 'invalid_media', 'invalid_terms', 'invalid_template', 'invalid_metadata', 'invalid_arguments', 'validation_failed' => wp_api_problem( 422, 'validation-failed', 'The request did not pass validation.', $message ),
		default => wp_api_problem( 503, 'storage-unavailable', 'The operation could not be completed.', WP_DEBUG ? $message : null )
	};
}

function wp_api_send( array $response ): void
{
	$request_id = (string) ( $GLOBALS['wp_api_request_id'] ?? wp_api_gateway_uuid() );
	if ( ! headers_sent() ) {
		http_response_code( (int) $response['status'] );
		header( 'Content-Type: ' . ( ! empty( $response['problem'] ) ? 'application/problem+json' : 'application/json' ) );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Request-Id: ' . $request_id );
		foreach ( (array) ( $response['headers'] ?? [] ) as $name => $value ) {
			if ( null !== $value && '' !== (string) $value ) {
				header( $name . ': ' . $value );
			}
		}
	}
	echo wp_json_encode( $response['body'] );
}

/* ------------------------------------------------------------ representations */

function wp_api_entry_url( array $row ): ?string
{
	return 'published' === (string) $row['status'] ? wp_post_url( $row ) : null;
}

function wp_api_entry_summary( array $row ): array
{
	return [
		'id'           => (int) $row['id'],
		'type'         => (string) $row['post_type'],
		'title'        => (string) $row['title'],
		'slug'         => (string) $row['slug'],
		'status'       => (string) $row['status'],
		'revision'     => (int) $row['revision'],
		'published_at' => null === $row['published_at'] ? null : (int) $row['published_at'],
		'updated_at'   => (int) $row['updated_at'],
		'url'          => wp_api_entry_url( $row )
	];
}

function wp_api_entry_full( WP_SQLite_DB $db, array $row ): array
{
	$entry = wp_api_entry_summary( $row );
	$content = json_decode( (string) $row['content_raw'], true );
	if ( ! is_array( $content ) || ! isset( $content['blocks'] ) ) {
		$normalized = wp_normalize_content( (string) $row['content_raw'] );
		$content = is_wp_error( $normalized ) ? [
			'version' => 1,
			'blocks'  => []
		] : json_decode( (string) $normalized, true );
	}
	$metadata = isset( $row['metadata'] ) ? json_decode( (string) $row['metadata'], true ) : null;
	$metadata = is_array( $metadata ) ? $metadata : [];
	$entry['content'] = $content;
	$entry['excerpt'] = (string) ( $row['excerpt'] ?? '' );
	$entry['featured_media_id'] = null === ( $row['featured_media_id'] ?? null ) ? null : (int) $row['featured_media_id'];
	$entry['social_image_id'] = isset( $metadata['social_image_id'] ) ? (int) $metadata['social_image_id'] : null;
	if ( 'post' === (string) $row['post_type'] ) {
		$entry['term_ids'] = array_map( 'intval', wp_post_term_ids( (int) $row['id'], $db ) );
	} else {
		$entry['page_template'] = (string) ( $metadata['page_template'] ?? 'standard' );
	}
	return $entry;
}

/* ---------------------------------------------------------------- validation */

function wp_api_reserved_slugs(): array
{
	return [ 'blog', 'search', 'preview', 'category', 'tag', 'author', 'site-assets', 'wp-admin', 'wp-content', 'wp-includes', 'var', 'docs', 'vendor', 'tests', 'tools', 'bin', 'documents', 'feed', 'wp-next', 'sitemap.xml', 'robots.txt' ];
}

/** Slug form only: canonical, bounded, unreserved. Collision is a commit-time decision under the write lock. */
function wp_api_slug_form_error( string $slug ): ?array
{
	if ( '' === $slug || strlen( $slug ) > 120 || wp_sanitize_slug( $slug ) !== $slug ) {
		return wp_api_problem( 422, 'invalid-slug', 'The slug is not in canonical form.', 'A supplied slug must be nonempty, at most 120 characters, and already equal to its canonical form.' );
	}
	if ( in_array( $slug, wp_api_reserved_slugs(), true ) || str_starts_with( $slug, 'wp-' ) ) {
		return wp_api_problem( 422, 'reserved-slug', 'The slug is reserved for an application route.' );
	}
	return null;
}

/**
 * Closed-field validation for write payloads. Returns the sparse field map to
 * hand to the mutation candidate builder, or a problem response array.
 */
function wp_api_validate_write( array $data, string $kind, string $operation ): array
{
	$allowed = [ 'title', 'slug', 'content', 'excerpt', 'featured_media_id', 'social_image_id' ];
	$allowed[] = 'post' === $kind ? 'term_ids' : 'page_template';
	if ( 'create' !== $operation ) {
		$allowed[] = 'expected_revision';
	}
	$errors = [];
	foreach ( array_keys( $data ) as $field ) {
		if ( ! in_array( $field, $allowed, true ) ) {
			$errors[] = [
				'path' => (string) $field,
				'code' => 'unexpected-field'
			];
		}
	}
	if ( 'create' === $operation ) {
		foreach ( [ 'title', 'content' ] as $required ) {
			if ( ! array_key_exists( $required, $data ) ) {
				$errors[] = [
					'path' => $required,
					'code' => 'required'
				];
			}
		}
	} elseif ( ! array_key_exists( 'expected_revision', $data ) || ! is_int( $data['expected_revision'] ) || (int) $data['expected_revision'] < 1 ) {
		return wp_api_problem( 400, 'malformed-request', 'A positive integer expected_revision is required.' );
	} elseif ( 'update_draft' === $operation && count( $data ) < 2 ) {
		$errors[] = [
			'path' => '',
			'code' => 'no-changes'
		];
	}
	if ( array_key_exists( 'title', $data ) ) {
		if ( ! is_string( $data['title'] ) || '' === trim( $data['title'] ) || strlen( trim( $data['title'] ) ) > 300 ) {
			$errors[] = [
				'path' => 'title',
				'code' => 'invalid-title'
			];
		}
	}
	if ( array_key_exists( 'slug', $data ) && ! is_string( $data['slug'] ) ) {
		$errors[] = [
			'path' => 'slug',
			'code' => 'invalid-slug'
		];
	}
	if ( array_key_exists( 'content', $data ) ) {
		$content = $data['content'];
		if ( ! is_array( $content ) || 1 !== ( $content['version'] ?? null ) || ! isset( $content['blocks'] ) || ! is_array( $content['blocks'] ) || count( $content['blocks'] ) > 500 ) {
			$errors[] = [
				'path' => 'content',
				'code' => 'invalid-content'
			];
		}
	}
	if ( array_key_exists( 'excerpt', $data ) && ( ! is_string( $data['excerpt'] ) || strlen( $data['excerpt'] ) > 2000 ) ) {
		$errors[] = [
			'path' => 'excerpt',
			'code' => 'invalid-excerpt'
		];
	}
	foreach ( [ 'featured_media_id', 'social_image_id' ] as $field ) {
		if ( array_key_exists( $field, $data ) && null !== $data[ $field ] && ( ! is_int( $data[ $field ] ) || (int) $data[ $field ] < 1 ) ) {
			$errors[] = [
				'path' => $field,
				'code' => 'invalid-media'
			];
		}
	}
	if ( 'post' === $kind && array_key_exists( 'term_ids', $data ) ) {
		$terms = $data['term_ids'];
		if ( ! is_array( $terms ) || count( $terms ) > 100 || count( $terms ) !== count( array_unique( $terms ) ) || [] !== array_filter( $terms, static fn( mixed $term ): bool => ! is_int( $term ) || $term < 1 ) ) {
			$errors[] = [
				'path' => 'term_ids',
				'code' => 'invalid-terms'
			];
		}
	}
	if ( 'page' === $kind && array_key_exists( 'page_template', $data ) && ( ! is_string( $data['page_template'] ) || ! in_array( $data['page_template'], [ 'standard', 'wide', 'landing' ], true ) ) ) {
		$errors[] = [
			'path' => 'page_template',
			'code' => 'invalid-template'
		];
	}
	if ( [] !== $errors ) {
		return wp_api_problem( 422, 'validation-failed', 'The request did not pass validation.', null, $errors );
	}
	if ( array_key_exists( 'slug', $data ) ) {
		$slug_error = wp_api_slug_form_error( (string) $data['slug'] );
		if ( null !== $slug_error ) {
			return $slug_error;
		}
	}
	return [
		'ok'     => true,
		'fields' => $data
	];
}

/** Translate validated API fields into the mutation service's input vocabulary, sparsely: omitted fields stay untouched. */
function wp_api_fields_to_input( array $fields, string $kind ): array
{
	$input = [];
	if ( array_key_exists( 'title', $fields ) ) {
		$input['post_title'] = trim( (string) $fields['title'] );
	}
	if ( array_key_exists( 'slug', $fields ) ) {
		$input['post_name'] = (string) $fields['slug'];
	}
	if ( array_key_exists( 'content', $fields ) ) {
		$input['post_content'] = (string) wp_json_encode( $fields['content'] );
	}
	if ( array_key_exists( 'excerpt', $fields ) ) {
		$input['post_excerpt'] = (string) $fields['excerpt'];
	}
	if ( array_key_exists( 'featured_media_id', $fields ) ) {
		$input['featured_media_id'] = $fields['featured_media_id'];
	}
	if ( array_key_exists( 'social_image_id', $fields ) ) {
		$input['social_image_id'] = null === $fields['social_image_id'] ? 0 : (int) $fields['social_image_id'];
	}
	if ( 'post' === $kind && array_key_exists( 'term_ids', $fields ) ) {
		$input['term_ids'] = array_map( 'intval', $fields['term_ids'] );
	}
	if ( 'page' === $kind && array_key_exists( 'page_template', $fields ) ) {
		$input['page_template'] = (string) $fields['page_template'];
	}
	return $input;
}

/* ------------------------------------------------------- activation checks */

/**
 * Every public path the front controller can resolve ahead of the API mount,
 * across the current and all recorded permalink structures. Activation is
 * refused while any published Post path claims the mounted namespace; nothing
 * is moved or renamed to clear a conflict.
 */
function wp_api_route_conflicts( WP_SQLite_DB $db ): array
{
	$conflicts = [];
	$posts = $db->get_results( "SELECT * FROM posts WHERE status='published' AND post_type='post'" );
	foreach ( wp_permalink_structures() as $structure ) {
		$segments = explode( '/', trim( $structure, '/' ) );
		if ( 'wp-next' === $segments[0] ) {
			$conflicts[] = 'Permalink structure ' . $structure . ' begins with the reserved wp-next segment.';
		}
		if ( '%author%' === $segments[0] && 'v1' === ( $segments[1] ?? '' ) ) {
			$author = $db->get_row( "SELECT id FROM users WHERE LOWER(username)='wp-next' LIMIT 1" );
			if ( null !== $author ) {
				$conflicts[] = 'Permalink structure ' . $structure . ' can route the wp-next author under the API mount.';
			}
		}
		foreach ( $posts as $post ) {
			$path = trim( wp_permalink_path( $post, $structure ), '/' );
			if ( str_starts_with( $path, 'wp-next/v1' ) ) {
				$conflicts[] = 'Published Post #' . (int) $post['id'] . ' resolves at /' . $path . '/ under structure ' . $structure . '.';
			}
			if ( count( $conflicts ) >= 20 ) {
				return $conflicts;
			}
		}
	}
	return $conflicts;
}

/** Conflicts a proposed permalink structure would create, for the save-time guard. */
function wp_api_structure_conflicts( WP_SQLite_DB $db, string $structure ): array
{
	$segments = explode( '/', trim( $structure, '/' ) );
	if ( 'wp-next' === $segments[0] ) {
		return [ 'The structure begins with the reserved wp-next segment.' ];
	}
	$conflicts = [];
	if ( '%author%' === $segments[0] && 'v1' === ( $segments[1] ?? '' ) && null !== $db->get_row( "SELECT id FROM users WHERE LOWER(username)='wp-next' LIMIT 1" ) ) {
		$conflicts[] = 'The structure can route the wp-next author under the API mount.';
	}
	foreach ( $db->get_results( "SELECT * FROM posts WHERE status='published' AND post_type='post'" ) as $post ) {
		$path = trim( wp_permalink_path( $post, $structure ), '/' );
		if ( str_starts_with( $path, 'wp-next/v1' ) ) {
			$conflicts[] = 'Published Post #' . (int) $post['id'] . ' would resolve at /' . $path . '/.';
		}
		if ( count( $conflicts ) >= 20 ) {
			break;
		}
	}
	return $conflicts;
}

/* ------------------------------------------------------------ operator ops */

function wp_api_initialize( WP_SQLite_DB $db ): array|WP_Error
{
	$existing = wp_api_epoch_read();
	if ( is_wp_error( $existing ) ) {
		if ( 'epoch_missing' !== $existing->get_error_code() ) {
			return $existing;
		}
		$epoch = wp_api_epoch_generate();
		if ( ! wp_api_epoch_write( $epoch ) ) {
			return new WP_Error( 'epoch_write_failed', 'The API epoch file could not be written. Check WP_API_EPOCH_PATH and its directory permissions.' );
		}
	} else {
		$epoch = $existing;
	}
	$state = wp_api_state( $db );
	if ( null === $state ) {
		return new WP_Error( 'storage_unavailable', 'The API tables are missing. Run the database upgrade first.' );
	}
	if ( (string) ( $state['bound_epoch'] ?? '' ) !== $epoch && wp_api_token_active_count( $db ) > 0 ) {
		return new WP_Error( 'active_credentials', 'Active API credentials exist under a different epoch. Revoke them before rebinding.' );
	}
	$db->transaction( static function ( WP_SQLite_DB $db ) use ( $epoch ): void {
		$db->query( 'UPDATE api_security_state SET bound_epoch=:epoch WHERE singleton=1', [ ':epoch' => $epoch ] );
	} );
	wp_audit( 'api_epoch_initialized', null, [], 0 );
	return [
		'epoch' => $epoch,
		'bound' => true
	];
}

/** Replace the external epoch first, then bind and revoke: a crash between the two leaves a mismatch that refuses every credential. */
function wp_api_rotate_epoch( WP_SQLite_DB $db ): array|WP_Error
{
	$lock = wp_api_epoch_lock( true );
	if ( false === $lock ) {
		return new WP_Error( 'epoch_lock', 'The API epoch lock could not be acquired.' );
	}
	try {
		$epoch = wp_api_epoch_generate();
		if ( ! wp_api_epoch_write( $epoch ) ) {
			return new WP_Error( 'epoch_write_failed', 'The API epoch file could not be replaced.' );
		}
		$db->transaction( static function ( WP_SQLite_DB $db ) use ( $epoch ): void {
			$db->query( 'UPDATE api_security_state SET bound_epoch=:epoch WHERE singleton=1', [ ':epoch' => $epoch ] );
			wp_api_revoke_all_tokens( $db, 'epoch_rotation' );
		} );
		wp_audit( 'api_epoch_rotated', null, [], 0 );
		return [ 'epoch' => $epoch ];
	} finally {
		wp_api_epoch_unlock( $lock );
	}
}

function wp_api_set_enabled( WP_SQLite_DB $db, bool $enable, int $actor ): true|WP_Error
{
	if ( ! $enable ) {
		$db->transaction( static function ( WP_SQLite_DB $db ): void {
			$db->query( 'UPDATE api_security_state SET enabled=0 WHERE singleton=1' );
			wp_api_revoke_all_tokens( $db, 'api_disabled' );
		} );
		wp_audit( 'api_disabled', null, [], $actor );
		return true;
	}
	$state = wp_api_state( $db );
	$epoch = wp_api_epoch_read();
	if ( null === $state || is_wp_error( $epoch ) || (string) ( $state['bound_epoch'] ?? '' ) !== $epoch ) {
		return new WP_Error( 'epoch_unbound', 'Initialize and bind the API epoch before enabling the API.' );
	}
	if ( ! wp_api_base_path_consistent() ) {
		return new WP_Error( 'base_path_mismatch', 'WP_API_BASE_PATH does not match the configured site base path.' );
	}
	$conflicts = wp_api_route_conflicts( $db );
	if ( [] !== $conflicts ) {
		return new WP_Error( 'route_conflicts', 'Route conflicts block API activation: ' . implode( ' ', $conflicts ) );
	}
	$db->transaction( static function ( WP_SQLite_DB $db ): void {
		$db->query( 'UPDATE api_security_state SET enabled=1 WHERE singleton=1' );
	} );
	wp_audit( 'api_enabled', null, [], $actor );
	return true;
}

/* ---------------------------------------------------------------- dispatch */

function wp_api_dispatch( array $segments ): void
{
	global $wpdb, $wp_post_mutation_service;
	$GLOBALS['wp_api_request_id'] = wp_api_gateway_uuid();
	try {
		if ( null === $wpdb || ! wp_api_tables_exist( $wpdb ) ) {
			wp_api_send( wp_api_problem( 503, 'storage-unavailable', 'The editorial API storage is unavailable.' ) );
			return;
		}
		$state = wp_api_state( $wpdb );
		if ( null === $state || 1 !== (int) $state['enabled'] ) {
			wp_api_send( wp_api_problem( 503, 'credentials-unavailable', 'The editorial API is disabled.' ) );
			return;
		}
		$lock = wp_api_epoch_lock( false );
		if ( false === $lock ) {
			wp_api_send( wp_api_problem( 503, 'credentials-unavailable', 'The API credential state is unavailable.' ) );
			return;
		}
		try {
			$epoch = wp_api_epoch_read();
			if ( is_wp_error( $epoch ) || (string) ( $state['bound_epoch'] ?? '' ) !== $epoch ) {
				wp_api_send( wp_api_problem( 503, 'credentials-unavailable', 'The API credential state is unavailable.' ) );
				return;
			}
			wp_api_dispatch_routed( $segments, (string) $epoch, $wp_post_mutation_service );
		} finally {
			wp_api_epoch_unlock( $lock );
		}
	} catch ( Throwable $error ) {
		error_log( 'Editorial API: ' . $error->getMessage() );
		wp_api_send( wp_api_problem( 503, 'storage-unavailable', 'The editorial API could not complete the request.' ) );
	}
}

function wp_api_dispatch_routed( array $segments, string $epoch, ?WP_Post_Mutation_Service $mutation_service ): void
{
	global $wpdb;
	$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
	$kind = null;
	$operation = null;
	$id = null;
	$allowed_methods = [];
	if ( 1 === count( $segments ) && in_array( $segments[0], [ 'posts', 'pages' ], true ) ) {
		$kind = 'posts' === $segments[0] ? 'post' : 'page';
		$operation = 'GET' === $method ? 'list' : ( 'POST' === $method ? 'create' : null );
		$allowed_methods = [ 'GET', 'POST' ];
	} elseif ( 2 === count( $segments ) && in_array( $segments[0], [ 'posts', 'pages' ], true ) && preg_match( '/^[1-9][0-9]*$/D', $segments[1] ) ) {
		$kind = 'posts' === $segments[0] ? 'post' : 'page';
		$id = (int) $segments[1];
		$operation = 'GET' === $method ? 'item' : ( 'PATCH' === $method ? 'update_draft' : null );
		$allowed_methods = [ 'GET', 'PATCH' ];
	} elseif ( 3 === count( $segments ) && in_array( $segments[0], [ 'posts', 'pages' ], true ) && preg_match( '/^[1-9][0-9]*$/D', $segments[1] ) && 'publish' === $segments[2] ) {
		$kind = 'posts' === $segments[0] ? 'post' : 'page';
		$id = (int) $segments[1];
		$operation = 'POST' === $method ? 'publish' : null;
		$allowed_methods = [ 'POST' ];
	}
	if ( null === $kind ) {
		wp_api_send( wp_api_problem( 404, 'not-found', 'The API route was not found.' ) );
		return;
	}
	if ( null === $operation ) {
		wp_api_send( wp_api_problem( 405, 'method-not-allowed', 'This method is not allowed on this route.', null, null, [ 'Allow' => implode( ', ', $allowed_methods ) ] ) );
		return;
	}
	$plural = 'post' === $kind ? 'posts' : 'pages';
	$required_scopes = match ( $operation ) {
		'list', 'item' => [ $plural . ':read' ],
		'create', 'update_draft' => [ $plural . ':write' ],
		'publish' => [ $plural . ':write', $plural . ':publish' ]
	};
	$peer = wp_api_rate_admit( $wpdb, 'peer_auth', wp_api_peer_material() );
	if ( is_wp_error( $peer ) ) {
		wp_api_send( wp_api_problem_from_error( $peer ) );
		return;
	}
	$ctx = wp_api_authenticate( $wpdb, $epoch );
	if ( is_wp_error( $ctx ) ) {
		wp_api_send( wp_api_problem_from_error( $ctx ) );
		return;
	}
	// The request acts as the credential's account: ambient save-time checks
	// (block normalization, asset access) must see that actor, not a guest.
	// Transactional rechecks still run against the token context explicitly.
	wp_set_current_user( (int) $ctx['user_id'] );
	if ( [] !== array_diff( $required_scopes, $ctx['scopes'] ) ) {
		wp_api_send( wp_api_problem( 403, 'insufficient-scope', 'The integration token lacks a required scope.', 'This operation requires: ' . implode( ', ', $required_scopes ) . '.' ) );
		return;
	}
	$rate = wp_api_rate_admit( $wpdb, in_array( $operation, [ 'list', 'item' ], true ) ? 'token_read' : 'token_write', (string) $ctx['token_id'] );
	if ( is_wp_error( $rate ) ) {
		wp_api_send( wp_api_problem_from_error( $rate ) );
		return;
	}
	$response = match ( $operation ) {
		'list' => wp_api_list( $ctx, $kind ),
		'item' => wp_api_item( $ctx, $kind, (int) $id ),
		'create' => wp_api_write( $ctx, $kind, 'create', null, $mutation_service ),
		'update_draft' => wp_api_write( $ctx, $kind, 'update_draft', (int) $id, $mutation_service ),
		'publish' => wp_api_write( $ctx, $kind, 'publish', (int) $id, $mutation_service )
	};
	wp_api_touch_token( $ctx );
	wp_api_send( $response );
}

function wp_api_touch_token( array $ctx ): void
{
	global $wpdb;
	try {
		$last = (int) ( $ctx['token']['last_used_at'] ?? 0 );
		if ( $last < time() - 3600 ) {
			$wpdb->query( 'UPDATE api_tokens SET last_used_at=unixepoch() WHERE id=:id', [ ':id' => (string) $ctx['token_id'] ] );
		}
	} catch ( Throwable ) {
		// Last-used is telemetry; never fail a completed request over it.
	}
}

/* ------------------------------------------------------------------- reads */

function wp_api_visible_row( array $ctx, string $kind, int $id ): ?array
{
	global $wpdb;
	$row = $wpdb->get_row( 'SELECT * FROM posts WHERE id=:id AND post_type=:type', [
		':id'   => $id,
		':type' => $kind
	] );
	if ( null === $row || 'trashed' === (string) $row['status'] ) {
		return null;
	}
	return user_can( (int) $ctx['user_id'], 'edit_post', $id ) ? $row : null;
}

function wp_api_item( array $ctx, string $kind, int $id ): array
{
	global $wpdb;
	if ( [] !== $_GET ) {
		return wp_api_problem( 400, 'malformed-request', 'This route accepts no query parameters.' );
	}
	$row = wp_api_visible_row( $ctx, $kind, $id );
	if ( null === $row ) {
		return wp_api_problem( 404, 'not-found', 'The resource was not found.' );
	}
	return wp_api_success( 200, wp_api_entry_full( $wpdb, $row ) );
}

function wp_api_list( array $ctx, string $kind ): array
{
	global $wpdb;
	foreach ( array_keys( $_GET ) as $key ) {
		if ( ! in_array( $key, [ 'limit', 'cursor', 'status' ], true ) ) {
			return wp_api_problem( 400, 'malformed-request', 'Unsupported query parameter: ' . $key . '.' );
		}
	}
	$limit = 20;
	if ( isset( $_GET['limit'] ) ) {
		$raw_limit = wp_input( $_GET, 'limit' );
		if ( ! preg_match( '/^[0-9]+$/D', $raw_limit ) || (int) $raw_limit < 1 || (int) $raw_limit > 100 ) {
			return wp_api_problem( 400, 'malformed-request', 'The limit must be an integer between 1 and 100.' );
		}
		$limit = (int) $raw_limit;
	}
	$status = wp_input( $_GET, 'status', 'all' );
	if ( ! in_array( $status, [ 'all', 'draft', 'published', 'archived' ], true ) ) {
		return wp_api_problem( 400, 'malformed-request', 'The status filter is not recognized.' );
	}
	$binding = substr( hash( 'sha256', (string) $ctx['user_id'] . '|' . $kind . '|' . $status ), 0, 16 );
	$anchor = [
		'u' => PHP_INT_MAX,
		'i' => PHP_INT_MAX
	];
	if ( isset( $_GET['cursor'] ) ) {
		$decoded = base64_decode( strtr( wp_input( $_GET, 'cursor' ), '-_', '+/' ), true );
		$cursor = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $cursor ) || ! isset( $cursor['u'], $cursor['i'] ) || ( $cursor['b'] ?? '' ) !== $binding || ! is_int( $cursor['u'] ) || ! is_int( $cursor['i'] ) ) {
			return wp_api_problem( 400, 'invalid-cursor', 'The pagination cursor is not valid for this list.' );
		}
		$anchor = [
			'u' => (int) $cursor['u'],
			'i' => (int) $cursor['i']
		];
	}
	$where = 'post_type=:type AND (updated_at<:u OR (updated_at=:u AND id<:i))';
	$params = [ ':type' => $kind ];
	if ( 'all' === $status ) {
		$where .= " AND status IN ('draft','published','archived')";
	} else {
		$where .= ' AND status=:status';
		$params[':status'] = $status;
	}
	// Permission is applied row by row before pagination; the scan is bounded
	// and the cursor always advances past examined rows, visible or not.
	$visible = [];
	$exhausted = false;
	$scanned = 0;
	while ( $scanned < 1200 && count( $visible ) <= $limit ) {
		$batch = $wpdb->get_results( 'SELECT * FROM posts WHERE ' . $where . ' ORDER BY updated_at DESC,id DESC LIMIT 300', $params + [
			':u' => $anchor['u'],
			':i' => $anchor['i']
		] );
		if ( [] === $batch ) {
			$exhausted = true;
			break;
		}
		foreach ( $batch as $row ) {
			++$scanned;
			$anchor = [
				'u' => (int) $row['updated_at'],
				'i' => (int) $row['id']
			];
			if ( user_can( (int) $ctx['user_id'], 'edit_post', (int) $row['id'] ) ) {
				$visible[] = $row;
				if ( count( $visible ) > $limit ) {
					break;
				}
			}
		}
		if ( count( $batch ) < 300 ) {
			$exhausted = true;
		}
	}
	$page = array_slice( $visible, 0, $limit );
	$next = null;
	if ( count( $visible ) > $limit ) {
		$last = $page[ count( $page ) - 1 ];
		$next = [
			'u' => (int) $last['updated_at'],
			'i' => (int) $last['id']
		];
	} elseif ( ! $exhausted ) {
		$next = $anchor;
	}
	$cursor_out = null;
	if ( null !== $next ) {
		$cursor_out = rtrim( strtr( base64_encode( (string) wp_json_encode( [
			'u' => $next['u'],
			'i' => $next['i'],
			'b' => $binding
		] ) ), '+/', '-_' ), '=' );
	}
	return wp_api_success( 200, [
		'items'       => array_map( 'wp_api_entry_summary', $page ),
		'next_cursor' => $cursor_out
	] );
}

/* ------------------------------------------------------------------ writes */

function wp_api_write( array $ctx, string $kind, string $operation, ?int $id, ?WP_Post_Mutation_Service $mutation_service ): array
{
	if ( null === $mutation_service ) {
		return wp_api_problem( 503, 'storage-unavailable', 'The editorial mutation service is unavailable.' );
	}
	if ( [] !== $_GET ) {
		return wp_api_problem( 400, 'malformed-request', 'Write operations accept no query parameters.' );
	}
	$content_type = strtolower( trim( explode( ';', (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ) )[0] ) );
	if ( 'application/json' !== $content_type ) {
		return wp_api_problem( 415, 'unsupported-media-type', 'Write operations require an application/json body.' );
	}
	$declared = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
	if ( $declared > WP_API_ENVELOPE_LIMIT ) {
		return wp_api_problem( 413, 'request-too-large', 'The request body exceeds the 3 MiB API envelope.' );
	}
	$raw = file_get_contents( 'php://input' );
	$raw = is_string( $raw ) ? $raw : '';
	if ( strlen( $raw ) > WP_API_ENVELOPE_LIMIT ) {
		return wp_api_problem( 413, 'request-too-large', 'The request body exceeds the 3 MiB API envelope.' );
	}
	$key = (string) ( $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '' );
	if ( ! preg_match( '/^[!-~]{16,128}$/D', $key ) ) {
		return wp_api_problem( 400, 'malformed-request', 'A valid Idempotency-Key header (16-128 printable ASCII characters) is required.' );
	}
	$trimmed = ltrim( $raw );
	if ( '' === $trimmed || '{' !== $trimmed[0] ) {
		return wp_api_problem( 400, 'malformed-request', 'The request body must be a JSON object.' );
	}
	$data = json_decode( $raw, true, 32 );
	if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
		return wp_api_problem( 400, 'malformed-request', 'The request body is not valid JSON.' );
	}
	if ( null !== $id && null === wp_api_visible_row( $ctx, $kind, $id ) ) {
		return wp_api_problem( 404, 'not-found', 'The resource was not found.' );
	}
	$validated = wp_api_validate_write( $data, $kind, $operation );
	if ( empty( $validated['ok'] ) ) {
		return $validated;
	}
	$fields = $validated['fields'];
	$input = wp_api_fields_to_input( $fields, $kind );
	$input['post_type'] = $kind;
	$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
	$api_ctx = [
		'token_id'      => (string) $ctx['token_id'],
		'user_id'       => (int) $ctx['user_id'],
		'epoch'         => (string) $ctx['epoch'],
		'scopes'        => $ctx['scopes'],
		'key_hash'      => hash( 'sha256', $key ),
		'request_hash'  => wp_api_request_fingerprint( $method, $operation, $kind, null === $id ? 'create' : (string) $id, $raw ),
		'operation'     => $operation,
		'kind'          => $kind,
		'slug_explicit' => array_key_exists( 'slug', $fields )
	];
	$result = match ( $operation ) {
		'create' => $mutation_service->api_create_draft( $input, (int) $ctx['user_id'], $api_ctx ),
		'update_draft' => $mutation_service->api_update_draft( $input + [
			'ID'                => $id,
			'expected_revision' => (int) $fields['expected_revision']
		], (int) $ctx['user_id'], $api_ctx ),
		'publish' => $mutation_service->publish_with_changes( $input + [
			'ID'                => $id,
			'expected_revision' => (int) $fields['expected_revision']
		], (int) $ctx['user_id'], $api_ctx ),
		default => new WP_Error( 'not_found', 'Unknown operation.' )
	};
	if ( is_wp_error( $result ) ) {
		return wp_api_problem_from_error( $result );
	}
	$headers = [];
	if ( ! empty( $result['location'] ) ) {
		$headers['Location'] = (string) $result['location'];
	}
	$headers['Idempotency-Replayed'] = ! empty( $result['replayed'] ) ? 'true' : 'false';
	return wp_api_success( (int) $result['status'], $result['result'], $headers );
}
