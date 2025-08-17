<?php

namespace Mlangeni\Machinjiri\Core\Files;
use \Exception;
use Mlangeni\Machinjiri\Core\Network\FTPHandler;
use NexaPay\Controllers\System\Settings;

class FileUploader
{
  private $uploadDir = __DIR__ . "/../../../../storage/files/uploads/";
  private $allowedTypes = ["jpg", "jpeg", "png", "pdf"];
  private $maxSize = 5242880; // 5MB default
  private $errors = [];
  private $uploadedFilePath = '';
  private $fileName = '';
  

  public function __construct(private string $group, $config = [])
  {
      if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0755, true)) {
          throw new Exception("Upload directory doesn't exist and cannot be created");
      }

      if (isset($config['upload_dir'])) $this->setUploadDir($config['upload_dir']);
      if (isset($config['allowed_types'])) $this->setAllowedTypes($config['allowed_types']);
      if (isset($config['max_size'])) $this->setMaxSize($config['max_size']);
  }

  public function setUploadDir($path)
  {
      $this->uploadDir = rtrim($path, '/') . '/';
      
      if (!is_dir($this->uploadDir)) {
          if (!mkdir($this->uploadDir, 0755, true)) {
              throw new Exception("Upload directory doesn't exist and cannot be created");
          }
      }
      
      if (!is_writable($this->uploadDir)) {
          throw new Exception("Upload directory is not writable");
      }
  }

  public function setAllowedTypes($types)
  {
      if (is_array($types)) {
          $this->allowedTypes = $types;
      }
  }

  public function setMaxSize($bytes)
  {
      $this->maxSize = (int)$bytes;
  }

  public function upload($fileInputName)
  {
      if (!isset($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] == UPLOAD_ERR_NO_FILE) {
          $this->errors[] = "No file was uploaded";
          return false;
      }

      $file = $_FILES[$fileInputName];
      $this->errors = [];
      $this->fileName = $this->sanitizeFileName($file['name']);

      // Validate upload errors
      if ($file['error'] !== UPLOAD_ERR_OK) {
          $this->handleUploadError($file['error']);
          return false;
      }

      // Validate file size
      if ($file['size'] > $this->maxSize) {
          $this->errors[] = "File exceeds maximum size of " . $this->formatBytes($this->maxSize);
          return false;
      }

      // Validate file type
      $fileExt = $this->getFileExtension($this->fileName);
      $fileMime = $this->getFileMimeType($file['tmp_name']);

      if (!$this->isValidType($fileExt, $fileMime)) {
          $this->errors[] = "Invalid file type. Allowed types: " . implode(', ', $this->allowedTypes);
          return false;
      }

      // Generate unique filename
      $uniqueName = $this->generateUniqueFilename($fileExt);
      
      $destination = $this->uploadDir . DIRECTORY_SEPARATOR . $uniqueName;
        
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $this->uploadedFilePath = $destination;
            return true;
        }
  
        $this->errors[] = "Failed to move uploaded file";
        return false; 

  }

  public function getFilePath()
  {
      return $this->uploadedFilePath;
  }

  public function getErrors()
  {
      return $this->errors;
  }

  private function sanitizeFileName($filename)
  {
      $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename);
      return preg_replace('/_{2,}/', '_', $filename);
  }

  private function getFileExtension($filename)
  {
      return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  }

  private function getFileMimeType($tmpPath)
  {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime = finfo_file($finfo, $tmpPath);
      finfo_close($finfo);
      return $mime;
  }

  private function isValidType($ext, $mime)
  {
      if (empty($this->allowedTypes)) return true;

      $allowedMimes = [
          'jpg' => ['image/jpeg', 'image/pjpeg'],
          'jpeg' => ['image/jpeg', 'image/pjpeg'],
          'png' => 'image/png',
          'gif' => 'image/gif',
          'pdf' => 'application/pdf',
          'txt' => 'text/plain',
          'zip' => ['application/zip', 'application/x-zip-compressed'],
      ];

      // Check against allowed extensions
      if (!in_array($ext, $this->allowedTypes)) {
          return false;
      }

      // Check MIME type against allowed types
      if (isset($allowedMimes[$ext])) {
          $validMimes = (array)$allowedMimes[$ext];
          if (!in_array($mime, $validMimes)) {
              return false;
          }
      }

      return true;
  }

  private function generateUniqueFilename($ext)
  {
      return date("ymdhis") . strtoupper(base_convert(rand(000000000, 999999999), 10, 30)) . '.' . $ext;
  }

  private function handleUploadError($errorCode)
  {
      $errors = [
          UPLOAD_ERR_INI_SIZE => 'File exceeds server size limit',
          UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit',
          UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
          UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
          UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
          UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload',
      ];

      $this->errors[] = $errors[$errorCode] ?? 'Unknown upload error';
  }

  private function formatBytes($bytes, $precision = 2)
  {
      $units = ['B', 'KB', 'MB', 'GB'];
      $bytes = max($bytes, 0);
      $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
      $pow = min($pow, count($units) - 1);
      $bytes /= pow(1024, $pow);
      return round($bytes, $precision) . ' ' . $units[$pow];
  }
  
  private function ftpEnabled () : bool {
    $settings = new Settings();
    return $settings->getFTPServerOptions()[0]['use_ftp'];
  }
  
  private function getFTPOpt () : array {
    $settings = new Settings();
    return $settings->getFTPServerOptions()[0];
  }
  
}