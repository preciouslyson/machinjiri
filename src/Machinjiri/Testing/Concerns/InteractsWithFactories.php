<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

trait InteractsWithFactories
{
    /**
     * Create a model using its factory.
     */
    protected function create(string $model, array $attributes = [], int $count = 1)
    {
        $factoryClass = $model . 'Factory';
        if (!class_exists($factoryClass)) {
            throw new \Exception("Factory {$factoryClass} not found for model {$model}");
        }
        $factory = new $factoryClass();
        $instances = [];
        for ($i = 0; $i < $count; $i++) {
            $instances[] = $factory->create($attributes);
        }
        return $count === 1 ? $instances[0] : $instances;
    }

    /**
     * Make a model without persisting.
     */
    protected function make(string $model, array $attributes = [])
    {
        $factoryClass = $model . 'Factory';
        if (!class_exists($factoryClass)) {
            throw new \Exception("Factory {$factoryClass} not found for model {$model}");
        }
        $factory = new $factoryClass();
        return $factory->make($attributes);
    }
}