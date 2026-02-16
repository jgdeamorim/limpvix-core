<?php
/**
 * BookneticServiceAdapter - Adaptador para Eventos de Serviço
 *
 * RESPONSABILIDADE:
 * - Capturar hook booknetic_appointment_completed
 * - Extrair dados necessários (appointment → order)
 * - Traduzir para Command interno
 * - Chamar Use Case apropriado
 *
 * PRINCÍPIOS:
 * - Adapter Pattern
 * - Zero regras de negócio
 * - Zero decisões financeiras
 * - Apenas tradução: evento externo → comando interno
 *
 * IMPORTANTE:
 * - NÃO decide se pode transicionar (Policy decide)
 * - NÃO valida regras (Use Case + Policy validam)
 * - NÃO acessa ledger diretamente
 * - Apenas: hook → dados → use case
 *
 * HOOK:
 * - booknetic_appointment_completed (customizado)
 * - Disparado quando serviço é marcado como completado
 *
 * PASSO 5.4 - Adaptadores de Eventos
 *
 * @package LimpVix\Infrastructure\Adapters
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Application\UseCases\ProcessServiceCompleted;

defined('ABSPATH') || exit;

class BookneticServiceAdapter
{
    /**
     * Use Case
     *
     * @var ProcessServiceCompleted
     */
    private $useCase;

    /**
     * Construtor
     *
     * @param ProcessServiceCompleted $useCase
     */
    public function __construct(ProcessServiceCompleted $useCase)
    {
        $this->useCase = $useCase;
    }

    /**
     * Registrar hooks
     *
     * @return void
     */
    public function register(): void
    {
        // Hook customizado (será criado no integration layer)
        add_action('limpvix_booknetic_appointment_completed', [$this, 'handleAppointmentCompleted'], 10, 2);
    }

    /**
     * Handler: limpvix_booknetic_appointment_completed
     *
     * @param int $appointmentId Booknetic appointment ID
     * @param array $appointmentData Dados do appointment
     * @return void
     */
    public function handleAppointmentCompleted(int $appointmentId, array $appointmentData = []): void
    {
        error_log("[BookneticServiceAdapter] Hook recebido! Appointment ID: {$appointmentId}");

        try {
            // 1. Obter UUID da order vinculada
            $orderUuid = $this->getOrderUuidFromAppointment($appointmentId);

            if ($orderUuid === null) {
                error_log("[BookneticServiceAdapter] ERRO: Appointment {$appointmentId} não tem order vinculada");
                $this->logWarning("Appointment {$appointmentId} não tem order vinculada");
                return;
            }

            error_log("[BookneticServiceAdapter] UUID encontrado: {$orderUuid}");

            // 2. Obter professional ID
            $professionalId = $appointmentData['staff_id'] ?? $this->getProfessionalId($appointmentId);
            error_log("[BookneticServiceAdapter] Professional ID: " . ($professionalId ?? 'NULL'));

            // 3. Executar Use Case
            error_log("[BookneticServiceAdapter] Executando ProcessServiceCompleted...");
            $result = $this->useCase->execute($orderUuid, $professionalId);
            error_log("[BookneticServiceAdapter] Use Case retornou: " . ($result->isSuccess() ? 'SUCCESS' : 'FAILED'));

            // 4. Log do resultado
            if ($result->isSuccess()) {
                error_log("[BookneticServiceAdapter] Transição realizada: {$result->getFromStatus()->getValue()} → {$result->getToStatus()->getValue()}");
                $this->logSuccess($appointmentId, $orderUuid, $result);
            } else {
                error_log("[BookneticServiceAdapter] Transição rejeitada: " . $result->getRejectReason());
                $this->logRejection($appointmentId, $orderUuid, $result);
            }

        } catch (\Exception $e) {
            error_log("[BookneticServiceAdapter] EXCEPTION: " . $e->getMessage());
            error_log("[BookneticServiceAdapter] Stack trace: " . $e->getTraceAsString());
            $this->logError($appointmentId, $e);
        }
    }

    /**
     * Obter UUID da order a partir do appointment ID
     *
     * @param int $appointmentId
     * @return string|null
     */
    private function getOrderUuidFromAppointment(int $appointmentId): ?string
    {
        global $wpdb;

        // Assumindo que existe uma tabela de mapeamento appointment → order
        $table = $wpdb->prefix . 'limpvix_appointment_order_map';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $orderUuid = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT order_uuid FROM {$table} WHERE appointment_id = %d LIMIT 1",
                $appointmentId
            )
        );

        return $orderUuid ?: null;
    }

    /**
     * Obter professional ID do appointment
     *
     * @param int $appointmentId
     * @return int|null
     */
    private function getProfessionalId(int $appointmentId): ?int
    {
        global $wpdb;

        // Query direta na tabela do Booknetic
        $table = $wpdb->prefix . 'bkntc_appointments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $staffId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT staff_id FROM {$table} WHERE id = %d LIMIT 1",
                $appointmentId
            )
        );

        return $staffId ? (int) $staffId : null;
    }

    /**
     * Log de sucesso
     *
     * @param int $appointmentId
     * @param string $orderUuid
     * @param \LimpVix\Application\Results\TransitionFinancialStatusResult $result
     * @return void
     */
    private function logSuccess(int $appointmentId, string $orderUuid, $result): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_booknetic_service_processed', [
            'appointment_id' => $appointmentId,
            'order_uuid' => $orderUuid,
            'from_status' => $result->getFromStatus()->getValue(),
            'to_status' => $result->getToStatus()->getValue(),
            'ledger_uuid' => $result->getLedgerUuid()
        ]);
    }

    /**
     * Log de rejeição
     *
     * @param int $appointmentId
     * @param string $orderUuid
     * @param \LimpVix\Application\Results\TransitionFinancialStatusResult $result
     * @return void
     */
    private function logRejection(int $appointmentId, string $orderUuid, $result): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_booknetic_service_rejected', [
            'appointment_id' => $appointmentId,
            'order_uuid' => $orderUuid,
            'reason' => $result->getRejectReason()
        ]);
    }

    /**
     * Log de warning
     *
     * @param string $message
     * @return void
     */
    private function logWarning(string $message): void
    {
        if (function_exists('do_action')) {
            do_action('limpvix_adapter_warning', [
                'adapter' => 'BookneticServiceAdapter',
                'message' => $message
            ]);
        }
    }

    /**
     * Log de erro
     *
     * @param int $appointmentId
     * @param \Exception $exception
     * @return void
     */
    private function logError(int $appointmentId, \Exception $exception): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_adapter_error', [
            'adapter' => 'BookneticServiceAdapter',
            'appointment_id' => $appointmentId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
