<?php

declare(strict_types=1);

namespace Denosys\Database\Schema;

use Denosys\Database\Connection\Connection;
use Denosys\Database\Exceptions\DatabaseException;
use Denosys\Database\Schema\Grammar\SqliteSchemaGrammar;

/**
 * Applies an ALTER that SQLite cannot express (changing a column, adding or
 * dropping a foreign key) by rebuilding the table, following SQLite's
 * documented procedure (https://www.sqlite.org/lang_altertable.html#otheralter)
 * in one transaction: create the new table under a temporary name, copy the
 * rows, drop the old table, rename the new one into place, recreate the
 * indexes, then check every foreign key.
 *
 * Dropping a table that other tables reference runs an implicit DELETE, which
 * fires their ON DELETE actions, so enforcement must be off for the rebuild.
 * SQLite can only turn it off outside a transaction:
 *
 * - outside a transaction, enforcement is turned off for the rebuild and on
 *   again afterwards, and foreign_key_check must pass before it commits;
 * - inside a transaction (a migration, by default), a table that no other
 *   table references is rebuilt with enforcement on; a referenced one is
 *   refused, because it cannot be rebuilt safely there.
 *
 * The rebuild keeps column collations, composite foreign keys and their
 * names, index definitions (including partial and collated indexes), table
 * options (STRICT, WITHOUT ROWID) and the AUTOINCREMENT sequence. A table
 * with triggers, CHECK constraints or generated columns is refused, because a
 * rebuild would not keep them. Every refusal throws DatabaseException and
 * changes nothing.
 *
 * @internal Used by SchemaBuilder.
 */
