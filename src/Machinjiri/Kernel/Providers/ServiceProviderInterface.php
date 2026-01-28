<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Providers;

use Mlangeni\Machinjiri\Core\Container;

/**
 * ServiceProviderInterface defines the contract for service providers
 * 
 * Service providers are the central place of all application bootstrapping.
 * All crucial app services must be registered and bootstrapped in providers.
 */
interface ServiceProviderInterface
{
    /**
     * Register services in the container
     * 
     * Called when the provider is first loaded. Use this method to register
     * bindings, singletons, and other services into the container.
     * 
     * @return void
     */
    public function register(): void;

    /**
     * Bootstrap services after all providers are registered
     * 
     * Called after all providers have been registered. Use this method to
     * perform any bootstrapping work that depends on other services.
     * 
     * @return void
     */
    public function boot(): void;

    /**
     * Get the services provided by the provider
     * 
     * @return array Service names provided
     */
    public function provides(): array;

    /**
     * Determine if the provider is deferred
     * 
     * @return bool True if provider is deferred
     */
    public function isDeferred(): bool;

    /**
     * Publish assets (configuration files, etc.)
     * 
     * @return void
     */
    public function publish(): void;

    /**
     * Get service provider name
     * 
     * @return string Provider name
     */
    public function getName(): string;

    /**
     * Get service provider description
     * 
     * @return string Provider description
     */
    public function getDescription(): string;
}
