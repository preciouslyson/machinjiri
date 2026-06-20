<?php

namespace Mlangeni\Machinjiri\Core\Views\Services;

use Mlangeni\Machinjiri\Core\Views\Contracts\AssetManagerInterface;
use Mlangeni\Machinjiri\Core\Views\Config\ViewConfig;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class AssetManager implements AssetManagerInterface
{
    protected static array $assetTimestamps = [];

    public function asset(string $path): string
    {
        if (strpos($path, '..') !== false) {
            throw new MachinjiriException("Invalid asset path: {$path}");
        }

        $fullPath = ViewConfig::getAssetsPath() . ltrim($path, '/\\');
        if (!file_exists($fullPath)) {
            throw new MachinjiriException("Asset file not found: {$fullPath}");
        }

        if (!isset(self::$assetTimestamps[$fullPath])) {
            self::$assetTimestamps[$fullPath] = filemtime($fullPath);
        }
        $version = self::$assetTimestamps[$fullPath];

        $url = ViewConfig::getAssetsUrl() . ltrim($path, '/\\');
        $separator = (parse_url($url, PHP_URL_QUERY) ? '&' : '?');
        return $url . $separator . 'v=' . $version;
    }

    public function style(string $path, array $attributes = []): void
    {
        $url = $this->asset($path);
        $attrs = $this->buildAttributes($attributes);
        printf('<link rel="stylesheet" href="%s"%s>', htmlspecialchars($url), $attrs);
    }

    public function script(string $path, array $attributes = []): void
    {
        $url = $this->asset($path);
        $attrs = $this->buildAttributes($attributes);
        printf('<script src="%s"%s></script>', htmlspecialchars($url), $attrs);
    }

    public function setAssetsPath(string $path): void
    {
        ViewConfig::setAssetsPath($path);
    }

    public function setAssetsUrl(string $url): void
    {
        ViewConfig::setAssetsUrl($url);
    }

    public function getAssetsPath(): string
    {
        return ViewConfig::getAssetsPath();
    }

    public function getAssetsUrl(): string
    {
        return ViewConfig::getAssetsUrl();
    }

    public function buildAttributes(array $attributes): string
    {
        $attrs = '';
        foreach ($attributes as $key => $value) {
            if (is_int($key)) {
                $attrs .= ' ' . htmlspecialchars($value);
            } elseif (is_bool($value) && $value) {
                $attrs .= ' ' . htmlspecialchars($key);
            } else {
                $attrs .= sprintf(' %s="%s"', htmlspecialchars($key), htmlspecialchars($value));
            }
        }
        return $attrs;
    }
}