<?php
namespace Mlangeni\Machinjiri\Components\Layout;

use Mlangeni\Machinjiri\Components\Base\ComponentBase;
use Mlangeni\Machinjiri\Components\Base\ComponentTrait;

class Container extends ComponentBase
{
    use ComponentTrait;

    private bool $fluid = false;
    private string $breakpoint = ''; // sm, md, lg, xl, xxl
    private array $content = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->addClass('container');
    }

    public function fluid(bool $fluid = true): self
    {
        $this->fluid = $fluid;
        
        if ($fluid) {
            // Remove regular container class
            $key = array_search('container', $this->classes);
            if ($key !== false) {
                unset($this->classes[$key]);
            }
            $this->addClass('container-fluid');
        } else {
            // Remove fluid class if present
            $key = array_search('container-fluid', $this->classes);
            if ($key !== false) {
                unset($this->classes[$key]);
            }
            $this->addClass('container');
        }
        
        return $this;
    }

    public function responsive(string $breakpoint): self
    {
        $this->breakpoint = $breakpoint;
        
        // Remove existing container classes
        $keys = array_keys(array_intersect($this->classes, ['container', 'container-fluid']));
        foreach ($keys as $key) {
            unset($this->classes[$key]);
        }
        
        $this->addClass('container-' . $breakpoint);
        return $this;
    }

    public function addContent(string $content): self
    {
        $this->content[] = $content;
        return $this;
    }

    public function addContents(array $contents): self
    {
        $this->content = array_merge($this->content, $contents);
        return $this;
    }

    public function setContent(array $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function clear(): self
    {
        $this->content = [];
        return $this;
    }

    public function render(): string
    {
        $content = implode("\n", $this->content);
        return $this->renderElement('div', $content);
    }
}