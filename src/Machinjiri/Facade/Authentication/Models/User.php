<?php

namespace Mlangeni\Machinjiri\Core\Facade\Authentication\Models;

use Mlangeni\Machinjiri\Core\Facade\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;

class User implements Authenticatable
{
    private array $attributes = [];
    private ?QueryBuilder $queryBuilder;
    private Session $session;
    private Cookie $cookie;

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
        $this->session = new Session();
        $this->cookie = new Cookie();
        $this->queryBuilder = new QueryBuilder('users');
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->attributes['id'] ?? null;
    }

    public function getAuthPassword(): string
    {
        return $this->attributes['password'] ?? '';
    }

    public function getRememberToken(): string
    {
        return $this->attributes['remember_token'] ?? '';
    }

    public function setRememberToken(string $token): void
    {
        $this->attributes['remember_token'] = $token;
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function hasRole(string $role): bool
    {
        return $this->attributes['role'] === $role;
    }

    public function hasPermission(string $permission): bool
    {
        // Check permissions from database or cache
        $permissions = $this->getPermissions();
        return in_array($permission, $permissions);
    }

    private function getPermissions(): array
    {
        if (isset($this->attributes['permissions'])) {
            return is_array($this->attributes['permissions']) 
                ? $this->attributes['permissions'] 
                : json_decode($this->attributes['permissions'], true);
        }
        return [];
    }

    public function fill(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }

    public function save(): bool
    {
        $id = $this->getAuthIdentifier();
        
        if ($id) {
            // Update existing user
            $result = $this->queryBuilder
                ->where('id', '=', $id)
                ->update($this->attributes)
                ->execute();
        } else {
            // Insert new user
            $result = $this->queryBuilder
                ->insert($this->attributes)
                ->execute();
            
            if (isset($result['lastInsertId'])) {
                $this->attributes['id'] = $result['lastInsertId'];
                return true;
            }
        }
        
        return false;
    }

    public function delete(): bool
    {
        $id = $this->getAuthIdentifier();
        
        if ($id) {
            $result = $this->queryBuilder
                ->where('id', '=', $id)
                ->delete()
                ->execute();
            
            return $result['rowCount'] > 0;
        }
        
        return false;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function toArray(): array
    {
        unset($this->attributes['password']);
        unset($this->attributes['remember_token']);
        return $this->attributes;
    }

    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }
}