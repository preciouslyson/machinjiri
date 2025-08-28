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
  

  // Share data across all views
  public static function share($key, $value = null)
  {
      if (is_array($key)) {
          self::$shared = array_merge(self::$shared, $key);
      } else {
          self::$shared[$key] = $value;
      }
  }

  // Create view instance
  public static function make($view, $data = [])
  {
      return new self($view, $data);
  }

  // Constructor
  public function __construct($view, $data = [])
  {
      self::$basePath = Container::$appBasePath . "/../resources/views/";
      $this->view = $view;
      $this->data = array_merge(self::$shared, $data);
  }

  // Render the view
  public function render()
  {
      self::$currentInstance = $this;
      
      // Extract data for the view
      extract($this->data);
      
      // Capture view content
      ob_start();
      $this->includeView($this->view);
      $content = ob_get_clean();

      // Process layout if specified
      if ($this->layout) {
          if (!isset($this->sections['content'])) {
              $this->sections['content'] = $content;
          }
          
          ob_start();
          $this->includeView($this->layout);
          $content = ob_get_clean();
      }

      self::$currentInstance = null;
      return $content;
  }

  // Include a view file
  protected function includeView($view)
  {
      $path = self::$basePath . $view . '.php';
      
      if (!file_exists($path)) {
          throw new MachinjiriException("View file not found: {$path}");
      }
      
      include $path;
  }

  // Section handling
  public static function section($name, $content = null)
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

  public static function endsection()
  {
      $instance = self::$currentInstance;
      
      if (!$instance || !$instance->currentSection) {
          throw new MachinjiriException('No active section');
      }
      
      $content = ob_get_clean();
      $instance->sections[$instance->currentSection] = $content;
      $instance->currentSection = null;
  }

  public static function yield($name)
  {
      $instance = self::$currentInstance;
      
      if ($instance && isset($instance->sections[$name])) {
          echo $instance->sections[$name];
      }
  }

  public static function extend($layout)
  {
      $instance = self::$currentInstance;
      
      if (!$instance) {
          throw new MachinjiriException('No active view instance');
      }
      
      $instance->layout = $layout;
  }

  public static function include($view, $data = [])
  {
      $instance = self::$currentInstance;
      
      if (!$instance) {
          throw new MachinjiriException('No active view instance');
      }
      
      // Merge parent data with new data
      $mergedData = array_merge($instance->data, $data);
      extract($mergedData);
      
      $path = self::$basePath . $view . '.php';
      
      if (!file_exists($path)) {
          throw new MachinjiriException("Included view not found: {$path}");
      }
      
      include $path;
  }
  
  public function display()
  {
      try {
        print $this->render();
      } catch (MachinjiriException $e) {
        $e->display();
      }
  }

  // Convert view to string when echoed directly
  public function __toString()
  {
      return $this->render();
  }
  
}
