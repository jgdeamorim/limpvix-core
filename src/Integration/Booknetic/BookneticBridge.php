<?php
/**
 * BookneticBridge - Ponte de Integração Booknetic × Limpvix-Core
 *
 * RESPONSABILIDADE:
 * - Interceptar eventos do Booknetic (agendamentos, conclusões)
 * - Mapear appointment_id → order_uuid
 * - Registrar hooks de permissão e UI
 * - Manter isolamento completo (sem mexer no Booknetic)
 *
 * PRINCÍPIOS:
 * - Booknetic = engine operacional (agenda/execução)
 * - Limpvix = soberano (regras/dinheiro/compliance)
 * - Interceptação limpa via hooks
 * - Zero alteração no código do Booknetic
 *
 * @package LimpVix\Integration\Booknetic
 */

namespace LimpVix\Integration\Booknetic;

use LimpVix\Integration\Booknetic\Guards\StaffAccessGuard;
use LimpVix\Integration\Booknetic\Guards\StaffActionGuard;
use LimpVix\Integration\Booknetic\UI\StaffPanelOverride;
use LimpVix\Integration\Booknetic\UI\StaffNotices;
use LimpVix\Integration\Booknetic\Mappers\AppointmentOrderMapper;

defined('ABSPATH') || exit;

final class BookneticBridge
{
    private static ?self $instance = null;
    private AppointmentOrderMapper $mapper;

    private function __construct()
    {
        $this->mapper = new AppointmentOrderMapper();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(): void
    {
        if (!$this->isBookneticActive()) {
            return;
        }

        // Eventos do Booknetic
        add_action('bkntc_appointment_created', [$this, 'onAppointmentCreated'], 10, 1);
        add_action('bkntc_appointment_completed', [$this, 'onAppointmentCompleted'], 10, 1);
        add_action('bkntc_appointment_canceled', [$this, 'onAppointmentCanceled'], 10, 1);
        add_action('bkntc_staff_updated', [$this, 'onStaffUpdated'], 10, 1);

        // Guards
        add_filter('bkntc_staff_can_access', [StaffAccessGuard::class, 'canAccess'], 10, 2);
        add_filter('bkntc_staff_can_execute_action', [StaffActionGuard::class, 'canExecute'], 10, 3);

        // UI Overrides
        add_action('bkntc_staff_panel_header', [StaffNotices::class, 'renderHeader']);
        add_action('bkntc_staff_panel_footer', [StaffPanelOverride::class, 'hideFinancialTabs']);
        add_action('admin_menu', [StaffPanelOverride::class, 'hideMenus'], 999);

        // Briefing
        add_action('bkntc_after_booking_completed', [$this, 'redirectToBriefing'], 10, 1);
    }

    private function isBookneticActive(): bool
    {
        return class_exists('BookneticApp');
    }

    public function onAppointmentCreated(int $appointmentId): void
    {
        try {
            $orderUuid = $this->mapper->createOrderFromAppointment($appointmentId);
            do_action('limpvix_log_event', 'booknetic_appointment_created', [
                'appointment_id' => $appointmentId,
                'order_uuid' => $orderUuid,
            ]);
        } catch (\Exception $e) {
            error_log('[LimpVix] Erro ao criar order do appointment: ' . $e->getMessage());
        }
    }

    public function onAppointmentCompleted(int $appointmentId): void
    {
        try {
            $orderUuid = $this->mapper->getOrderUuidByAppointment($appointmentId);
            if (!$orderUuid) {
                throw new \RuntimeException("Order não encontrada para appointment {$appointmentId}");
            }
            do_action('limpvix_service_completed', $orderUuid);
            do_action('limpvix_log_event', 'booknetic_appointment_completed', [
                'appointment_id' => $appointmentId,
                'order_uuid' => $orderUuid,
            ]);
        } catch (\Exception $e) {
            error_log('[LimpVix] Erro ao processar conclusão do serviço: ' . $e->getMessage());
        }
    }

    public function onAppointmentCanceled(int $appointmentId): void
    {
        try {
            $orderUuid = $this->mapper->getOrderUuidByAppointment($appointmentId);
            if ($orderUuid) {
                do_action('limpvix_service_canceled', $orderUuid);
            }
        } catch (\Exception $e) {
            error_log('[LimpVix] Erro ao processar cancelamento: ' . $e->getMessage());
        }
    }

    public function onStaffUpdated(int $staffId): void
    {
        do_action('limpvix_staff_updated', $staffId);
    }

    public function redirectToBriefing(int $appointmentId): void
    {
        $briefingUrl = add_query_arg([
            'page' => 'limpvix-briefing',
            'appointment' => $appointmentId,
        ], admin_url('admin.php'));
        wp_safe_redirect($briefingUrl);
        exit;
    }

    public function getMapper(): AppointmentOrderMapper
    {
        return $this->mapper;
    }

    public function healthCheck(): array
    {
        return [
            'booknetic_active' => $this->isBookneticActive(),
            'hooks_registered' => has_action('bkntc_appointment_completed'),
            'guards_active' => has_filter('bkntc_staff_can_access'),
            'mapper_ready' => $this->mapper !== null,
        ];
    }
}