final class SqliteTableRebuild
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SqliteSchemaGrammar $grammar,
    ) {
    }

    public function apply(Blueprint $blueprint): void
    {
        $table = $blueprint->getTable();
        $definition = $this->tableSql($table);
        if (preg_match('/\b(CHECK|GENERATED\s+ALWAYS)\b|\bAS\s*\(/i', $definition) === 1) {
            throw new DatabaseException("Cannot rebuild [{$table}]: it has CHECK constraints or generated columns, which a rebuild would lose.");
        }
        if ($this->rows("SELECT 1 FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [$table]) !== []) {
            throw new DatabaseException("Cannot rebuild [{$table}]: it has triggers, which a rebuild would lose.");
        }

        $elements = self::elements($definition);
        $options = self::tableOptions($definition);

        $renames = $blueprint->getRenameColumns();
        $dropped = $blueprint->getDropColumns();
        $changed = [];
        $added = [];
        foreach ($blueprint->getColumns() as $column) {
            if ($column->isChange()) {
                $changed[$column->getName()] = $column;
            } else {
                $added[] = $column;
            }
        }

        // Columns: existing ones in order (renamed, changed or kept), then new ones.
        $columns = [];
        $copy = [];
        $primary = [];
        $autoIncrement = stripos($definition, 'AUTOINCREMENT') !== false;
        foreach ($this->rows('PRAGMA table_info(' . $this->quote($table) . ')') as $row) {
            $old = self::text($row, 'name');
            if (in_array($old, $dropped, true)) {
                continue;
            }
            $new = $renames[$old] ?? $old;
            if (self::number($row, 'pk') > 0) {
                $primary[self::number($row, 'pk')] = $new;
            }
            $copy[$new] = $old;

            if (isset($changed[$new])) {
                $columns[$new] = $this->grammar->columnDefinition($changed[$new]);
                unset($changed[$new]);
                continue;
            }

            $sql = $this->quote($new) . ' ' . (self::text($row, 'type') ?: 'BLOB');
            if (self::number($row, 'notnull') === 1) {
                $sql .= ' NOT NULL';
            }
            if (($row['dflt_value'] ?? null) !== null) {
                $sql .= ' DEFAULT ' . self::text($row, 'dflt_value');
            }
            if (isset($elements['collations'][$old])) {
                $sql .= ' COLLATE ' . $elements['collations'][$old];
            }
            $columns[$new] = $sql;
        }
        if ($changed !== []) {
            throw new DatabaseException("Cannot change missing column(s) [" . implode(', ', array_keys($changed)) . "] on [{$table}].");
        }
        foreach ($added as $column) {
            $columns[$column->getName()] = $this->grammar->columnDefinition($column);
        }

        ksort($primary);
        $primary = array_values($primary);
        if (count($primary) === 1 && $autoIncrement && isset($columns[$primary[0]]) && !str_contains($columns[$primary[0]], 'PRIMARY KEY')) {
            $columns[$primary[0]] = $this->quote($primary[0]) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
            $primary = [];
        }

        // Foreign keys: existing ones not dropped and not on dropped columns, then new ones.
        $constraints = [];
        if ($primary !== []) {
            $constraints[] = 'PRIMARY KEY (' . implode(', ', array_map($this->quote(...), $primary)) . ')';
        }
        $droppedForeign = $blueprint->getDropForeignKeys();
        $foreignKeys = $this->foreignKeys($table, $elements['foreign']);
        $missing = array_diff($droppedForeign, array_filter(array_column($foreignKeys, 'name')));
        if ($missing !== []) {
            throw new DatabaseException("Cannot drop foreign key(s) [" . implode(', ', $missing) . "] on [{$table}]: no such constraint.");
        }
        foreach ($foreignKeys as $key) {
            if (in_array($key['name'], $droppedForeign, true) || array_intersect($key['from'], $dropped) !== []) {
                continue;
            }
            $from = array_map(fn (string $column): string => $this->quote($renames[$column] ?? $column), $key['from']);
            $sql = ($key['name'] !== null ? 'CONSTRAINT ' . $this->quote($key['name']) . ' ' : '')
                . 'FOREIGN KEY (' . implode(', ', $from) . ') REFERENCES ' . $this->quote($key['table'])
                . ' (' . implode(', ', array_map($this->quote(...), $key['to'])) . ')';
            if ($key['on_delete'] !== 'NO ACTION') {
                $sql .= ' ON DELETE ' . $key['on_delete'];
            }
            if ($key['on_update'] !== 'NO ACTION') {
                $sql .= ' ON UPDATE ' . $key['on_update'];
            }
            $constraints[] = $sql;
        }
        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $constraints[] = $this->grammar->foreignKeyDefinition($foreignKey);
        }

        // Indexes: existing ones not dropped and not on dropped columns, then new ones.
        $indexes = [];
        $droppedIndexes = $blueprint->getDropIndexes();
        foreach ($this->indexes($table) as $index) {
            if (in_array($index['name'], $droppedIndexes, true) || array_intersect($index['columns'], $dropped) !== []) {
                continue;
            }
            $renamed = array_intersect($index['columns'], array_keys($renames)) !== [];
            // An index created with CREATE INDEX is recreated from its own
            // definition, which keeps a WHERE clause, collations and sort order.
            if ($index['sql'] !== null && !$renamed) {
                $indexes[] = $index['sql'];
                continue;
            }
            if ($index['sql'] !== null && preg_match('/\b(WHERE|COLLATE|DESC)\b/i', $index['sql']) === 1) {
                throw new DatabaseException("Cannot rebuild [{$table}]: index [{$index['name']}] has a WHERE clause, collation or sort order and uses a renamed column.");
            }
            $name = $index['automatic']
                ? IndexDefinition::defaultName(IndexType::Unique, $table, array_map(fn (string $column): string => $renames[$column] ?? $column, $index['columns']))
                : $index['name'];
            $indexes[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index['unique'] ? 'UNIQUE ' : '',
                $this->quote($name),
                $this->quote($table),
                implode(', ', array_map(fn (string $column): string => $this->quote($renames[$column] ?? $column), $index['columns'])),
            );
        }
        foreach ($blueprint->getColumns() as $column) {
            if ($column->isUnique() && !$column->isPrimary()) {
                $indexes[] = sprintf('CREATE UNIQUE INDEX %s ON %s (%s)', $this->quote(IndexDefinition::defaultName(IndexType::Unique, $table, [$column->getName()])), $this->quote($table), $this->quote($column->getName()));
            }
            if ($column->hasIndex()) {
                $indexes[] = sprintf('CREATE INDEX %s ON %s (%s)', $this->quote(IndexDefinition::defaultName(IndexType::Index, $table, [$column->getName()])), $this->quote($table), $this->quote($column->getName()));
            }
        }
        foreach ($blueprint->getIndexes() as $index) {
            $indexes[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index->getType() === IndexType::Unique ? 'UNIQUE ' : '',
                $this->quote($index->getName()),
                $this->quote($table),
                implode(', ', array_map($this->quote(...), $index->getColumns())),
            );
        }

        $enforced = self::number($this->rows('PRAGMA foreign_keys')[0] ?? [], 'foreign_keys') === 1;
        $inTransaction = $this->connection->inTransaction();
        if ($enforced && $inTransaction) {
            $referencing = $this->referencing($table);
            if ($referencing !== []) {
                throw new DatabaseException(sprintf(
                    'Cannot rebuild [%s] inside a transaction while foreign keys are enforced: [%s] reference it, and '
                    . 'SQLite can only stop enforcing them outside a transaction. Run this change outside a transaction '
                    . '(in a migration, return false from withinTransaction()).',
                    $table,
                    implode(', ', $referencing),
                ));
            }
        }

        $sequence = $autoIncrement
            ? self::number($this->rows('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table])[0] ?? [], 'seq')
            : 0;

        $new = '__denosys_rebuild_' . $table;
        $rebuild = function () use ($table, $new, $columns, $constraints, $copy, $indexes, $options, $sequence): void {
            $this->connection->statement(sprintf('CREATE TABLE %s (%s)%s', $this->quote($new), implode(', ', [...array_values($columns), ...$constraints]), $options));
            $this->connection->statement(sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s',
                $this->quote($new),
                implode(', ', array_map($this->quote(...), array_keys($copy))),
                implode(', ', array_map($this->quote(...), array_values($copy))),
                $this->quote($table),
            ));
            $this->connection->statement('DROP TABLE ' . $this->quote($table));
            $this->connection->statement('ALTER TABLE ' . $this->quote($new) . ' RENAME TO ' . $this->quote($table));
            // Dropping the old table dropped its sequence; keep issued keys from being reused.
            if ($sequence > 0) {
                $this->connection->statement('DELETE FROM sqlite_sequence WHERE name = ?', [$table]);
                $this->connection->statement('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$table, max($sequence, $this->maxKey($table))]);
            }
            foreach ($indexes as $sql) {
                $this->connection->statement($sql);
            }

            $violations = $this->rows('PRAGMA foreign_key_check');
            if ($violations !== []) {
                throw new DatabaseException("Rebuilding [{$table}] would leave " . count($violations) . ' row(s) violating a foreign key.');
            }
        };

        if (!$enforced || $inTransaction) {
            $this->connection->transaction($rebuild);

            return;
        }

        $this->connection->statement('PRAGMA foreign_keys = OFF');
        try {
            $this->connection->transaction($rebuild);
        } finally {
            $this->connection->statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * The other tables with a foreign key to $table.
     *
     * @return list<string>
     */
    private function referencing(string $table): array
    {
        $tables = [];
        foreach ($this->rows("SELECT name FROM sqlite_master WHERE type = 'table' AND name != ?", [$table]) as $row) {
            foreach ($this->rows('PRAGMA foreign_key_list(' . $this->quote(self::text($row, 'name')) . ')') as $key) {
                if (strcasecmp(self::text($key, 'table'), $table) === 0) {
                    $tables[] = self::text($row, 'name');
                    break;
                }
            }
        }

        return $tables;
    }

    private function tableSql(string $table): string
    {
        $sql = $this->rows("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])[0]['sql'] ?? null;
        if (!is_string($sql)) {
            throw new DatabaseException("Table [{$table}] does not exist.");
        }

        return $sql;
    }

    /**
     * The table's foreign keys, one per constraint (a composite key keeps its
     * columns together, in order), with the name the definition gives it.
     *
     * @param array<string, string> $names constraint name by its comma-joined columns
     * @return list<array{name: string|null, from: list<string>, table: string, to: list<string>, on_update: string, on_delete: string}>
     */
    private function foreignKeys(string $table, array $names): array
    {
        $grouped = [];
        foreach ($this->rows('PRAGMA foreign_key_list(' . $this->quote($table) . ')') as $row) {
            $id = self::number($row, 'id');
            $grouped[$id] ??= [
                'from' => [],
                'table' => self::text($row, 'table'),
                'to' => [],
                'on_update' => strtoupper(self::text($row, 'on_update')),
                'on_delete' => strtoupper(self::text($row, 'on_delete')),
            ];
            $grouped[$id]['from'][self::number($row, 'seq')] = self::text($row, 'from');
            $grouped[$id]['to'][self::number($row, 'seq')] = self::text($row, 'to') ?: 'id';
        }
        ksort($grouped);

        $keys = [];
        foreach ($grouped as $key) {
            ksort($key['from']);
            ksort($key['to']);
            $from = array_values($key['from']);
            $keys[] = [
                'name' => $names[strtolower(implode(',', $from))] ?? null,
                'from' => $from,
                'table' => $key['table'],
                'to' => array_values($key['to']),
                'on_update' => $key['on_update'],
                'on_delete' => $key['on_delete'],
            ];
        }

        return array_reverse($keys);
    }

    /**
     * Indexes created with CREATE INDEX, and those SQLite makes for an inline
     * UNIQUE constraint ("automatic": they cannot be dropped, and are
     * recreated as named unique indexes). Primary key indexes are left out.
     *
     * @return list<array{name: string, unique: bool, automatic: bool, columns: list<string>, sql: string|null}>
     */
    private function indexes(string $table): array
    {
        $definitions = [];
        foreach ($this->rows("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]) as $row) {
            $definitions[self::text($row, 'name')] = self::text($row, 'sql');
        }

        $indexes = [];
        foreach ($this->rows('PRAGMA index_list(' . $this->quote($table) . ')') as $row) {
            if (self::text($row, 'origin') === 'pk') {
                continue;
            }
            $columns = [];
            foreach ($this->rows('PRAGMA index_info(' . $this->quote(self::text($row, 'name')) . ')') as $column) {
                if (($column['name'] ?? null) === null) {
                    throw new DatabaseException("Cannot rebuild [{$table}]: index [" . self::text($row, 'name') . "] uses an expression.");
                }
                $columns[self::number($column, 'seqno')] = self::text($column, 'name');
            }
            ksort($columns);
            $indexes[] = [
                'name' => self::text($row, 'name'),
                'unique' => self::number($row, 'unique') === 1,
                'automatic' => self::text($row, 'origin') === 'u',
                'columns' => array_values($columns),
                'sql' => $definitions[self::text($row, 'name')] ?? null,
            ];
        }

        return $indexes;
    }

    /**
     * Read what PRAGMA table_info does not report from the table definition:
     * each column's collation, and each named foreign key constraint.
     *
     * @return array{collations: array<string, string>, foreign: array<string, string>}
     */
    private static function elements(string $definition): array
    {
        $open = strpos($definition, '(');
        $close = strrpos($definition, ')');
        $body = $open === false || $close === false ? '' : substr($definition, $open + 1, $close - $open - 1);

        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        foreach (str_split($body) as $char) {
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif (in_array($char, ['"', "'", '`'], true)) {
                $quote = $char;
            } elseif ($char === '[') {
                $quote = ']';
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);

        $identifier = '("(?:[^"]|"")+"|`[^`]+`|\\[[^\\]]+\\]|[A-Za-z_][\\w$]*)';
        $collations = [];
        $foreign = [];
        foreach ($parts as $part) {
            if (preg_match('/^CONSTRAINT\s+' . $identifier . '\s+FOREIGN\s+KEY\s*\(([^)]*)\)/i', $part, $match) === 1) {
                $columns = array_map(static fn (string $column): string => strtolower(self::unquote(trim($column))), explode(',', $match[2]));
                $foreign[implode(',', $columns)] = self::unquote($match[1]);
                continue;
            }
            if (preg_match('/^(CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN)\b/i', $part) === 1 || preg_match('/^' . $identifier . '/', $part, $name) !== 1) {
                continue;
            }
            if (preg_match('/\bCOLLATE\s+' . $identifier . '/i', $part, $collation) === 1) {
                $collations[self::unquote($name[1])] = $collation[1];
            }
        }

        return ['collations' => $collations, 'foreign' => $foreign];
    }

    /** STRICT and WITHOUT ROWID, as written after the column list. */
    private static function tableOptions(string $definition): string
    {
        $close = strrpos($definition, ')');
        $options = $close === false ? '' : trim(substr($definition, $close + 1));

        return $options === '' ? '' : ' ' . $options;
    }

    private static function unquote(string $identifier): string
    {
        return match ($identifier[0] ?? '') {
            '"' => str_replace('""', '"', substr($identifier, 1, -1)),
            '`', '[' => substr($identifier, 1, -1),
            default => $identifier,
        };
    }

    private function maxKey(string $table): int
    {
        return self::number($this->rows('SELECT MAX(rowid) AS id FROM ' . $this->quote($table))[0] ?? [], 'id');
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<mixed>>
     */
    private function rows(string $sql, array $bindings = []): array
    {
        return array_map(static fn (object $row): array => (array) $row, $this->connection->select($sql, $bindings));
    }

    /**
     * @param array<mixed> $row
     */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<mixed> $row
     */
    private static function number(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
