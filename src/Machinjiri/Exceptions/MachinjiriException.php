<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

final class MachinjiriException extends \Exception {
  
  public function display () : void {
    $display = "<h3>Caught Exception</h3>";
    $display .= "<p><strong>Message :</strong>".$this->getMessage(). "</p>";
    $display .= "<p><strong>Code :</strong>".$this->getCode(). "</p>";
    $display .= "<p><strong>Line :</strong>".$this->getLine(). "</p>";
    $display .= "<p><strong>File :</strong>".$this->getFile(). "</p>";
    print $display;
  }
}