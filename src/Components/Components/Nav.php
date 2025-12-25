<?php

namespace Mlangeni\Machinjiri\Components\Components;

use Mlangeni\Machinjiri\Components\Base\ComponentBase;
use Mlangeni\Machinjiri\Components\Base\ComponentTrait;

class Nav extends ComponentBase
{
    use ComponentTrait;

    private array $items = [];
    private bool $tabs = false;
    private bool $pills = false;
    private bool $vertical = false;
    private bool $justified = false;
    private bool $fill = false;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->addClass('nav');
    }

    public function addItem(string $label, string $href = '#', bool $active = false, bool $disabled = false): self
    {
        $this->items[] = [
            'label' => $label,
            'href' => $href,
            'active' => $active,
            'disabled' => $disabled,
        ];
        return $this;
    }

    public function tabs(bool $tabs = true): self
    {
        $this->tabs = $tabs;
        $this->addClass('nav-tabs');
        return $this;
    }

    public function pills(bool $pills = true): self
    {
        $this->pills = $pills;
        $this->addClass('nav-pills');
        return $this;
    }

    public function vertical(bool $vertical = true): self
    {
        $this->vertical = $vertical;
        $this->addClass('flex-column');
        return $this;
    }

    public function justified(bool $justified = true): self
    {
        $this->justified = $justified;
        $this->addClass('nav-justified');
        return $this;
    }

    public function fill(bool $fill = true): self
    {
        $this->fill = $fill;
        $this->addClass('nav-fill');
        return $this;
    }

    public function render(): string
    {
        $content = '';
        foreach ($this->items as $item) {
            $classes = ['nav-link'];
            if ($item['active']) {
                $classes[] = 'active';
                $classes[] = 'aria-current="page"';
            }
            if ($item['disabled']) {
                $classes[] = 'disabled';
            }

            $content .= sprintf(
                '<li class="nav-item"><a class="%s" href="%s">%s</a></li>',
                implode(' ', $classes),
                htmlspecialchars($item['href']),
                htmlspecialchars($item['label'])
            );
        }

        return $this->renderElement('ul', $content);
    }
}