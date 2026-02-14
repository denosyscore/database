<?php

declare(strict_types=1);

namespace CFXP\Core\Database;

/**
 * @phpstan-consistent-constructor
 */
abstract class Entity
{
    /**
     * The entity's attributes.
     */
    /** @var array<string, mixed> */

    protected array $attributes = [];

    /**
     * Create a new entity instance.
      * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * Fill the entity with an array of attributes.
      * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    /**
     * Set an attribute value.
     */
    public function set(string $key, mixed $value): static
    {
        // Check for setter method
        $setter = 'set' . $this->studly($key);
        if (method_exists($this, $setter)) {
            $this->$setter($value);
            return $this;
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Get an attribute value.
     */
    public function get(string $key): mixed
    {
        // Check for getter method
        $getter = 'get' . $this->studly($key);
        if (method_exists($this, $getter)) {
            return $this->$getter();
        }

        return $this->attributes[$key] ?? null;
    }

    /**
     * Check if an attribute exists.
     */
    public function has(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Get all attributes.
     */
    /**
     * @return array<string, mixed>
     */
public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Convert snake_case to StudlyCase.
     */
    protected function studly(string $value): string
    {
        $words = explode(' ', str_replace(['-', '_'], ' ', $value));
        $studly = array_map('ucfirst', $words);

        return implode('', $studly);
    }

    /**
     * Dynamically access attributes.
     */
    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    /**
     * Dynamically set attributes.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    /**
     * Determine if an attribute exists.
     */
    public function __isset(string $key): bool
    {
        return $this->has($key);
    }

    /**
     * Unset an attribute.
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /**
     * Convert the entity to an array.
      * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * Convert the entity to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Create a new entity from an array.
      * @param array<string, mixed> $attributes
     */
    public static function fromArray(array $attributes): static
    {
        return new static($attributes);
    }

    /**
     * Create a new entity from a database row (stdClass object).
     */
    public static function fromDatabase(object $row): static
    {
        return new static((array) $row);
    }
}
