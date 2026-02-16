<?php
/**
 * AppointmentOrderMapper - Mapeamento Appointment ↔ Order
 *
 * RESPONSABILIDADE:
 * - Criar order_uuid a partir de appointment_id
 * - Manter mapeamento bidirecional
 * - Sincronizar dados entre Booknetic e Limpvix
 *
 * PRINCÍPIOS:
 * - 1 appointment = 1 order (1:1)
 * - Idempotência (não duplicar orders)
 * - Auditoria completa
 *
 * @package LimpVix\Integration\Booknetic\Mappers
 */

namespace LimpVix\Integration\Booknetic\Mappers;

defined('ABSPATH') || exit;

final class AppointmentOrderMapper
{
    /**
     * Criar order a partir de appointment
     *
     * @param int $appointmentId
     * @return string order_uuid
     * @throws \RuntimeException
     */
    public function createOrderFromAppointment(int $appointmentId): string
    {
        // Verificar se já existe order para este appointment
        $existingOrder = $this->getOrderUuidByAppointment($appointmentId);

        if ($existingOrder) {
            return $existingOrder;
        }

        // Buscar dados do appointment no Booknetic
        $appointment = $this->getAppointmentData($appointmentId);

        if (!$appointment) {
            throw new \RuntimeException("Appointment {$appointmentId} não encontrado no Booknetic");
        }

        // Gerar order_uuid único
        $orderUuid = $this->generateOrderUuid();

        // Salvar mapeamento
        $this->saveMapping($appointmentId, $orderUuid, $appointment);

        // Registrar no ledger
        do_action('limpvix_ledger_write', [
            'event_type' => 'order_created_from_appointment',
            'order_uuid' => $orderUuid,
            'appointment_id' => $appointmentId,
            'customer_id' => $appointment['customer_id'],
            'professional_id' => $appointment['staff_id'],
            'amount' => $appointment['price'],
            'timestamp' => time(),
        ]);

        return $orderUuid;
    }

    /**
     * Obter order_uuid por appointment_id
     *
     * @param int $appointmentId
     * @return string|null
     */
    public function getOrderUuidByAppointment(int $appointmentId): ?string
    {
        global $wpdb;

        $orderUuid = $wpdb->get_var($wpdb->prepare(
            "SELECT order_uuid FROM {$wpdb->prefix}limpvix_appointment_order_map WHERE appointment_id = %d",
            $appointmentId
        ));

        return $orderUuid ?: null;
    }

    /**
     * Obter appointment_id por order_uuid
     *
     * @param string $orderUuid
     * @return int|null
     */
    public function getAppointmentByOrderUuid(string $orderUuid): ?int
    {
        global $wpdb;

        $appointmentId = $wpdb->get_var($wpdb->prepare(
            "SELECT appointment_id FROM {$wpdb->prefix}limpvix_appointment_order_map WHERE order_uuid = %s",
            $orderUuid
        ));

        return $appointmentId ? (int)$appointmentId : null;
    }

    /**
     * Buscar dados do appointment no Booknetic
     *
     * CORREÇÃO BLC-003: Buscar price do serviço via JOIN
     *
     * @param int $appointmentId
     * @return array|null
     */
    private function getAppointmentData(int $appointmentId): ?array
    {
        global $wpdb;

        // JOIN com bkntc_services para buscar preço correto
        $appointment = $wpdb->get_row($wpdb->prepare(
            "SELECT
                a.*,
                s.price as service_price,
                s.name as service_name,
                s.duration as service_duration
             FROM {$wpdb->prefix}bkntc_appointments a
             LEFT JOIN {$wpdb->prefix}bkntc_services s ON a.service_id = s.id
             WHERE a.id = %d",
            $appointmentId
        ), ARRAY_A);

        if (!$appointment) {
            return null;
        }

        // Calcular total_amount (service + extras se houver)
        $totalAmount = (float)($appointment['service_price'] ?? 0);

        // Buscar extras do appointment (se existir tabela)
        $extras = $wpdb->get_results($wpdb->prepare(
            "SELECT extra_id, quantity, price
             FROM {$wpdb->prefix}bkntc_appointment_extras
             WHERE appointment_id = %d",
            $appointmentId
        ), ARRAY_A);

        if ($extras) {
            foreach ($extras as $extra) {
                $totalAmount += (float)($extra['price'] ?? 0) * (int)($extra['quantity'] ?? 1);
            }
        }

        // Adicionar campo calculado 'price' para compatibilidade
        $appointment['price'] = $totalAmount;
        $appointment['calculated_extras'] = $extras;

        error_log("[AppointmentOrderMapper] Appointment {$appointmentId} - Service: {$appointment['service_name']}, Price: R\$" . number_format($totalAmount, 2, ',', '.'));

        return $appointment;
    }

    /**
     * Gerar order_uuid único
     *
     * Formato: ORD-{timestamp}-{random}
     * Exemplo: ORD-1738865400-a3f9b2c1
     *
     * @return string
     */
    private function generateOrderUuid(): string
    {
        return sprintf(
            'ORD-%d-%s',
            time(),
            bin2hex(random_bytes(4))
        );
    }

    /**
     * Salvar mapeamento no banco
     *
     * @param int $appointmentId
     * @param string $orderUuid
     * @param array $appointmentData
     */
    private function saveMapping(int $appointmentId, string $orderUuid, array $appointmentData): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'limpvix_appointment_order_map',
            [
                'appointment_id' => $appointmentId,
                'order_uuid' => $orderUuid,
                'customer_id' => $appointmentData['customer_id'] ?? null,
                'staff_id' => $appointmentData['staff_id'] ?? null,
                'price' => $appointmentData['price'] ?? 0,
                'status' => $appointmentData['status'] ?? 'pending',
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%d', '%f', '%s', '%s']
        );
    }

    /**
     * Obter dados completos do mapeamento
     *
     * @param string $orderUuid
     * @return array|null
     */
    public function getMappingData(string $orderUuid): ?array
    {
        global $wpdb;

        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_appointment_order_map WHERE order_uuid = %s",
            $orderUuid
        ), ARRAY_A);

        return $mapping ?: null;
    }

    /**
     * Sincronizar status do appointment para a order
     *
     * @param int $appointmentId
     */
    public function syncStatus(int $appointmentId): void
    {
        $orderUuid = $this->getOrderUuidByAppointment($appointmentId);

        if (!$orderUuid) {
            return;
        }

        $appointment = $this->getAppointmentData($appointmentId);

        if (!$appointment) {
            return;
        }

        // Atualizar status no mapeamento
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'limpvix_appointment_order_map',
            ['status' => $appointment['status']],
            ['appointment_id' => $appointmentId],
            ['%s'],
            ['%d']
        );

        // Acionar evento de sincronização
        do_action('limpvix_appointment_status_synced', $orderUuid, $appointment['status']);
    }
}
