<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Authentication;

/**
 * AuthenticationInterface defines the contract for authentication systems
 * 
 * All authentication implementations must follow this contract to ensure
 * consistent authentication behavior across the application.
 */
interface AuthenticationInterface
{
    /**
     * Authenticate user with credentials
     * 
     * @param array $credentials Authentication credentials
     * @return bool True if authenticated successfully
     */
    public function authenticate(array $credentials): bool;

    /**
     * Check if user is authenticated
     * 
     * @return bool True if user is authenticated
     */
    public function isAuthenticated(): bool;

    /**
     * Get authenticated user data
     * 
     * @return array|null User data or null if not authenticated
     */
    public function getUser(): ?array;

    /**
     * Get user ID
     * 
     * @return int|string|null User ID or null if not authenticated
     */
    public function getUserId();

    /**
     * Logout user
     * 
     * @return void
     */
    public function logout(): void;

    /**
     * Get user roles
     * 
     * @return array User roles
     */
    public function getRoles(): array;

    /**
     * Check if user has a specific role
     * 
     * @param string $role Role name
     * @return bool True if user has role
     */
    public function hasRole(string $role): bool;

    /**
     * Check if user has any of the specified roles
     * 
     * @param array $roles Role names
     * @return bool True if user has any role
     */
    public function hasAnyRole(array $roles): bool;

    /**
     * Check if user has all specified roles
     * 
     * @param array $roles Role names
     * @return bool True if user has all roles
     */
    public function hasAllRoles(array $roles): bool;

    /**
     * Get user permissions
     * 
     * @return array User permissions
     */
    public function getPermissions(): array;

    /**
     * Check if user has a specific permission
     * 
     * @param string $permission Permission name
     * @return bool True if user has permission
     */
    public function hasPermission(string $permission): bool;

    /**
     * Check if user has any of the specified permissions
     * 
     * @param array $permissions Permission names
     * @return bool True if user has any permission
     */
    public function hasAnyPermission(array $permissions): bool;

    /**
     * Check if user has all specified permissions
     * 
     * @param array $permissions Permission names
     * @return bool True if user has all permissions
     */
    public function hasAllPermissions(array $permissions): bool;

    /**
     * Get authentication token/session
     * 
     * @return string|null Token or session ID
     */
    public function getToken(): ?string;

    /**
     * Validate token
     * 
     * @param string $token Token to validate
     * @return bool True if token is valid
     */
    public function validateToken(string $token): bool;

    /**
     * Get error message
     * 
     * @return string|null Error message if authentication failed
     */
    public function getError(): ?string;

    /**
     * Set error message
     * 
     * @param string $message Error message
     * @return void
     */
    public function setError(string $message): void;

    /**
     * Remember user
     * 
     * @param int|string $userId User ID
     * @param int $duration Duration in seconds
     * @return void
     */
    public function remember($userId, int $duration = 604800): void;

    /**
     * Forget user
     * 
     * @return void
     */
    public function forget(): void;

    /**
     * Check if user is remembered
     * 
     * @return bool True if user is remembered
     */
    public function isRemembered(): bool;
}
