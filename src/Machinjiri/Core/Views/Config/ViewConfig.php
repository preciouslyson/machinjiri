<?php

namespace Mlangeni\Machinjiri\Core\Views\Config;

use Mlangeni\Machinjiri\Core\Container;

class ViewConfig
{
    public static array $shared = [];
    public static array $composers = [];
    public static array $namespaces = [];
    public static ?string $basePath = null;
    public static ?string $cachePath = null;
    public static ?string $assetsPath = null;
    public static ?string $assetsUrl = null;

    public static function share(array|string $key, mixed $value = null): void
    {
        if (is_array($key)) {
            self::$shared = array_merge(self::$shared, $key);
        } else {
            self::$shared[$key] = $value;
        }
    }

    public static function composer(string $view, callable $callback): void
    {
        self::$composers[$view] = $callback;
    }

    public static function addNamespace(string $namespace, string $path): void
    {
        self::$namespaces[$namespace] = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function setAssetsPath(string $path): void
    {
        self::$assetsPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function setAssetsUrl(string $url): void
    {
        self::$assetsUrl = rtrim($url, '/') . '/';
    }

    public static function getAssetsPath(): string
    {
        if (!isset(self::$assetsPath)) {
            $base = rtrim(Container::$appBasePath . '/../', '/\\');
            self::$assetsPath = $base . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        }
        return self::$assetsPath;
    }

    public static function getAssetsUrl(): string
    {
        if (!isset(self::$assetsUrl)) {
            $appUrl = env('ASSET_URL') ?? env('APP_URL');
            if ($appUrl) {
                self::$assetsUrl = rtrim($appUrl, '/') . '/src/';
            } else {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
                $baseDir = $scriptDir === '/' ? '' : $scriptDir;
                self::$assetsUrl = $protocol . $host . $baseDir . '/src/';
            }
        }
        return self::$assetsUrl;
    }

    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function getBasePath(): string
    {
        if (!isset(self::$basePath)) {
            self::$basePath = Container::$appBasePath . '/../resources/views/';
        }
        return self::$basePath;
    }

    public static function setCachePath(string $path): void
    {
        self::$cachePath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function getCachePath(): string
    {
        if (!isset(self::$cachePath)) {
            self::$cachePath = Container::$appBasePath . '/../storage/cache/views/';
        }
        return self::$cachePath;
    }
}