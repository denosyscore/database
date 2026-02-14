<?php

declare(strict_types=1);

namespace Denosys\Database\Grammar;

class SqliteGrammar extends Grammar
{
    /**
     * SQLite accepts both double quotes and backticks,
     * but double quotes are the standard.
     */
    protected string $wrapper = '"%s"';

    /**
     * {@inheritdoc}
     */
    public function getDriverName(): string
    {
        return 'sqlite';
    }

    /**
     * Compile a truncate statement.
     * SQLite doesn't have TRUNCATE, so we use DELETE and reset the autoincrement.
     */
    public function compileTruncate(string $table): string
    {
        // SQLite requires two statements to truly truncate
        // This returns the DELETE statement; autoincrement reset should be handled separately
        return 'DELETE FROM ' . $this->wrapTable($table);
    }

    /**
     * Compile statements to reset autoincrement after truncate.
     *
     * @param string $table
     * @return array<string, mixed> SQL statements
     */
    /**
     * @return array<string, mixed>
     */
public function compileTruncateReset(string $table): array
    {
        return [
            'DELETE FROM ' . $this->wrapTable($table),
            "DELETE FROM sqlite_sequence WHERE name = '{$table}'",
        ];
    }

    /**
     * Compile an "upsert" statement (INSERT ... ON CONFLICT).
     * SQLite 3.24+ supports ON CONFLICT.
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
            fn($col) => $this->wrap($col) . ' = excluded.' . $this->wrap($col),
            $updateColumns
        ));

        return $insert . ' ON CONFLICT (' . $conflict . ') DO UPDATE SET ' . $updates;
    }

    /**
     * Compile an INSERT OR REPLACE statement.
     *
     * @param string $table
     * @param array<string> $columns
     * @return string
     */
    public function compileReplace(string $table, array $columns): string
    {
        $columnList = implode(', ', array_map(fn($col) => $this->wrap($col), $columns));
        $placeholders = $this->parameterize($columns);

        return "INSERT OR REPLACE INTO {$this->wrapTable($table)} ({$columnList}) VALUES ({$placeholders})";
    }

    /**
     * Compile an INSERT OR IGNORE statement.
     *
     * @param string $table
     * @param array<string> $columns
     * @return string
     */
    public function compileInsertIgnore(string $table, array $columns): string
    {
        $columnList = implode(', ', array_map(fn($col) => $this->wrap($col), $columns));
        $placeholders = $this->parameterize($columns);

        return "INSERT OR IGNORE INTO {$this->wrapTable($table)} ({$columnList}) VALUES ({$placeholders})";
    }

    /**
     * Compile a JSON extract.
     * SQLite uses json_extract() function.
     *
     * @param string $column
     * @param string $path
     * @return string
     */
    public function compileJsonExtract(string $column, string $path): string
    {
        return "json_extract({$this->wrap($column)}, '\$.{$path}')";
    }

    /**
     * Compile a JSON contains check using json_each.
     *
     * @param string $column
     * @return string
     */
    public function compileJsonContains(string $column): string
    {
        // SQLite's JSON support is more limited
        return "EXISTS (SELECT 1 FROM json_each({$this->wrap($column)}) WHERE value = ?)";
    }

    /**
     * Compile a LIKE clause (SQLite LIKE is case-insensitive for ASCII).
     *
     * @param string $column
     * @return string
     */
    public function compileLike(string $column): string
    {
        return $this->wrap($column) . ' LIKE ?';
    }

    /**
     * Compile a GLOB clause (case-sensitive pattern matching).
     *
     * @param string $column
     * @return string
     */
    public function compileGlob(string $column): string
    {
        return $this->wrap($column) . ' GLOB ?';
    }

    /**
     * SQLite doesn't support FOR UPDATE, but we can use BEGIN IMMEDIATE.
     * Returns empty string as SQLite handles this differently.
     */
    public function compileLockForUpdate(): string
    {
        return '';
    }

    /**
     * SQLite doesn't support shared locks in the same way.
     */
    public function compileSharedLock(): string
    {
        return '';
    }

    /**
     * Get the format for storing dates.
     * SQLite stores dates as TEXT in ISO8601 format.
     */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Compile a datetime function.
     *
     * @param string $column
     * @param string $modifier e.g., '+1 day', '-1 month'
     * @return string
     */
    public function compileDatetime(string $column, string $modifier): string
    {
        return "datetime({$this->wrap($column)}, '{$modifier}')";
    }

    /**
     * Compile a strftime function for date formatting.
     *
     * @param string $format
     * @param string $column
     * @return string
     */
    public function compileStrftime(string $format, string $column): string
    {
        return "strftime('{$format}', {$this->wrap($column)})";
    }
}

