<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Scheduling\Professional;
use LimpVix\Domain\Scheduling\ValueObjects\WeeklyAvailability;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceRegion;
use LimpVix\Domain\Scheduling\ValueObjects\ProfessionalSkills;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceComplexity;
use LimpVix\Domain\Scheduling\ValueObjects\TimeWindow;
use LimpVix\Domain\Scheduling\Repositories\ProfessionalRepositoryInterface;
use LimpVix\Domain\Scheduling\Policies\AllocationPolicy;

/**
 * Repository: WpProfessionalRepository
 *
 * Implementação WordPress do ProfessionalRepository.
 * Lê de wp_bkntc_staff + wp_limpvix_professional_availability
 */
final class WpProfessionalRepository implements ProfessionalRepositoryInterface
{
    private \wpdb $wpdb;
    private string $staffTable;
    private string $availabilityTable;
    private string $allocationsTable;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->staffTable = $wpdb->prefix . 'bkntc_staff';
        $this->availabilityTable = $wpdb->prefix . 'limpvix_professional_availability';
        $this->allocationsTable = $wpdb->prefix . 'limpvix_professional_allocations';
    }

    public function save(Professional $professional): void
    {
        // Professional é read-only do Booknetic
        // Apenas salvamos availability se mudou
        $this->saveAvailability($professional);
    }

    public function findByStaffId(int $staffId): ?Professional
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->staffTable} WHERE id = %d",
                $staffId
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByUserId(int $userId): ?Professional
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->staffTable} WHERE wp_user_id = %d",
                $userId
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findAllActive(): array
    {
        $results = $this->wpdb->get_results(
            "SELECT * FROM {$this->staffTable} WHERE is_active = 1 ORDER BY name ASC",
            ARRAY_A
        );

        return array_map(fn($row) => $this->hydrate($row), $results);
    }

    public function findAvailableFor(
        \DateTimeImmutable $date,
        TimeWindow $window,
        ServiceLocation $location,
        ServiceComplexity $complexity
    ): array {
        $allActive = $this->findAllActive();
        $available = [];

        foreach ($allActive as $professional) {
            // Verificar elegibilidade usando AllocationPolicy
            $schedule = $this->createMockSchedule($window, $location);

            if (AllocationPolicy::isEligible($professional, $schedule, $complexity)) {
                $available[] = $professional;
            }
        }

        return $available;
    }

    public function findByRegion(ServiceLocation $location): array
    {
        $allActive = $this->findAllActive();
        $inRegion = [];

        foreach ($allActive as $professional) {
            if ($professional->canServeLocation($location)) {
                $inRegion[] = $professional;
            }
        }

        return $inRegion;
    }

    public function findBySkill(string $skill): array
    {
        // Query simples - filtrar via JSON skills
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT s.* FROM {$this->staffTable} s
                INNER JOIN {$this->availabilityTable} a ON s.id = a.professional_id
                WHERE JSON_CONTAINS(a.skills, %s)
                AND s.is_active = 1
                GROUP BY s.id",
                json_encode([$skill])
            ),
            ARRAY_A
        );

        return array_map(fn($row) => $this->hydrate($row), $results);
    }

    public function getDailyLoad(int $professionalId, \DateTimeImmutable $date): int
    {
        $dateStart = $date->format('Y-m-d 00:00:00');
        $dateEnd = $date->format('Y-m-d 23:59:59');

        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT SUM(TIMESTAMPDIFF(MINUTE, allocated_start, allocated_end))
                FROM {$this->allocationsTable}
                WHERE professional_id = %d
                AND allocated_start BETWEEN %s AND %s
                AND status = 'allocated'",
                $professionalId,
                $dateStart,
                $dateEnd
            )
        );

        return (int) ($result ?? 0);
    }

    public function countActive(): int
    {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->staffTable} WHERE is_active = 1"
        );
    }

    private function hydrate(array $row): Professional
    {
        // Carregar availability
        $availability = $this->loadAvailability((int) $row['id']);

        // Criar Professional via Reflection
        $reflection = new \ReflectionClass(Professional::class);
        $professional = $reflection->newInstanceWithoutConstructor();

        $this->setProperty($professional, 'staffId', (int) $row['id']);
        $this->setProperty($professional, 'userId', (int) $row['wp_user_id']);
        $this->setProperty($professional, 'name', $row['name']);
        $this->setProperty($professional, 'availability', $availability['availability']);
        $this->setProperty($professional, 'maxDailyHours', $availability['max_daily_hours']);
        $this->setProperty($professional, 'serviceRegion', $availability['service_region']);
        $this->setProperty($professional, 'skills', $availability['skills']);
        $this->setProperty($professional, 'physicalLimitations', $availability['limitations']);
        $this->setProperty($professional, 'allocationScore', 0.0);
        $this->setProperty($professional, 'completedServices', 0);
        $this->setProperty($professional, 'averageRating', 0.0);
        $this->setProperty($professional, 'isActive', (bool) $row['is_active']);

        return $professional;
    }

    private function loadAvailability(int $professionalId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->availabilityTable} WHERE professional_id = %d AND is_active = 1",
                $professionalId
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            // Default: Monday-Friday, 08:00-18:00
            return [
                'availability' => WeeklyAvailability::standardWeekdays(),
                'max_daily_hours' => 8,
                'service_region' => ServiceRegion::create(
                    \LimpVix\Domain\Scheduling\ValueObjects\GeoCoordinates::fromLatLong(-20.315, -40.298),
                    15
                ),
                'skills' => ProfessionalSkills::basicOnly(),
                'limitations' => null,
            ];
        }

        // Construir WeeklyAvailability
        $schedule = [];
        $firstRow = $rows[0];

        foreach ($rows as $row) {
            $day = strtolower($row['day_of_week']);
            $slot = \LimpVix\Domain\Scheduling\ValueObjects\TimeSlot::fromStrings(
                $row['start_time'],
                $row['end_time']
            );

            if (!isset($schedule[$day])) {
                $schedule[$day] = [];
            }
            $schedule[$day][] = $slot;
        }

        $availability = WeeklyAvailability::create($schedule);

        // Service Region e Skills do primeiro row
        $regionData = json_decode($firstRow['service_region'], true);
        $skillsData = json_decode($firstRow['skills'], true);

        return [
            'availability' => $availability,
            'max_daily_hours' => (int) $firstRow['max_daily_hours'],
            'service_region' => ServiceRegion::fromArray($regionData),
            'skills' => ProfessionalSkills::fromArray($skillsData),
            'limitations' => $firstRow['limitations'] ? json_decode($firstRow['limitations'], true) : null,
        ];
    }

    private function saveAvailability(Professional $professional): void
    {
        // Deletar existente
        $this->wpdb->delete(
            $this->availabilityTable,
            ['professional_id' => $professional->getStaffId()],
            ['%d']
        );

        // Inserir nova availability
        $availability = $professional->getAvailability();
        foreach ($availability->getAvailableDays() as $day) {
            $slots = $availability->getSlotsFor($day);
            foreach ($slots as $slot) {
                $this->wpdb->insert(
                    $this->availabilityTable,
                    [
                        'professional_id' => $professional->getStaffId(),
                        'day_of_week' => $day,
                        'start_time' => $slot->getStart()->format('H:i:s'),
                        'end_time' => $slot->getEnd()->format('H:i:s'),
                        'max_daily_hours' => $professional->getMaxDailyHours(),
                        'service_region' => json_encode($professional->getServiceRegion()->toArray()),
                        'skills' => json_encode($professional->getSkills()->toArray()),
                        'limitations' => $professional->getPhysicalLimitations() ? json_encode($professional->getPhysicalLimitations()) : null,
                        'is_active' => true,
                    ],
                    ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d']
                );
            }
        }
    }

    private function createMockSchedule(TimeWindow $window, ServiceLocation $location): object
    {
        return new class($window, $location) {
            private $window;
            private $location;

            public function __construct($window, $location)
            {
                $this->window = $window;
                $this->location = $location;
            }

            public function getValidWindow()
            {
                return $this->window;
            }

            public function getLocation()
            {
                return $this->location;
            }

            public function getRequestedTime()
            {
                return $this->window->getRequestedTime();
            }
        };
    }

    private function setProperty(object $object, string $property, $value): void
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}
