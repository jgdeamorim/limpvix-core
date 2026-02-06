<?php
/**
 * Kernel - Bootstrap principal do LimpVix-Core
 *
 * RESPONSABILIDADE:
 * - Inicializar todos os componentes do sistema
 * - Registrar Service Providers
 * - Verificar Feature Flags globais
 * - Configurar ambiente
 *
 * PRINCÍPIO:
 * - Singleton (apenas uma instância)
 * - Inicialização lazy (só quando necessário)
 * - Feature Flag "core_enabled" controla TUDO
 *
 * @package LimpVix\Core
 */

namespace LimpVix\Core;

defined('ABSPATH') || exit;

class Kernel
{
    /**
     * Instância singleton
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Indica se o sistema já foi inicializado
     *
     * @var bool
     */
    private $booted = false;

    /**
     * Feature Flags instance
     *
     * @var FeatureFlags
     */
    private $featureFlags;

    /**
     * Hooks manager
     *
     * @var Hooks
     */
    private $hooks;

    /**
     * Construtor privado (Singleton)
     */
    private function __construct()
    {
        // Construtor vazio - inicialização acontece no boot()
    }

    /**
     * Obtém instância única (Singleton)
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
     * Inicializa o sistema
     *
     * ORDEM DE EXECUÇÃO:
     * 1. Verificar Feature Flag "core_enabled"
     * 2. Inicializar FeatureFlags
     * 3. Inicializar Hooks
     * 4. Registrar interceptações do Booknetic
     *
     * @return void
     */
    public function boot(): void
    {
        // Evitar múltiplas inicializações
        if ($this->booted) {
            return;
        }

        // Inicializar Feature Flags (SEMPRE primeiro)
        $this->featureFlags = new FeatureFlags();

        // Verificar se o Core está habilitado
        if (!$this->featureFlags->isEnabled('core_enabled')) {
            // Core desabilitado - não fazer NADA
            $this->logInfo('LimpVix Core está DESABILITADO via Feature Flag');
            return;
        }

        // Core habilitado - inicializar componentes
        $this->logInfo('LimpVix Core está HABILITADO - iniciando bootstrap');

        // Inicializar Hooks Manager
        $this->hooks = new Hooks($this->featureFlags);
        $this->hooks->register();

        // Marcar como inicializado
        $this->booted = true;

        $this->logInfo('LimpVix Core inicializado com sucesso');
    }

    /**
     * Verifica se o sistema está inicializado
     *
     * @return bool
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Obtém instância do FeatureFlags
     *
     * @return FeatureFlags|null
     */
    public function getFeatureFlags(): ?FeatureFlags
    {
        return $this->featureFlags;
    }

    /**
     * Obtém instância do Hooks
     *
     * @return Hooks|null
     */
    public function getHooks(): ?Hooks
    {
        return $this->hooks;
    }

    /**
     * Log informativo (apenas se WP_DEBUG ativo)
     *
     * @param string $message
     * @return void
     */
    private function logInfo(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Core] [%s] %s',
                date('Y-m-d H:i:s'),
                $message
            ));
        }
    }

    /**
     * Retorna versão do plugin
     *
     * @return string
     */
    public function getVersion(): string
    {
        return defined('LIMPVIX_VERSION') ? LIMPVIX_VERSION : 'unknown';
    }

    /**
     * Health check - verifica se sistema está saudável
     *
     * @return array Status do sistema
     */
    public function healthCheck(): array
    {
        return [
            'version' => $this->getVersion(),
            'booted' => $this->booted,
            'core_enabled' => $this->featureFlags ? $this->featureFlags->isEnabled('core_enabled') : false,
            'booknetic_active' => is_plugin_active('booknetic/init.php'),
            'autoloader' => class_exists('LimpVix\\Core\\Kernel'),
            'feature_flags_loaded' => $this->featureFlags !== null,
            'hooks_registered' => $this->hooks !== null,
        ];
    }

    /**
     * Prevenir clonagem (Singleton)
     */
    private function __clone() {}

    /**
     * Prevenir unserialize (Singleton)
     */
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}
