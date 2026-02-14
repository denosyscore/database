<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Grammar;

class MySqlGrammar extends Grammar
{
    /**
     * MySQL uses backticks for identifier quoting.
     */
    protected string $wrapper = '`%s`';

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'mysql';
    }

    /**
     * Compile a truncate statement.
     * MySQL supports TRUNCATE TABLE directly.
     */
    public function compileTruncate(string $table): string
    {
        return 'TRUNCATE TABLE ' . $this->wrapTable($table);
    }

    /**
     * Compile an "upsert" statement (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * @param string $table
     * @param array<string, mixed> $insertColumns
     * @param array<string, mixed> $conflictColumns Not used in MySQL (uses primary/unique key)
     * @param array<string, mixed> $updateColumns
     * @return string
     */
    public function compileUpsert(string $table, array $insertColumns, array $conflictColumns = [], array $updateColumns = []): string
    {
        $insert = $this->compileInsert($table, $insertColumns);
        
        // If no update columns specified, use all insert columns
        if (empty($updateColumns)) {
            $updateColumns = $insertColumns;
        }
        
        $updates = implode(', ', array_map(
            fn($col) => $this->wrap($col) . ' = VALUES(' . $this->wrap($col) . ')',
            $updateColumns
        ));

        return $insert . ' ON DUPLICATE KEY UPDATE ' . $updates;
    }

    /**
     * Compile a "replace" statement (MySQL-specific).
     *
     * @param string $table
     * @param array<string> $columns
     * @return string
     */
    public function compileReplace(string $table, array $columns): string
    {
        $columnList = implode(', ', array_map(fn($col) => $this->wrap($col), $columns));
        $placeholders = $this->parameterize($columns);

        return "REPLACE INTO {$this->wrapTable($table)} ({$columnList}) VALUES ({$placeholders})";
    }

    /**
     * Compile a JSON contains check.
     *
     * @param string $column
     * @param string $path
     * @return string
     */
    public function compileJsonContains(string $column, string $path = '$'): string
    {
        return "JSON_CONTAINS({$this->wrap($column)}, ?, '{$path}')";
    }

    /**
     * Compile a JSON extract.
     *
     * @param string $column
     * @param string $path
     * @return string
     */
    public function compileJsonExtract(string $column, string $path): string
    {
        return "JSON_EXTRACT({$this->wrap($column)}, '{$path}')";
    }

    /**
     * Compile a fulltext search.
     *
     * @param array<string> $columns
     * @param string $mode 'natural' | 'boolean' | 'expansion'
     * @return string
     */
    public function compileFullText(array $columns, string $mode = 'natural'): string
    {
        $columnList = implode(', ', array_map(fn($col) => $this->wrap($col), $columns));
        
        $modeClause = match ($mode) {
            'boolean' => ' IN BOOLEAN MODE',
            'expansion' => ' WITH QUERY EXPANSION',
            default => ' IN NATURAL LANGUAGE MODE',
        };

        return "MATCH ({$columnList}) AGAINST (?{$modeClause})";
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
        return 'LOCK IN SHARE MODE';
    }
}

