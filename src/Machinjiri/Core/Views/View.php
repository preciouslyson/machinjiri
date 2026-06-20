<?php

namespace Mlangeni\Machinjiri\Core\Views;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Views\Config\ViewConfig;
use Mlangeni\Machinjiri\Core\Views\Services\AssetManager;
use Mlangeni\Machinjiri\Core\Views\Services\ViewCompiler;
use Mlangeni\Machinjiri\Core\Views\Services\ViewRenderer;

class View
{
    // Active view stack (for nesting)
    protected static array $instanceStack = [];

    // Prevent duplicate rendering of the same view
    private static $yielded = [];

    // Service instances (lazy-loaded)
    private static ?AssetManager $assetManager = null;
    private static ?ViewRenderer $renderer = null;

    // Instance properties
    protected string $view;
    protected array $data;
    protected ?string $layout = null;
    protected array $sections = [];
    protected array $parentSections = [];
    protected ?string $currentSection = null;
    
    public static function share(array|string $key, mixed $value = null): void
    {
        ViewConfig::share($key, $value);
    }

    public static function composer(string $view, callable $callback): void
    {
        ViewConfig::composer($view, $callback);
    }

    public static function addNamespace(string $namespace, string $path): void
    {
        ViewConfig::addNamespace($namespace, $path);
    }

    public static function setAssetsPath(string $path): void
    {
        self::getAssetManager()->setAssetsPath($path);
    }

    public static function setAssetsUrl(string $url): void
    {
        self::getAssetManager()->setAssetsUrl($url);
    }

    public static function asset(string $path): string
    {
        return self::getAssetManager()->asset($path);
    }

    public static function style(string $path, array $attributes = []): void
    {
        self::getAssetManager()->style($path, $attributes);
    }

    public static function script(string $path, array $attributes = []): void
    {
        self::getAssetManager()->script($path, $attributes);
    }

    public static function make(string $view, array $data = []): self
    {
        return new self($view, $data);
    }

    public static function section(string $name, ?string $content = null): void
    {
        self::getCurrentInstance()->sectionInternal($name, $content);
    }

    public static function endSection(): void
    {
        self::getCurrentInstance()->endSectionInternal();
    }

    public static function yield(string $name): void
    {
        self::getCurrentInstance()->yieldInternal($name);
    }

    public static function extend(string $layout): void
    {
        self::getCurrentInstance()->extendInternal($layout);
    }

    public static function parent(): void
    {
        self::getCurrentInstance()->parentInternal();
    }

    public static function include(string $view, array $data = []): void
    {
        self::getCurrentInstance()->includeInternal($view, $data);
    }

    public static function hasSection(string $name): bool
    {
        return self::getCurrentInstance()->hasSectionInternal($name);
    }

    public static function getSection(string $name): string
    {
        return self::getCurrentInstance()->getSectionInternal($name);
    }

    // -------------------------------------------------------------------------
    //  Instance methods
    // -------------------------------------------------------------------------

    public function __construct(string $view, array $data = [])
    {
        $this->view = $view;
        $this->data = array_merge(ViewConfig::$shared, $data);
    }

    public function render(): string
    {
        if (isset(self::$yielded[$this->view])) {
            return "";
        }
        self::$yielded[$this->view] = true;

        array_push(self::$instanceStack, $this);

        // Run view composer if registered
        if (isset(ViewConfig::$composers[$this->view])) {
            (ViewConfig::$composers[$this->view])($this);
        }

        // Capture main view content
        $content = $this->getRenderer()->compileAndInclude($this->view, 'view', $this->data);

        // Apply layout if any
        if ($this->layout) {
            if (!isset($this->sections['content'])) {
                $this->sections['content'] = $content;
            }
            $content = $this->getRenderer()->compileAndInclude($this->layout, 'layout', $this->data);
        }

        array_pop(self::$instanceStack);

        return $content;
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

    // -------------------------------------------------------------------------
    //  Internal instance methods (used by static wrappers)
    // -------------------------------------------------------------------------

    protected function sectionInternal(string $name, ?string $content = null): void
    {
        if ($content !== null) {
            $this->sections[$name] = $content;
        } else {
            if ($this->currentSection) {
                throw new MachinjiriException('Cannot nest sections');
            }

            if (isset($this->sections[$name])) {
                $this->parentSections[$name] = $this->sections[$name];
            }

            $this->currentSection = $name;
            ob_start();
        }
    }

    protected function endSectionInternal(): void
    {
        if (!$this->currentSection) {
            throw new MachinjiriException('No active section');
        }

        $content = ob_get_clean();
        $sectionName = $this->currentSection;
        $this->sections[$sectionName] = $content;
        $this->currentSection = null;
    }

    protected function yieldInternal(string $name): void
    {
        print $this->sections[$name] ?? '';
    }

    protected function extendInternal(string $layout): void
    {
        $this->layout = trim($layout, '\'"');
    }

    protected function parentInternal(): void
    {
        $sectionName = $this->currentSection;
        if (!$sectionName) {
            throw new MachinjiriException('@parent must be used inside a section');
        }

        if (isset($this->parentSections[$sectionName])) {
            echo $this->parentSections[$sectionName];
        }
    }

    protected function includeInternal(string $view, array $data = []): void
    {
        $view = trim($view, '\'"');

        if (is_string($data) && str_starts_with($data, '[')) {
            $data = json_decode($data, true) ?? [];
        }

        $mergedData = array_merge($this->data, $data);
        $content = $this->getRenderer()->compileAndInclude($view, 'fragment', $mergedData);
        echo $content;
    }

    protected function hasSectionInternal(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    protected function getSectionInternal(string $name): string
    {
        return $this->sections[$name] ?? '';
    }

    // -------------------------------------------------------------------------
    //  Service providers (lazy-loaded)
    // -------------------------------------------------------------------------

    protected static function getAssetManager(): AssetManager
    {
        if (self::$assetManager === null) {
            self::$assetManager = new AssetManager();
        }
        return self::$assetManager;
    }

    protected static function getRenderer(): ViewRenderer
    {
        if (self::$renderer === null) {
            self::$renderer = new ViewRenderer(new ViewCompiler());
        }
        return self::$renderer;
    }

    protected static function getCurrentInstance(): self
    {
        if (empty(self::$instanceStack)) {
            throw new MachinjiriException('No active view instance');
        }
        return end(self::$instanceStack);
    }
}