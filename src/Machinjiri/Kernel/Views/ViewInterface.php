<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Views;

/**
 * ViewInterface defines the contract for view/template rendering
 * 
 * All view implementations must follow this contract to ensure
 * consistent template rendering and data management.
 */
interface ViewInterface
{
    /**
     * Create a new view instance
     * 
     * @param string $view View name/path
     * @param array $data View data
     * @return self Fluent interface
     */
    public static function make(string $view, array $data = []): self;

    /**
     * Share data across all views
     * 
     * @param string|array $key Data key or array of data
     * @param mixed $value Data value (if key is string)
     * @return void
     */
    public static function share($key, $value = null): void;

    /**
     * Set view data
     * 
     * @param string|array $key Data key or array of data
     * @param mixed $value Data value (if key is string)
     * @return self Fluent interface
     */
    public function with($key, $value = null): self;

    /**
     * Get view data
     * 
     * @return array All view data
     */
    public function getData(): array;

    /**
     * Get a specific data value
     * 
     * @param string $key Data key
     * @param mixed $default Default value if not found
     * @return mixed The data value
     */
    public function get(string $key, $default = null);

    /**
     * Set the layout to use
     * 
     * @param string $layout Layout name/path
     * @return self Fluent interface
     */
    public function layout(string $layout): self;

    /**
     * Get the layout
     * 
     * @return string|null Layout name
     */
    public function getLayout(): ?string;

    /**
     * Render the view and return as string
     * 
     * @return string Rendered view HTML
     */
    public function render(): string;

    /**
     * Render and output the view
     * 
     * @return void
     */
    public function output(): void;

    /**
     * Start a view section
     * 
     * @param string $section Section name
     * @return void
     */
    public function section(string $section): void;

    /**
     * End current section
     * 
     * @return void
     */
    public function endSection(): void;

    /**
     * Get a section content
     * 
     * @param string $section Section name
     * @param string|null $default Default content if not found
     * @return string Section content
     */
    public function getSection(string $section, ?string $default = null): string;

    /**
     * Include a view file
     * 
     * @param string $view View name/path
     * @param array $data View data
     * @return string Rendered view
     */
    public function include(string $view, array $data = []): string;

    /**
     * Include a component
     * 
     * @param string $component Component name
     * @param array $props Component properties
     * @return string Rendered component
     */
    public function component(string $component, array $props = []): string;

    /**
     * Check if view file exists
     * 
     * @param string $view View name/path
     * @return bool True if view exists
     */
    public function exists(string $view): bool;

    /**
     * Get view file path
     * 
     * @param string $view View name/path
     * @return string File path
     */
    public function getPath(string $view): string;

    /**
     * Clear shared data
     * 
     * @return void
     */
    public static function clearShared(): void;

    /**
     * Get shared data
     * 
     * @return array Shared data
     */
    public static function getShared(): array;

    /**
     * Set base views path
     * 
     * @param string $path Path to views directory
     * @return void
     */
    public static function setBasePath(string $path): void;

    /**
     * Set components path
     * 
     * @param string $path Path to components directory
     * @return void
     */
    public static function setComponentsPath(string $path): void;

    /**
     * Magic toString method
     * 
     * @return string Rendered view
     */
    public function __toString(): string;
}
