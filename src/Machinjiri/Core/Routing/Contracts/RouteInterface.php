<?php

namespace Mlangeni\Machinjiri\Core\Routing\Contracts;

interface RouteInterface
{
    public function getMethods(): array;
    public function getPattern(): string;
    public function getHandler(): mixed;
    public function getName(): ?string;
    public function getRegex(): string;
    public function getMiddleware(): array;
    public function getCors(): ?array;
    public function getRateLimit(): ?string;
    public function isAjaxOnly(): bool;
    public function isNoAjax(): bool;
    public function getConstraints(): array;
    public function setRegex(string $regex): void;
}