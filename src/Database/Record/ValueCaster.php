<?php

declare(strict_types=1);

namespace Denosys\Database\Record;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Denosys\Database\Exceptions\InvalidRecord;
use Exception;
use Stringable;
use ValueError;

/**
 * Converts between column values and record property values, from the
 * property's declared type: int, float, string, bool, DateTimeImmutable
 * (UTC), a backed enum, or a #[CastWith] cast.
 *
 * @internal
 */
final class ValueCaster
{
    /** @var array<class-string<Cast>, Cast> */
    private array $casts = [];

    public function __construct(private readonly string $dateFormat)
    {
    }

    /** @throws InvalidRecord */
    public function fromDatabase(RecordMetadata $record, PropertyMetadata $property, mixed $value): mixed
    {
        if ($property->cast !== null) {
            return $this->cast($property->cast)->fromDatabase($value);
        }

        if ($value === null) {
            return $property->nullable
                ? null
                : throw new InvalidRecord("{$record->class}::\${$property->name} is not nullable, but its column {$property->column} is NULL.");
        }

        $type = $property->type;

        return match (true) {
            $type === 'int' => self::int($record, $property, $value),
            $type === 'float' => is_numeric($value) ? (float) $value : self::refuse($record, $property, $value),
            $type === 'string' => is_scalar($value) || $value instanceof Stringable ? (string) $value : self::refuse($record, $property, $value),
            $type === 'bool' => (bool) $value,
            $type === DateTimeImmutable::class || $type === DateTimeInterface::class => self::date($record, $property, $value),
            is_subclass_of($type, BackedEnum::class) => self::enum($record, $property, $type, $value),
            default => $value,
        };
    }

    public function toDatabase(PropertyMetadata $property, mixed $value): mixed
    {
        if ($property->cast !== null) {
            return $this->cast($property->cast)->toDatabase($value);
        }

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 1 : 0,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format($this->dateFormat),
            $value instanceof BackedEnum => $value->value,
            default => $value,
        };
    }

    /** @param class-string<Cast> $class */
    private function cast(string $class): Cast
    {
        return $this->casts[$class] ??= new $class();
    }

    private static function int(RecordMetadata $record, PropertyMetadata $property, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return (int) $value;
        }

        $int = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return is_int($int) ? $int : self::refuse($record, $property, $value);
    }

    private static function date(RecordMetadata $record, PropertyMetadata $property, mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }

        if (!is_string($value)) {
            self::refuse($record, $property, $value);
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            self::refuse($record, $property, $value);
        }

        // An impossible date such as 2000-02-30 parses with a warning and rolls over.
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            self::refuse($record, $property, $value);
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /** @param class-string<BackedEnum> $enum */
    private static function enum(RecordMetadata $record, PropertyMetadata $property, string $enum, mixed $value): BackedEnum
    {
        if (!is_int($value) && !is_string($value)) {
            self::refuse($record, $property, $value);
        }

        if (is_string($value) && (string) (new \ReflectionEnum($enum))->getBackingType() === 'int') {
            $int = filter_var($value, FILTER_VALIDATE_INT);
            $value = is_int($int) ? $int : self::refuse($record, $property, $value);
        }

        try {
            return $enum::from($value);
        } catch (ValueError) {
            self::refuse($record, $property, $value);
        }
    }

    private static function refuse(RecordMetadata $record, PropertyMetadata $property, mixed $value): never
    {
        throw new InvalidRecord(sprintf(
            '%s::$%s cannot hold the value %s read from its column %s.',
            $record->class,
            $property->name,
            get_debug_type($value) . (is_scalar($value) ? ' ' . var_export($value, true) : ''),
            $property->column,
        ));
    }
}
