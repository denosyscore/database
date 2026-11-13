<?php

declare(strict_types=1);

namespace Denosys\Database\Record;

use Denosys\Database\Exceptions\InvalidRecord;
use Denosys\Database\Record\Attributes\CastWith;
use Denosys\Database\Record\Attributes\Column;
use Denosys\Database\Record\Attributes\Id;
use Denosys\Database\Record\Attributes\Table;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * The table, connection and columns of a record class, read once per class
 * from its attributes and property types. A record's public, non-static
 * properties are its columns.
 */
final class RecordMetadata
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * @param class-string $class
     * @param array<string, PropertyMetadata> $properties keyed by property name
     */
    private function __construct(
        public readonly string $class,
        public readonly string $table,
        public readonly ?string $connection,
        public readonly PropertyMetadata $id,
        public readonly array $properties,
    ) {
    }

    /**
     * @param class-string $class
     *
     * @throws InvalidRecord
     */
    public static function of(string $class): self
    {
        return self::$cache[$class] ??= self::read($class);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /** @throws InvalidRecord */
    public function column(string $property): string
    {
        return ($this->properties[$property] ?? throw new InvalidRecord("{$this->class} has no column property \${$property}."))->column;
    }

    /** @throws InvalidRecord */
    public function propertyForColumn(string $column): PropertyMetadata
    {
        foreach ($this->properties as $property) {
            if ($property->column === $column) {
                return $property;
            }
        }

        throw new InvalidRecord("{$this->class} has no property for the column {$column}.");
    }

    /**
     * @param class-string $class
     *
     * @throws InvalidRecord
     */
    private static function read(string $class): self
    {
        $reflection = new ReflectionClass($class);
        $table = ($reflection->getAttributes(Table::class)[0] ?? null)?->newInstance();

        $properties = [];
        $id = null;
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $metadata = self::property($class, $property);
            $properties[$metadata->name] = $metadata;
            if ($property->getAttributes(Id::class) !== []) {
                $id = $metadata;
            }
        }

        $id ??= $properties['id'] ?? throw new InvalidRecord("{$class} has no key: mark a property #[Id] or name one \$id.");

        return new self(
            $class,
            $table->name ?? self::plural(self::snake($reflection->getShortName())),
            $table?->connection,
            $id,
            $properties,
        );
    }

    /**
     * @param class-string $class
     *
     * @throws InvalidRecord
     */
    private static function property(string $class, ReflectionProperty $property): PropertyMetadata
    {
        $name = $property->getName();
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || $type->getName() === 'mixed') {
            throw new InvalidRecord("{$class}::\${$name} needs a single declared type (a union, mixed or no type cannot be stored).");
        }

        $column = ($property->getAttributes(Column::class)[0] ?? null)?->newInstance();
        $cast = ($property->getAttributes(CastWith::class)[0] ?? null)?->newInstance();

        return new PropertyMetadata(
            $name,
            $column->name ?? self::snake($name),
            $type->getName(),
            $type->allowsNull(),
            $property->hasDefaultValue() && ($property->getDefaultValue() !== null || $type->allowsNull()),
            $cast?->cast,
        );
    }

    /** @internal Converts a class or property name to snake case. */
    public static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])|(?<=[A-Z])([A-Z])(?=[a-z])/', '_$1$2', $name));
    }

    private static function plural(string $word): string
    {
        return match (true) {
            preg_match('/[^aeiou]y$/', $word) === 1 => substr($word, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/', $word) === 1 => $word . 'es',
            default => $word . 's',
        };
    }
}
