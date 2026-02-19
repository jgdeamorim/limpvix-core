<?php
/**
 * Hooks - Gerenciador de Eventos do LimpVix
 *
 * RESPONSABILIDADE:
 * - Registrar TODOS os hooks/filters do WordPress
 * - Interceptar eventos do ciclo de vida do serviço
 * - Delegar execução para componentes apropriados
 * - Respeitar Feature Flags
 *
 * PRINCÍPIO:
 * - Este é o ÚNICO ponto de contato com hooks do WordPress
 * - Cada hook verifica Feature Flag antes de executar
 * - NUNCA quebrar fluxos de domínio já em andamento
 * - Apenas observar e validar
 *
 * @todo DÍVIDA TÉCNICA: Refatorar para Interceptors
 *       Esta classe está com 474 linhas e pode virar "God Object".
 *       Plano de refatoração (PASSO 3+):
 *       - Hooks.php deve apenas registrar (add_action/add_filter)
 *       - Criar Core/Interceptors/BookingInterceptor.php
 *       - Criar Core/Interceptors/WorkflowInterceptor.php
 *       - Criar Core/Interceptors/PaymentInterceptor.php
 *       - Criar Core/Interceptors/FrontendInterceptor.php
 *
 * @package LimpVix\Core
 */

namespace LimpVix\Core;

defined('ABSPATH') || exit;

class Hooks
{
    /**
     * Feature Flags instance
     *
     * @var FeatureFlags
     */
    private $featureFlags;

    /**
     * Construtor
     *
     * @param FeatureFlags $featureFlags
     */
    public function __construct(FeatureFlags $featureFlags)
    {
        $this->featureFlags = $featureFlags;
    }

    /**
     * Registra todos os hooks
     *
     * ORGANIZAÇÃO:     * - Lifecycle hooks (create, update, delete) - FUTURO
     * - Status hooks (status changes) - FUTURO
     * - Validation hooks (before actions) - FUTURO
     * - Frontend hooks (booking panel) - FUTURO
     *
     * @return void
     */
    public function register(): void
    {
        // PASSO 5.4: Adapters do sistema financeiro
        $this->registerFinancialAdapters();

        // PASSO 5.5: Módulo de payout (Mercado Pago)
        $this->registerPayoutModule();

        // PASSO 5.6: Admin Interface
        $this->registerAdminInterface();
        $this->registerGlobalRestApi();
        // AJAX Handlers
        $this->registerAjaxHandlers();


        // S3-HOOKS-DEAD: Legacy hooks removed (were never enabled).
        // Lifecycle, Status, Validation, Frontend hooks cleaned up.
    }

    /**
     * PASSO 3: Registra hook de interceptação controlada
     *
             * Comportamento: Observador (log + Order em memória)
     *
     * @return void
     */
    private function registerPasso3Hooks(): void
    {
        add_action(
            [$this, 'onAppointmentCreated'],
            10,
            1
        );
    }

    /**
     * PASSO 5.4: Registra Adapters do Sistema Financeiro
     *
     * RESPONSABILIDADE:
     * - Inicializar AdapterBootstrap
         * - Conectar eventos externos ao sistema financeiro
     *
     * ADAPTERS:
     * - WooCommercePaymentAdapter (woocommerce_payment_complete)
         * - FeedbackAdapter (limpvix_customer_feedback_submitted)
     * - TimerCronAdapter (WP Cron hourly)
     *
     * @return void
     */
    private function registerFinancialAdapters(): void
    {
        // Verificar feature flag
        if (!$this->featureFlags->isEnabled('financial_workflow')) {
            return;
        }

        // Inicializar AdapterBootstrap (gerencia registro de todos os adapters)
        if (class_exists('LimpVix\\Infrastructure\\Adapters\\AdapterBootstrap')) {
            $adapterBootstrap = new \LimpVix\Infrastructure\Adapters\AdapterBootstrap();
            $adapterBootstrap->boot();
        }
    }

