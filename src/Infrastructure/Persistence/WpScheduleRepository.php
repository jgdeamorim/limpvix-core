<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Scheduling\Schedule;
use LimpVix\Domain\Scheduling\ValueObjects\TimeWindow;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;
use LimpVix\Domain\Scheduling\ValueObjects\TimeSlot;
use LimpVix\Domain\Scheduling\ValueObjects\CheckIn;
use LimpVix\Domain\Scheduling\ValueObjects\CheckOut;
use LimpVix\Domain\Scheduling\ValueObjects\SlaViolation;
use LimpVix\Domain\Scheduling\ValueObjects\GeoCoordinates;
use LimpVix\Domain\Scheduling\ValueObjects\MediaCollection;
use LimpVix\Domain\Scheduling\Repositories\ScheduleRepositoryInterface;

/**
 * Repository: WpScheduleRepository
 *
 * Implementação WordPress do ScheduleRepository.
 * Persiste Schedules em wp_limpvix_schedules + tabelas relacionadas.
 *
 * Hidratação/Desidratação de Value Objects via JSON.
 */
final class WpScheduleRepository implements ScheduleRepositoryInterface
{
    private \wpdb $wpdb;
    private string $table;
    private string $allocationsTable;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_schedules';
        $this->allocationsTable = $wpdb->prefix . 'limpvix_professional_allocations';
    }

    public function save(Schedule $schedule): void
    {
        $data = $this->dehydrate($schedule);

        // Verificar se já existe
        $existing = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE uuid = %s",
                $schedule->getUuid()
            )
        );

        if ($existing) {
            // UPDATE
            $this->wpdb->update(
                $this->table,
                $data,
                ['uuid' => $schedule->getUuid()],
                [
                    '%s', '%s', '%d',
                    '%s', '%s', '%s', '%d',
                    '%s', '%d',
                    '%s', '%s', '%s', '%s',
                    '%s', '%s', '%s',
                ],
                ['%s']
            );
        } else {
            // INSERT
            $this->wpdb->insert(
                $this->table,
                $data,
                [
                    '%s', '%s', '%d',
                    '%s', '%s', '%s', '%d',
                    '%s', '%d',
                    '%s', '%s', '%s', '%s',
                    '%s', '%s', '%s',
                ]
            );
        }

        // Salvar alocações de profissionais
        $this->saveAllocations($schedule);
    }

    public function findByUuid(string $uuid): ?Schedule
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE uuid = %s",
                $uuid
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByOrderUuid(string $orderUuid): ?Schedule
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE order_uuid = %s ORDER BY created_at DESC LIMIT 1",
                $orderUuid
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByProfessionalAndDate(int $professionalId, \DateTimeImmutable $date): array
    {
        $dateStart = $date->format('Y-m-d 00:00:00');
        $dateEnd = $date->format('Y-m-d 23:59:59');

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT s.* FROM {$this->table} s
                INNER JOIN {$this->allocationsTable} a ON s.uuid = a.schedule_uuid
                WHERE a.professional_id = %d
                AND s.requested_time BETWEEN %s AND %s
                ORDER BY s.requested_time ASC",
                $professionalId,
                $dateStart,
                $dateEnd
            ),
            ARRAY_A
        );

        return array_map(fn($row) => $this->hydrate($row), $results);
    }

    public function findByStatus(string $status, int $limit = 50, int $offset = 0): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $status,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return array_map(fn($row) => $this->hydrate($row), $results);
    }

    public function findAll(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        // Filter: status
        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        // Filter: date range
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(requested_time) >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(requested_time) <= %s';
            $params[] = $filters['date_to'];
        }

        // Filter: SLA violations only
        if (!empty($filters['sla_only'])) {
            $where[] = 'sla_violation IS NOT NULL';
        }

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 100";

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, ...$params);
        }

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        return $results ?: [];
    }

    public function findWithSlaViolations(?\DateTimeImmutable $since = null): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE sla_violation IS NOT NULL";

        if ($since) {
            $sql .= $this->wpdb->prepare(" AND created_at >= %s", $since->format('Y-m-d H:i:s'));
        }

        $sql .= " ORDER BY created_at DESC";

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(fn($row) => $this->hydrate($row), $results);
    }

    public function findPendingAllocation(): array
    {
        $results = $this->wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE status = 'draft' ORDER BY created_at ASC",
            ARRAY_A
        );

        return array_map(fn($row) => $this->hydrate($row), $results);
    }

    public function findByDate(\DateTimeImmutable $date): array
    {
        $dateStart = $date->format('Y-m-d 00:00:00');
        $dateEnd = $date->format('Y-m-d 23:59:59');

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE requested_time BETWEEN %s AND %s ORDER BY requested_time ASC",
                $dateStart,
                $dateEnd
            ),
            ARRAY_A
        );

        return array_map(fn($row) => $this->hydrate($row), $results);
    }

    public function delete(string $uuid): void
    {
        $this->wpdb->delete(
            $this->table,
            ['uuid' => $uuid],
            ['%s']
        );
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
                $status
            )
        );
    }

    /**
     * Desidrata Schedule para array (para salvar no banco)
     */
    private function dehydrate(Schedule $schedule): array
    {
        return [
            'uuid' => $schedule->getUuid(),
            'order_uuid' => $schedule->getOrderUuid(),
            'briefing_id' => $schedule->getBriefingId(),
            'requested_time' => $schedule->getRequestedTime()->format('Y-m-d H:i:s'),
            'window_start' => $schedule->getValidWindow()->getWindowStart()->format('Y-m-d H:i:s'),
            'window_end' => $schedule->getValidWindow()->getWindowEnd()->format('Y-m-d H:i:s'),
            'estimated_duration_minutes' => $schedule->getEstimatedDurationMinutes(),
            'status' => $schedule->getStatus(),
            'required_professionals' => $schedule->getRequiredProfessionals(),
            'service_location' => json_encode($schedule->getLocation()->toArray()),
            'check_in_data' => $schedule->getCheckIn() ? json_encode($schedule->getCheckIn()->toArray()) : null,
            'check_out_data' => $schedule->getCheckOut() ? json_encode($schedule->getCheckOut()->toArray()) : null,
            'sla_violation' => $schedule->getSlaViolation() ? json_encode($schedule->getSlaViolation()->toArray()) : null,
            'created_at' => $schedule->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'allocated_at' => $schedule->getAllocatedAt() ? $schedule->getAllocatedAt()->format('Y-m-d H:i:s') : null,
            'completed_at' => $schedule->getCompletedAt() ? $schedule->getCompletedAt()->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Hidrata Schedule a partir de array do banco
     */
    private function hydrate(array $row): Schedule
    {
        // Criar Schedule usando Reflection (pois construtor é private)
        $reflection = new \ReflectionClass(Schedule::class);
        $schedule = $reflection->newInstanceWithoutConstructor();

        // Setar propriedades privadas
        $this->setProperty($schedule, 'uuid', $row['uuid']);
        $this->setProperty($schedule, 'orderUuid', $row['order_uuid']);
        $this->setProperty($schedule, 'briefingId', (int) $row['briefing_id']);
        $this->setProperty($schedule, 'requestedTime', new \DateTimeImmutable($row['requested_time']));

        // TimeWindow
        $validWindow = TimeWindow::fromRequestedTime(
            new \DateTimeImmutable($row['requested_time']),
            60 // TODO: calcular tolerance correto
        );
        $this->setProperty($schedule, 'validWindow', $validWindow);

        $this->setProperty($schedule, 'estimatedDurationMinutes', (int) $row['estimated_duration_minutes']);

        // ServiceLocation
        $locationData = json_decode($row['service_location'], true);
        $location = ServiceLocation::fromArray($locationData);
        $this->setProperty($schedule, 'location', $location);

        // Alocações de profissionais
        $allocations = $this->loadAllocations($row['uuid']);
        $this->setProperty($schedule, 'allocatedProfessionals', $allocations);

        $this->setProperty($schedule, 'requiredProfessionals', (int) $row['required_professionals']);

        // CheckIn
        if ($row['check_in_data']) {
            $checkInData = json_decode($row['check_in_data'], true);
            $checkIn = CheckIn::fromArray($checkInData);
            $this->setProperty($schedule, 'checkIn', $checkIn);
        } else {
            $this->setProperty($schedule, 'checkIn', null);
        }

        // CheckOut
        if ($row['check_out_data']) {
            $checkOutData = json_decode($row['check_out_data'], true);
            $checkOut = CheckOut::fromArray($checkOutData);
            $this->setProperty($schedule, 'checkOut', $checkOut);
        } else {
            $this->setProperty($schedule, 'checkOut', null);
        }

        // SlaViolation
        if ($row['sla_violation']) {
            $violationData = json_decode($row['sla_violation'], true);
            $violation = SlaViolation::fromArray($violationData);
            $this->setProperty($schedule, 'slaViolation', $violation);
        } else {
            $this->setProperty($schedule, 'slaViolation', null);
        }

        $this->setProperty($schedule, 'status', $row['status']);
        $this->setProperty($schedule, 'createdAt', new \DateTimeImmutable($row['created_at']));
        $this->setProperty($schedule, 'allocatedAt', $row['allocated_at'] ? new \DateTimeImmutable($row['allocated_at']) : null);
        $this->setProperty($schedule, 'completedAt', $row['completed_at'] ? new \DateTimeImmutable($row['completed_at']) : null);

        return $schedule;
    }

    /**
     * Salva alocações de profissionais
     */
    private function saveAllocations(Schedule $schedule): void
    {
        // Deletar alocações antigas
        $this->wpdb->delete(
            $this->allocationsTable,
            ['schedule_uuid' => $schedule->getUuid()],
            ['%s']
        );

        // Inserir novas alocações
        foreach ($schedule->getAllocatedProfessionals() as $professionalId => $slot) {
            $this->wpdb->insert(
                $this->allocationsTable,
                [
                    'schedule_uuid' => $schedule->getUuid(),
                    'professional_id' => $professionalId,
                    'allocated_start' => $slot->getStart()->format('Y-m-d H:i:s'),
                    'allocated_end' => $slot->getEnd()->format('Y-m-d H:i:s'),
                    'status' => 'allocated',
                    'allocation_score' => null, // TODO: armazenar score
                    'allocated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                ['%s', '%d', '%s', '%s', '%s', '%f', '%s']
            );
        }
    }

    /**
     * Carrega alocações de profissionais
     */
    private function loadAllocations(string $scheduleUuid): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->allocationsTable} WHERE schedule_uuid = %s",
                $scheduleUuid
            ),
            ARRAY_A
        );

        $allocations = [];
        foreach ($results as $row) {
            $slot = TimeSlot::create(
                new \DateTimeImmutable($row['allocated_start']),
                new \DateTimeImmutable($row['allocated_end'])
            );
            $allocations[(int) $row['professional_id']] = $slot;
        }

        return $allocations;
    }

    /**
     * Helper para setar propriedades privadas via Reflection
     */
    private function setProperty(object $object, string $property, $value): void
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}
