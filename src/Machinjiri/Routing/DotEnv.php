<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
class DotEnv
{
  protected $path;
  protected $overload;

  public function __construct(string $path, bool $overload = false)
  {
      $this->path = rtrim($path, DIRECTORY_SEPARATOR);
      $this->overload = $overload;
  }

  public function load(): void
  {
      $pathToFile = $this->path . DIRECTORY_SEPARATOR . '.env';
      
      if (!is_file($pathToFile)) {
          $pathToFile = "./.env";
      }
      
      $filePath = $pathToFile;
      
      if (!is_readable($filePath)) {
          throw new MachinjiriException("Environment file is not readable");
      }

      $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      foreach ($lines as $line) {
          $line = trim($line);
          if ($this->isComment($line)) {
              continue;
          }
          $this->processLine($line);
      }
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