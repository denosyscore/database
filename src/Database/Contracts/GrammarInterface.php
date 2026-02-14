<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Contracts;

interface GrammarInterface
{
    /**
     * Get the grammar's driver name.
     */
    public function getDriverName(): string;

    /**
     * Wrap a value in keyword identifiers (e.g., `column` for MySQL, "column" for PostgreSQL).
     */
    public function wrap(string $value): string;

    /**
     * Wrap a table name in keyword identifiers.
     */
    public function wrapTable(string $table): string;

    /**
     * Compile a select statement.
     *
     * @param array<string, mixed> $components Query components (columns, from, where, etc.)
     * @return string
     */
    public function compileSelect(array $components): string;

    /**
     * Compile an insert statement.
     *
     * @param string $table
     * @param array<string> $columns
     * @return string
     */
    public function compileInsert(string $table, array $columns): string;

    /**
     * Compile an insert statement for multiple rows.
     *
     * @param string $table
     * @param array<string> $columns
     * @param int $rowCount
     * @return string
     */
    public function compileInsertBatch(string $table, array $columns, int $rowCount): string;

    /**
     * Compile an update statement.
     *
     * @param string $table
     * @param array<string> $columns
     * @param string $where
     * @return string
     */
    public function compileUpdate(string $table, array $columns, string $where): string;

    /**
     * Compile a delete statement.
     *
     * @param string $table
     * @param string $where
     * @return string
     */
    public function compileDelete(string $table, string $where): string;

    /**
     * Compile the columns portion of a query.
     *
     * @param array<string> $columns
     * @return string
     */
    public function compileColumns(array $columns): string;

    /**
     * Compile the where clause.
     *
     * @param array<int|string, mixed> $wheres
     * @return string
     */
    public function compileWheres(array $wheres): string;

    /**
     * Compile the order by clause.
     *
     * @param array<array<string, string>> $orders
     * @return string
      * @param array<array<string, string>> $orders
     */
    public function compileOrders(array $orders): string;

    /**
     * Compile the limit clause.
     *
     * @param int|null $limit
     * @param int|null $offset
     * @return string
     */
    public function compileLimit(?int $limit, ?int $offset = null): string;

    /**
     * Compile the join clauses.
     *
     * @param array<array<string, string>> $joins
     * @return string
      * @param array<array<string, string>> $joins
     */
    public function compileJoins(array $joins): string;

    /**
     * Compile the group by clause.
     *
     * @param array<string> $groups
     * @return string
     */
    public function compileGroups(array $groups): string;

    /**
     * Compile the having clause.
     *
     * @param array<array<string, mixed>> $havings
     * @return string
      * @param array<array<string, mixed>> $havings
     */
    public function compileHavings(array $havings): string;

    /**
     * Get the format for storing dates.
     */
    public function getDateFormat(): string;

    /**
     * Compile an upsert (INSERT ... ON DUPLICATE KEY UPDATE / ON CONFLICT) statement.
     *
     * @param string $table
     * @param array<string> $columns The columns to insert
     * @param array<string> $conflictColumns The columns that determine uniqueness
     * @param array<string> $updateColumns The columns to update on conflict
     * @return string
     */
    public function compileUpsert(string $table, array $columns, array $conflictColumns = [], array $updateColumns = []): string;

    /**
     * Compile a truncate table statement.
     *
     * @param string $table
     * @return string
     */
    public function compileTruncate(string $table): string;

    /**
     * Parameterize an array of values for use in a query.
     *
     * @param array<int|string, mixed> $values
     * @return string
     */
    public function parameterize(array $values): string;

    /**
     * Compile a savepoint statement.
     *
     * @param string $name
     * @return string
     */
    public function compileSavepoint(string $name): string;

    /**
     * Compile a rollback to savepoint statement.
     *
     * @param string $name
     * @return string
     */
    public function compileSavepointRollback(string $name): string;
}
