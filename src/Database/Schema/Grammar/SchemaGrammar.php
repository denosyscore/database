<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema\Grammar;

use CFXP\Core\Database\Schema\Blueprint;
use CFXP\Core\Database\Schema\Column;
use CFXP\Core\Database\Schema\ColumnType;
use CFXP\Core\Database\Schema\IndexDefinition;
use CFXP\Core\Database\Schema\IndexType;
use CFXP\Core\Database\Schema\ForeignKeyDefinition;

/**
 * Base schema grammar class.
 * 
 * This abstract class exists for backwards compatibility.
 * It implements the interface and uses the trait.
 * 
 * For pure composition, you can implement SchemaGrammarInterface
 * and use SchemaGrammarTrait directly.
 * 
 * Driver-specific grammars should override methods as needed.
 */
abstract class SchemaGrammar implements SchemaGrammarInterface
{
    use SchemaGrammarTrait;

    /**
     * Get the database driver name.
     */
    abstract public function getDriverName(): string;

    /**
     * Compile a CREATE TABLE statement.
     * 
     * @return string[]
     */
    abstract public function compileCreate(Blueprint $blueprint): array;

    /**
     * Compile ALTER TABLE statements.
     * 
     * @return string[]
     */
    abstract public function compileAlter(Blueprint $blueprint): array;

    /**
     * Compile a statement to check if table exists.
     */
    abstract public function compileTableExists(string $table): string;

    /**
     * Compile a statement to get all tables.
     */
    abstract public function compileGetAllTables(): string;

    /**
     * Compile a statement to get columns in a table.
     */
    abstract public function compileGetColumns(string $table): string;

    /**
     * Compile a statement to get column type.
     */
    abstract public function compileGetColumnType(string $table, string $column): string;

    /**
     * Compile a statement to disable foreign key constraints.
     */
    abstract public function compileDisableForeignKeyConstraints(): string;

    /**
     * Compile a statement to enable foreign key constraints.
     */
    abstract public function compileEnableForeignKeyConstraints(): string;
}
