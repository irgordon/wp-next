<?php
/** SQLite connection and short write transactions. */
declare( strict_types=1 );
namespace WordPress\Database;
use PDO;
use RuntimeException;
use Throwable;
class Database_Exception extends RuntimeException {
}
class Transaction_Exception extends Database_Exception {
}
class WP_SQLite_DB {
	protected PDO $pdo;
	public function __construct( string $db_path, string $sync_mode = 'NORMAL' )
	{
		if ( PHP_VERSION_ID < 80400 ) {
			throw new Database_Exception( 'PHP 8.4 or newer is required.' );
		}
		$this->pdo = new PDO( 'sqlite:' . $db_path, null, null, [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
		] );
		$this->pdo->exec( 'PRAGMA foreign_keys=ON; PRAGMA busy_timeout=3000; PRAGMA cache_size=-16000;' );
		if ( ':memory:' !== $db_path ) {
			$journal_mode = strtolower( (string) $this->pdo->query( 'PRAGMA journal_mode=WAL' )->fetchColumn() );
			if ( 'wal' !== $journal_mode ) {
				throw new Database_Exception( 'SQLite could not enable WAL mode.' );
			}
		}
		$sync_mode = in_array( $sync_mode, [ 'NORMAL', 'FULL' ], true ) ? $sync_mode : 'NORMAL';
		$this->pdo->exec( 'PRAGMA synchronous=' . $sync_mode );
	}
	/** @phpstan-impure */
	public function is_in_transaction(): bool
	{
		return $this->pdo->inTransaction();
	}
	public function transaction( callable $callback ): mixed
	{
		if ( $this->is_in_transaction() ) {
			throw new Transaction_Exception( 'Nested transactions are not supported.' );
		}
		$this->pdo->exec( 'BEGIN IMMEDIATE' );
		try {
			$value = $callback( $this );
			$this->pdo->commit();
			return $value;
		} catch ( Throwable $error ) {
			if ( $this->is_in_transaction() ) {
				$this->pdo->rollBack();
			}
			throw $error;
		}
	}
	public function query( string $sql, array $parameters = [] ): \PDOStatement
	{
		$stmt = $this->pdo->prepare( $sql );
		$stmt->execute( $parameters );
		return $stmt;
	}
	public function get_row( string $sql, array $parameters = [] ): ?array
	{
		$stmt = $this->query( $sql, $parameters );
		try {
			$row = $stmt->fetch();
			return false === $row ? null : $row;
		} finally {
			$stmt->closeCursor();
		}
	}
	public function get_results( string $sql, array $parameters = [] ): array
	{
		$stmt = $this->query( $sql, $parameters );
		try {
			return $stmt->fetchAll();
		} finally {
			$stmt->closeCursor();
		}
	}
}
