<?php
/**
 * Hooks - Gerenciador de Interceptações do Booknetic
 *
 * RESPONSABILIDADE:
 * - Registrar TODOS os hooks/filters do WordPress
 * - Interceptar ações do Booknetic
 * - Delegar execução para componentes apropriados
 * - Respeitar Feature Flags
 *
 * PRINCÍPIO:
 * - Este é o ÚNICO ponto de contato com hooks do WordPress
 * - Cada hook verifica Feature Flag antes de executar
 * - NUNCA modificar comportamento do Booknetic diretamente
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
     * ORGANIZAÇÃO:
     * - PASSO 3: Primeira interceptação real (bkntc_appointment_created)
     * - Lifecycle hooks (create, update, delete) - FUTURO
     * - Status hooks (status changes) - FUTURO
     * - Validation hooks (before actions) - FUTURO
     * - Frontend hooks (booking panel) - FUTURO
     *
     * @return void
     */
    public function register(): void
    {
        // PASSO 3: Primeira interceptação real
        $this->registerPasso3Hooks();

        // PASSO 5.4: Adapters do sistema financeiro
        $this->registerFinancialAdapters();

        // PASSO 5.5: Módulo de payout (Mercado Pago)
        $this->registerPayoutModule();

        // PASSO 5.6: Admin Interface
        $this->registerAdminInterface();

        // Lifecycle hooks (FUTURO - ainda não habilitados)
        // $this->registerLifecycleHooks();

        // Status hooks (FUTURO)
        // $this->registerStatusHooks();

        // Validation hooks (FUTURO)
        // $this->registerValidationHooks();

        // Frontend hooks (FUTURO)
        // $this->registerFrontendHooks();
    }

    /**
     * PASSO 3: Registra hook de interceptação controlada
     *
     * Hook: bkntc_appointment_created
     * Momento: DEPOIS de criar appointment no Booknetic
     * Comportamento: Observador (log + Order em memória)
     *
     * @return void
     */
    private function registerPasso3Hooks(): void
    {
        add_action(
            'bkntc_appointment_created',
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
     * - Registrar hooks de WooCommerce, Booknetic, Feedback, Timer
     * - Conectar eventos externos ao sistema financeiro
     *
     * ADAPTERS:
     * - WooCommercePaymentAdapter (woocommerce_payment_complete)
     * - BookneticServiceAdapter (limpvix_booknetic_appointment_completed)
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
     * - Carregar módulo Mercado Pago
     * - Inicializar PayoutProvider
     * - Disponibilizar ExecuteTransfer Use Case
     *
     * CONFIGURAÇÃO NECESSÁRIA (wp-config.php):
     * - LIMPVIX_MP_ACCESS_TOKEN
     * - LIMPVIX_MP_TIMEOUT (opcional)
     *
     * @return void
     */
    private function registerPayoutModule(): void
    {
        // Verificar feature flag
        if (!$this->featureFlags->isEnabled('payout_engine')) {
            return;
        }

        // Carregar interface do provider (necessária para autoload)
        $providerInterface = LIMPVIX_PLUGIN_DIR . 'modules/payouts/PayoutProviderInterface.php';
        if (file_exists($providerInterface)) {
            require_once $providerInterface;
        }

        // Carregar módulo Mercado Pago
        $mpModuleFile = LIMPVIX_PLUGIN_DIR . 'modules/payouts/mercadopago/MercadoPagoModule.php';
        if (file_exists($mpModuleFile)) {
            require_once $mpModuleFile;

            // Inicializar módulo (optional - não quebrar se MP não configurado)
            if (class_exists('LimpVix\\Modules\\Payouts\\MercadoPago\\MercadoPagoModule')) {
                try {
                    $mpModule = new \LimpVix\Modules\Payouts\MercadoPago\MercadoPagoModule();
                    $mpModule->boot();
                } catch (\Exception $e) {
                    // Mercado Pago não configurado - não é crítico
                    // Log para debug mas não quebrar o plugin
                    error_log('[LimpVix] Payout Module not initialized: ' . $e->getMessage());
                }
            }
        }
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
        // Só executar no admin
        if (!is_admin()) {
            return;
        }

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

    /**
     * Registra hooks de ciclo de vida
     *
     * HOOKS:
     * - booknetic_before_appointment_add
     * - booknetic_after_appointment_add
     * - booknetic_before_appointment_update
     * - booknetic_after_appointment_update
     * - booknetic_before_appointment_delete
     *
     * @return void
     */
    private function registerLifecycleHooks(): void
    {
        // ANTES de criar appointment
        add_action('booknetic_before_appointment_add', [$this, 'beforeAppointmentAdd'], 1, 1);

        // DEPOIS de criar appointment
        add_action('booknetic_after_appointment_add', [$this, 'afterAppointmentAdd'], 999, 2);

        // ANTES de atualizar appointment
        add_action('booknetic_before_appointment_update', [$this, 'beforeAppointmentUpdate'], 1, 2);

        // DEPOIS de atualizar appointment
        add_action('booknetic_after_appointment_update', [$this, 'afterAppointmentUpdate'], 999, 2);

        // ANTES de deletar appointment
        add_action('booknetic_before_appointment_delete', [$this, 'beforeAppointmentDelete'], 1, 1);
    }

    /**
     * Registra hooks de mudança de status
     *
     * HOOKS:
     * - booknetic_before_status_change
     * - booknetic_after_status_change
     *
     * @return void
     */
    private function registerStatusHooks(): void
    {
        // ANTES de mudar status
        add_action('booknetic_before_status_change', [$this, 'beforeStatusChange'], 1, 3);

        // DEPOIS de mudar status
        add_action('booknetic_after_status_change', [$this, 'afterStatusChange'], 999, 2);
    }

    /**
     * Registra hooks de validação
     *
     * FILTERS:
     * - booknetic_validate_appointment
     * - booknetic_validate_booking_data
     *
     * @return void
     */
    private function registerValidationHooks(): void
    {
        // Validação de appointment
        add_filter('booknetic_validate_appointment', [$this, 'validateAppointment'], 10, 2);

        // Validação de booking data
        add_filter('booknetic_validate_booking_data', [$this, 'validateBookingData'], 10, 2);
    }

    /**
     * Registra hooks do frontend (booking panel)
     *
     * FILTERS:
     * - booknetic_available_services
     * - booknetic_available_staff
     * - booknetic_available_timeslots
     * - booknetic_appointment_price
     *
     * @return void
     */
    private function registerFrontendHooks(): void
    {
        // Filtrar serviços disponíveis
        add_filter('booknetic_available_services', [$this, 'filterAvailableServices'], 10, 2);

        // Filtrar staff disponível
        add_filter('booknetic_available_staff', [$this, 'filterAvailableStaff'], 10, 2);

        // Filtrar timeslots disponíveis
        add_filter('booknetic_available_timeslots', [$this, 'filterAvailableTimeslots'], 10, 3);

        // Ajustar preço
        add_filter('booknetic_appointment_price', [$this, 'calculatePrice'], 10, 2);
    }

    // ========================================
    // PASSO 3: HANDLER DE INTERCEPTAÇÃO
    // ========================================

    /**
     * Handler: Appointment criado no Booknetic
     *
     * ⚠️ PRINCÍPIO FUNDAMENTAL:
     * - Este hook NÃO é dono da lógica
     * - Este hook apenas delega
     * - Este hook NUNCA quebra fluxo do Booknetic
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
     * - NÃO bloqueia Booknetic
     * - NÃO altera Booknetic
     * - NÃO lança exceções
     *
     * @param object $appointmentData AppointmentRequestData do Booknetic
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
            // NÃO quebrar o fluxo do Booknetic
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

    // ========================================
    // LIFECYCLE HOOKS CALLBACKS (FUTURO)
    // ========================================

    /**
     * ANTES de criar appointment
     *
     * @param array $data Dados do appointment
     * @return void
     */
    public function beforeAppointmentAdd(array $data): void
    {
        if (!$this->featureFlags->isEnabled('intercept_appointment_creation')) {
            return;
        }

        // TODO: Implementar validações
        // TODO: Aplicar políticas de agendamento
        // TODO: Verificar SLA de antecedência

        $this->log('BEFORE_APPOINTMENT_ADD', $data);
    }

    /**
     * DEPOIS de criar appointment
     *
     * @param int $appointmentId ID do appointment criado
     * @param array $data Dados utilizados
     * @return void
     */
    public function afterAppointmentAdd(int $appointmentId, array $data): void
    {
        if (!$this->featureFlags->isEnabled('create_service_order')) {
            return;
        }

        // TODO: Criar Ordem de Serviço
        // TODO: Sincronizar com sistemas externos
        // TODO: Disparar notificações

        $this->log('AFTER_APPOINTMENT_ADD', [
            'appointment_id' => $appointmentId,
            'data' => $data
        ]);
    }

    /**
     * ANTES de atualizar appointment
     *
     * @param int $appointmentId ID do appointment
     * @param array $data Novos dados
     * @return void
     */
    public function beforeAppointmentUpdate(int $appointmentId, array $data): void
    {
        if (!$this->featureFlags->isEnabled('intercept_appointment_update')) {
            return;
        }

        // TODO: Validar se pode atualizar
        // TODO: Aplicar políticas de reagendamento

        $this->log('BEFORE_APPOINTMENT_UPDATE', [
            'appointment_id' => $appointmentId,
            'data' => $data
        ]);
    }

    /**
     * DEPOIS de atualizar appointment
     *
     * @param int $appointmentId ID do appointment
     * @param array $data Dados atualizados
     * @return void
     */
    public function afterAppointmentUpdate(int $appointmentId, array $data): void
    {
        if (!$this->featureFlags->isEnabled('sync_order_updates')) {
            return;
        }

        // TODO: Sincronizar com OS
        // TODO: Auditar mudanças

        $this->log('AFTER_APPOINTMENT_UPDATE', [
            'appointment_id' => $appointmentId,
            'data' => $data
        ]);
    }

    /**
     * ANTES de deletar appointment
     *
     * @param int $appointmentId ID do appointment
     * @return void
     */
    public function beforeAppointmentDelete(int $appointmentId): void
    {
        if (!$this->featureFlags->isEnabled('prevent_appointment_deletion')) {
            return;
        }

        // TODO: Validar se pode deletar
        // TODO: Soft delete na OS

        $this->log('BEFORE_APPOINTMENT_DELETE', [
            'appointment_id' => $appointmentId
        ]);
    }

    // ========================================
    // STATUS HOOKS CALLBACKS
    // ========================================

    /**
     * ANTES de mudar status
     *
     * @param int $appointmentId
     * @param string $fromStatus
     * @param string $toStatus
     * @return void
     */
    public function beforeStatusChange(int $appointmentId, string $fromStatus, string $toStatus): void
    {
        if (!$this->featureFlags->isEnabled('intercept_status_change')) {
            return;
        }

        // TODO: Validar transição de estado
        // TODO: Aplicar políticas de cancelamento

        $this->log('BEFORE_STATUS_CHANGE', [
            'appointment_id' => $appointmentId,
            'from' => $fromStatus,
            'to' => $toStatus
        ]);
    }

    /**
     * DEPOIS de mudar status
     *
     * @param int $appointmentId
     * @param string $newStatus
     * @return void
     */
    public function afterStatusChange(int $appointmentId, string $newStatus): void
    {
        if (!$this->featureFlags->isEnabled('sync_status_change')) {
            return;
        }

        // TODO: Sincronizar status com OS
        // TODO: Aplicar ações colaterais

        $this->log('AFTER_STATUS_CHANGE', [
            'appointment_id' => $appointmentId,
            'new_status' => $newStatus
        ]);
    }

    // ========================================
    // VALIDATION HOOKS CALLBACKS
    // ========================================

    /**
     * Validar appointment
     *
     * @param bool $isValid Validação do Booknetic
     * @param array $data Dados do appointment
     * @return bool
     */
    public function validateAppointment(bool $isValid, array $data): bool
    {
        if (!$this->featureFlags->isEnabled('validate_appointments')) {
            return $isValid;
        }

        if (!$isValid) {
            return false; // Já falhou no Booknetic
        }

        // TODO: Aplicar validações LimpVix

        return $isValid;
    }

    /**
     * Validar booking data
     *
     * @param bool $isValid Validação do Booknetic
     * @param array $bookingData Dados do booking
     * @return bool
     */
    public function validateBookingData(bool $isValid, array $bookingData): bool
    {
        if (!$this->featureFlags->isEnabled('validate_booking_data')) {
            return $isValid;
        }

        if (!$isValid) {
            return false; // Já falhou no Booknetic
        }

        // TODO: Aplicar validações LimpVix

        return $isValid;
    }

    // ========================================
    // FRONTEND HOOKS CALLBACKS
    // ========================================

    /**
     * Filtrar serviços disponíveis
     *
     * @param array $services Serviços do Booknetic
     * @param int $locationId ID da localização
     * @return array
     */
    public function filterAvailableServices(array $services, int $locationId): array
    {
        if (!$this->featureFlags->isEnabled('filter_services')) {
            return $services;
        }

        // TODO: Aplicar filtros LimpVix

        return $services;
    }

    /**
     * Filtrar staff disponível
     *
     * @param array $staffList Lista de staff
     * @param int $serviceId ID do serviço
     * @return array
     */
    public function filterAvailableStaff(array $staffList, int $serviceId): array
    {
        if (!$this->featureFlags->isEnabled('filter_staff')) {
            return $staffList;
        }

        // TODO: Aplicar filtros LimpVix

        return $staffList;
    }

    /**
     * Filtrar timeslots disponíveis
     *
     * @param array $timeslots Timeslots do Booknetic
     * @param string $date Data selecionada
     * @param int $serviceId ID do serviço
     * @return array
     */
    public function filterAvailableTimeslots(array $timeslots, string $date, int $serviceId): array
    {
        if (!$this->featureFlags->isEnabled('filter_timeslots')) {
            return $timeslots;
        }

        // TODO: Aplicar filtros LimpVix (SLA, capacidade)

        return $timeslots;
    }

    /**
     * Calcular preço final
     *
     * @param float $price Preço do Booknetic
     * @param int $appointmentId ID do appointment
     * @return float
     */
    public function calculatePrice(float $price, int $appointmentId): float
    {
        if (!$this->featureFlags->isEnabled('calculate_custom_price')) {
            return $price;
        }

        // TODO: Aplicar cálculo de preço LimpVix

        return $price;
    }

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
}
