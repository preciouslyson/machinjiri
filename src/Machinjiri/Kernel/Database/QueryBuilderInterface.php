<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Database;

/**
 * QueryBuilderInterface defines the contract for building SQL queries
 * 
 * All query builder implementations must follow this contract to ensure
 * consistent query building across the application.
 */
interface QueryBuilderInterface
{
    /**
     * Specify columns to select
     * 
     * @param array $columns Columns to select
     * @return self Fluent interface
     */
    public function select(array $columns = ['*']): self;

    /**
     * Add a WHERE condition
     * 
     * @param string $column Column name
     * @param string $operator Comparison operator
     * @param mixed $value Condition value
     * @return self Fluent interface
     */
    public function where(string $column, string $operator, $value): self;

    /**
     * Add an OR WHERE condition
     * 
     * @param string $column Column name
     * @param string $operator Comparison operator
     * @param mixed $value Condition value
     * @return self Fluent interface
     */
    public function orWhere(string $column, string $operator, $value): self;

    /**
     * Add a WHERE IN condition
     * 
     * @param string $column Column name
     * @param array $values Values to check
     * @return self Fluent interface
     */
    public function whereIn(string $column, array $values): self;

    /**
     * Add a WHERE NOT IN condition
     * 
     * @param string $column Column name
     * @param array $values Values to check
     * @return self Fluent interface
     */
    public function whereNotIn(string $column, array $values): self;

    /**
     * Add a WHERE NULL condition
     * 
     * @param string $column Column name
     * @return self Fluent interface
     */
    public function whereNull(string $column): self;

    /**
     * Add a WHERE NOT NULL condition
     * 
     * @param string $column Column name
     * @return self Fluent interface
     */
    public function whereNotNull(string $column): self;

    /**
     * Add an INNER JOIN
     * 
     * @param string $table Table to join
     * @param string $on Join condition
     * @return self Fluent interface
     */
    public function innerJoin(string $table, string $on): self;

    /**
     * Add a LEFT JOIN
     * 
     * @param string $table Table to join
     * @param string $on Join condition
     * @return self Fluent interface
     */
    public function leftJoin(string $table, string $on): self;

    /**
     * Add a RIGHT JOIN
     * 
     * @param string $table Table to join
     * @param string $on Join condition
     * @return self Fluent interface
     */
    public function rightJoin(string $table, string $on): self;

    /**
     * Add ORDER BY clause
     * 
     * @param string $column Column to order by
     * @param string $direction Sort direction (ASC, DESC)
     * @return self Fluent interface
     */
    public function orderBy(string $column, string $direction = 'ASC'): self;

    /**
     * Add GROUP BY clause
     * 
     * @param string $column Column to group by
     * @return self Fluent interface
     */
    public function groupBy(string $column): self;

    /**
     * Set LIMIT
     * 
     * @param int $limit Number of rows to limit to
     * @return self Fluent interface
     */
    public function limit(int $limit): self;

    /**
     * Set OFFSET
     * 
     * @param int $offset Number of rows to offset by
     * @return self Fluent interface
     */
    public function offset(int $offset): self;

    /**
     * Execute SELECT query
     * 
     * @return array Query results
     */
    public function get(): array;

    /**
     * Get first result
     * 
     * @return array|null First result or null
     */
    public function first(): ?array;

    /**
     * Insert data
     * 
     * @param array $data Data to insert
     * @return int|string Last insert ID
     */
    public function insert(array $data);

    /**
     * Update data
     * 
     * @param array $data Data to update
     * @return int Number of affected rows
     */
    public function update(array $data): int;

    /**
     * Delete data
     * 
     * @return int Number of affected rows
     */
    public function delete(): int;

    /**
     * Count rows
     * 
     * @param string $column Column to count
     * @return int Row count
     */
    public function count(string $column = '*'): int;

    /**
     * Get minimum value
     * 
     * @param string $column Column name
     * @return mixed Minimum value
     */
    public function min(string $column);

    /**
     * Get maximum value
     * 
     * @param string $column Column name
     * @return mixed Maximum value
     */
    public function max(string $column);

    /**
     * Get sum of values
     * 
     * @param string $column Column name
     * @return int|float Sum of values
     */
    public function sum(string $column);

    /**
     * Get average value
     * 
     * @param string $column Column name
     * @return int|float Average value
     */
    public function avg(string $column);

    /**
     * Build the SQL query
     * 
     * @return string SQL query
     */
    public function toSql(): string;

    /**
     * Get query bindings
     * 
     * @return array Query bindings
     */
    public function getBindings(): array;

    /**
     * Create table
     * 
     * @param string $table Table name
     * @param callable $callback Callback to define columns
     * @return bool True if table created
     */
    public static function create(string $table, callable $callback): bool;

    /**
     * Drop table
     * 
     * @param string $table Table name
     * @return bool True if table dropped
     */
    public static function dropTable(string $table): bool;

    /**
     * Alter table
     * 
     * @param string $table Table name
     * @param callable $callback Callback to define alterations
     * @return bool True if table altered
     */
    public static function alterTable(string $table, callable $callback): bool;
}
