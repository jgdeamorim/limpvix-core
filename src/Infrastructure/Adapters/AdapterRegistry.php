<?php
/**
 * AdapterRegistry - Registro Central de Adaptadores
 *
 * RESPONSABILIDADE:
 * - Registrar todos os adaptadores de eventos
 * - Centralizar inicialização
 * - Gerenciar lifecycle dos adaptadores
 *
 * PRINCÍPIOS:
 * - Registry Pattern
 * - Single Responsibility
 * - Dependency Injection
 * - Fail-safe (adapters independentes)
 *
 * USO:
 * ```php
 * $registry = new AdapterRegistry();
 * $registry->add($wooCommerceAdapter);
 * $registry->add($bookneticAdapter);
 * $registry->registerAll();
 * ```
 *
 * PASSO 5.4 - Adaptadores de Eventos
 *
 * @package LimpVix\Infrastructure\Adapters
 */

namespace LimpVix\Infrastructure\Adapters;

defined('ABSPATH') || exit;

class AdapterRegistry
{
    /**
     * Lista de adaptadores registrados
     *
     * @var array
     */
    private $adapters = [];

    /**
     * Adicionar adaptador
     *
     * @param object $adapter Adaptador com método register()
     * @param string $name Nome do adaptador (para logging)
     * @return void
     */
    public function add($adapter, string $name): void
    {
        if (!method_exists($adapter, 'register')) {
            throw new \InvalidArgumentException(
                "Adapter {$name} deve ter método register()"
            );
        }

        $this->adapters[$name] = $adapter;
    }

    /**
     * Registrar todos os adaptadores
     *
     * @return void
     */
    public function registerAll(): void
    {
        foreach ($this->adapters as $name => $adapter) {
            try {
                $adapter->register();
                $this->logRegistered($name);
            } catch (\Exception $e) {
                $this->logError($name, $e);
            }
        }
    }

    /**
     * Obter adaptador por nome
     *
     * @param string $name
     * @return object|null
     */
    public function get(string $name)
    {
        return $this->adapters[$name] ?? null;
    }

    /**
     * Listar todos os adaptadores
     *
     * @return array
     */
    public function getAll(): array
    {
        return $this->adapters;
    }

    /**
     * Verificar se adaptador está registrado
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->adapters[$name]);
    }

    /**
     * Log de adaptador registrado
     *
     * @param string $name
     * @return void
     */
    private function logRegistered(string $name): void
    {
        if (function_exists('do_action')) {
            do_action('limpvix_adapter_registered', [
                'adapter' => $name,
                'timestamp' => current_time('mysql')
            ]);
        }
    }

    /**
     * Log de erro no registro
     *
     * @param string $name
     * @param \Exception $exception
     * @return void
     */
    private function logError(string $name, \Exception $exception): void
    {
        if (function_exists('do_action')) {
            do_action('limpvix_adapter_registration_error', [
                'adapter' => $name,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
        }
    }
}
