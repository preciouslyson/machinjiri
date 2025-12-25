<?php
namespace Mlangeni\Machinjiri\Components\Components;

use Mlangeni\Machinjiri\Components\Base\ComponentBase;
use Mlangeni\Machinjiri\Components\Base\ComponentTrait;

class Card extends ComponentBase
{
    use ComponentTrait;

    private string $title = '';
    private string $subtitle = '';
    private string $text = '';
    private string $header = '';
    private string $footer = '';
    private array $bodyItems = [];
    private array $listItems = [];
    private string $image = '';
    private string $imageAlt = '';
    private bool $imageTop = true;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->addClass('card');
    }

    public function title(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function subtitle(string $subtitle): self
    {
        $this->subtitle = $subtitle;
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function header(string $header): self
    {
        $this->header = $header;
        return $this;
    }

    public function footer(string $footer): self
    {
        $this->footer = $footer;
        return $this;
    }

    public function addBodyItem(string $item): self
    {
        $this->bodyItems[] = $item;
        return $this;
    }

    public function addListItems(array $items): self
    {
        $this->listItems = array_merge($this->listItems, $items);
        return $this;
    }

    public function image(string $src, string $alt = '', bool $top = true): self
    {
        $this->image = $src;
        $this->imageAlt = $alt;
        $this->imageTop = $top;
        return $this;
    }

    public function primary(): self { return $this->addClass('text-bg-primary'); }
    public function secondary(): self { return $this->addClass('text-bg-secondary'); }
    public function success(): self { return $this->addClass('text-bg-success'); }
    public function danger(): self { return $this->addClass('text-bg-danger'); }
    public function warning(): self { return $this->addClass('text-bg-warning'); }
    public function info(): self { return $this->addClass('text-bg-info'); }
    public function light(): self { return $this->addClass('text-bg-light'); }
    public function dark(): self { return $this->addClass('text-bg-dark'); }

    public function render(): string
    {
        $content = '';

        // Image at top
        if ($this->image && $this->imageTop) {
            $content .= sprintf(
                '<img src="%s" class="card-img-top" alt="%s">',
                htmlspecialchars($this->image),
                htmlspecialchars($this->imageAlt)
            );
        }

        // Header
        if ($this->header) {
            $content .= '<div class="card-header">' . $this->header . '</div>';
        }

        // Card body
        $bodyContent = '';
        if ($this->title) {
            $bodyContent .= '<h5 class="card-title">' . htmlspecialchars($this->title) . '</h5>';
        }
        if ($this->subtitle) {
            $bodyContent .= '<h6 class="card-subtitle mb-2 text-muted">' . htmlspecialchars($this->subtitle) . '</h6>';
        }
        if ($this->text) {
            $bodyContent .= '<p class="card-text">' . htmlspecialchars($this->text) . '</p>';
        }
        
        foreach ($this->bodyItems as $item) {
            $bodyContent .= $item;
        }

        // List items
        if (!empty($this->listItems)) {
            $bodyContent .= '<ul class="list-group list-group-flush">';
            foreach ($this->listItems as $item) {
                $bodyContent .= '<li class="list-group-item">' . htmlspecialchars($item) . '</li>';
            }
            $bodyContent .= '</ul>';
        }

        if ($bodyContent) {
            $content .= '<div class="card-body">' . $bodyContent . '</div>';
        }

        // Image at bottom
        if ($this->image && !$this->imageTop) {
            $content .= sprintf(
                '<img src="%s" class="card-img-bottom" alt="%s">',
                htmlspecialchars($this->image),
                htmlspecialchars($this->imageAlt)
            );
        }

        // Footer
        if ($this->footer) {
            $content .= '<div class="card-footer">' . $this->footer . '</div>';
        }

        return $this->renderElement('div', $content);
    }
}