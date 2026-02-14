<?php

declare(strict_types=1);

namespace Denosys\Database;

use Denosys\Database\Connection\Connection;
use Denosys\Database\Connection\ConnectionManager;
use Denosys\Database\Query\Builder;

/**
 * @phpstan-consistent-constructor
 * 
 * @method static ModelBuilder where(string $column, mixed $operator = null, mixed $value = null)
 * @method static ModelBuilder with(string|array<string> $relations)
 * @method static static|null first()
 * @method static ModelBuilder orderBy(string $column, string $direction = 'asc')
 * @method static ModelBuilder limit(int $limit)
 * @method static ModelBuilder select(array<string> $columns = ['*'])
 */
abstract class Model
{
    /**
     * The table associated with the model.
     */
    protected string $table;

    /**
     * The primary key for the model.
     */
    protected string $primaryKey = 'id';

    /**
     * The type of the primary key (auto-incrementing int vs UUID string).
     */
    protected string $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    protected bool $incrementing = true;

    /**
     * The attributes that are mass assignable.
     */
    /** @var array<string, mixed> */

    protected array $fillable = [];

    /**
     * The attributes that are NOT mass assignable.
      * @var array<string>
     */
    protected array $guarded = ['*'];

    /**
     * The attributes that should be hidden for serialization.
     */
    /** @var array<string, mixed> */

    protected array $hidden = [];

    /**
     * The attributes that should be cast.
     */
    /** @var array<string, mixed> */

    protected array $casts = [];

    /**
     * Indicates if the model should be timestamped.
     */
    protected bool $timestamps = true;

    /**
     * The name of the "created at" column.
     */
    protected string $createdAt = 'created_at';

    /**
     * The name of the "updated at" column.
     */
    protected string $updatedAt = 'updated_at';

    /**
     * The model's attributes.
     */
    /** @var array<string, mixed> */

    protected array $attributes = [];

    /**
     * The model's original attributes (before changes).
     */
    /** @var array<string, mixed> */

    protected array $original = [];

    /**
     * Indicates if the model exists in the database.
    */
    protected bool $exists = false;

    /**
     * The connection name for the model.
     */
    protected ?string $connection = null;

    /**
     * The connection manager instance (static, shared across all models).
     */
    protected static ?ConnectionManager $connectionManager = null;

    /**
     * Whether lazy loading should be prevented (N+1 detection).
     */
    protected static bool $preventLazyLoading = false;

    /**
     * Whether auto eager loading is enabled.
     */
    protected static bool $autoEagerLoadEnabled = false;

    /**
     * Whether mass assignment is allowed (bypasses fillable/guarded checks).
     */
    protected static bool $massAssignmentAllowed = false;

    /**
     * Collection siblings for auto eager loading.
     * Maps a unique collection ID to all models in that collection.
     * 
     * @var array<string, array<Model>>
     */
    protected static array $collectionSiblings = [];

    /**
     * The collection ID this model belongs to (for auto eager loading).
     */
    protected ?string $collectionId = null;

    /**
     * Prevent lazy loading (throws exception on N+1).
     */
    public static function preventLazyLoading(bool $prevent = true): void
    {
        static::$preventLazyLoading = $prevent;
    }

    /**
     * Check if lazy loading is prevented.
     */
    public static function isLazyLoadingPrevented(): bool
    {
        return static::$preventLazyLoading;
    }

    /**
     * Allow mass assignment (bypasses fillable/guarded checks).
     */
    public static function allowMassAssignment(): void
    {
        static::$massAssignmentAllowed = true;
    }

    /**
     * Prevent mass assignment (re-enables fillable/guarded checks).
     */
    public static function preventMassAssignment(): void
    {
        static::$massAssignmentAllowed = false;
    }

    /**
     * Check if mass assignment is allowed.
     */
    public static function isMassAssignmentAllowed(): bool
    {
        return static::$massAssignmentAllowed;
    }

    /**
     * Enable auto eager loading (batch loads relations on first access).
     */
    public static function autoEagerLoad(bool $enable = true): void
    {
        static::$autoEagerLoadEnabled = $enable;
    }

    /**
     * Check if auto eager loading is enabled.
     */
    public static function isAutoEagerLoadEnabled(): bool
    {
        return static::$autoEagerLoadEnabled;
    }

    /**
     * Create a new model instance.
      * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->initializeTable();
        $this->fill($attributes);
    }

    /**
     * Initialize the table name if not explicitly set.
     */
    protected function initializeTable(): void
    {
        if (!isset($this->table)) {
            $this->table = $this->inferTableName();
        }
    }

