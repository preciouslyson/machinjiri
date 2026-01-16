<?php

namespace Mlangeni\Machinjiri\Core\Database\Factory;

class ModelFactory
{
    protected string $model;
    protected int $count = 1;
    protected array $attributes = [];
    protected array $states = [];

    public function __construct(string $model)
    {
        $this->model = $model;
    }

    /**
     * Set the number of models to create.
     */
    public function count(int $count): self
    {
        $this->count = $count;
        return $this;
    }

    /**
     * Set model attributes.
     */
    public function attributes(array $attributes): self
    {
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * Apply a state to the model.
     */
    public function state(string $state): self
    {
        $this->states[] = $state;
        return $this;
    }

    /**
     * Create models.
     */
    public function create(): array
    {
        // Apply states to attributes
        $attributes = $this->attributes;
        
        if (isset(Factory::$states[$this->model])) {
            foreach ($this->states as $state) {
                if (isset(Factory::$states[$this->model][$state])) {
                    $stateAttributes = call_user_func(
                        Factory::$states[$this->model][$state],
                        Factory::faker()
                    );
                    $attributes = array_merge($attributes, $stateAttributes);
                }
            }
        }
        
        return Factory::create($this->model, $this->count, $attributes);
    }

    /**
     * Make models without persisting.
     */
    public function make(): array
    {
        $instances = [];
        
        for ($i = 0; $i < $this->count; $i++) {
            $instances[] = Factory::make($this->model, $this->attributes, false);
        }
        
        return $instances;
    }
}