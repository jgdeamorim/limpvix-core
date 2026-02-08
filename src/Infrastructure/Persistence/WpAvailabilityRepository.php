<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Scheduling\ValueObjects\WeeklyAvailability;
use LimpVix\Domain\Scheduling\ValueObjects\TimeSlot;
use LimpVix\Domain\Scheduling\Repositories\AvailabilityRepositoryInterface;

/**
 * Repository: WpAvailabilityRepository
 *
 * Gerencia disponibilidade semanal de profissionais.
 */
final class WpAvailabilityRepository implements AvailabilityRepositoryInterface
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_professional_availability';
    }

    public function save(int $professionalId, WeeklyAvailability $availability): void
    {
        // Deletar existente
        $this->wpdb->delete(
            $this->table,
            ['professional_id' => $professionalId],
            ['%d']
        );

        // Inserir nova availability
        foreach ($availability->getAvailableDays() as $day) {
            $slots = $availability->getSlotsFor($day);
            foreach ($slots as $slot) {
                $this->wpdb->insert(
                    $this->table,
                    [
                        'professional_id' => $professionalId,
                        'day_of_week' => $day,
                        'start_time' => $slot->getStart()->format('H:i:s'),
                        'end_time' => $slot->getEnd()->format('H:i:s'),
                        'max_daily_hours' => 8, // Default
                        'service_region' => '{}', // Placeholder
                        'skills' => '{"skills":[]}', // Placeholder
                        'is_active' => true,
                    ],
                    ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d']
                );
            }
        }
    }

    public function findByProfessional(int $professionalId): ?WeeklyAvailability
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE professional_id = %d AND is_active = 1",
                $professionalId
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return null;
        }

        $schedule = [];
        foreach ($rows as $row) {
            $day = strtolower($row['day_of_week']);
            $slot = TimeSlot::fromStrings($row['start_time'], $row['end_time']);

            if (!isset($schedule[$day])) {
                $schedule[$day] = [];
            }
            $schedule[$day][] = $slot;
        }

        return WeeklyAvailability::create($schedule);
    }

    public function findAvailableOnDay(string $dayOfWeek): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT DISTINCT professional_id FROM {$this->table} WHERE day_of_week = %s AND is_active = 1",
                strtolower($dayOfWeek)
            ),
            ARRAY_A
        );

        return array_map(fn($row) => (int) $row['professional_id'], $results);
    }

    public function findAvailableSlots(
        int $professionalId,
        \DateTimeImmutable $date,
        int $durationMinutes
    ): array {
        $dayOfWeek = strtolower($date->format('l'));

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE professional_id = %d AND day_of_week = %s AND is_active = 1",
                $professionalId,
                $dayOfWeek
            ),
            ARRAY_A
        );

        $slots = [];
        foreach ($rows as $row) {
            $slots[] = TimeSlot::fromStrings($row['start_time'], $row['end_time']);
        }

        return $slots;
    }

    public function isAvailableAt(int $professionalId, \DateTimeImmutable $timestamp): bool
    {
        $dayOfWeek = strtolower($timestamp->format('l'));
        $time = $timestamp->format('H:i:s');

        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                WHERE professional_id = %d
                AND day_of_week = %s
                AND start_time <= %s
                AND end_time >= %s
                AND is_active = 1",
                $professionalId,
                $dayOfWeek,
                $time,
                $time
            )
        );

        return (int) $count > 0;
    }

    public function delete(int $professionalId): void
    {
        $this->wpdb->delete(
            $this->table,
            ['professional_id' => $professionalId],
            ['%d']
        );
    }
}
