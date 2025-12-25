<?php
namespace Mlangeni\Machinjiri\Components\Components;

use Mlangeni\Machinjiri\Components\Base\ComponentBase;
use Mlangeni\Machinjiri\Components\Base\ComponentTrait;

class Form extends ComponentBase
{
    use ComponentTrait;

    private string $method = 'post';
    private string $action = '';
    private bool $needsValidation = false;
    private array $fields = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }

    public function method(string $method): self
    {
        $this->method = strtolower($method);
        $this->setAttribute('method', $this->method);
        return $this;
    }

    public function action(string $action): self
    {
        $this->action = $action;
        $this->setAttribute('action', $action);
        return $this;
    }

    public function needsValidation(bool $validate = true): self
    {
        $this->needsValidation = $validate;
        if ($validate) {
            $this->addClass('needs-validation');
            $this->setAttribute('novalidate', 'novalidate');
        }
        return $this;
    }

    public function inline(bool $inline = true): self
    {
        if ($inline) {
            $this->addClass('form-inline');
        }
        return $this;
    }

    public function addField(string $field): self
    {
        $this->fields[] = $field;
        return $this;
    }

    public function addFields(array $fields): self
    {
        $this->fields = array_merge($this->fields, $fields);
        return $this;
    }

    public function render(): string
    {
        $content = implode("\n", $this->fields);
        return $this->renderElement('form', $content);
    }
}