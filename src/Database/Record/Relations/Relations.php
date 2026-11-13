<?php

declare(strict_types=1);

namespace Denosys\Database\Record\Relations;

use Denosys\Database\Record\Record;
use Denosys\Database\Record\RecordMetadata;

/**
 * Builds relations in a record's relation methods:
 *
 *     public function posts(): HasMany
 *     {
 *         return Relations::hasMany($this, Post::class);
 *     }
 *
 * A default foreign key is the snake-case short class name plus `_id`.
 */
final class Relations
{
    /**
     * @template P of Record
     * @template R of Record
     * @param P $parent
     * @param class-string<R> $related
     * @return HasMany<P, R>
     */
    public static function hasMany(Record $parent, string $related, ?string $foreignKey = null, string $localKey = 'id'): HasMany
    {
        return new HasMany($parent, $related, $foreignKey ?? self::foreignKey($parent::class), $localKey);
    }

    /**
     * @template P of Record
     * @template R of Record
     * @param P $parent
     * @param class-string<R> $related
     * @return HasOne<P, R>
     */
    public static function hasOne(Record $parent, string $related, ?string $foreignKey = null, string $localKey = 'id'): HasOne
    {
        return new HasOne($parent, $related, $foreignKey ?? self::foreignKey($parent::class), $localKey);
    }

    /**
     * @template P of Record
     * @template R of Record
     * @param P $child
     * @param class-string<R> $related
     * @return BelongsTo<P, R>
     */
    public static function belongsTo(Record $child, string $related, ?string $foreignKey = null, string $ownerKey = 'id'): BelongsTo
    {
        return new BelongsTo($child, $related, $foreignKey ?? self::foreignKey($related), $ownerKey);
    }

    /**
     * @template P of Record
     * @template R of Record
     * @param P $parent
     * @param class-string<R> $related
     * @param list<string> $pivotColumns
     * @return BelongsToMany<P, R>
     */
    public static function belongsToMany(
        Record $parent,
        string $related,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        array $pivotColumns = [],
    ): BelongsToMany {
        return new BelongsToMany($parent, $related, $pivotTable, $foreignPivotKey, $relatedPivotKey, $pivotColumns);
    }

    /** @param class-string $class */
    private static function foreignKey(string $class): string
    {
        $short = substr($class, (int) strrpos('\\' . $class, '\\'));

        return RecordMetadata::snake($short) . '_id';
    }
}
