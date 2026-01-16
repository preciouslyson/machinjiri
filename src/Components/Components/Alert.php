<?php
namespace Mlangeni\Machinjiri\Components\Components;

use Mlangeni\Machinjiri\Components\Base\ComponentBase;
use Mlangeni\Machinjiri\Components\Base\ComponentTrait;

class Alert extends ComponentBase
{
    use ComponentTrait;

    private string $message = '';
    private bool $dismissible = false;
    private string $icon = '';

    public function __construct(string $message = '', array $attributes = [])
    {
        parent::__construct($attributes);
        $this->message = $message;
        $this->addClass('alert');
        $this->setAttribute('role', 'alert');
    }

    public function primary(): self
    {
        $this->addClass('alert-primary');
        return $this;
    }

    public function secondary(): self
    {
        $this->addClass('alert-secondary');
        return $this;
    }

    public function success(): self
    {
        $this->addClass('alert-success');
        return $this;
    }

    public function danger(): self
    {
        $this->addClass('alert-danger');
        return $this;
    }

    public function warning(): self
    {
        $this->addClass('alert-warning');
        return $this;
    }

    public function info(): self
    {
        $this->addClass('alert-info');
        return $this;
    }

    public function light(): self
    {
        $this->addClass('alert-light');
        return $this;
    }

    public function dark(): self
    {
        $this->addClass('alert-dark');
        return $this;
    }

    public function dismissible(bool $dismissible = true): self
    {
        $this->dismissible = $dismissible;
        if ($dismissible) {
            $this->addClass('alert-dismissible fade show');
        }
        return $this;
    }

    public function withIcon(string $icon): self
    {
        $this->icon = $icon;
        return $this;
    }

    public function render(): string
    {
        $content = '';

        if ($this->icon) {
            $content .= '<i class="' . htmlspecialchars($this->icon) . ' me-2"></i>';
        }

        $content .= $this->message;

        if ($this->dismissible) {
            $content .= '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        }

        return $this->renderElement('div', $content);
    }
}