    /**
     * PASSO 5.5: Registra Módulo de Payout
     *
     * RESPONSABILIDADE:
     * - Inicializar PayoutProvider (nova arquitetura em src/Infrastructure)
     * - Módulo antigo (modules/payouts) foi DEPRECADO
     *
     * NOTA: A nova implementação está em:
     * - src/Infrastructure/Finance/Providers/MercadoPagoPayoutProvider.php
     * - Inicializado via PayoutReconciliationService
     * - Lê credenciais de wp_options (sincronizado pelo MercadoPagoDetector)
     *
     * @deprecated Módulo antigo em modules/payouts foi substituído
     * @return void
     */
    private function registerPayoutModule(): void
    {
        // DESABILITADO: Módulo antigo foi substituído pela nova arquitetura
        // A nova implementação é carregada via autoload PSR-4 e não precisa de registro manual
        // Credenciais são gerenciadas via MercadoPagoDetector + wp_options

        // ✅ ARQUITETURA NOVA (ativa):
        // - src/Infrastructure/Finance/Providers/MercadoPagoPayoutProvider.php
        // - src/Application/Services/PayoutReconciliationService.php
        // - src/Admin/Settings/MercadoPagoDetector.php

        // ❌ ARQUITETURA ANTIGA (deprecada):
        // - modules/payouts/mercadopago/MercadoPagoModule.php
        // - modules/payouts/mercadopago/MercadoPagoPayoutProvider.php (duplicado!)

        return; // Nada a fazer aqui - nova arquitetura se auto-inicializa
    }

    /**
     * PASSO 5.6: Registra Admin Interface
     *
     * RESPONSABILIDADE:
     * - Registrar menu "Limpvix Finance"
     * - Registrar páginas (Orders, Payouts)
     * - Registrar assets (CSS, JS)
     * - Registrar AJAX handlers
     * - Adicionar capabilities
     *
     * @return void
     */
    private function registerAdminInterface(): void
    {
        error_log("=== Hooks::registerAdminInterface() CALLED ===");

        // Verificar feature flag
        if (!$this->featureFlags->isEnabled('admin_interface')) {
            return;
        }

        // Inicializar AdminBootstrap
        if (class_exists('LimpVix\\Admin\\Bootstrap\\AdminBootstrap')) {
            $adminBootstrap = new \LimpVix\Admin\Bootstrap\AdminBootstrap();
            $adminBootstrap->boot();
        }
    }

    // S3-HOOKS-DEAD: Removed registerLifecycleHooks, registerStatusHooks,
    // registerValidationHooks, registerFrontendHooks (never enabled, dead code)

    // ========================================
    // PASSO 3: HANDLER DE INTERCEPTAÇÃO
    // ========================================

