<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Dev;

use \RuntimeException;

class DevServer 
{
  private const host = 'localhost';
  private const port = 3000;
  /**
   * Starts a developmental server using PHP's built-in web server.
   *
   * @param string $host The hostname or IP address to bind to.
   * @param int $port The port number to bind to.
   * @param string $documentRoot The document root directory.
   */
  public static function startDevelopmentServer(string $documentRoot = '.') : array
  {  
      $output = [self::host, self::port];
      $returnCode = 0;
      $command = 'php -S localhost:3000 -t ' . $documentRoot;
      exec($command, $output, $returnCode);
      
      if ($returnCode == 0) {
        throw new RuntimeException ("Unable to start server at the moment");
      }
      
      return $output;
  }

}