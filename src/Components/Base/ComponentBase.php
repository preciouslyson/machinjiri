<?php

namespace Mlangeni\Machinjiri\Components\Base;

abstract class ComponentBase
{
    protected array $attributes = [];
    protected array $classes = [];
    protected array $styles = [];
    protected array $dataAttributes = [];

    public function __construct(array $attributes = [])
    {
        $this->setAttributes($attributes);
    }

    public function setId(string $id): self
    {
        $this->attributes['id'] = $id;
        return $this;
    }

    public function addClass(string $class): self
    {
        $this->classes[] = $class;
        return $this;
    }

    public function addClasses(array $classes): self
    {
        $this->classes = array_merge($this->classes, $classes);
        return $this;
    }

    public function setAttribute(string $key, string $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function setAttributes(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }

    public function setData(string $key, string $value): self
    {
        $this->dataAttributes[$key] = $value;
        return $this;
    }

    public function setStyle(string $property, string $value): self
    {
        $this->styles[$property] = $value;
        return $this;
    }

    protected function buildAttributes(): string
    {
        $attrs = [];
        
        // Add classes
        if (!empty($this->classes)) {
            $attrs[] = 'class="' . implode(' ', array_unique($this->classes)) . '"';
        }

        // Add other attributes
        foreach ($this->attributes as $key => $value) {
            if ($value !== null && $value !== '') {
                $attrs[] = $key . '="' . htmlspecialchars($value) . '"';
            }
        }

        // Add data attributes
        foreach ($this->dataAttributes as $key => $value) {
            $attrs[] = 'data-' . $key . '="' . htmlspecialchars($value) . '"';
        }

        // Add inline styles
        if (!empty($this->styles)) {
            $styles = [];
            foreach ($this->styles as $property => $value) {
                $styles[] = $property . ':' . $value;
            }
            $attrs[] = 'style="' . implode(';', $styles) . '"';
        }

        return implode(' ', $attrs);
    }

    abstract public function render(): string;
}