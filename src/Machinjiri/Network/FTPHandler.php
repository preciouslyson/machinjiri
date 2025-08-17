<?php

namespace Mlangeni\Machinjiri\Core\Network;
use \Exception;

class FTPHandler {
  private $connection;
  private $host;
  private $username;
  private $password;
  private $port;
  private $timeout;
  private $useSsl;

  /**
   * Constructor for FTPHandler.
   * 
   * @param string $host     FTP server hostname
   * @param string $username FTP username
   * @param string $password FTP password
   * @param int    $port     FTP port (default 21)
   * @param int    $timeout  Connection timeout in seconds (default 30)
   * @param bool   $useSsl   Use SSL-FTP connection (default false)
   */
  public function __construct(
      string $host,
      string $username,
      string $password,
      int $port = 21,
      int $timeout = 30,
      bool $useSsl = false
  ) {
      $this->host = $host;
      $this->username = $username;
      $this->password = $password;
      $this->port = $port;
      $this->timeout = $timeout;
      $this->useSsl = $useSsl;
  }

  /**
   * Establish FTP connection.
   * 
   * @throws Exception If connection fails
   */
  public function connect(): void {
      if ($this->useSsl) {
          $this->connection = @ftp_ssl_connect($this->host, $this->port, $this->timeout);
      } else {
          $this->connection = @ftp_connect($this->host, $this->port, $this->timeout);
      }

      if (!$this->connection) {
          throw new Exception("FTP connection failed to {$this->host}:{$this->port}");
      }

      if (!@ftp_login($this->connection, $this->username, $this->password)) {
          $this->disconnect();
          throw new Exception("FTP login failed for user: {$this->username}");
      }

      // Enable passive mode for better compatibility
      ftp_pasv($this->connection, true);
  }

  /**
   * Close FTP connection.
   */
  public function disconnect(): void {
      if ($this->connection) {
          ftp_close($this->connection);
          $this->connection = null;
      }
  }

  /**
   * Create or overwrite a file on FTP server.
   * 
   * @param string $remotePath Remote file path
   * @param string $content    File content
   * @throws Exception If file creation fails
   */
  public function createFile(string $remotePath, string $content): void {
      $this->validateConnection();

      // Use temporary local file
      $tempFile = tmpfile();
      if ($tempFile === false) {
          throw new Exception("Failed to create temporary file");
      }

      // Write content to temp file
      fwrite($tempFile, $content);
      rewind($tempFile);

      // Upload to server
      if (!@ftp_fput($this->connection, $remotePath, $tempFile, FTP_BINARY)) {
          fclose($tempFile);
          throw new Exception("Failed to create file: {$remotePath}");
      }

      fclose($tempFile);
  }

  /**
   * Read file content from FTP server.
   * 
   * @param string $remotePath Remote file path
   * @return string File contents
   * @throws Exception If file read fails
   */
  public function readFile(string $remotePath): string {
      $this->validateConnection();

      $tempFile = tmpfile();
      if ($tempFile === false) {
          throw new Exception("Failed to create temporary file");
      }

      // Download file
      if (!@ftp_fget($this->connection, $tempFile, $remotePath, FTP_BINARY)) {
          fclose($tempFile);
          throw new Exception("Failed to read file: {$remotePath}");
      }

      // Read content from temp file
      rewind($tempFile);
      $content = stream_get_contents($tempFile);
      fclose($tempFile);

      if ($content === false) {
          throw new Exception("Failed to read temporary file content");
      }

      return $content;
  }

  /**
   * Modify existing file by replacing its content.
   * 
   * @param string $remotePath Remote file path
   * @param string $newContent New file content
   * @throws Exception If modification fails
   */
  public function modifyFile(string $remotePath, string $newContent): void {
      $this->createFile($remotePath, $newContent); // Overwrites existing file
  }

  /**
   * Append content to existing file.
   * 
   * @param string $remotePath Remote file path
   * @param string $content    Content to append
   * @throws Exception If file operation fails
   */
  public function appendToFile(string $remotePath, string $content): void {
      $this->validateConnection();

      // Download current content
      $currentContent = $this->readFile($remotePath);
      
      // Append new content and upload
      $this->createFile($remotePath, $currentContent . $content);
  }

  /**
   * Validate active connection.
   * 
   * @throws Exception If not connected
   */
  private function validateConnection(): void {
      if (!$this->connection) {
          throw new Exception("Not connected to FTP server");
      }
  }
  
  public function uploadFile(string $localPath, string $remotePath): void {
      $this->validateConnection();
      
      $handle = fopen($localPath, 'rb');
      if (!$handle) {
          throw new Exception("Failed to open local file: {$localPath}");
      }

      if (!@ftp_fput($this->connection, $remotePath, $handle, FTP_BINARY)) {
          fclose($handle);
          throw new Exception("Failed to upload file to: {$remotePath}");
      }
      
      fclose($handle);
  }

  // Destructor ensures connection is closed
  public function __destruct() {
      $this->disconnect();
  }
}
