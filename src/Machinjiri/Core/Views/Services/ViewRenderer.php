<?php

namespace Mlangeni\Machinjiri\Core\Views\Services;

use Mlangeni\Machinjiri\Core\Views\Config\ViewConfig;
use Mlangeni\Machinjiri\Core\Views\Contracts\ViewCompilerInterface;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class ViewRenderer
{
    protected ViewCompilerInterface $compiler;
    protected array $extensionMap = [
        'view'     => '.view.php',
        'layout'   => '.layout.php',
        'fragment' => '.frag.php'
    ];

    public function __construct(ViewCompilerInterface $compiler)
    {
        $this->compiler = $compiler;
    }

    public function resolveViewPath(string $view, string $type): ?string
    {
        $ext = $this->extensionMap[$type] ?? $this->extensionMap['view'];

        if (strpos($view, '::') !== false) {
            [$namespace, $name] = explode('::', $view, 2);
            if (isset(ViewConfig::$namespaces[$namespace])) {
                $path = ViewConfig::$namespaces[$namespace] . str_replace('.', DIRECTORY_SEPARATOR, $name) . $ext;
                return file_exists($path) ? $path : null;
            }
        }

        $path = ViewConfig::getBasePath() . str_replace('.', DIRECTORY_SEPARATOR, $view) . $ext;
        return file_exists($path) ? $path : null;
    }

    public function getCacheFilePath(string $sourcePath): string
    {
        return ViewConfig::getCachePath() . md5($sourcePath) . '.php';
    }

    public function compileAndInclude(string $view, string $type, array $data): string
    {
        $sourcePath = $this->resolveViewPath($view, $type);
        if (!$sourcePath) {
            throw new MachinjiriException("View file not found: {$view} (type: {$type})");
        }

        $cachePath = ViewConfig::getCachePath();
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        $cacheFile = $this->getCacheFilePath($sourcePath);

        if (!file_exists($cacheFile) || filemtime($sourcePath) > filemtime($cacheFile)) {
            $compiled = $this->compiler->compile(file_get_contents($sourcePath));
            file_put_contents($cacheFile, $compiled, LOCK_EX);
        }

        return (function($cacheFile, $data) {
            extract($data, EXTR_SKIP);
            ob_start();
            include $cacheFile;
            unlink($cacheFile);
            return ob_get_clean();
        })($cacheFile, $data);
    }
}