<?php

namespace Mlangeni\Machinjiri\Core\Views;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class View
{
    protected static $shared = [];
    protected static $componentsPath;
    protected static $basePath;
    protected static $cachePath;
    protected static $currentInstance = null;
    protected static $assetsPath;
    protected static $assetsUrl;
    protected static $assetTimestamps = []; // per‑request cache

    protected $view;
    protected $data;
    protected $layout = null;
    protected $sections = [];
    protected $currentSection = null;

    protected $extensionMap = [
        'view'     => '.view.php',
        'layout'   => '.layout.php',
        'fragment' => '.frag.php'
    ];

    /**
     * Share data across all views.
     */
    public static function share($key, $value = null): void
    {
        if (is_array($key)) {
            self::$shared = array_merge(self::$shared, $key);
        } else {
            self::$shared[$key] = $value;
        }
    }

    /**
     * Set the base directory for asset files (absolute path).
     */
    public static function setAssetsPath(string $path): void
    {
        self::$assetsPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Set the base URL for serving assets.
     */
    public static function setAssetsUrl(string $url): void
    {
        self::$assetsUrl = rtrim($url, '/') . '/';
    }

    /**
     * Get the base assets path – defaults to public/src/.
     */
    protected static function getAssetsPath(): string
    {
        if (!self::$assetsPath) {
            $base = rtrim(Container::$appBasePath, '/\\');
            self::$assetsPath = $base . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        }
        return self::$assetsPath;
    }

    /**
     * Get the base assets URL using APP_URL from .env if available.
     */
    protected static function getAssetsUrl(): string
    {
        if (!self::$assetsUrl) {
            $appUrl = env('APP_URL'); // read from .env
            if ($appUrl) {
                self::$assetsUrl = rtrim($appUrl, '/') . '/src/';
            } else {
                // fallback to auto‑detection
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                self::$assetsUrl = $protocol . $host . '/src/';
            }
        }
        return self::$assetsUrl;
    }

    /**
     * Generate a versioned (hashed) URL for an asset.
     * Version = file modification time (cached per request).
     *
     * @throws MachinjiriException
     */
    public static function asset(string $path): string
    {
        // Basic security: forbid directory traversal
        if (strpos($path, '..') !== false) {
            throw new MachinjiriException("Invalid asset path: {$path}");
        }

        $fullPath = self::getAssetsPath() . ltrim($path, '/\\');
        if (!file_exists($fullPath)) {
            throw new MachinjiriException("Asset file not found: {$fullPath}");
        }

        // Cache filemtime per request
        if (!isset(self::$assetTimestamps[$fullPath])) {
            self::$assetTimestamps[$fullPath] = filemtime($fullPath);
        }
        $version = self::$assetTimestamps[$fullPath];

        $url = self::getAssetsUrl() . ltrim($path, '/\\');
        $separator = (parse_url($url, PHP_URL_QUERY) ? '&' : '?');
        return $url . $separator . 'v=' . $version;
    }

    /**
     * Generate a <link> tag for a CSS file.
     */
    public static function style(string $path, array $attributes = []): string
    {
        $url = self::asset($path);
        $attrs = self::buildAttributes($attributes);
        return '<link rel="stylesheet" href="' . htmlspecialchars($url) . '"' . $attrs . '>';
    }

    /**
     * Generate a <script> tag for a JavaScript file.
     */
    public static function script(string $path, array $attributes = []): string
    {
        $url = self::asset($path);
        $attrs = self::buildAttributes($attributes);
        return '<script src="' . htmlspecialchars($url) . '"' . $attrs . '></script>';
    }

    /**
     * Helper to build HTML attribute string.
     */
    protected static function buildAttributes(array $attributes): string
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

    /**
     * Legacy resource loader – loads all CSS/JS files recursively.
     * @deprecated Use explicit style()/script() calls in production.
     */
    public static function loadResource(string $type, string $path = ""): string
    {
        $resource = "";
        $basePath = self::getAssetsPath();

        if (empty($path)) {
            $typeDir = $basePath . $type;
            if (!is_dir($typeDir)) {
                return '';
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($typeDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) continue;
                $relativePath = str_replace($basePath, '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);
                if ($type === 'css') {
                    $resource .= self::style($relativePath) . "\n";
                } elseif ($type === 'js') {
                    $resource .= self::script($relativePath) . "\n";
                }
            }
        } else {
            $relativePath = $type . '/' . ltrim($path, '/\\');
            if ($type === 'css') {
                $resource = self::style($relativePath);
            } elseif ($type === 'js') {
                $resource = self::script($relativePath);
            }
        }
        return $resource;
    }

    /**
     * Create a new view instance.
     */
    public static function make(string $view, array $data = []): self
    {
        return new self($view, $data);
    }

    /**
     * Constructor – sets up paths and data.
     */
    public function __construct(string $view, array $data = [])
    {
        $this->view = $view;
        $this->data = array_merge(self::$shared, $data);

        // Configure view paths – allow override via Container config
        $config = Container::$config ?? [];
        self::$basePath = $config['views.path'] ?? (Container::$appBasePath . '/../resources/views/');
        self::$cachePath = $config['views.cache'] ?? (Container::$appBasePath . '/../storage/cache/views/');

        if (!is_dir(self::$cachePath)) {
            mkdir(self::$cachePath, 0755, true);
        }
    }

    /**
     * Render the view (with layout if defined).
     */
    public function render(): string
    {
        self::$currentInstance = $this;

        // Capture main view content
        $content = $this->compileAndInclude($this->view, 'view', $this->data);

        // Apply layout if any
        if ($this->layout) {
            if (!isset($this->sections['content'])) {
                $this->sections['content'] = $content;
            }
            $content = $this->compileAndInclude($this->layout, 'layout', $this->data);
        }

        self::$currentInstance = null;
        return $content;
    }

    /**
     * Compile (if needed) and include a view file.
     */
    protected function compileAndInclude(string $view, string $type, array $data): string
    {
        $extension = $this->extensionMap[$type] ?? $this->extensionMap['view'];
        $sourcePath = self::$basePath . $view . $extension;

        if (!file_exists($sourcePath)) {
            throw new MachinjiriException("View file not found: {$sourcePath}");
        }

        // Compiled cache path
        $cacheFile = $this->getCacheFilePath($sourcePath);

        // Recompile if source is newer or cache missing
        if (!file_exists($cacheFile) || filemtime($sourcePath) > filemtime($cacheFile)) {
            $compiled = $this->compile(file_get_contents($sourcePath));
            file_put_contents($cacheFile, $compiled, LOCK_EX);
        }

        // Extract data and include cache file
        extract($data);
        ob_start();
        include $cacheFile;
        return ob_get_clean();
    }

    /**
     * Generate a unique cache file path for a given source view.
     */
    protected function getCacheFilePath(string $sourcePath): string
    {
        $hash = md5($sourcePath);
        return self::$cachePath . $hash . '.php';
    }

    /**
     * Compile custom template tags into raw PHP.
     */
    protected function compile(string $content): string
    {
        // Convert custom tags to PHP
        $patterns = [
            '/<%\s*content\s*%>/'                           => '<?php View::yield(\'content\'); ?>',
            '/<%\s*section\(([^)]+)\)\s*%>/'                => '<?php View::section($1); ?>',
            '/<%\s*endsection\s*%>/'                        => '<?php View::endSection(); ?>',
            '/<%\s*include\s+([^%]+)\s*%>/'                 => '<?php View::include($1); ?>',
            '/<%\s*extend\s+([^%]+)\s*%>/'                  => '<?php View::extend($1); ?>',
            '/<%=\s*([^%]+?)\s*%>/'                         => '<?php echo $1; ?>',
            '/<%\s*if\s*\(([^)]+)\)\s*%>/'                  => '<?php if ($1): ?>',
            '/<%\s*else\s*%>/'                              => '<?php else: ?>',
            '/<%\s*endif\s*%>/'                             => '<?php endif; ?>',
            '/<%\s*foreach\s*\(([^)]+)\)\s*%>/'             => '<?php foreach ($1): ?>',
            '/<%\s*endforeach\s*%>/'                        => '<?php endforeach; ?>',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $content);
    }

    /**
     * Start a new section.
     */
    public static function section(string $name, ?string $content = null): void
    {
        $instance = self::$currentInstance;
        if (!$instance) {
            throw new MachinjiriException('No active view instance');
        }

        if ($content !== null) {
            $instance->sections[$name] = $content;
        } else {
            if ($instance->currentSection) {
                throw new MachinjiriException('Cannot nest sections');
            }
            $instance->currentSection = $name;
            ob_start();
        }
    }

    /**
     * End the current section.
     */
    public static function endSection(): void
    {
        $instance = self::$currentInstance;
        if (!$instance || !$instance->currentSection) {
            throw new MachinjiriException('No active section');
        }
        $instance->sections[$instance->currentSection] = ob_get_clean();
        $instance->currentSection = null;
    }

    /**
     * Yield a section's content.
     */
    public static function yield(string $name): void
    {
        $instance = self::$currentInstance;
        if ($instance && isset($instance->sections[$name])) {
            echo $instance->sections[$name];
        }
    }

    /**
     * Extend a layout.
     */
    public static function extend(string $layout): void
    {
        $instance = self::$currentInstance;
        if (!$instance) {
            throw new MachinjiriException('No active view instance');
        }
        $instance->layout = $layout;
    }

    /**
     * Include a partial view.
     */
    public static function include(string $view, array $data = []): void
    {
        $instance = self::$currentInstance;
        if (!$instance) {
            throw new MachinjiriException('No active view instance');
        }

        $view = trim($view, '\'"');
        $mergedData = array_merge($instance->data, $data);

        // Try fragment extension first, then view extension
        $foundPath = null;
        foreach (['fragment', 'view'] as $type) {
            $ext = $instance->extensionMap[$type];
            $testPath = self::$basePath . $view . $ext;
            if (file_exists($testPath)) {
                $foundPath = $testPath;
                break;
            }
        }

        if (!$foundPath) {
            throw new MachinjiriException("Included view not found: " . self::$basePath . $view);
        }

        echo $instance->compileAndInclude($view, 'fragment', $mergedData);
    }

    /**
     * Display the rendered view (handles exceptions).
     */
    public function display(): void
    {
        try {
            echo $this->render();
        } catch (MachinjiriException $e) {
            $e->show();
        }
    }

    public function __toString(): string
    {
        return $this->render();
    }

    public static function hasSection(string $name): bool
    {
        $instance = self::$currentInstance;
        return $instance && isset($instance->sections[$name]);
    }

    public static function getSection(string $name): string
    {
        $instance = self::$currentInstance;
        return $instance && isset($instance->sections[$name]) ? $instance->sections[$name] : '';
    }

    public static function parent(string $name): void
    {
        $instance = self::$currentInstance;
        if ($instance && isset($instance->sections[$name])) {
            echo $instance->sections[$name];
        }
    }
}