<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Helpers;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class DotEnv
{
  protected ?string $path = null;
  protected $overload;
  private $isArtisan;

  public function __construct(bool $isArtisan, bool $overload = false)
  {
      $this->isArtisan = $isArtisan;
      $this->overload = $overload;
  }
  
  public function setPath(string $path): self 
  {
    $this->path = rtrim($path, DIRECTORY_SEPARATOR);
    return $this;
  }

  public function load(): self
  {
      if ($this->path === null) {
        $path = Container::$appBasePath . '/../';
        if ($this->isArtisan) $path = getcwd()  . DIRECTORY_SEPARATOR;
        $this->setPath($path);
      }
      $filePath = $this->path . DIRECTORY_SEPARATOR . '.env';
      
      $test = ($this->isArtisan) ? "Yes" : "No";
      
      if (!is_file($filePath)) {
          $filePath = str_replace("../", "", $filePath);
          if (!is_file($filePath)) {
            throw new MachinjiriException('Could not locate ENV in ' . $filePath . " isArtisan = " . $test);
          }
          
      }
      
      if (!is_readable($filePath)) {
          throw new MachinjiriException("Environment file is not readable: \n path: " . $filePath);
      }

      $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      foreach ($lines as $line) {
          $line = trim($line);
          if ($this->isComment($line)) {
              continue;
          }
          $this->processLine($line);
      }
      return $this;
  }

  protected function isComment(string $line): bool
  {
      return strpos($line, '#') === 0 || strpos($line, '//') === 0;
  }

  protected function processLine(string $line): void
  {
      list($name, $value) = $this->splitLine($line);
      $name = $this->sanitizeName($name);
      $value = $this->sanitizeValue($value);
      
      $this->setEnvironmentVariable($name, $value);
  }

  protected function splitLine(string $line): array
  {
      $parts = explode('=', $line, 2);
      if (count($parts) !== 2) {
          return [$line, ''];
      }
      return $parts;
  }

  protected function sanitizeName(string $name): string
  {
      $name = trim($name);
      return preg_replace('/^export\h+/i', '', $name);
  }

  protected function sanitizeValue(string $value): string
  {
      $value = trim($value);
      $quote = $value[0] ?? '';
      
      // Handle quoted values
      if ($quote === '"' || $quote === "'") {
          $value = substr($value, 1, -1);
          
          // Unescape characters in double-quoted strings
          if ($quote === '"') {
              $value = str_replace(['\\"', '\\n', '\\r'], ['"', "\n", "\r"], $value);
              $value = preg_replace('/\\\\([\\\\$"])/', '$1', $value);
          }
      }
      
      return $value;
  }

  protected function setEnvironmentVariable(string $name, string $value): void
  {
      $current = getenv($name);
      
      // Don't overwrite existing vars unless overload is enabled
      if ($current !== false && !$this->overload) {
          return;
      }
      
      // Set in all environments
      putenv("$name=$value");
      $_ENV[$name] = $value;
      $_SERVER[$name] = $value;
  }

  public function getVariables(): array
  {
      return $_ENV;
  }
}