<?php
namespace Mlangeni\Machinjiri\Components\Components;

use Mlangeni\Machinjiri\Components\Base\ComponentBase;
use Mlangeni\Machinjiri\Components\Base\ComponentTrait;

class Input extends ComponentBase
{
    use ComponentTrait;

    private string $type = 'text';
    private string $name = '';
    private string $value = '';
    private string $placeholder = '';
    private bool $readonly = false;
    private bool $required = false;
    private string $label = '';
    private string $helpText = '';
    private bool $floatingLabel = false;

    public function __construct(string $name = '', array $attributes = [])
    {
        parent::__construct($attributes);
        $this->name = $name;
        $this->addClass('form-control');
    }

    public function type(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function text(): self { return $this->type('text'); }
    public function email(): self { return $this->type('email'); }
    public function password(): self { return $this->type('password'); }
    public function number(): self { return $this->type('number'); }
    public function date(): self { return $this->type('date'); }
    public function file(): self { return $this->type('file'); }
    public function checkbox(): self { return $this->type('checkbox'); }
    public function radio(): self { return $this->type('radio'); }
    public function hidden(): self { return $this->type('hidden'); }

    public function name(string $name): self
    {
        $this->name = $name;
        $this->setAttribute('name', $name);
        return $this;
    }

    public function value($value): self
    {
        $this->value = (string) $value;
        $this->setAttribute('value', $this->value);
        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;
        $this->setAttribute('placeholder', $placeholder);
        return $this;
    }

    public function readonly(bool $readonly = true): self
    {
        $this->readonly = $readonly;
        if ($readonly) {
            $this->setAttribute('readonly', 'readonly');
        }
        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;
        if ($required) {
            $this->setAttribute('required', 'required');
        }
        return $this;
    }

    public function disabled(bool $disabled = true): self
    {
        if ($disabled) {
            $this->setAttribute('disabled', 'disabled');
        }
        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function helpText(string $text): self
    {
        $this->helpText = $text;
        return $this;
    }

    public function floatingLabel(bool $floating = true): self
    {
        $this->floatingLabel = $floating;
        return $this;
    }

    public function size(string $size): self
    {
        switch ($size) {
            case 'sm':
                $this->addClass('form-control-sm');
                break;
            case 'lg':
                $this->addClass('form-control-lg');
                break;
        }
        return $this;
    }

    public function plainText(bool $plain = true): self
    {
        if ($plain) {
            $this->removeClass('form-control');
            $this->addClass('form-control-plaintext');
        }
        return $this;
    }

    private function removeClass(string $class): void
    {
        $key = array_search($class, $this->classes);
        if ($key !== false) {
            unset($this->classes[$key]);
        }
    }

    public function render(): string
    {
        $this->setAttributes([
            'type' => $this->type,
            'name' => $this->name,
            'value' => $this->value,
            'placeholder' => $this->placeholder,
        ]);

        $input = $this->renderElement('input');

        if ($this->floatingLabel && $this->label) {
            return sprintf(
                '<div class="form-floating">%s<label for="%s">%s</label></div>',
                $input,
                $this->attributes['id'] ?? $this->name,
                htmlspecialchars($this->label)
            );
        }

        if ($this->label || $this->helpText) {
            $html = '';
            if ($this->label) {
                $html .= sprintf(
                    '<label for="%s" class="form-label">%s</label>',
                    $this->attributes['id'] ?? $this->name,
                    htmlspecialchars($this->label)
                );
            }
            $html .= $input;
            if ($this->helpText) {
                $html .= sprintf('<div class="form-text">%s</div>', htmlspecialchars($this->helpText));
            }
            return $html;
        }

        return $input;
    }
}