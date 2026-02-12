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
     * 1. Inicializar Environment (.env loader)
     * 2. Verificar Feature Flag "core_enabled"
     * 3. Inicializar FeatureFlags
     * 4. Inicializar Hooks
     * 5. Registrar interceptações do Booknetic
     *
     * @return void
     */
    public function boot(): void
    {
        // Evitar múltiplas inicializações
        if ($this->booted) {
            return;
        }

        // Inicializar Environment (ANTES de tudo - variáveis podem ser usadas por features)
        $this->initializeEnvironment();

        // Inicializar Feature Flags
        $this->featureFlags = new FeatureFlags();

        // Verificar se o Core está habilitado
        if (!$this->featureFlags->isEnabled('core_enabled')) {
            // Core desabilitado - não fazer NADA
            $this->logInfo('LimpVix Core está DESABILITADO via Feature Flag');
            return;
        }

        // Core habilitado - inicializar componentes
        $this->logInfo('LimpVix Core está HABILITADO - iniciando bootstrap');

        // Inicializar Transaction Manager (infraestrutura base)
        $this->initializeTransactionManager();

        // Inicializar Authorization Service (ANTES dos módulos)
        $this->initializeAuthorization();

        // Inicializar Hooks Manager
        $this->hooks = new Hooks($this->featureFlags);
        $this->hooks->register();

        // Inicializar módulo Briefing
        if (class_exists('LimpVix\\Core\\BriefingBootstrap')) {
            BriefingBootstrap::init();
        }

        // Inicializar módulo Communication
        if (class_exists('LimpVix\\Core\\CommunicationBootstrap')) {
            CommunicationBootstrap::init();
        }

        // Inicializar módulo Feedback
        if (class_exists('LimpVix\\Core\\FeedbackBootstrap')) {
            FeedbackBootstrap::init();
        }

        // Inicializar módulo Scheduling
        if (class_exists('LimpVix\\Core\\SchedulingBootstrap')) {
            SchedulingBootstrap::init();
        }

        // Inicializar módulo Professional (CRÍTICO: desbloqueia marketplace)
        if (class_exists('LimpVix\\Core\\ProfessionalBootstrap')) {
            ProfessionalBootstrap::init();
        }

        // Inicializar módulo Contract (DDD Architecture)
        if (class_exists('LimpVix\\Core\\ContractBootstrap')) {
            ContractBootstrap::init();
        }

        // Inicializar módulo Execution (Contract Executions)
        if (class_exists('LimpVix\\Core\\ExecutionBootstrap')) {
            ExecutionBootstrap::init();

        // Inicializar módulo Professional (KYC + Management)
        if (class_exists('LimpVix\\Core\\ProfessionalBootstrap')) {
            ProfessionalBootstrap::init();
        }
        }

        // Inicializar automação de contratos
        if (class_exists('LimpVix\\Infrastructure\\Automation\\ContractAutomation')) {
            \LimpVix\Infrastructure\Automation\ContractAutomation::init();
        }

        // Inicializar listener Briefing → Contract
        if (class_exists('LimpVix\\Infrastructure\\Integration\\BriefingContractListener')) {
            \LimpVix\Infrastructure\Integration\BriefingContractListener::register();
        }

        // Marcar como inicializado
        $this->booted = true;

        $this->logInfo('LimpVix Core inicializado com sucesso');
    }

    /**
     * Inicializa o Environment (.env loader)
     *
     * Carrega variáveis de ambiente do arquivo .env e disponibiliza
     * via classe Environment. Deve ser chamado ANTES de qualquer
     * outro componente, pois feature flags e configurações podem
     * depender de variáveis de ambiente.
     *
     * HIERARQUIA DE BUSCA:
     * 1. .env file (priority)
     * 2. PHP Constants (LIMPVIX_*)
     * 3. wp_options (fallback - backward compatibility)
     *
     * SPRINT 7 - Item 1.10: Environment Configuration
     *
     * @return void
     */
    private function initializeEnvironment(): void
    {
        if (!class_exists('LimpVix\\Core\\Environment')) {
            $this->logInfo('Environment class not found - skipping initialization');
            return;
        }

        // Carregar .env
        Environment::load();

        // Log ambiente atual
        $env = Environment::getEnvironment();
        $debug = Environment::isDebug() ? 'YES' : 'NO';
        $this->logInfo("Environment loaded: {$env} (Debug: {$debug})");

        // Validar variáveis críticas em produção
        if (Environment::isProduction()) {
            try {
                Environment::validate([
                    // Não obrigar MercadoPago pois pode vir do WooCommerce plugin
                    // Não obrigar outras integrações pois são opcionais
                ]);
            } catch (\RuntimeException $e) {
                $this->logError('Missing required environment variables: ' . $e->getMessage());
                // Não parar o sistema, apenas logar erro
            }
        }

        // Disponibilizar globalmente (opcional - pode acessar via Environment::get() diretamente)
        $GLOBALS['limpvix_environment'] = [
            'type' => $env,
            'debug' => Environment::isDebug(),
            'test_mode' => Environment::isTestMode(),
        ];

        $this->logInfo('Environment initialized');
    }

    /**
     * Inicializa o Transaction Manager
     *
     * Cria instância do gerenciador de transações de banco de dados.
     * Disponibiliza globalmente via $GLOBALS['limpvix_transaction_manager']
     *
     * O TransactionManager é usado por Use Cases críticos que precisam
     * garantir atomicidade em operações multi-step:
     * - RegisterProfessional (WordPress user + domain record)
     * - CreateOrder (Order + Financial aggregates)
     * - ExecutePayout (provider call + DB persistence)
     *
     * @return void
     */
    private function initializeTransactionManager(): void
    {
        if (!class_exists('LimpVix\\Infrastructure\\Database\\TransactionManager')) {
            $this->logInfo('TransactionManager não encontrado - pulando inicialização');
            return;
        }

        global $wpdb;

        $transactionManager = new \LimpVix\Infrastructure\Database\TransactionManager($wpdb);

        // Disponibilizar globalmente
        $GLOBALS['limpvix_transaction_manager'] = $transactionManager;

        $this->logInfo('TransactionManager inicializado e disponível globalmente');
    }

    /**
     * Inicializa o Authorization Service
     *
     * Registra policies para todos os recursos do sistema:
     * - Contract
     * - Execution
     * - Professional
     *
     * O serviço fica disponível globalmente via $GLOBALS['limpvix_authorization_service']
     *
     * @return void
     */
    private function initializeAuthorization(): void
    {
        // Carregar classes necessárias
        if (!class_exists('LimpVix\\Infrastructure\\Authorization\\AuthorizationService')) {
            $this->logInfo('AuthorizationService não encontrado - pulando inicialização');
            return;
        }

        $authService = new \LimpVix\Infrastructure\Authorization\AuthorizationService();

        // Registrar policies
        if (class_exists('LimpVix\\Infrastructure\\Authorization\\Policies\\ContractPolicy')) {
            $authService->registerPolicy('contract', new \LimpVix\Infrastructure\Authorization\Policies\ContractPolicy());
        }

        if (class_exists('LimpVix\\Infrastructure\\Authorization\\Policies\\ExecutionPolicy')) {
            $authService->registerPolicy('execution', new \LimpVix\Infrastructure\Authorization\Policies\ExecutionPolicy());
        }

        if (class_exists('LimpVix\\Infrastructure\\Authorization\\Policies\\ProfessionalPolicy')) {
            $authService->registerPolicy('professional', new \LimpVix\Infrastructure\Authorization\Policies\ProfessionalPolicy());
        }

        // Disponibilizar globalmente
        $GLOBALS['limpvix_authorization_service'] = $authService;

        $this->logInfo('AuthorizationService inicializado com ' . count($authService->getPolicies()) . ' policies');
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
