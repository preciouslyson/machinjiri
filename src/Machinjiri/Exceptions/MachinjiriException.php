<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

final class MachinjiriException extends \Exception {
  
  public final function show () : void {
    print <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Machinjiri - Exception</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
        }
        .error-container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .error-header {
            background: #dc3545;
            color: white;
            padding: 20px;
            font-size: 24px;
            font-weight: bold;
        }
        .error-body {
            padding: 20px;
        }
        .error-detail {
            margin-bottom: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-left: 4px solid #dc3545;
            border-radius: 4px;
        }
        .error-label {
            font-weight: bold;
            color: #495057;
            display: block;
            margin-bottom: 5px;
        }
        .error-value {
            color: #212529;
        }
        .error-trace {
            background: #2b303b;
            color: #dee2e6;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-family: monospace;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-header">Machinjiri - Application Error</div>
        <div class="error-body">
            <div class="error-detail">
                <span class="error-label">Message:</span>
                <span class="error-value">{$this->getMessage()}</span>
            </div>
            <div class="error-detail">
                <span class="error-label">Code:</span>
                <span class="error-value">{$this->getCode()}</span>
            </div>
            <div class="error-detail">
                <span class="error-label">Location:</span>
                <span class="error-value">{$this->getFile()} on line {$this->getLine()}</span>
            </div>
            <div class="error-detail">
                <span class="error-label">Stack Trace:</span>
                <div class="error-trace">{$this->getTraceAsString()}</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
  }
}