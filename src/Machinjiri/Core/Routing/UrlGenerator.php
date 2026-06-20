<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Routing\Contracts\RouteCollectionInterface;
use Mlangeni\Machinjiri\Core\Routing\Contracts\UrlGeneratorInterface;

class UrlGenerator implements UrlGeneratorInterface
{
    protected string $basePath;
    protected string $baseUrl;
    protected $collection;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->baseUrl = $this->detectBaseUrl();
    }

    public function url(string $name, array $params = []): string
    {
        $route = $this->collection->getByName($name);
        if (!$route) {
            throw new MachinjiriException("Route '{$name}' not found");
        }

        $pattern = $route->getPattern();
        foreach ($params as $key => $value) {
            $pattern = str_replace('{' . $key . '}', urlencode((string)$value), $pattern);
        }

        if ($this->basePath && strpos($pattern, $this->basePath) !== 0) {
            $pattern = $this->basePath . $pattern;
        }

        return $pattern;
    }

    public function absoluteUrl(string $name, array $params = []): string
    {
        return $this->baseUrl . $this->url($name, $params);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function detectBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host . $this->basePath;
    }

    public function setCollection(RouteCollectionInterface $collection): void
    {
        $this->collection = $collection;
    }
}