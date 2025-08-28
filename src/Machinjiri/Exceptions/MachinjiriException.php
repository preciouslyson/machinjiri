<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

final class MachinjiriException extends \Exception {
  
  public function display () : void {
    $message = $this->getMessage();
    $code = $this->getCode();
    $trace = $this->getTraceAsString();
    $response = <<<EOT
<?php
// Clear any previous output
while (ob_get_level()) {
    ob_end_clean();
}

// Send fresh headers
http_response_code(500);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Exception Report</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: monospace; background: #f9f9f9; color: #333; padding: 20px; }
    .error-box { background: #fff; border-left: 6px solid #e74c3c; padding: 20px; box-shadow: 0 0 5px rgba(0,0,0,0.1); border-radius: .4rem; margin-bottom:500px;}
    .error-title { font-size: 1.5em; color: #c0392b; margin-bottom: 10px; }
    .error-details { margin-bottom: 8px; }
    .trace { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; overflow-x: auto; border-radius: .4rem;}
    .trace pre { margin: 0; white-space: pre-wrap; word-wrap: break-word; }
  </style>
</head>
<body>

<div class="error-box">
  <div class="error-title">Machinjiri - Exception</div>
  <div class="error-details"><strong>Message:</strong> $message</div>
  <div class="error-details"><strong>Code:</strong>$code</div>
  <div class="trace"><strong>Trace:</strong>$trace</pre></div>
</div>

</body>
</html>
EOT;
  print $response;
  }
}