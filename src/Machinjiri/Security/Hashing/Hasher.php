<?php

namespace Mlangeni\Machinjiri\Core\Security\Hashing;

use \RuntimeException;

class Hasher
{
    private int $cost = 12;
    private string $algorithm = PASSWORD_BCRYPT;

    public function make(string $password): string
    {
        $hashed = password_hash($password, $this->algorithm, ['cost' => $this->cost]);
        
        if ($hashed === false) {
            throw new \RuntimeException('Password hashing failed');
        }
        
        return $hashed;
    }

    public function verify(string $password, string $hashed): bool
    {
        if (empty($hashed)) {
            return false;
        }
        
        return password_verify($password, $hashed);
    }

    public function needsRehash(string $hashed): bool
    {
        return password_needs_rehash($hashed, $this->algorithm, ['cost' => $this->cost]);
    }

    public function setCost(int $cost): self
    {
        $this->cost = $cost;
        return $this;
    }

    public function setAlgorithm(string $algorithm): self
    {
        $this->algorithm = $algorithm;
        return $this;
    }
}