<?php

namespace Mlangeni\Machinjiri\Core\Routing\Contracts;

interface RouteCollectionInterface
{
    public function add(RouteInterface $route): void;
    public function addNamedRoute(string $name, RouteInterface $route): void;
    public function get(int $index): ?RouteInterface;
    public function all(): array;
    public function getByName(string $name): ?RouteInterface;
    public function hasNamed(string $name): bool;
    public function last(): ?RouteInterface;
}