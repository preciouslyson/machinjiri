<?php

namespace Mlangeni\Machinjiri\Core\Forms;

class Password {
  
  public function __construct (private $password, private int $min_length = 8) {}

  public function validate () : bool
  {
    $uppercase = preg_match('@[A-Z]@', $this->password);
    $lowercase = preg_match('@[a-z]@', $this->password);
    $number = preg_match('@[0-9]@', $this->password);
    $specialChars = preg_match('@[^\w]@', $this->password);
    if (!$uppercase || !$lowercase || !$number || !$specialChars || strlen($this->password) < $this->min_length) {
      return false;
    }
    return true;
  }
  
}