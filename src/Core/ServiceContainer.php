<?php
/**
 * Service Container
 *
 * Dependency Injection container com lazy loading support.
 * Elimina dependência de $GLOBALS, melhora testabilidade.
 *
 * @package LimpVix\Core
 * @since SPRINT 8.5 - FASE 2
 */

namespace LimpVix\Core;

defined('ABSPATH') || exit;

/**
 * ServiceContainer
 *
 * Singleton container for dependency injection.
 *
 * Features:
 * - Singleton pattern (getInstance())
 * - Service registration (set)
 * - Service resolution (get)
 * - Lazy loading via factory functions
 * - Type-safe retrieval with generics-like syntax
 * - has() to check service existence
 *
 * Usage:
 * ```php
 * // Register service
 * ServiceContainer::getInstance()->set('professional_repository', $repo);
 *
 * // Register factory (lazy loading)
 * ServiceContainer::getInstance()->factory('professional_repository', function() {
 *     return new WpProfessionalRepository();
 * });
 *
 * // Retrieve service
 * $repo = ServiceContainer::getInstance()->get('professional_repository');
 *
 * // Check if service exists
 * if (ServiceContainer::getInstance()->has('professional_repository')) {
 *     // ...
 * }
 * ```
 *
 * @since SPRINT 8.5 - FASE 2
 */
final class ServiceContainer
{
    /**
     * Singleton instance
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Registered services (instances)
     *
     * @var array<string, mixed>
     */
    private array $services = [];

    /**
     * Registered factories (callables)
     *
     * @var array<string, callable>
     */
    private array $factories = [];

    /**
     * Private constructor (Singleton pattern)
     */
    private function __construct()
    {
        // Singleton: use getInstance()
    }

    /**
     * Get singleton instance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a service instance
     *
     * @param string $key Service identifier
     * @param mixed $service Service instance
     * @return void
     */
    public function set(string $key, mixed $service): void
    {
        $this->services[$key] = $service;

        // Remove factory if exists (instance takes precedence)
        if (isset($this->factories[$key])) {
            unset($this->factories[$key]);
        }
    }

    /**
     * Register a factory for lazy loading
     *
     * Factory is called only when service is first requested.
     *
     * @param string $key Service identifier
     * @param callable $factory Factory function that returns service instance
     * @return void
     */
    public function factory(string $key, callable $factory): void
    {
        // Don't override existing instance
        if (isset($this->services[$key])) {
            return;
        }

        $this->factories[$key] = $factory;
    }

    /**
     * Get a service from container
     *
     * @param string $key Service identifier
     * @return mixed Service instance
     * @throws \RuntimeException If service not registered
     */
    public function get(string $key): mixed
    {
        // Return existing instance if available
        if (isset($this->services[$key])) {
            return $this->services[$key];
        }

        // Instantiate from factory if available
        if (isset($this->factories[$key])) {
            $factory = $this->factories[$key];
            $service = $factory();

            // Cache the instance
            $this->services[$key] = $service;

            // Remove factory (no longer needed)
            unset($this->factories[$key]);

            return $service;
        }

        // Service not found
        throw new \RuntimeException(
            sprintf(
                'Service "%s" not registered in ServiceContainer. ' .
                'Available services: %s',
                $key,
                implode(', ', array_keys(array_merge($this->services, $this->factories)))
            )
        );
    }

    /**
     * Check if service is registered
     *
     * @param string $key Service identifier
     * @return bool True if service or factory is registered
     */
    public function has(string $key): bool
    {
        return isset($this->services[$key]) || isset($this->factories[$key]);
    }

    /**
     * Get all registered service keys
     *
     * @return array<string> List of service identifiers
     */
    public function getRegisteredServices(): array
    {
        return array_keys(array_merge($this->services, $this->factories));
    }

    /**
     * Remove a service from container
     *
     * Useful for testing or hot-swapping implementations.
     *
     * @param string $key Service identifier
     * @return void
     */
    public function remove(string $key): void
    {
        unset($this->services[$key]);
        unset($this->factories[$key]);
    }

    /**
     * Clear all services (DANGER - use only in tests)
     *
     * @return void
     */
    public function clear(): void
    {
        $this->services = [];
        $this->factories = [];
    }

    /**
     * Prevent cloning (Singleton pattern)
     */
    private function __clone()
    {
        // Singleton: prevent cloning
    }

    /**
     * Prevent unserialization (Singleton pattern)
     */
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}