    /**
         *
     * ⚠️ PRINCÍPIO FUNDAMENTAL:
     * - Este hook NÃO é dono da lógica
     * - Este hook apenas delega
         *
     * PASSO 3: Primeira interceptação
     * - Loga evento
     * - Cria Order em memória
     *
     * PASSO 4.4: Persistência controlada
     * - Persiste Order via Use Case (se flag habilitada)
     * - Loga resultado
     *
     * GARANTIAS:
             * - NÃO lança exceções
     *
         * @return void
     */
    public function onAppointmentCreated($appointmentData): void
    {
        // Master switch
        if (!$this->featureFlags->isEnabled('core_enabled')) {
            return;
        }

        // PASSO 3: Interceptação básica
        if (!$this->featureFlags->isEnabled('intercept_booking')) {
            return;
        }

        try {
            // Log simples (observador)
            $this->log('APPOINTMENT_CREATED', [
                'appointment_id' => $appointmentData->appointmentId ?? null,
                'customer_id' => $appointmentData->customerId ?? null,
                'service_id' => $appointmentData->serviceId ?? null,
                'staff_id' => $appointmentData->staffId ?? null,
                'status' => $appointmentData->status ?? null,
            ]);

            // Criar Order em memória (Domain)
            if (!class_exists('LimpVix\\Domain\\Order\\Order')) {
                return;
            }

            $order = \LimpVix\Domain\Order\Order::fromAppointmentData($appointmentData);

            // Log de Order criada
            $this->log('ORDER_CREATED_IN_MEMORY', [
                'order_uuid' => $order->getUuid(),
                'appointment_id' => $appointmentData->appointmentId ?? null,
            ]);

            // PASSO 4.4: Persistir Order (se flag habilitada)
            if ($this->featureFlags->isEnabled('persist_orders')) {
                $this->persistOrder($order);
            }

        } catch (\Exception $e) {
                        // Apenas logar erro
            $this->log('ERROR_ON_INTERCEPT', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Persistir Order via Use Case
     *
     * ⚠️ PRINCÍPIO:
     * - Este método apenas delega
     * - Nenhuma lógica de decisão aqui
     * - Use Case decide tudo
     *
     * PASSO 4.4: Integração Repository + Use Case
     *
     * @param \LimpVix\Domain\Order\Order $order Order a persistir
     * @return void
     */
    private function persistOrder($order): void
    {
        try {
            // Instanciar dependências (Infrastructure)
            if (!class_exists('LimpVix\\Infrastructure\\Persistence\\WpOrderRepository')) {
                $this->log('ERROR_PERSIST_ORDER', ['error' => 'WpOrderRepository não encontrado']);
                return;
            }

            if (!class_exists('LimpVix\\Application\\UseCases\\PersistOrder')) {
                $this->log('ERROR_PERSIST_ORDER', ['error' => 'PersistOrder Use Case não encontrado']);
                return;
            }

            $repository = new \LimpVix\Infrastructure\Persistence\WpOrderRepository();
            $useCase = new \LimpVix\Application\UseCases\PersistOrder($repository);

            // Executar Use Case
            $result = $useCase->execute($order);

            // Logar resultado
            $this->log('PERSIST_ORDER_RESULT', $result->toArray());

        } catch (\Exception $e) {
            // Capturar QUALQUER exceção
            // NUNCA deixar escapar para o hook
            $this->log('ERROR_PERSIST_ORDER', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    // S3-HOOKS-DEAD: Removed all dead callback methods (beforeAppointmentAdd,
    // afterAppointmentAdd, beforeAppointmentUpdate, afterAppointmentUpdate,
    // beforeAppointmentDelete, beforeStatusChange, afterStatusChange,
    // validateAppointment, validateBookingData, filterAvailableServices,
    // filterAvailableStaff, filterAvailableTimeslots, calculatePrice)

    // ========================================
    // HELPERS
    // ========================================

    /**
     * Log de auditoria
     *
     * @param string $event Nome do evento
     * @param array $data Dados do evento
     * @return void
     */
    private function log(string $event, array $data = []): void
    {
        if (!$this->featureFlags->isEnabled('audit_logging')) {
            return;
        }

        // TODO: Implementar logging real em tabela

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Audit] %s: %s',
                $event,
                json_encode($data, JSON_UNESCAPED_UNICODE)
            ));
        }
    }

    /**
     * Registra REST API endpoints globais
     * 
     * @return void
     */
    private function registerGlobalRestApi(): void
    {
        add_action('rest_api_init', function() {
            // CepController (consulta de CEP via ViaCEP)
            if (class_exists('LimpVix\Infrastructure\API\CepController')) {
                $cepController = new \LimpVix\Infrastructure\API\CepController();
                $cepController->register_routes();
            }
        });
    }

    /**
     * Register AJAX Handlers
     *
     * Handlers para chamadas AJAX do admin
     */
    private function registerAjaxHandlers(): void
    {
        // PPID - Test Connection
        add_action('wp_ajax_limpvix_test_ppid_connection', function() {
            check_ajax_referer('limpvix_ppid_test', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error([
                    'message' => 'Permissão negada'
                ]);
                return;
            }

            $email = sanitize_email($_POST['email'] ?? '');
            $senha = sanitize_text_field($_POST['senha'] ?? '');

            if (empty($email) || empty($senha)) {
                wp_send_json_error([
                    'message' => 'Email e senha são obrigatórios'
                ]);
                return;
            }

            $result = \LimpVix\Infrastructure\KYC\PPIDProviderFactory::testConnection($email, $senha);

            if ($result['success']) {
                wp_send_json_success([
                    'message' => $result['message'],
                    'saldo' => $result['saldo'],
                    'nome' => $result['nome'],
                ]);
            } else {
                wp_send_json_error([
                    'message' => $result['message']
                ]);
            }
        });
    }
}
