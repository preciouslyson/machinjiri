<?php
namespace Mlangeni\Machinjiri\Components\Components;

use Mlangeni\Machinjiri\Components\Base\ComponentBase;
use Mlangeni\Machinjiri\Components\Base\ComponentTrait;

class Button extends ComponentBase
{
    use ComponentTrait;

    private string $type = 'button';
    private string $text = '';
    private bool $outline = false;
    private string $size = '';
    private bool $disabled = false;
    private bool $block = false;

    public function __construct(string $text = '', array $attributes = [])
    {
        parent::__construct($attributes);
        $this->text = $text;
        $this->addClass('btn');
    }

    public function primary(): self
    {
        $this->addClass($this->outline ? 'btn-outline-primary' : 'btn-primary');
        return $this;
    }

    public function secondary(): self
    {
        $this->addClass($this->outline ? 'btn-outline-secondary' : 'btn-secondary');
        return $this;
    }

    public function success(): self
    {
        $this->addClass($this->outline ? 'btn-outline-success' : 'btn-success');
        return $this;
    }

    public function danger(): self
    {
        $this->addClass($this->outline ? 'btn-outline-danger' : 'btn-danger');
        return $this;
    }

    public function warning(): self
    {
        $this->addClass($this->outline ? 'btn-outline-warning' : 'btn-warning');
        return $this;
    }

    public function info(): self
    {
        $this->addClass($this->outline ? 'btn-outline-info' : 'btn-info');
        return $this;
    }

    public function light(): self
    {
        $this->addClass($this->outline ? 'btn-outline-light' : 'btn-light');
        return $this;
    }

    public function dark(): self
    {
        $this->addClass($this->outline ? 'btn-outline-dark' : 'btn-dark');
        return $this;
    }

    public function link(): self
    {
        $this->addClass('btn-link');
        return $this;
    }

    public function outline(bool $outline = true): self
    {
        $this->outline = $outline;
        return $this;
    }

    public function small(): self
    {
        $this->size = 'sm';
        $this->addClass('btn-sm');
        return $this;
    }

    public function large(): self
    {
        $this->size = 'lg';
        $this->addClass('btn-lg');
        return $this;
    }

    public function block(bool $block = true): self
    {
        $this->block = $block;
        if ($block) {
            $this->addClass('btn-block d-block w-100');
        }
        return $this;
    }

    public function disabled(bool $disabled = true): self
    {
        $this->disabled = $disabled;
        if ($disabled) {
            $this->setAttribute('disabled', 'disabled');
            $this->setAttribute('aria-disabled', 'true');
        }
        return $this;
    }

    public function asLink(string $href): self
    {
        $this->type = 'link';
        $this->setAttribute('href', $href);
        $this->setAttribute('role', 'button');
        return $this;
    }

    public function asSubmit(): self
    {
        $this->type = 'submit';
        $this->setAttribute('type', 'submit');
        return $this;
    }

    public function asReset(): self
    {
        $this->type = 'reset';
        $this->setAttribute('type', 'reset');
        return $this;
    }

    public function render(): string
    {
        if ($this->type === 'link') {
            return $this->renderElement('a', $this->text);
        }

        if (!isset($this->attributes['type'])) {
            $this->setAttribute('type', $this->type);
        }

        return $this->renderElement('button', $this->text);
    }
}