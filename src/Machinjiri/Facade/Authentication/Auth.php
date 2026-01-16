<?php

namespace Mlangeni\Machinjiri\Core\Facade\Authentication;

use Mlangeni\Machinjiri\Core\Facade\Authentication\Guards\SessionGuard;
use Mlangeni\Machinjiri\Core\Facade\Authentication\Models\User;

class Auth
{
    private static ?Guard $guard = null;
    private static string $defaultGuard = 'session';
    private static array $guards = [];
    private static ?User $userModel = null;

    /**
     * Initialize the authentication system
     */
    public static function initialize(array $config = []): void
    {
        $defaultGuard = $config['default'] ?? 'session';
        $guardsConfig = $config['guards'] ?? [];
        
        self::$defaultGuard = $defaultGuard;
        
        foreach ($guardsConfig as $name => $guardConfig) {
            self::registerGuard($name, $guardConfig);
        }
        
        if (isset($config['model'])) {
            self::setUserModel($config['model']);
        }
    }

    /**
     * Register a guard
     */
    public static function registerGuard(string $name, array $config): void
    {
        $driver = $config['driver'] ?? 'session';
        
        if ($driver === 'session') {
            self::$guards[$name] = new SessionGuard(
                $config['session'] ?? null,
                $config['cookie'] ?? null,
                $config['queryBuilder'] ?? null,
                $config['hasher'] ?? null,
                $config['model'] ?? User::class
            );
        }
    }

    /**
     * Set the user model
     */
    public static function setUserModel(string $model): void
    {
        if (!class_exists($model)) {
            throw new \InvalidArgumentException("User model {$model} does not exist");
        }
        
        self::$userModel = $model;
    }

    /**
     * Get the current guard instance
     */
    public static function guard(?string $name = null): Guard
    {
        $name = $name ?? self::$defaultGuard;
        
        if (!isset(self::$guards[$name])) {
            throw new \InvalidArgumentException("Auth guard [{$name}] is not defined");
        }
        
        return self::$guards[$name];
    }

    /**
     * Check if user is authenticated
     */
    public static function check(): bool
    {
        return self::guard()->check();
    }

    /**
     * Check if user is a guest
     */
    public static function guest(): bool
    {
        return self::guard()->guest();
    }

    /**
     * Get the authenticated user
     */
    public static function user(): ?Authenticatable
    {
        return self::guard()->user();
    }

    /**
     * Get the authenticated user's ID
     */
    public static function id(): mixed
    {
        return self::guard()->id();
    }

    /**
     * Attempt to authenticate a user
     */
    public static function attempt(array $credentials, bool $remember = false): bool
    {
        return self::guard()->attempt($credentials, $remember);
    }

    /**
     * Log a user into the application
     */
    public static function login(Authenticatable $user, bool $remember = false): void
    {
        self::guard()->login($user, $remember);
    }

    /**
     * Log the user out of the application
     */
    public static function logout(): void
    {
        self::guard()->logout();
    }

    /**
     * Validate user credentials
     */
    public static function validate(array $credentials): bool
    {
        return self::guard()->validate($credentials);
    }

    /**
     * Create a new user instance
     */
    public static function create(array $attributes): User
    {
        $model = self::$userModel ?? User::class;
        return new $model($attributes);
    }

    /**
     * Find a user by ID
     */
    public static function find(mixed $id): ?User
    {
        $model = self::$userModel ?? User::class;
        $user = new $model();
        
        $data = $user->getQueryBuilder()
            ->where('id', '=', $id)
            ->first();
        
        if ($data) {
            return new $model($data);
        }
        
        return null;
    }

    /**
     * Find a user by credentials
     */
    public static function findByCredentials(array $credentials): ?User
    {
        $model = self::$userModel ?? User::class;
        $user = new $model();
        
        $query = $user->getQueryBuilder();
        
        foreach ($credentials as $key => $value) {
            $query->where($key, '=', $value);
        }
        
        $data = $query->first();
        
        if ($data) {
            return new $model($data);
        }
        
        return null;
    }

    /**
     * Hash a password
     */
    public static function hash(string $password): string
    {
        $hasher = new \Mlangeni\Machinjiri\Core\Security\Hashing\Hasher();
        return $hasher->make($password);
    }

    /**
     * Verify a password
     */
    public static function verify(string $password, string $hashed): bool
    {
        $hasher = new \Mlangeni\Machinjiri\Core\Security\Hashing\Hasher();
        return $hasher->verify($password, $hashed);
    }

    /**
     * Check if user has a role
     */
    public static function hasRole(string $role): bool
    {
        $user = self::user();
        return $user ? $user->hasRole($role) : false;
    }

    /**
     * Check if user has a permission
     */
    public static function hasPermission(string $permission): bool
    {
        $user = self::user();
        return $user ? $user->hasPermission($permission) : false;
    }

    /**
     * Get all registered guards
     */
    public static function getGuards(): array
    {
        return array_keys(self::$guards);
    }

    /**
     * Register an event listener for authentication events
     */
    public static function on(string $event, callable $listener): void
    {
        // Implementation depends on your event system
        // This is a placeholder for event registration
    }
}