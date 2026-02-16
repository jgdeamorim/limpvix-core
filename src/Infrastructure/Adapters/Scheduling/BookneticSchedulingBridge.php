<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Adapters\Scheduling;

use LimpVix\Domain\Scheduling\Schedule;
use LimpVix\Domain\Scheduling\ValueObjects\TimeSlot;

/**
 * Adapter: BookneticSchedulingBridge
 *
 * Bridge entre LimpVix Scheduling e Booknetic.
 * Cria appointments no Booknetic quando profissionais são alocados.
 *
 * LimpVix → Booknetic (one-way sync)
 */
final class BookneticSchedulingBridge
{
    private \wpdb $wpdb;
    private string $appointmentsTable;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->appointmentsTable = $wpdb->prefix . 'bkntc_appointments';
    }

    /**
     * Cria appointment no Booknetic para um profissional alocado
     *
     * @param Schedule $schedule
     * @param int $professionalId
     * @param TimeSlot $slot
     * @return int Appointment ID do Booknetic
     */
    public function createAppointment(
        Schedule $schedule,
        int $professionalId,
        TimeSlot $slot
    ): int {
        $data = [
            'customer_id' => $this->getCustomerIdFromOrder($schedule->getOrderUuid()),
            'staff_id' => $professionalId,
            'service_id' => $this->getServiceId(), // Service padrão LimpVix
            'location_id' => $this->getDefaultLocationId(),
            'date' => $slot->getStart()->format('Y-m-d'),
            'start_time' => $slot->getStart()->format('H:i:s'),
            'end_time' => $slot->getEnd()->format('H:i:s'),
            'duration' => $slot->getDurationInMinutes(),
            'status' => 'approved', // LimpVix já validou
            'created_by' => 0, // System
            'created_at' => current_time('mysql'),
        ];

        $this->wpdb->insert(
            $this->appointmentsTable,
            $data,
            ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s']
        );

        return $this->wpdb->insert_id;
    }

    /**
     * Atualiza status de appointment no Booknetic
     *
     * @param int $appointmentId
     * @param string $status 'pending'|'approved'|'rejected'|'canceled'
     */
    public function updateAppointmentStatus(int $appointmentId, string $status): void
    {
        $this->wpdb->update(
            $this->appointmentsTable,
            ['status' => $status],
            ['id' => $appointmentId],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Bloqueia agenda de um profissional (timeoff)
     *
     * @param int $staffId
     * @param TimeSlot $slot
     */
    public function blockStaffTime(int $staffId, TimeSlot $slot): void
    {
        $timeoffsTable = $this->wpdb->prefix . 'bkntc_timeoffs';

        $this->wpdb->insert(
            $timeoffsTable,
            [
                'staff_id' => $staffId,
                'date_start' => $slot->getStart()->format('Y-m-d H:i:s'),
                'date_end' => $slot->getEnd()->format('Y-m-d H:i:s'),
                'note' => 'Bloqueado por LimpVix Scheduling',
            ],
            ['%d', '%s', '%s', '%s']
        );
    }

    /**
     * Remove bloqueio de agenda
     *
     * @param int $staffId
     * @param TimeSlot $slot
     */
    public function releaseStaffTime(int $staffId, TimeSlot $slot): void
    {
        $timeoffsTable = $this->wpdb->prefix . 'bkntc_timeoffs';

        $this->wpdb->delete(
            $timeoffsTable,
            [
                'staff_id' => $staffId,
                'date_start' => $slot->getStart()->format('Y-m-d H:i:s'),
            ],
            ['%d', '%s']
        );
    }

    private function getCustomerIdFromOrder(string $orderUuid): int
    {
        $ordersTable = $this->wpdb->prefix . 'limpvix_orders';

        $customerId = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT customer_id FROM {$ordersTable} WHERE uuid = %s",
                $orderUuid
            )
        );

        return (int) ($customerId ?? 1); // Default customer se não encontrar
    }

    private function getServiceId(): int
    {
        // Retornar ID do serviço padrão LimpVix no Booknetic
        // TODO: Configurável via settings
        return 1;
    }

    private function getDefaultLocationId(): int
    {
        // Retornar ID da localização padrão
        // TODO: Configurável via settings
        return 1;
    }
}
