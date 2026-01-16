<?php
namespace Mlangeni\Machinjiri\Components\Components;

use Mlangeni\Machinjiri\Components\Base\ComponentBase;
use Mlangeni\Machinjiri\Components\Base\ComponentTrait;

class Modal extends ComponentBase
{
    use ComponentTrait;

    private string $title = '';
    private string $body = '';
    private string $footer = '';
    private bool $staticBackdrop = false;
    private bool $centered = false;
    private bool $scrollable = false;
    private string $size = ''; // sm, lg, xl

    public function __construct(string $id = '', array $attributes = [])
    {
        parent::__construct($attributes);
        if ($id) {
            $this->setId($id);
        }
        $this->addClass('modal fade');
        $this->setAttribute('tabindex', '-1');
        $this->setAttribute('aria-hidden', 'true');
    }

    public function title(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function footer(string $footer): self
    {
        $this->footer = $footer;
        return $this;
    }

    public function staticBackdrop(bool $static = true): self
    {
        $this->staticBackdrop = $static;
        if ($static) {
            $this->setData('bs-backdrop', 'static');
        }
        return $this;
    }

    public function centered(bool $centered = true): self
    {
        $this->centered = $centered;
        return $this;
    }

    public function scrollable(bool $scrollable = true): self
    {
        $this->scrollable = $scrollable;
        return $this;
    }

    public function size(string $size): self
    {
        $this->size = $size;
        return $this;
    }

    public function render(): string
    {
        $dialogClasses = ['modal-dialog'];
        if ($this->centered) {
            $dialogClasses[] = 'modal-dialog-centered';
        }
        if ($this->scrollable) {
            $dialogClasses[] = 'modal-dialog-scrollable';
        }
        if ($this->size) {
            $dialogClasses[] = 'modal-' . $this->size;
        }

        $content = '<div class="' . implode(' ', $dialogClasses) . '">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">' . htmlspecialchars($this->title) . '</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">' . $this->body . '</div>';

        if ($this->footer) {
            $content .= '<div class="modal-footer">' . $this->footer . '</div>';
        }

        $content .= '</div></div>';

        return $this->renderElement('div', $content);
    }
}