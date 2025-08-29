<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

final class MachinjiriException extends \Exception {
  
  public function display () : void {
    $message = $this->getMessage();
    $code = $this->getCode();
    $trace = $this->getFile();
    $body = <<<EOT
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Machinjiri - Exception</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: monospace; background: #f9f9f9; color: #333; padding: 20px;}
    .error-container {position: fixed;top:0;left:0;width:100%; height:100%;padding: 40px 60px; background: #f3f3f3;}
    .error-box { background: #fff; border-left: 6px solid #e74c3c; padding: 20px; box-shadow: 0 0 5px rgba(0,0,0,0.1); border-radius: .4rem; margin-bottom:500px;}
    .error-title { font-size: 1.5em; color: #c0392b; margin-bottom: 10px; }
    .error-details { margin-bottom: 8px; }
    .trace { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; overflow-x: auto; border-radius: .4rem;}
    .trace pre { margin: 0; white-space: pre-wrap; word-wrap: break-word; }
  </style>
</head>
<body>

<div class="error-container">
  <div class="error-box">
    <div class="error-title">Machinjiri - Exception</div>
    <div class="error-details"><strong>Message:</strong> $message</div>
    <div class="error-details"><strong>Code:</strong>$code</div>
    <div class="trace"><strong>Trace:</strong>$trace</pre></div>
  </div>
</div>

</body>
</html>
EOT;
  $response = new HttpResponse();
  $response->setStatusCode(400)->send();
  print $body;
  }
}