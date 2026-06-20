<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Artisans\Base;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Database\Caching\CachedQueryBuilder;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Date\DateTimeHandler;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use DateTimeInterface;
use DateInterval;

/**
 * Base Model Class
 *
 * Provides an enhanced ActiveRecord‑style interface with:
 * - Attribute casting (including to DateTimeHandler)
 * - Model events
 * - Soft deletes with query scopes
 * - Caching integration
 * - Dirty attribute tracking
 * - Timestamps (using DateTimeHandler)
 *
 * @method static QueryBuilder|CachedQueryBuilder query()
 * @method static static|null find(mixed $id)
 * @method static \Mlangeni\Machinjiri\Core\Database\QueryBuilder where(string $column, string $operator = null, mixed $value = null)
 * @method static \Mlangeni\Machinjiri\Core\Database\QueryBuilder first()
 * @method static \Mlangeni\Machinjiri\Core\Database\QueryBuilder get()
 * @method static static create(array $attributes)
 * @method static static|null firstOrCreate(array $attributes, array $values = [])
 * @method static static updateOrCreate(array $attributes, array $values = [])
 */
abstract class AbstractModel
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected string $keyType = 'int';
    public bool $incrementing = true;

    protected array $fillable = [];
    protected array $guarded = ['*'];  // block all by default, override in child
    protected array $attributes = [];
    protected array $original = [];
    protected bool $exists = false;

    // Attribute casting
    protected array $casts = [];

    // Cache configuration
    protected static bool $cacheEnabled = false;
    protected static ?int $cacheTtl = null;
    protected static array $cacheTags = [];

    // Timestamps
    protected bool $timestamps = true;
    protected const CREATED_AT = 'created_at';
    protected const UPDATED_AT = 'updated_at';
    protected string $dateFormat = 'Y-m-d H:i:s';
    protected string $timezone = 'UTC';

    // Soft delete
    protected bool $softDelete = false;
    protected const DELETED_AT = 'deleted_at';
    protected static bool $withTrashed = false;

    // Event dispatcher
    protected static ?EventListener $eventDispatcher = null;
    protected static ?Logger $logger = null;

    // Cache keys for invalidation
    protected static array $cacheKeysForModel = [];

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
        $this->bootIfNotBooted();
        $this->fireEvent('booting');
    }

    protected function bootIfNotBooted(): void
    {
        static::boot();
    }

    protected static function boot(): void
    {
        static::resolveEventDispatcher();
        static::resolveLogger();
        static::bootTraits();
    }

    protected static function bootTraits(): void
    {
        // Future trait support
    }

    protected static function resolveEventDispatcher(): void
    {
        if (!static::$eventDispatcher) {
            static::$eventDispatcher = resolve(EventListener::class);
        }
    }

    protected static function resolveLogger(): void
    {
        if (!static::$logger) {
            static::$logger = resolve(Logger::class);
        }
    }

    protected function fireEvent(string $event, mixed $payload = null): mixed
    {
        $eventName = 'model.' . $event . '.' . static::class;
        return static::$eventDispatcher?->until($eventName, $payload ?? $this);
    }

    // -------------------------------------------------------------------------
    // Attribute Handling & Casting (with DateTimeHandler)
    // -------------------------------------------------------------------------

    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    public function forceFill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    protected function setAttribute(string $key, mixed $value): void
    {
        // If cast to datetime, accept DateTimeHandler, string, or DateTimeInterface
        if (isset($this->casts[$key]) && in_array($this->casts[$key], ['datetime', 'date', 'timestamp'])) {
            $value = $this->asDateTimeHandler($value);
            // Store as string in DB format
            $this->attributes[$key] = $value->format($this->dateFormat);
        } else {
            $this->attributes[$key] = $this->castAttribute($key, $value);
        }
    }

    public function getAttribute(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }

        // If cast to datetime, return a DateTimeHandler instance
        if (isset($this->casts[$key]) && in_array($this->casts[$key], ['datetime', 'date', 'timestamp'])) {
            if ($this->attributes[$key] === null) {
                return null;
            }
            return $this->asDateTimeHandler($this->attributes[$key]);
        }

        return $this->castAttribute($key, $this->attributes[$key]);
    }

    protected function castAttribute(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key])) {
            return $value;
        }

        $castType = $this->casts[$key];

        return match ($castType) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => json_decode($value, true) ?? [],
            'object' => json_decode($value),
            'datetime', 'date', 'timestamp' => $this->asDateTimeHandler($value), // handled in getAttribute
            default => $value,
        };
    }

    /**
     * Convert a value to a DateTimeHandler instance.
     */
    protected function asDateTimeHandler(mixed $value): DateTimeHandler
    {
        if ($value instanceof DateTimeHandler) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return new DateTimeHandler($value->format($this->dateFormat), $this->timezone);
        }

        if (is_numeric($value)) {
            return DateTimeHandler::fromTimestamp((int) $value, $this->timezone);
        }

        return new DateTimeHandler((string) $value, $this->timezone);
    }

    protected function asDate(mixed $value): string
    {
        return $this->asDateTimeHandler($value)->format('Y-m-d');
    }

    protected function asTimestamp(mixed $value): int
    {
        return $this->asDateTimeHandler($value)->getTimestamp();
    }

    protected function isFillable(string $key): bool
    {
        if (in_array($key, $this->guarded) && $this->guarded !== ['*']) {
            return false;
        }
        if (!empty($this->fillable)) {
            return in_array($key, $this->fillable);
        }
        // If fillable is empty and guarded = ['*'] , nothing is fillable
        return $this->guarded !== ['*'];
    }

    protected function inferTableName(): string
    {
        $parts = explode('\\', static::class);
        $className = end($parts);
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
        return $snake . 's';
    }

    // -------------------------------------------------------------------------
    // Magic Accessors
    // -------------------------------------------------------------------------

    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    public function __set(string $name, mixed $value): void
    {
        if ($this->isFillable($name)) {
            $this->setAttribute($name, $value);
        }
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    public function toArray(): array
    {
        $array = [];
        foreach ($this->attributes as $key => $value) {
            $casted = $this->getAttribute($key);
            if ($casted instanceof DateTimeHandler) {
                $array[$key] = $casted->format($this->dateFormat);
            } else {
                $array[$key] = $casted;
            }
        }
        return $array;
    }

    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    // -------------------------------------------------------------------------
    // Query Builder Integration & Caching
    // -------------------------------------------------------------------------

    protected function newQuery(): QueryBuilder
    {
        $query = new QueryBuilder($this->table);
        if ($this->softDelete && !static::$withTrashed) {
            $query->where(static::DELETED_AT, 'IS', null);
        }
        return $query;
    }

    protected function newCachedQuery(): CachedQueryBuilder
    {
        $cacheManager = $this->getCacheManager();
        $builder = $this->newQuery();
        $cached = new CachedQueryBuilder($builder, $cacheManager);
        if (!static::$cacheEnabled) {
            $cached = $cached->withoutCache();
        } else {
            $cached = $cached->withCache(static::$cacheTtl, static::$cacheTags);
            $this->registerCacheTags();
        }
        return $cached;
    }

    protected function getCacheManager(): CacheManager
    {
        $cache = resolve(CacheManager::class);
        if (!$cache) {
            throw new MachinjiriException('CacheManager not available for model caching.');
        }
        return $cache;
    }

    protected function registerCacheTags(): void
    {
        $tag = 'model.' . $this->table;
        if (!in_array($tag, static::$cacheTags)) {
            static::$cacheTags[] = $tag;
        }
    }

    public static function query(): QueryBuilder
    {
        return (new static())->newQuery();
    }

    public static function cached(): CachedQueryBuilder
    {
        return (new static())->newCachedQuery();
    }

    public static function __callStatic(string $name, array $arguments)
    {
        $query = static::$cacheEnabled ? (new static())->newCachedQuery() : (new static())->newQuery();
        return $query->$name(...$arguments);
    }

    public function __call(string $name, array $arguments)
    {
        $query = static::$cacheEnabled ? $this->newCachedQuery() : $this->newQuery();
        return $query->$name(...$arguments);
    }

    // -------------------------------------------------------------------------
    // Soft Delete Scopes
    // -------------------------------------------------------------------------

    public static function withTrashed(): QueryBuilder
    {
        static::$withTrashed = true;
        $query = (new static())->newQuery();
        static::$withTrashed = false;
        return $query;
    }

    public static function onlyTrashed(): QueryBuilder
    {
        $instance = new static();
        $query = $instance->newQuery();
        $query->where(static::DELETED_AT, 'IS NOT', null);
        return $query;
    }

    public function restore(): bool
    {
        if ($this->softDelete && $this->exists && $this->getAttribute(static::DELETED_AT) !== null) {
            $this->setAttribute(static::DELETED_AT, null);
            return $this->save();
        }
        return false;
    }

    public function trashed(): bool
    {
        return $this->softDelete && $this->getAttribute(static::DELETED_AT) !== null;
    }

    // -------------------------------------------------------------------------
    // CRUD Operations
    // -------------------------------------------------------------------------

    public static function find($id): ?self
    {
        $instance = new static();
        $builder = static::$cacheEnabled ? $instance->newCachedQuery() : $instance->newQuery();
        $result = $builder->select()
            ->where($instance->primaryKey, '=', $id)
            ->first();

        if (!$result) {
            return null;
        }

        $instance->exists = true;
        $instance->attributes = $result;
        $instance->original = $result;
        return $instance;
    }

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

    public function save(): bool
    {
        if ($this->fireEvent('saving') === false) {
            return false;
        }

        $this->updateTimestamps();
        $dirty = $this->getDirtyAttributes();

        if (empty($dirty) && $this->exists) {
            return true;
        }

        $query = $this->newQuery();
        $success = false;

        if ($this->exists && isset($this->attributes[$this->primaryKey])) {
            $result = $query->where($this->primaryKey, '=', $this->attributes[$this->primaryKey])
                ->update($dirty);
            $success = ($result['rowCount'] > 0);
            if ($success) {
                $this->original = array_merge($this->original, $dirty);
                $this->fireEvent('updated');
                $this->invalidateModelCache();
            }
        } else {
            $result = $query->insert($dirty)->execute();
            if ($result['rowCount'] > 0 && isset($result['lastInsertId']) && $this->incrementing) {
                $this->attributes[$this->primaryKey] = $result['lastInsertId'];
                $this->exists = true;
                $this->original = $this->attributes;
                $success = true;
                $this->fireEvent('created');
                $this->invalidateModelCache();
            }
        }

        if ($success) {
            $this->fireEvent('saved');
        }

        return $success;
    }

    public function delete(): bool
    {
        if ($this->fireEvent('deleting') === false) {
            return false;
        }

        if (!$this->exists) {
            return false;
        }

        if ($this->softDelete) {
            $this->setAttribute(static::DELETED_AT, new DateTimeHandler('now', $this->timezone));
            $result = $this->save();
            if ($result) {
                $this->fireEvent('deleted');
                $this->invalidateModelCache();
            }
            return $result;
        }

        $query = $this->newQuery();
        $result = $query->where($this->primaryKey, '=', $this->attributes[$this->primaryKey])
            ->delete();

        if ($result['rowCount'] > 0) {
            $this->exists = false;
            $this->fireEvent('deleted');
            $this->invalidateModelCache();
            return true;
        }
        return false;
    }

    public function forceDelete(): bool
    {
        $softDeleteBackup = $this->softDelete;
        $this->softDelete = false;
        $result = $this->delete();
        $this->softDelete = $softDeleteBackup;
        return $result;
    }

    public function refresh(): self
    {
        if (!$this->exists) {
            throw new MachinjiriException('Cannot refresh a model that does not exist.');
        }
        $fresh = static::find($this->getAttribute($this->primaryKey));
        if (!$fresh) {
            throw new MachinjiriException('Model no longer exists in database.');
        }
        $this->attributes = $fresh->attributes;
        $this->original = $fresh->original;
        return $this;
    }

    public function fresh(): ?self
    {
        if (!$this->exists) {
            return null;
        }
        return static::find($this->getAttribute($this->primaryKey));
    }

    // -------------------------------------------------------------------------
    // Dirty & Original
    // -------------------------------------------------------------------------

    protected function getDirtyAttributes(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!$this->isFillable($key)) {
                continue;
            }
            // For datetime attributes, compare using timestamp
            if (isset($this->casts[$key]) && in_array($this->casts[$key], ['datetime', 'date', 'timestamp'])) {
                $oldValue = $this->original[$key] ?? null;
                $newValue = $value;
                if ($oldValue != $newValue) {
                    $dirty[$key] = $newValue;
                }
            } elseif (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    public function isDirty(?string $attribute = null): bool
    {
        if ($attribute) {
            return array_key_exists($attribute, $this->getDirtyAttributes());
        }
        return !empty($this->getDirtyAttributes());
    }

    // -------------------------------------------------------------------------
    // Timestamps (using DateTimeHandler)
    // -------------------------------------------------------------------------

    protected function updateTimestamps(): void
    {
        if (!$this->timestamps) {
            return;
        }
        $now = new DateTimeHandler('now', $this->timezone);
        if (!$this->exists && !$this->getAttribute(static::CREATED_AT)) {
            $this->setAttribute(static::CREATED_AT, $now);
        }
        $this->setAttribute(static::UPDATED_AT, $now);
    }

    public function touch(): bool
    {
        if (!$this->timestamps) {
            return false;
        }
        $this->setAttribute(static::UPDATED_AT, new DateTimeHandler('now', $this->timezone));
        return $this->save();
    }

    // -------------------------------------------------------------------------
    // Increment / Decrement
    // -------------------------------------------------------------------------

    public function increment(string $column, int $amount = 1): int
    {
        $current = (int)($this->getAttribute($column) ?? 0);
        $new = $current + $amount;
        $this->setAttribute($column, $new);
        $this->save();
        return $new;
    }

    public function decrement(string $column, int $amount = 1): int
    {
        return $this->increment($column, -$amount);
    }

    // -------------------------------------------------------------------------
    // First Or Create / Update Or Create
    // -------------------------------------------------------------------------

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

    public static function firstOrNew(array $attributes, array $values = []): self
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
        return new static(array_merge($attributes, $values));
    }

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

    public static function create(array $attributes): self
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    // -------------------------------------------------------------------------
    // Cache Invalidation
    // -------------------------------------------------------------------------

    protected function invalidateModelCache(): void
    {
        if (!static::$cacheEnabled) {
            return;
        }
        $tag = 'model.' . $this->table;
        $cache = resolve(CacheManager::class);
        if ($cache) {
            $cache->tags([$tag])->clear();
        }
        static::$logger?->debug("Invalidated cache for model table: {$this->table}");
    }

    // -------------------------------------------------------------------------
    // Relationships (stubs for extension)
    // -------------------------------------------------------------------------

    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): ?HasOne
    {
        return null;
    }

    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): ?HasMany
    {
        return null;
    }

    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ?BelongsTo
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Helper methods for subclasses
    // -------------------------------------------------------------------------

    public function getTable(): string
    {
        return $this->table;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function getKey(): mixed
    {
        return $this->getAttribute($this->primaryKey);
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
    }
}