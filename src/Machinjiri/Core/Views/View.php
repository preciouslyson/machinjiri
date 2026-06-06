<?php

namespace Mlangeni\Machinjiri\Core\Views;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class View
{
    // Shared data across all views
    protected static array $shared = [];

    // View composers (callbacks keyed by view name)
    protected static array $composers = [];

    // Namespace => base path mapping for modular views
    protected static array $namespaces = [];

    // Path configuration
    protected static string $basePath;
    protected static string $cachePath;
    protected static string $assetsPath;
    protected static string $assetsUrl;

    // Stack of active view instances (for nesting)
    protected static array $instanceStack = [];

    // Per‑request asset timestamp cache
    protected static array $assetTimestamps = [];

    // Instance properties
    protected string $view;
    protected array $data;
    protected ?string $layout = null;
    protected array $sections = [];
    protected array $parentSections = []; // for @parent support
    protected ?string $currentSection = null;
    private static $yielded = [];

    // File extension mapping
    protected array $extensionMap = [
        'view'     => '.view.php',
        'layout'   => '.layout.php',
        'fragment' => '.frag.php'
    ];
    /**
     * Share data across all views.
     */
    public static function share(array|string $key, mixed $value = null): void
    {
        if (is_array($key)) {
            self::$shared = array_merge(self::$shared, $key);
        } else {
            self::$shared[$key] = $value;
        }
    }

    /**
     * Register a view composer.
     */
    public static function composer(string $view, callable $callback): void
    {
        self::$composers[$view] = $callback;
    }

