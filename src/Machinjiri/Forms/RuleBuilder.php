<?php

namespace Mlangeni\Machinjiri\Core\Forms;
use Mlangeni\Machinjiri\Core\Forms\FormValidator;
class RuleBuilder
{
    private string $field;
    private FormValidator $validator;

    public function __construct(string $field, FormValidator $validator)
    {
        $this->field = $field;
        $this->validator = $validator;
    }

    public function __call(string $method, array $params): self
    {
        $this->validator->addRule($this->field, $method, ...$params);
        return $this;
    }

    public function custom(callable $rule, string $message = null): self
    {
        $this->validator->addRule($this->field, $rule);
        if ($message) {
            $this->validator->setCustomMessage($this->field, 'custom', $message);
        }
        return $this;
    }
}