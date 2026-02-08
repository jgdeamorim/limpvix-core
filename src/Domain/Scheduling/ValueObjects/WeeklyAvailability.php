<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: WeeklyAvailability
 *
 * Representa disponibilidade semanal de um profissional.
 * Mapa de dia da semana → slots de horário.
 *
 * Ex: Monday: [08:00-12:00, 14:00-18:00], Tuesday: [09:00-17:00]
 *
 * IMUTÁVEL.
 */
final class WeeklyAvailability
{
    private const VALID_DAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    /** @var array<string, TimeSlot[]> */
    private array $schedule;

    /**
     * @param array<string, TimeSlot[]> $schedule
     */
    private function __construct(array $schedule)
    {
        foreach ($schedule as $day => $slots) {
            $this->validateDay($day);
            $this->validateSlots($slots);
        }

        $this->schedule = $schedule;
    }

    /**
     * Factory: Criar disponibilidade a partir de mapa
     *
     * @param array<string, TimeSlot[]> $schedule
     */
    public static function create(array $schedule): self
    {
        return new self($schedule);
    }

    /**
     * Factory: Disponibilidade padrão (segunda a sexta, 08:00-18:00)
     */
    public static function standardWeekdays(): self
    {
        $slot = TimeSlot::fromStrings('08:00', '18:00');

        return new self([
            'monday' => [$slot],
            'tuesday' => [$slot],
            'wednesday' => [$slot],
            'thursday' => [$slot],
            'friday' => [$slot],
        ]);
    }

    /**
     * Factory: A partir de array (hidratação)
     */
    public static function fromArray(array $data): self
    {
        $schedule = [];

        foreach ($data as $day => $slots) {
            $schedule[$day] = array_map(function ($slotData) {
                return TimeSlot::fromStrings($slotData['start'], $slotData['end']);
            }, $slots);
        }

        return new self($schedule);
    }

    /**
     * Verifica se está disponível em um dia específico
     */
    public function isAvailableOn(string $dayOfWeek): bool
    {
        $day = strtolower($dayOfWeek);
        return isset($this->schedule[$day]) && !empty($this->schedule[$day]);
    }

    /**
     * Retorna slots de um dia específico
     *
     * @return TimeSlot[]
     */
    public function getSlotsFor(string $dayOfWeek): array
    {
        $day = strtolower($dayOfWeek);
        return $this->schedule[$day] ?? [];
    }

    /**
     * Verifica se um timestamp específico está em algum slot disponível
     */
    public function isAvailableAt(\DateTimeImmutable $timestamp): bool
    {
        $dayOfWeek = strtolower($timestamp->format('l')); // 'Monday' → 'monday'

        if (!$this->isAvailableOn($dayOfWeek)) {
            return false;
        }

        $slots = $this->getSlotsFor($dayOfWeek);

        foreach ($slots as $slot) {
            // Criar timestamp no mesmo dia para comparação apenas de horário
            $slotStart = $timestamp->setTime(
                (int) $slot->getStart()->format('H'),
                (int) $slot->getStart()->format('i')
            );
            $slotEnd = $timestamp->setTime(
                (int) $slot->getEnd()->format('H'),
                (int) $slot->getEnd()->format('i')
            );

            $slotForDay = TimeSlot::create($slotStart, $slotEnd);

            if ($slotForDay->contains($timestamp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcula total de horas disponíveis por semana
     */
    public function getTotalHoursPerWeek(): int
    {
        $totalMinutes = 0;

        foreach ($this->schedule as $slots) {
            foreach ($slots as $slot) {
                $totalMinutes += $slot->getDurationInMinutes();
            }
        }

        return (int) round($totalMinutes / 60);
    }

    /**
     * Retorna dias com disponibilidade
     *
     * @return string[]
     */
    public function getAvailableDays(): array
    {
        return array_keys($this->schedule);
    }

    public function toArray(): array
    {
        $data = [];

        foreach ($this->schedule as $day => $slots) {
            $data[$day] = array_map(fn(TimeSlot $slot) => $slot->toArray(), $slots);
        }

        return $data;
    }

    private function validateDay(string $day): void
    {
        $normalizedDay = strtolower($day);

        if (!in_array($normalizedDay, self::VALID_DAYS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid day of week: %s', $day)
            );
        }
    }

    /**
     * @param TimeSlot[] $slots
     */
    private function validateSlots(array $slots): void
    {
        if (empty($slots)) {
            throw new \InvalidArgumentException('Day must have at least one time slot');
        }

        foreach ($slots as $slot) {
            if (!($slot instanceof TimeSlot)) {
                throw new \InvalidArgumentException('All slots must be TimeSlot instances');
            }
        }

        // Validar que não há sobreposição de slots
        for ($i = 0; $i < count($slots) - 1; $i++) {
            for ($j = $i + 1; $j < count($slots); $j++) {
                if ($slots[$i]->overlaps($slots[$j])) {
                    throw new \InvalidArgumentException('Time slots cannot overlap');
                }
            }
        }
    }
}