    /**
     * Set the connection manager for all models.
     */
    public static function setConnectionManager(ConnectionManager $manager): void
    {
        static::$connectionManager = $manager;
    }

    /**
     * Get the connection manager.
     */
    public static function getConnectionManager(): ?ConnectionManager
    {
        return static::$connectionManager;
    }

    /**
     * Get the database connection for the model.
     */
    public function getConnection(): Connection
    {
        return static::$connectionManager->connection($this->connection);
    }

    /**
     * Get a new query builder for the model.
     */
    public function newQuery(): ModelBuilder|Builder
    {
        $builder = new ModelBuilder($this->getConnection());
        $builder->setModel($this);
        
        return $builder->table($this->getTable());
    }

    /**
     * Start a new query (static convenience method).
     */
    public static function query(): ModelBuilder|Builder
    {
        return (new static())->newQuery();
    }

    /**
     * Get all records.
     *
     * @param array<string> $columns
     */
    public static function all(array $columns = ['*']): mixed
    {
        return static::query()->select($columns)->get();
    }

    /**
     * Find a record by its primary key.
     */
    public static function find(int|string $id): ?static
    {
        return static::query()->find($id);
    }

    /**
     * Find a record by its primary key or throw an exception.
     *
     * @throws Exceptions\ModelNotFoundException
     */
    public static function findOrFail(int|string $id): static
    {
        $result = static::find($id);

        if ($result === null) {
            throw (new Exceptions\ModelNotFoundException())->setModel(static::class, $id);
        }

        return $result;
    }

    /**
     * Find records by multiple primary keys.
     *
     * @param array<int|string> $ids
     * @return array<static>
     */
    public static function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $instance = new static();
        $results = $instance->newQuery()
            ->whereIn($instance->primaryKey, $ids)
            ->get();

