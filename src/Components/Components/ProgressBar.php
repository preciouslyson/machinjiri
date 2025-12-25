<?php
namespace Mlangeni\Machinjiri\Components\Components;

use Mlangeni\Machinjiri\Components\Base\ComponentBase;
use Mlangeni\Machinjiri\Components\Base\ComponentTrait;

class ProgressBar extends ComponentBase
{
    use ComponentTrait;

    private int $value = 0;
    private int $min = 0;
    private int $max = 100;
    private string $text = '';
    private bool $striped = false;
    private bool $animated = false;
    private bool $showLabel = false;

    public function __construct(int $value = 0, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->value = $value;
        $this->addClass('progress');
    }

    public function value(int $value): self
    {
        $this->value = max($this->min, min($this->max, $value));
        return $this;
    }

    public function min(int $min): self
    {
        $this->min = $min;
        return $this;
    }

    public function max(int $max): self
    {
        $this->max = $max;
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function striped(bool $striped = true): self
    {
        $this->striped = $striped;
        return $this;
    }

    public function animated(bool $animated = true): self
    {
        $this->animated = $animated;
        return $this;
    }

    public function showLabel(bool $show = true): self
    {
        $this->showLabel = $show;
        return $this;
    }

    public function primary(): self { return $this->addClass('bg-primary'); }
    public function secondary(): self { return $this->addClass('bg-secondary'); }
    public function success(): self { return $this->addClass('bg-success'); }
    public function danger(): self { return $this->addClass('bg-danger'); }
    public function warning(): self { return $this->addClass('bg-warning'); }
    public function info(): self { return $this->addClass('bg-info'); }

    public function render(): string
    {
        $percentage = (($this->value - $this->min) / ($this->max - $this->min)) * 100;
        
        $barClasses = ['progress-bar'];
        if ($this->striped) {
            $barClasses[] = 'progress-bar-striped';
        }
        if ($this->animated) {
            $barClasses[] = 'progress-bar-animated';
        }

        $barAttributes = [
            'class' => implode(' ', $barClasses),
            'role' => 'progressbar',
            'style' => 'width: ' . $percentage . '%',
            'aria-valuenow' => $this->value,
            'aria-valuemin' => $this->min,
            'aria-valuemax' => $this->max,
        ];

        $barContent = '';
        if ($this->showLabel || $this->text) {
            $barContent = $this->text ?: $this->value . '%';
        }

        $bar = '<div ' . $this->buildAttributesFromArray($barAttributes) . '>' . $barContent . '</div>';
        
        return $this->renderElement('div', $bar);
    }

    private function buildAttributesFromArray(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $key => $value) {
            $parts[] = $key . '="' . htmlspecialchars($value) . '"';
        }
        return implode(' ', $parts);
    }
}