<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Machinjiri;

final class MachinjiriException extends \Exception {
  
  public final function show () : void {
    $app = Machinjiri::getInstance();
    $appName = getenv("APP_NAME") ?? "Machinjiri";
    
    if ($app->getEnvironment() === 'development') {
      $this->showException($appName);
    } elseif ($app->getEnvironment() === 'production') {
      $this->renderGeneric($appName);
    }
  }
  
  public function renderGeneric (string $appName): void 
  {
      print <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Application Exception</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .error-container {
            max-width: 500px;
            text-align: center;
            background: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .error-icon {
            font-size: 48px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .error-title {
            font-size: 24px;
            margin-bottom: 10px;
            color: #212529;
        }
        .error-message {
            color: #6c757d;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <h1 class="error-title">Something Went Wrong</h1>
        <p class="error-message">We apologize for the inconvenience. Our team has been notified and is working to fix the issue.</p>
    </div>
</body>
</html>
HTML;
  }
  
  public function showException (string $appName): void 
  {
    print <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$appName - Exception</title>
    <style>
        :root {
            --primary: #6f42c1;
            --secondary: #20c997;
            --dark: #212529;
            --light: #f8f9fa;
            --danger: #e74c3c;
            --warning: #f39c12;
            --border-radius: 8px;
            --box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175);
            --transition: all 0.3s ease;
        }
        body {
            font-family: 'Century Gothic', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--dark);
            background-color: var(--light);
            margin: 0;
            padding: 0;
        }
        .main {
          position: fixed;
          height: 100%;
          width: 100%;
          padding: 0;
          margin: 0;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          background: var(--light);
          overflow-y: auto;
          z-index: 8000;
        }
        .error-container {
            max-width: 85%;
            width: 80%;
            margin: 24px auto;
            background: #fff;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }
        .error-header {
            padding: 20px;
            font-size: 1.5rem;
            font-weight: bold;
            background: var(--danger);
            color: var(--light);
            border-bottom: 2px solid var(--danger);
            border-top-left-radius: var(--border-radius);
            border-top-right-radius: var(--border-radius);
        }
        .error-body {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 12px;
          margin: 16px;
          background: var(--light);
          border-left: 4px solid var(--danger);
          border-right: 4px solid var(--danger);
          border-radius: var(--border-radius);
          padding: 4px 8px;
        }
        .error-detail .error-label {
          margin-bottom: 16px;
          color: var(--danger);
          display: block;
          font-size: 1.15rem;
          font-weight: 600;
        }
        .error-value {
            color: var(--dark);
        }
        .error-trace {
            background: var(--dark);
            color: var(--light);
            padding: 14px;
            border-radius: var(--border-radius);
            overflow: scroll;
            font-family: monospace;
            white-space: pre-wrap;
            width: 100%;
        }
        .tab-container {
            margin-top: 20px;
        }
        .tab-buttons {
            display: flex;
            border-bottom: 1px solid #ddd;
        }
        .tab-button {
            padding: 10px 20px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            margin-right: 5px;
            cursor: pointer;
        }
        .tab-button.active {
            background: #fff;
            border-bottom: 1px solid #fff;
            margin-bottom: -1px;
        }
        .tab-content {
            display: none;
            padding: 20px;
            border: 1px solid #ddd;
            border-top: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
  <div class="main">
    <div class="error-container">
        <div class="error-header">
          $appName - <span class="error-header-title">Caught Exception ({$this->getCode()})</span>
        </div>
        <div class="error-body">
            <div class="error-detail">
              <span class="error-label">Exception Message</span>
              <span class="error-value">>> {$this->getMessage()}</span>
            </div>
        </div>
        <div class="error-body">
            <div class="error-detail">
                <span class="error-label">Location: On line # {$this->getLine()}</span>
                <span class="error-value">{$this->getFile()}</span>
            </div>
        </div>
        <div class="error-body">
            <div class="error-detail">
                <span class="error-label">Stack Trace</span>
                <div class="error-trace">{$this->getTraceAsString()}</div>
            </div>
        </div>
    </div>
  </div>
</body>
</html>
HTML;
  }
}