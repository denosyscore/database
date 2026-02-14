<?php

declare(strict_types=1);

namespace Denosys\Database\Grammar;

class PostgresGrammar extends Grammar
{
    /**
     * PostgreSQL uses double quotes for identifier quoting.
     */
    protected string $wrapper = '"%s"';

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'pgsql';
    }

    /**
     * Compile an insert statement with RETURNING clause.
     *
     * @param string $table
     * @param array<string> $columns
     * @param array<string, mixed> $returning Columns to return
     * @return string
     */
    public function compileInsertReturning(string $table, array $columns, array $returning = ['id']): string
    {
        $insert = $this->compileInsert($table, $columns);
        $returningClause = implode(', ', array_map(fn($col) => $this->wrap($col), $returning));
        
        return $insert . ' RETURNING ' . $returningClause;
    }

    /**
     * Compile an update statement with RETURNING clause.
     *
     * @param string $table
     * @param array<string> $columns
     * @param string $where
     * @param array<string, mixed> $returning
     * @return string
     */
    public function compileUpdateReturning(string $table, array $columns, string $where, array $returning = ['*']): string
    {
        $update = $this->compileUpdate($table, $columns, $where);
        $returningClause = implode(', ', array_map(fn($col) => $this->wrap($col), $returning));
        
        return $update . ' RETURNING ' . $returningClause;
    }

    /**
     * Compile an "upsert" statement (INSERT ... ON CONFLICT).
     *
     * @param string $table
     * @param array<string, mixed> $insertColumns
     * @param array<string, mixed> $conflictColumns
     * @param array<string, mixed> $updateColumns
     * @return string
     */
    public function compileUpsert(string $table, array $insertColumns, array $conflictColumns = [], array $updateColumns = []): string
    {
        $insert = $this->compileInsert($table, $insertColumns);
        
        // Default to first column as conflict column if not specified
        if (empty($conflictColumns)) {
            $conflictColumns = [$insertColumns[0] ?? 'id'];
        }
        
        // Default to all insert columns as update columns if not specified
        if (empty($updateColumns)) {
            $updateColumns = $insertColumns;
        }
        
        $conflict = implode(', ', array_map(fn($col) => $this->wrap($col), $conflictColumns));
        
        $updates = implode(', ', array_map(
            fn($col) => $this->wrap($col) . ' = EXCLUDED.' . $this->wrap($col),
            $updateColumns
        ));

        return $insert . ' ON CONFLICT (' . $conflict . ') DO UPDATE SET ' . $updates;
    }

    /**
     * Compile a truncate statement.
     * PostgreSQL supports RESTART IDENTITY and CASCADE.
     */
    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrapTable($table) . ' RESTART IDENTITY CASCADE';
    }

    /**
     * Compile a JSONB contains check.
     *
     * @param string $column
     * @return string
     */
    public function compileJsonContains(string $column): string
    {
        return $this->wrap($column) . ' @> ?::jsonb';
    }

    /**
     * Compile a JSONB path extraction.
     *
     * @param string $column
     * @param string $path
     * @return string
     */
    public function compileJsonExtract(string $column, string $path): string
    {
        // PostgreSQL uses -> for object and ->> for text
        return $this->wrap($column) . " -> '{$path}'";
    }

    /**
     * Compile a JSONB text extraction.
     *
     * @param string $column
     * @param string $path
     * @return string
     */
    public function compileJsonExtractText(string $column, string $path): string
    {
        return $this->wrap($column) . " ->> '{$path}'";
    }

    /**
     * Compile a full-text search using tsvector/tsquery.
     *
     * @param array<string> $columns
     * @param string $config Search configuration (e.g., 'english')
     * @return string
     */
    public function compileFullText(array $columns, string $config = 'english'): string
    {
        $vectors = implode(' || ', array_map(
            fn($col) => "to_tsvector('{$config}', " . $this->wrap($col) . ")",
            $columns
        ));

        return "{$vectors} @@ plainto_tsquery('{$config}', ?)";
    }

    /**
     * Compile an ILIKE (case-insensitive LIKE) clause.
     *
     * @param string $column
     * @return string
     */
    public function compileILike(string $column): string
    {
        return $this->wrap($column) . ' ILIKE ?';
    }

    /**
     * Compile a regex match (PostgreSQL ~).
     *
     * @param string $column
     * @param bool $caseInsensitive
     * @return string
     */
    public function compileRegex(string $column, bool $caseInsensitive = false): string
    {
        $operator = $caseInsensitive ? '~*' : '~';
        return $this->wrap($column) . " {$operator} ?";
    }

    /**
     * Compile lock for update clause.
     */
    public function compileLockForUpdate(): string
    {
        return 'FOR UPDATE';
    }

    /**
     * Compile shared lock clause.
     */
    public function compileSharedLock(): string
    {
        return 'FOR SHARE';
    }

    /**
     * Compile an array contains check.
     *
     * @param string $column
     * @return string
     */
    public function compileArrayContains(string $column): string
    {
        return '? = ANY(' . $this->wrap($column) . ')';
    }

    /**
     * Get the format for storing dates.
     * PostgreSQL uses ISO 8601 format by default.
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.uP';
    }
}

