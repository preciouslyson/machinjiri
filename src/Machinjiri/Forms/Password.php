<?php

namespace Mlangeni\Machinjiri\Core\Forms;

class Password {
  
  private const MIN_LEN = 8;

  public function __construct (private $password) {}

  public function validatePassword () : bool
  {
    $uppercase = preg_match('@[A-Z]@', $this->password);
    $lowercase = preg_match('@[a-z]@', $this->password);
    $number = preg_match('@[0-9]@', $this->password);
    $specialChars = preg_match('@[^\w]@', $this->password);
    if (!$uppercase || !$lowercase || !$number || !$specialChars || strlen($this->password) < self::MIN_LEN) {
      return false;
    }
    return true;
  }
  
}