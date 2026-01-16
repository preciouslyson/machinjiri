<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Database;

use \PDOStatement;
use Mlangeni\Machinjiri\Core\Database\MysqlGrammar;

interface Connection {
  
  public static function setConfig(array $config): void;
  
  public static function getInstance();
  
  public static function executeQuery(string $sql, array $params = []): PDOStatement;
  
  public static function getDriver(): string;
  
  public static function beginTransaction(): void;
  
  public static function commit(): void;
  
  public static function rollback(): void;
  
  
  
}