        return array_map(fn($row) => $instance->newFromDatabase((array) $row), $results);
    }

    /**
     * Get the first record matching the attributes or create it.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        $instance = new static();
        $query = $instance->newQuery();

        foreach ($attributes as $key => $value) {
            $query->where($key, '=', $value);
        }

        $result = $query->first();

        if ($result !== null) {
            return $instance->newFromDatabase((array) $result);
        }

        $newInstance = new static(array_merge($attributes, $values));
        $newInstance->save();

        return $newInstance;
    }

    /**
     * Get the first record or fail.
     *
     * @throws Exceptions\ModelNotFoundException
     */
    public static function firstOrFail(): static
    {
        $result = static::query()->first();

        if ($result === null) {
            throw (new Exceptions\ModelNotFoundException())->setModel(static::class);
        }

        return (new static())->newFromDatabase((array) $result);
    }

    /**
     * Create a new record in the database.
      * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $instance = new static($attributes);
        $instance->save();

        return $instance;
    }

    /**
     * Fill the model with an array of attributes.
     * 
     * @throws \Denosys\Database\Exceptions\MassAssignmentException
      * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        $nonFillable = [];

        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } else {
                // Collect all non-fillable attributes (whether guarded or not)
                $nonFillable[] = $key;
            }
        }

        // Throw exception for non-fillable attributes (unless mass assignment allowed)
        if (!empty($nonFillable) && !static::isMassAssignmentAllowed()) {
            throw new Exceptions\MassAssignmentException(
                static::class,
                $nonFillable
            );
        }

        return $this;
    }

    /**
     * Fill the model without mass assignment protection.
      * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    /**
     * Determine if the given attribute may be mass assigned.
     */
    protected function isFillable(string $key): bool
    {
        // If mass assignment globally allowed, allow all except guarded
        if (static::isMassAssignmentAllowed()) {
            return !$this->isGuarded($key);
        }

        // If fillable is defined and key is in it, allow
        if (!empty($this->fillable) && in_array($key, $this->fillable)) {
            return true;
        }

        // If guarded is ['*'], block all unless in fillable
        if ($this->guarded === ['*']) {
            return !empty($this->fillable) && in_array($key, $this->fillable);
        }

        // If key is in guarded, block
        if (in_array($key, $this->guarded)) {
            return false;
        }

        // If guarded is empty, allow all (explicit unguard)
        if (empty($this->guarded)) {
            return true;
        }

        // FIXED: If fillable is empty but guarded has values, nothing is fillable
        // (except keys not in guarded, which are blocked above)
        return false;
    }

    /**
     * Determine if the given attribute is guarded.
     */
    protected function isGuarded(string $key): bool
    {
        if ($this->guarded === ['*']) {
            return true;
        }

        return in_array($key, $this->guarded);
    }

    /**
     * Set a given attribute on the model.
     */
    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $this->castAttribute($key, $value, 'set');

        return $this;
    }

    /**
     * Get an attribute from the model.
     */
    public function getAttribute(string $key): mixed
    {
        $value = $this->attributes[$key] ?? null;

        return $this->castAttribute($key, $value, 'get');
    }

    /**
     * Cast an attribute to a native PHP type.
     */
    protected function castAttribute(string $key, mixed $value, string $direction = 'get'): mixed
    {
        $casts = $this->getCasts();
        
        if (!isset($casts[$key]) || $value === null) {
            return $value;
        }

        $cast = $casts[$key];

        return match ($cast) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => $direction === 'get'
                ? (is_string($value) ? json_decode($value, true) : $value)
                : (is_array($value) ? json_encode($value) : $value),
            'datetime' => $direction === 'get'
                ? ($value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value))
                : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value),
            'date' => $direction === 'get'
                ? ($value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value))
                : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value),
            default => $value,
        };
    }

    /**
     * Get the casts array, merging property and method definitions.
     * Method casts take precedence over property casts.
     *
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        $casts = $this->casts;
        
        if (method_exists($this, 'casts')) {
            $casts = array_merge($casts, $this->casts());
        }
        
        return $casts;
    }

    /**
     * Get all of the current attributes on the model.
      * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get the original attributes (before any changes).
     */
    public function getOriginal(?string $key = null): mixed
    {
        if ($key !== null) {
            return $this->original[$key] ?? null;
        }

        return $this->original;
    }

    /**
     * Determine if the model has been modified.
     */
    public function isDirty(?string $key = null): bool
    {
        if ($key !== null) {
            return ($this->attributes[$key] ?? null) !== ($this->original[$key] ?? null);
        }

        return $this->attributes !== $this->original;
    }

    /**
     * Get the changed attributes.
      * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!isset($this->original[$key]) || $value !== $this->original[$key]) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Save the model to the database.
     */
    public function save(): bool
    {
        // Update timestamps
        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');

            if (!$this->exists) {
                $this->setAttribute($this->createdAt, $now);
            }

            $this->setAttribute($this->updatedAt, $now);
        }

        if ($this->exists) {
            return $this->performUpdate();
        }

        return $this->performInsert();
    }

    /**
     * Perform an insert operation.
     */
    protected function performInsert(): bool
    {
        $attributes = $this->getAttributes();

        $id = $this->newQuery()->insert($attributes);

        if ($this->incrementing) {
            $this->setAttribute($this->primaryKey, $id);
        }

        $this->exists = true;
        $this->original = $this->attributes;

        return true;
    }

    /**
     * Perform an update operation.
     */
    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return true;
        }

        $this->newQuery()
            ->where($this->primaryKey, '=', $this->getKey())
            ->update($dirty);

        $this->original = $this->attributes;

        return true;
    }

    /**
     * Delete the model from the database.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $this->newQuery()
            ->where($this->primaryKey, '=', $this->getKey())
            ->delete();

        $this->exists = false;

        return true;
    }

    /**
     * Get the primary key value.
     */
    public function getKey(): mixed
    {
        return $this->getAttribute($this->primaryKey);
    }

    /**
     * Get the primary key name.
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get the table name.
     * If not explicitly set, infers from class name (pluralized, snake_case).
     */
    public function getTable(): string
    {
        if (isset($this->table)) {
            return $this->table;
        }

        return $this->table = $this->inferTableName();
    }

    /**
     * Infer table name from class name.
     */
    protected function inferTableName(): string
    {
        $class = (new \ReflectionClass($this))->getShortName();
        
        $inflector = \Doctrine\Inflector\InflectorFactory::create()->build();
        
        return $inflector->pluralize($inflector->tableize($class));
    }

    /**
     * Create a new model instance from database attributes.
      * @param array<string, mixed> $attributes
     */
    public function newFromDatabase(array $attributes): static
    {
        $instance = new static();
        $instance->attributes = $attributes;
        $instance->original = $attributes;
        $instance->exists = true;

        return $instance;
    }

    /**
     * The loaded relationships for the model.
     */
    /** @var array<string, mixed> */

    protected array $relations = [];

    /**
     * Define a one-to-one relationship.
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): Relations\HasOne
    {
        $instance = new $related();

        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->primaryKey;

        return new Relations\HasOne($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    /**
     * Define a one-to-many relationship.
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): Relations\HasMany
    {
        $instance = new $related();

        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->primaryKey;

        return new Relations\HasMany($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    /**
     * Define a inverse one-to-one or many relationship.
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): Relations\BelongsTo
    {
        $instance = new $related();

        if (is_null($foreignKey)) {
            $foreignKey = $this->getForeignKeyFromModel($instance);
        }

        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return new Relations\BelongsTo($instance->newQuery(), $this, $foreignKey, $ownerKey);
    }

    /**
     * Get the default foreign key name for the model.
     */
    public function getForeignKey(): string
    {
        return strtolower($this->getClassBasename($this)) . '_id';
    }

    /**
     * Get the foreign key for a given model.
     */
    protected function getForeignKeyFromModel(Model $model): string
    {
        return strtolower($this->getClassBasename($model)) . '_id';
    }

    /**
     * Get the class "basename" of the given object / class.
     */
    protected function getClassBasename(mixed $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }

    /**
     * Set the specific relationship in the model.
     */
    public function setRelation(string $relation, mixed $value): static
    {
        $this->relations[$relation] = $value;

        return $this;
    }

    /**
     * Define a many-to-many relationship.
     */
    public function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): Relations\BelongsToMany {
        $instance = new $related();

        // If no table name is specified, we will derive it by joining the
        // model names in alphabetical order.
        if ($table === null) {
            $segments = [
                $this->getClassBasename($this),
                $this->getClassBasename($instance)
            ];
            sort($segments);
            $table = strtolower(implode('_', $segments));
        }

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();
        $parentKey = $parentKey ?: $this->primaryKey;
        $relatedKey = $relatedKey ?: $instance->primaryKey;

        return new Relations\BelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey
        );
    }

    /**
     * Get a specified relationship.
     */
    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation];
    }

    /**
     * Determine if the given relation is loaded.
     */
    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    /**
     * Dynamically access attributes or relationships.
     */
    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->getAttribute($key);
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        if (method_exists($this, $key)) {
            return $this->getRelationshipFromMethod($key);
        }

        return null;
    }

    /**
     * Get a relationship value from a method.
     */
    protected function getRelationshipFromMethod(string $method): mixed
    {
        // Auto eager load takes precedence over prevent lazy loading
        if (static::$autoEagerLoadEnabled && $this->collectionId !== null) {
            $this->autoEagerLoadForSiblings($method);
            return $this->relations[$method] ?? null;
        }

        if (static::$preventLazyLoading && !static::$autoEagerLoadEnabled) {
            throw new Exceptions\LazyLoadingViolationException(
                static::class,
                $method
            );
        }

        $relation = $this->$method();

        if (!$relation instanceof Relations\Relation) {
            throw new \LogicException("Relationship method must return an object of type CfXP\Core\Database\Relations\Relation");
        }

        $results = $relation->getResults();

        $this->setRelation($method, $results);

        return $results;
    }

    /**
     * Auto eager load a relation for all siblings in the same collection.
     */
    protected function autoEagerLoadForSiblings(string $method): void
    {
        $siblings = static::$collectionSiblings[$this->collectionId] ?? [];
        
        if (empty($siblings)) {
            // Fall back to normal loading for single model
            $relation = $this->$method();
            $this->setRelation($method, $relation->getResults());
            return;
        }

        // Get IDs of all siblings that don't have this relation loaded
        $modelsToLoad = [];
        foreach ($siblings as $model) {
            if (!isset($model->relations[$method])) {
                $modelsToLoad[] = $model;
            }
        }

        if (empty($modelsToLoad)) {
            return;
        }

        // Get the relation from the first model (without constraints to avoid parent ID filtering)
        $relation = Relations\Relation::noConstraints(function () use ($method) {
            return $this->$method();
        });
        
        // Set the relation name so match() knows what to call it
        $relation->setRelationName($method);
        
        // Load all results for all sibling models at once
        $relation->eagerLoadRelations($modelsToLoad);
    }

    /**
     * Set the collection ID for this model (used for auto eager loading).
     */
    public function setCollectionId(string $id): static
    {
        $this->collectionId = $id;
        return $this;
    }

    /**
     * Register this model as part of a collection.
     */
    public function registerInCollection(string $collectionId): static
    {
        $this->collectionId = $collectionId;
        static::$collectionSiblings[$collectionId][] = $this;
        return $this;
    }

    /**
     * Dynamically set attributes.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if an attribute exists.
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Unset an attribute.
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /**
     * Convert the model to an array.
      * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = $this->getAttributes();

        // Remove hidden attributes
        foreach ($this->hidden as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }

    /**
     * Convert the model to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Handle dynamic static method calls.
     *
     * @param array<int, mixed> $parameters
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return (new static())->$method(...$parameters);
    }

    /**
     * Handle dynamic method calls (forward to query builder).
      * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->newQuery()->$method(...$parameters);
    }
}
