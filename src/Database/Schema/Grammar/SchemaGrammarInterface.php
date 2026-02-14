<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema\Grammar;

use CFXP\Core\Database\Schema\Blueprint;

/**
 * Contract for schema grammar implementations.
 * 
 * Schema grammars compile Blueprint objects into SQL statements.
 */
interface SchemaGrammarInterface
{
    /**
     * Get the database driver name.
     */
    public function getDriverName(): string;

    /**
     * Compile a CREATE TABLE statement.
     * 
     * @return string[]
     */
    public function compileCreate(Blueprint $blueprint): array;

    /**
     * Compile ALTER TABLE statements.
     * 
     * @return string[]
     */
    public function compileAlter(Blueprint $blueprint): array;

    /**
     * Compile a DROP TABLE statement.
     */
    public function compileDrop(string $table): string;

    /**
     * Compile a DROP TABLE IF EXISTS statement.
     */
    public function compileDropIfExists(string $table): string;

    /**
     * Compile a RENAME TABLE statement.
     */
    public function compileRename(string $from, string $to): string;

    /**
     * Compile a statement to check if table exists.
     */
    public function compileTableExists(string $table): string;

    /**
     * Compile a statement to get all tables.
     */
    public function compileGetAllTables(): string;

    /**
     * Compile a statement to get columns in a table.
     */
    public function compileGetColumns(string $table): string;

    /**
     * Compile a statement to get column type.
     */
    public function compileGetColumnType(string $table, string $column): string;

    /**
     * Compile a statement to disable foreign key constraints.
     */
    public function compileDisableForeignKeyConstraints(): string;

    /**
     * Compile a statement to enable foreign key constraints.
     */
    public function compileEnableForeignKeyConstraints(): string;
}
