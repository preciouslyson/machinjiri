<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Base;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Database\Caching\CachedQueryBuilder;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * Base Model Class
 *
 * Provides an ActiveRecord‑style interface using QueryBuilder and optional caching.
 * All application models should extend this class.
 *
 * @method static QueryBuilder|CachedQueryBuilder query()
 * @method static static|null find(mixed $id)
 * @method static \Mlangeni\Machinjiri\Core\Database\QueryBuilder where(string $column, string $operator = null, mixed $value = null)
 * @method static \Mlangeni\Machinjiri\Core\Database\QueryBuilder first()
 * @method static \Mlangeni\Machinjiri\Core\Database\QueryBuilder get()
 */
abstract class AbstractModel
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $guarded = ['id'];
    protected array $attributes = [];
    protected array $original = [];
    protected bool $exists = false;

    // Cache configuration (static so it applies to static queries)
    protected static bool $cacheEnabled = false;
    protected static ?int $cacheTtl = null;
    protected static array $cacheTags = [];

    // Timestamps
    protected bool $timestamps = true;
    protected const CREATED_AT = 'created_at';
    protected const UPDATED_AT = 'updated_at';

    // Soft delete
    protected bool $softDelete = false;
    protected const DELETED_AT = 'deleted_at';

    /**
     * Constructor.
     *
     * @param array $attributes
     * @throws MachinjiriException
     */
    public function __construct(array $attributes = [])
    {
        if (empty($this->table)) {
            $this->table = $this->inferTableName();
        }
        $this->fill($attributes);
    }

    /**
     * Infer table name from the class name.
     *
     * @return string
     */
    protected function inferTableName(): string
    {
        $parts = explode('\\', static::class);
        $className = end($parts);
        // Convert PascalCase to snake_case and pluralise (basic)
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
        return $snake . 's';
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param array $attributes
     * @return $this
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->attributes[$key] = $value;
            }
        }
        return $this;
    }

    /**
     * Force fill even guarded attributes.
     *
     * @param array $attributes
     * @return $this
     */
    public function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    /**
     * Determine if an attribute is fillable.
     *
     * @param string $key
     * @return bool
     */
    protected function isFillable(string $key): bool
    {
        if (in_array($key, $this->guarded)) {
            return false;
        }
        if (empty($this->fillable)) {
            return true;
        }
        return in_array($key, $this->fillable);
    }

    // -------------------------------------------------------------------------
    // Magic Accessors
    // -------------------------------------------------------------------------

    public function __get(string $name)
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, $value): void
    {
        if ($this->isFillable($name)) {
            $this->attributes[$name] = $value;
        }
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    // -------------------------------------------------------------------------
    // Query Builder Integration
    // -------------------------------------------------------------------------

    /**
     * Get a new query builder instance for this model.
     *
     * @return QueryBuilder
     */
    protected function newQuery(): QueryBuilder
    {
        return new QueryBuilder($this->table);
    }

    /**
     * Get a new cached query builder instance.
     *
     * @return CachedQueryBuilder
     * @throws MachinjiriException
     */
    protected function newCachedQuery(): CachedQueryBuilder
    {
        $cacheManager = $this->getCacheManager();
        $builder = $this->newQuery();
        $cached = new CachedQueryBuilder($builder, $cacheManager);
        if (!static::$cacheEnabled) {
            $cached = $cached->withoutCache();
        } else {
            $cached = $cached->withCache(static::$cacheTtl, static::$cacheTags);
        }
        return $cached;
    }

    /**
     * Resolve the cache manager from the container.
     *
     * @return CacheManager
     * @throws MachinjiriException
     */
    protected function getCacheManager(): CacheManager
    {
        $cache = Container::resolve(CacheManager::class);
        if (!$cache) {
            throw new MachinjiriException('CacheManager not available for model caching.');
        }
        return $cache;
    }

    /**
     * Begin a fluent query on the model's table (uncached by default).
     *
     * @return QueryBuilder
     */
    public static function query(): QueryBuilder
    {
        return (new static())->newQuery();
    }

    /**
     * Begin a fluent cached query.
     *
     * @return CachedQueryBuilder
     * @throws MachinjiriException
     */
    public static function cached(): CachedQueryBuilder
    {
        return (new static())->newCachedQuery();
    }

    /**
     * Dynamically handle static method calls to the query builder.
     *
     * @param string $name
     * @param array  $arguments
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments)
    {
        $query = static::$cacheEnabled ? (new static())->newCachedQuery() : (new static())->newQuery();
        return $query->$name(...$arguments);
    }

    /**
     * Dynamically handle instance method calls to the query builder.
     *
     * @param string $name
     * @param array  $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        $query = static::$cacheEnabled ? $this->newCachedQuery() : $this->newQuery();
        return $query->$name(...$arguments);
    }

    // -------------------------------------------------------------------------
    // CRUD Operations
    // -------------------------------------------------------------------------

    /**
     * Find a model by its primary key.
     *
     * @param mixed $id
     * @return static|null
     */
    public static function find($id): ?self
    {
        $instance = new static();
        $builder = static::$cacheEnabled ? $instance->newCachedQuery() : $instance->newQuery();
        $result = $builder->select()->where($instance->primaryKey, '=', $id)->first();

        if (!$result) {
            return null;
        }

        $instance->exists = true;
        $instance->attributes = $result;
        $instance->original = $result;
        return $instance;
    }

    /**
     * Get all models from the table.
     *
     * @return array
     */
    public static function all(): array
    {
        $instance = new static();
        $builder = static::$cacheEnabled ? $instance->newCachedQuery() : $instance->newQuery();
        $rows = $builder->get();

        $models = [];
        foreach ($rows as $row) {
            $model = new static();
            $model->exists = true;
            $model->attributes = $row;
            $model->original = $row;
            $models[] = $model;
        }
        return $models;
    }

    /**
     * Save the current model (insert or update).
     *
     * @return bool
     * @throws MachinjiriException
     */
    public function save(): bool
    {
        $this->updateTimestamps();

        $attributes = $this->getDirtyAttributes();

        if (empty($attributes)) {
            return true;
        }

        $query = $this->newQuery();

        if ($this->exists && isset($this->attributes[$this->primaryKey])) {
            // Update existing record
            $result = $query->where($this->primaryKey, '=', $this->attributes[$this->primaryKey])
                            ->update($attributes);
            if ($result['rowCount'] > 0) {
                $this->original = array_merge($this->original, $attributes);
                return true;
            }
            return false;
        } else {
            // Insert new record
            $result = $query->insert($attributes);
            if ($result['rowCount'] > 0 && isset($result['lastInsertId'])) {
                $this->attributes[$this->primaryKey] = $result['lastInsertId'];
                $this->exists = true;
                $this->original = $this->attributes;
                return true;
            }
            return false;
        }
    }

    /**
     * Update the created_at and updated_at timestamps if enabled.
     *
     * @return void
     */
    protected function updateTimestamps(): void
    {
        if (!$this->timestamps) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        if (!$this->exists && !isset($this->attributes[static::CREATED_AT])) {
            $this->attributes[static::CREATED_AT] = $now;
        }
        $this->attributes[static::UPDATED_AT] = $now;
    }

    /**
     * Delete the current model (soft or hard).
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->exists || !isset($this->attributes[$this->primaryKey])) {
            return false;
        }

        if ($this->softDelete) {
            $this->attributes[static::DELETED_AT] = date('Y-m-d H:i:s');
            return $this->save();
        }

        $query = $this->newQuery();
        $result = $query->where($this->primaryKey, '=', $this->attributes[$this->primaryKey])
                        ->delete();

        if ($result['rowCount'] > 0) {
            $this->exists = false;
            return true;
        }
        return false;
    }

    /**
     * Force hard delete (ignores soft delete).
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        $softDeleteBackup = $this->softDelete;
        $this->softDelete = false;
        $result = $this->delete();
        $this->softDelete = $softDeleteBackup;
        return $result;
    }

    /**
     * Refresh the model from the database.
     *
     * @return $this
     * @throws MachinjiriException
     */
    public function refresh(): self
    {
        if (!$this->exists) {
            throw new MachinjiriException('Cannot refresh a model that does not exist.');
        }
        $fresh = static::find($this->attributes[$this->primaryKey]);
        if (!$fresh) {
            throw new MachinjiriException('Model no longer exists in database.');
        }
        $this->attributes = $fresh->attributes;
        $this->original = $fresh->original;
        return $this;
    }

    /**
     * Get a fresh instance of the model from the database.
     *
     * @return static|null
     */
    public function fresh(): ?self
    {
        if (!$this->exists) {
            return null;
        }
        return static::find($this->attributes[$this->primaryKey]);
    }

    // -------------------------------------------------------------------------
    // Attribute Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the dirty attributes (changed since last sync).
     *
     * @return array
     */
    protected function getDirtyAttributes(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!$this->isFillable($key)) {
                continue;
            }
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    // -------------------------------------------------------------------------
    // Increment / Decrement
    // -------------------------------------------------------------------------

    /**
     * Increment a column's value.
     *
     * @param string $column
     * @param int    $amount
     * @return int New value
     */
    public function increment(string $column, int $amount = 1): int
    {
        $current = $this->attributes[$column] ?? 0;
        $new = $current + $amount;
        $this->attributes[$column] = $new;
        $this->save();
        return $new;
    }

    /**
     * Decrement a column's value.
     *
     * @param string $column
     * @param int    $amount
     * @return int New value
     */
    public function decrement(string $column, int $amount = 1): int
    {
        return $this->increment($column, -$amount);
    }

    // -------------------------------------------------------------------------
    // First Or Create / Update Or Create
    // -------------------------------------------------------------------------

    /**
     * Get the first record matching the attributes or create a new one.
     *
     * @param array $attributes
     * @param array $values
     * @return static
     */
    public static function firstOrCreate(array $attributes, array $values = []): self
    {
        $instance = new static();
        $builder = $instance->newQuery();
        foreach ($attributes as $key => $value) {
            $builder->where($key, '=', $value);
        }
        $result = $builder->first();
        if ($result) {
            $model = new static();
            $model->exists = true;
            $model->attributes = $result;
            $model->original = $result;
            return $model;
        }
        return static::create(array_merge($attributes, $values));
    }

    /**
     * Update or create a record matching the attributes.
     *
     * @param array $attributes
     * @param array $values
     * @return static
     */
    public static function updateOrCreate(array $attributes, array $values = []): self
    {
        $instance = new static();
        $builder = $instance->newQuery();
        foreach ($attributes as $key => $value) {
            $builder->where($key, '=', $value);
        }
        $result = $builder->first();
        if ($result) {
            $model = new static();
            $model->exists = true;
            $model->attributes = $result;
            $model->original = $result;
            $model->fill($values);
            $model->save();
            return $model;
        }
        return static::create(array_merge($attributes, $values));
    }

    /**
     * Create a new model and save it.
     *
     * @param array $attributes
     * @return static
     */
    public static function create(array $attributes): self
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    // -------------------------------------------------------------------------
    // Relationship Stubs (to be integrated with a proper ORM)
    // -------------------------------------------------------------------------

    /**
     * Define a "has one" relationship.
     *
     * @param string $related
     * @param string|null $foreignKey
     * @param string|null $localKey
     * @return mixed (stub)
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null)
    {
        // Stub – return a relation object
        return null;
    }

    /**
     * Define a "has many" relationship.
     *
     * @param string $related
     * @param string|null $foreignKey
     * @param string|null $localKey
     * @return mixed
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null)
    {
        return null;
    }

    /**
     * Define a "belongs to" relationship.
     *
     * @param string $related
     * @param string|null $foreignKey
     * @param string|null $ownerKey
     * @return mixed
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null)
    {
        return null;
    }
}