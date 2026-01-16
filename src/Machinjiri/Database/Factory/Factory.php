<?php

namespace Mlangeni\Machinjiri\Core\Database\Factory;

use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Faker\Factory as FakerFactory;
use Faker\Generator;

class Factory
{
    /**
     * The Faker instance.
     */
    protected static ?Generator $faker = null;

    /**
     * Defined factory blueprints.
     */
    protected static array $definitions = [];

    /**
     * Model states.
     */
    protected static array $states = [];

    /**
     * After making callbacks.
     */
    protected static array $afterMaking = [];

    /**
     * After creating callbacks.
     */
    protected static array $afterCreating = [];

    /**
     * Get the Faker instance.
     */
    public static function faker(): Generator
    {
        if (self::$faker === null) {
            if (!class_exists(FakerFactory::class)) {
                throw new MachinjiriException(
                    "Faker library is required. Install it via: composer require fakerphp/faker",
                    500
                );
            }
            self::$faker = FakerFactory::create();
        }
        
        return self::$faker;
    }

    /**
     * Define a model factory.
     */
    public static function define(string $model, callable $definition): void
    {
        self::$definitions[$model] = $definition;
    }

    /**
     * Define a state for a model.
     */
    public static function state(string $model, string $state, callable $stateDefinition): void
    {
        self::$states[$model][$state] = $stateDefinition;
    }

    /**
     * Define a callback to run after making a model.
     */
    public static function afterMaking(string $model, callable $callback): void
    {
        self::$afterMaking[$model][] = $callback;
    }

    /**
     * Define a callback to run after creating a model.
     */
    public static function afterCreating(string $model, callable $callback): void
    {
        self::$afterCreating[$model][] = $callback;
    }

    /**
     * Create a new factory instance for the given model.
     */
    public static function factoryForModel(string $model): ModelFactory
    {
        return new ModelFactory($model);
    }

    /**
     * Create a collection of models.
     */
    public static function create(string $model, int $count = 1, array $attributes = []): array
    {
        $instances = [];
        
        for ($i = 0; $i < $count; $i++) {
            $instances[] = self::make($model, $attributes, true);
        }
        
        return $instances;
    }

    /**
     * Make a model instance.
     */
    public static function make(string $model, array $attributes = [], bool $persist = false): mixed
    {
        if (!isset(self::$definitions[$model])) {
            throw new MachinjiriException("No factory defined for model: {$model}", 500);
        }

        // Get the base definition
        $definition = call_user_func(self::$definitions[$model], self::faker());
        
        // Apply any states
        $definition = array_merge($definition, $attributes);
        
        // Create the instance
        $instance = $definition;
        
        // Run after making callbacks
        if (isset(self::$afterMaking[$model])) {
            foreach (self::$afterMaking[$model] as $callback) {
                $callback($instance);
            }
        }
        
        // Persist if requested
        if ($persist) {
            $instance = self::persist($model, $instance);
            
            // Run after creating callbacks
            if (isset(self::$afterCreating[$model])) {
                foreach (self::$afterCreating[$model] as $callback) {
                    $callback($instance);
                }
            }
        }
        
        return $instance;
    }

    /**
     * Persist a model to the database.
     */
    protected static function persist(string $model, array $attributes): array
    {
        $table = self::getTableName($model);
        
        $query = new QueryBuilder($table);
        $query->insert($attributes)->execute();
        
        // Get the inserted ID if available
        if (isset($attributes['id']) || array_key_exists('id', $attributes)) {
            $id = $attributes['id'];
        } else {
            // Try to get the last insert ID
            $result = $query->execute();
            $id = $result['lastInsertId'] ?? null;
        }
        
        // Return the attributes with ID
        if ($id) {
            $attributes['id'] = $id;
        }
        
        return $attributes;
    }

    /**
     * Get table name from model.
     */
    protected static function getTableName(string $model): string
    {
        // Convert from ModelName to table_name
        if (class_exists($model) && method_exists($model, 'getTable')) {
            return (new $model())->getTable();
        }
        
        // Simple conversion: User => users
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $model)) . 's';
    }

    /**
     * Create a raw database record.
     */
    public static function raw(string $table, array $data): array
    {
        $query = new QueryBuilder($table);
        $query->insert($data)->execute();
        
        return $data;
    }

    /**
     * Create multiple raw records.
     */
    public static function rawMany(string $table, array $records): void
    {
        foreach ($records as $record) {
            self::raw($table, $record);
        }
    }
}