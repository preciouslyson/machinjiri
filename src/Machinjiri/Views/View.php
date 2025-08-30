<?php

namespace Mlangeni\Machinjiri\Core\Views;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class View
{
    protected static $shared = [];
    protected static $basePath;
    protected static $currentInstance = null;

    protected $view;
    protected $data;
    protected $layout = null;
    protected $sections = [];
    protected $currentSection = null;
    protected $extensionMap = [
        'view' => '.mg.php',
        'layout' => '.mg.layout.php',
        'fragment' => '.mg.frg.php'
    ];

    /**
     * Share data across all views
     *
     * @param string|array $key
     * @param mixed $value
     * @return void
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
     * Create view instance
     *
     * @param string $view
     * @param array $data
     * @return self
     */
    public static function make(string $view, array $data = []): self
    {
        return new self($view, $data);
    }

    /**
     * Constructor
     *
     * @param string $view
     * @param array $data
     */
    public function __construct(string $view, array $data = [])
    {
        self::$basePath = Container::$appBasePath . "/../resources/views/";
        $this->view = $view;
        $this->data = array_merge(self::$shared, $data);
    }

    /**
     * Render the view
     *
     * @return string
     * @throws MachinjiriException
     */
    public function render(): string
    {
        self::$currentInstance = $this;
        
        // Extract data for the view
        extract($this->data);
        
        // Capture view content
        ob_start();
        $this->includeView($this->view, 'view');
        $content = ob_get_clean();

        // Process layout if specified
        if ($this->layout) {
            if (!isset($this->sections['content'])) {
                $this->sections['content'] = $content;
            }
            
            ob_start();
            $this->includeView($this->layout, 'layout');
            $content = ob_get_clean();
        }

        self::$currentInstance = null;
        return $content;
    }

    /**
     * Include a view file with appropriate extension
     *
     * @param string $view
     * @param string $type
     * @return void
     * @throws MachinjiriException
     */
    protected function includeView(string $view, string $type = 'view'): void
    {
        $extension = $this->extensionMap[$type] ?? $this->extensionMap['view'];
        $path = self::$basePath . $view . $extension;
        
        if (!file_exists($path)) {
            throw new MachinjiriException("View file not found: {$path}");
        }
        
        include $path;
    }

    /**
     * Start a new section
     *
     * @param string $name
     * @param string|null $content
     * @return void
     * @throws MachinjiriException
     */
    public static function section(string $name, string $content = null): void
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
     * End the current section
     *
     * @return void
     * @throws MachinjiriException
     */
    public static function endSection(): void
    {
        $instance = self::$currentInstance;
        
        if (!$instance || !$instance->currentSection) {
            throw new MachinjiriException('No active section');
        }
        
        $content = ob_get_clean();
        $instance->sections[$instance->currentSection] = $content;
        $instance->currentSection = null;
    }

    /**
     * Yield a section's content
     *
     * @param string $name
     * @return void
     */
    public static function yield(string $name): void
    {
        $instance = self::$currentInstance;
        
        if ($instance && isset($instance->sections[$name])) {
            echo $instance->sections[$name];
        }
    }

    /**
     * Extend a layout
     *
     * @param string $layout
     * @return void
     * @throws MachinjiriException
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
     * Include a partial view
     *
     * @param string $view
     * @param array $data
     * @return void
     * @throws MachinjiriException
     */
    public static function include(string $view, array $data = []): void
    {
        $instance = self::$currentInstance;
        
        if (!$instance) {
            throw new MachinjiriException('No active view instance');
        }
        
        // Merge parent data with new data
        $mergedData = array_merge($instance->data, $data);
        extract($mergedData);
        
        // Try to find the file with appropriate extension
        $path = null;
        $extensions = [
            $instance->extensionMap['fragment'],
            $instance->extensionMap['view']
        ];
        
        foreach ($extensions as $extension) {
            $testPath = self::$basePath . $view . $extension;
            if (file_exists($testPath)) {
                $path = $testPath;
                break;
            }
        }
        
        if (!$path) {
            throw new MachinjiriException("Included view not found: " . self::$basePath . $view . " with extensions " . implode(', ', $extensions));
        }
        
        include $path;
    }
    
    /**
     * Display the rendered view
     *
     * @return void
     */
    public function display(): void
    {
        try {
            echo $this->render();
        } catch (MachinjiriException $e) {
            $e->display();
        }
    }

    /**
     * Convert view to string when echoed directly
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->render();
    }
    
    /**
     * Check if a section exists
     *
     * @param string $name
     * @return bool
     */
    public static function hasSection(string $name): bool
    {
        $instance = self::$currentInstance;
        return $instance && isset($instance->sections[$name]);
    }
    
    /**
     * Get the content of a section
     *
     * @param string $name
     * @return string
     */
    public static function getSection(string $name): string
    {
        $instance = self::$currentInstance;
        return $instance && isset($instance->sections[$name]) ? $instance->sections[$name] : '';
    }
    
    /**
     * Add a parent section content
     *
     * @param string $name
     * @return void
     */
    public static function parent(string $name): void
    {
        $instance = self::$currentInstance;
        if ($instance && isset($instance->sections[$name])) {
            echo $instance->sections[$name];
        }
    }
}