    /**
     * Add a namespace for modular views (e.g. 'blog::post').
     */
    public static function addNamespace(string $namespace, string $path): void
    {
        self::$namespaces[$namespace] = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Set asset base path (absolute filesystem path).
     */
    public static function setAssetsPath(string $path): void
    {
        self::$assetsPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Set asset base URL.
     */
    public static function setAssetsUrl(string $url): void
    {
        self::$assetsUrl = rtrim($url, '/') . '/';
    }

    protected static function getAssetsPath(): string
    {
        if (!isset(self::$assetsPath)) {
            $base = rtrim(Container::$appBasePath . '/../', '/\\');
            self::$assetsPath = $base . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        }
        return self::$assetsPath;
    }

    protected static function getAssetsUrl(): string
    {
        if (!isset(self::$assetsUrl)) {
            $appUrl = env('ASSET_URL') ?? env('APP_URL');
            if ($appUrl) {
                self::$assetsUrl = rtrim($appUrl, '/') . '/src/';
            } else {
                // Auto‑detect with subdirectory support
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
                $baseDir = $scriptDir === '/' ? '' : $scriptDir;
                self::$assetsUrl = $protocol . $host . $baseDir . '/src/';
            }
        }
        return self::$assetsUrl;
    }

    /**
     * Generate a versioned asset URL (filemtime-based).
     *
     * @throws MachinjiriException
     */
    public static function asset(string $path): string
    {
        if (strpos($path, '..') !== false) {
            throw new MachinjiriException("Invalid asset path: {$path}");
        }

        $fullPath = self::getAssetsPath() . ltrim($path, '/\\');
        if (!file_exists($fullPath)) {
            throw new MachinjiriException("Asset file not found: {$fullPath}");
        }

        if (!isset(self::$assetTimestamps[$fullPath])) {
            self::$assetTimestamps[$fullPath] = filemtime($fullPath);
        }
        $version = self::$assetTimestamps[$fullPath];

        $url = self::getAssetsUrl() . ltrim($path, '/\\');
        $separator = (parse_url($url, PHP_URL_QUERY) ? '&' : '?');
        return $url . $separator . 'v=' . $version;
    }

    public static function style(string $path, array $attributes = []): void
    {
        $url = self::asset($path);
        $attrs = self::buildAttributes($attributes);
        printf('<link rel="stylesheet" href="%s"%s>', htmlspecialchars($url), $attrs);
    }

    public static function script(string $path, array $attributes = []): void
    {
        $url = self::asset($path);
        $attrs = self::buildAttributes($attributes);
        printf('<script src="%s"%s></script>', htmlspecialchars($url), $attrs);
    }

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

    public static function make(string $view, array $data = []): self
    {
        return new self($view, $data);
    }

    public function __construct(string $view, array $data = [])
    {
        $this->view = $view;
        $this->data = array_merge(self::$shared, $data);

        $config = Container::$config ?? [];
        self::$basePath = $config['views.path'] ?? (Container::$appBasePath . '/../resources/views/');
        self::$cachePath = $config['views.cache'] ?? (Container::$appBasePath . '/../storage/cache/views/');
    }

    /**
     * Render the view (with layout if defined).
     */
    public function render(): string
    {
        if (isset(self::$yielded[$this->view])) return "";
        self::$yielded[$this->view] = true;
        
        array_push(self::$instanceStack, $this);

        // Run view composer if registered
        if (isset(self::$composers[$this->view])) {
            (self::$composers[$this->view])($this);
        }

        // Capture main view content
        $content = $this->compileAndInclude($this->view, 'view', $this->data);

        // Apply layout if any
        if ($this->layout) {
            if (!isset($this->sections['content'])) {
                $this->sections['content'] = $content;
            }
            $content = $this->compileAndInclude($this->layout, 'layout', $this->data);
        }

        // Pop instance from stack
        array_pop(self::$instanceStack);

        return $content;
    }

    /**
     * Compile (if needed) and include a view file.
     */
    protected function compileAndInclude(string $view, string $type, array $data): string
    {
        $sourcePath = $this->resolveViewPath($view, $type);
        if (!$sourcePath) {
            throw new MachinjiriException("View file not found: {$view} (type: {$type})");
        }

        // Ensure cache directory exists (lazy creation)
        if (!is_dir(self::$cachePath)) {
            mkdir(self::$cachePath, 0755, true);
        }

        $cacheFile = $this->getCacheFilePath($sourcePath);

        if (!file_exists($cacheFile) || filemtime($sourcePath) > filemtime($cacheFile)) {
            $compiled = $this->compile(file_get_contents($sourcePath));
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

    
    protected function resolveViewPath(string $view, string $type): ?string
    {
        $ext = $this->extensionMap[$type] ?? $this->extensionMap['view'];

        // Namespace syntax: 'blog::post'
        if (strpos($view, '::') !== false) {
            [$namespace, $name] = explode('::', $view, 2);
            if (isset(self::$namespaces[$namespace])) {
                $path = self::$namespaces[$namespace] . str_replace('.', DIRECTORY_SEPARATOR, $name) . $ext;
                return file_exists($path) ? $path : null;
            }
        }

        // Normal view (dot notation)
        $path = self::$basePath . str_replace('.', DIRECTORY_SEPARATOR, $view) . $ext;
        return file_exists($path) ? $path : null;
    }

    protected function getCacheFilePath(string $sourcePath): string
    {
        return self::$cachePath . md5($sourcePath) . '.php';
    }

    /**
     * Compile custom tags to PHP (with auto‑escaping).
     */
    protected function compile(string $content): string
    {
        // Order matters: raw output {!! !!} first, then escaped {{ }}, then control structures
        $patterns = [
            '/{!!(.*?)!!}/s'                               => '<?php echo $1; ?>',
            '/{{(.*?)}}/s'                                 => '<?php echo htmlspecialchars($1, ENT_QUOTES, "UTF-8"); ?>',
            '/<%\s*content\s*%>/'                          => '<?php View::yield(\'content\'); ?>',
            '/<%\s*section\(([^)]+)\)\s*%>/'               => '<?php View::section($1); ?>',
            '/<%\s*endsection\s*%>/'                       => '<?php View::endSection(); ?>',
            '/<%\s*include\s+([^,]+?)(?:,\s*(.+?))?\s*%>/' => '<?php View::include($1, $2 ?? []); ?>',
            '/<%\s*extend\s+([^%]+)\s*%>/'                 => '<?php View::extend($1); ?>',
            '/<%\s*if\s+(.+?)\s*%>/'                       => '<?php if ($1): ?>',
            '/<%\s*else\s*%>/'                             => '<?php else: ?>',
            '/<%\s*endif\s*%>/'                            => '<?php endif; ?>',
            '/<%\s*foreach\s+(.+?)\s*%>/'                  => '<?php foreach ($1): ?>',
            '/<%\s*endforeach\s*%>/'                       => '<?php endforeach; ?>',
            '/<%\s*parent\s*%>/'                           => '<?php View::parent(); ?>',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $content);
    }
    
    public static function section(string $name, ?string $content = null): void
    {
        $instance = self::getCurrentInstance();
    
        if ($content !== null) {
            $instance->sections[$name] = $content;
        } else {
            if ($instance->currentSection) {
                throw new MachinjiriException('Cannot nest sections');
            }
    
            // If this section already has content (from a parent view), preserve it as the parent content
            if (isset($instance->sections[$name])) {
                $instance->parentSections[$name] = $instance->sections[$name];
            }
    
            $instance->currentSection = $name;
            ob_start();
        }
    }

    public static function endSection(): void
    {
        $instance = self::getCurrentInstance();
        if (!$instance->currentSection) {
            throw new MachinjiriException('No active section');
        }
    
        $content = ob_get_clean();
        $sectionName = $instance->currentSection;
    
        // Overwrite the section with the newly captured content
        $instance->sections[$sectionName] = $content;
        $instance->currentSection = null;
    }

    public static function yield(string $name): void
    {
        $instance = self::getCurrentInstance();
        print $instance->sections[$name] ?? '';
    }

    public static function extend(string $layout): void
    {
        $instance = self::getCurrentInstance();
        $instance->layout = trim($layout, '\'"');
    }

    public static function parent(): void
    {
        $instance = self::getCurrentInstance();
        $sectionName = $instance->currentSection;
    
        if (!$sectionName) {
            // If no active section, parent() cannot be called meaningfully
            throw new MachinjiriException('@parent must be used inside a section');
        }
    
        if (isset($instance->parentSections[$sectionName])) {
            echo $instance->parentSections[$sectionName];
        }
    }

    public static function include(string $view, array $data = []): void
    {
        $instance = self::getCurrentInstance();
        $view = trim($view, '\'"');

        // Allow passing data as string (legacy) – convert to array
        if (is_string($data) && str_starts_with($data, '[')) {
            $data = json_decode($data, true) ?? [];
        }

        $mergedData = array_merge($instance->data, $data);
        $content = $instance->compileAndInclude($view, 'fragment', $mergedData);
        echo $content;
    }

    // -------------------------------------------------------------------------
    //  Utility Methods
    // -------------------------------------------------------------------------

    public static function hasSection(string $name): bool
    {
        $instance = self::getCurrentInstance();
        return isset($instance->sections[$name]);
    }

    public static function getSection(string $name): string
    {
        $instance = self::getCurrentInstance();
        return $instance->sections[$name] ?? '';
    }

    /**
     * Get the currently active view instance (supports nesting).
     */
    protected static function getCurrentInstance(): self
    {
        if (empty(self::$instanceStack)) {
            throw new MachinjiriException('No active view instance');
        }
        return end(self::$instanceStack);
    }

    public function with(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function display(): void
    {
        try {
            print $this->render();
        } catch (MachinjiriException $e) {
            $e->show();
        }
    }

    public function __toString(): string
    {
        return $this->render();
    }
}