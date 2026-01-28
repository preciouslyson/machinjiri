<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Database;

use PDOStatement;

/**
 * ConnectionInterface defines the contract for database connections
 * 
 * All database connection implementations must follow this contract to ensure
 * consistent database interaction across the application.
 */
interface ConnectionInterface
{
    /**
     * Set database configuration
     * 
     * @param array $config Configuration array
     * @return void
     */
    public static function setConfig(array $config): void;

    /**
     * Get the active connection
     * 
     * @return mixed PDO or MongoDB client
     */
    public static function getInstance();

    /**
     * Execute a query
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return PDOStatement|mixed Query result
     */
    public static function query(string $query, array $params = []);

    /**
     * Execute a select query
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array Query results
     */
    public static function select(string $query, array $params = []): array;

    /**
     * Execute an insert query
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return int|string Last insert ID
     */
    public static function insert(string $query, array $params = []);

    /**
     * Execute an update query
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return int Number of affected rows
     */
    public static function update(string $query, array $params = []): int;

    /**
     * Execute a delete query
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return int Number of affected rows
     */
    public static function delete(string $query, array $params = []): int;

    /**
     * Begin a transaction
     * 
     * @return bool True if transaction started
     */
    public static function beginTransaction(): bool;

    /**
     * Commit current transaction
     * 
     * @return bool True if transaction committed
     */
    public static function commit(): bool;

    /**
     * Rollback current transaction
     * 
     * @return bool True if transaction rolled back
     */
    public static function rollback(): bool;

    /**
     * Check if in a transaction
     * 
     * @return bool True if in transaction
     */
    public static function inTransaction(): bool;

    /**
     * Get database driver
     * 
     * @return string Driver name (mysql, pgsql, sqlite, mongodb, etc.)
     */
    public static function getDriver(): string;

    /**
     * Get grammar instance for current driver
     * 
     * @return object Grammar instance
     */
    public static function getGrammar();

    /**
     * Close the connection
     * 
     * @return void
     */
    public static function close(): void;

    /**
     * Test the connection
     * 
     * @return bool True if connection is valid
     */
    public static function test(): bool;

    /**
     * Get last error message
     * 
     * @return string|null Error message
     */
    public static function getLastError(): ?string;

    /**
     * Escape value for query
     * 
     * @param string $value Value to escape
     * @return string Escaped value
     */
    public static function escape(string $value): string;
}
