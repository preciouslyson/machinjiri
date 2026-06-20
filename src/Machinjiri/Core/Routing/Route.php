<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Routing\Contracts\RouteInterface;

class Route implements RouteInterface
{
    public function __construct(
        protected array $methods,
        protected string $pattern,
        protected mixed $handler,
        protected ?string $name,
        protected string $regex,
        protected array $middleware = [],
        protected ?array $cors = null,
        protected ?string $rateLimit = null,
        protected bool $ajaxOnly = false,
        protected bool $noAjax = false,
        protected array $constraints = [],
        protected array $bindings = []
    ) {}

    public function getMethods(): array
    {
        return $this->methods;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getHandler(): mixed
    {
        return $this->handler;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getRegex(): string
    {
        return $this->regex;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getCors(): ?array
    {
        return $this->cors;
    }

    public function getRateLimit(): ?string
    {
        return $this->rateLimit;
    }

    public function isAjaxOnly(): bool
    {
        return $this->ajaxOnly;
    }

    public function isNoAjax(): bool
    {
        return $this->noAjax;
    }

    public function getConstraints(): array
    {
        return $this->constraints;
    }

    public function setRegex(string $regex): void
    {
        $this->regex = $regex;
    }

    public function __serialize(): array
    {
        // Omit non-serializable handler closures – we'll skip caching those routes or store as string
        return [
            'methods' => $this->methods,
            'pattern' => $this->pattern,
            'handler' => is_callable($this->handler) ? null : $this->handler,
            'name' => $this->name,
            'regex' => $this->regex,
            'middleware' => $this->middleware,
            'cors' => $this->cors,
            'rateLimit' => $this->rateLimit,
            'ajaxOnly' => $this->ajaxOnly,
            'noAjax' => $this->noAjax,
            'constraints' => $this->constraints,
            'bindings' => $this->bindings,
        ];
    }

    public function __unserialize(array $data): void
    {
        foreach ($data as $k => $v) $this->$k = $v;
    }

    public function getBindings() : array 
    {
        return $this->bindings;
    }
}