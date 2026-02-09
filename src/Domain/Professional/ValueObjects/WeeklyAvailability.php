<?php
/**
 * WeeklyAvailability Value Object
 *
 * Disponibilidade semanal do profissional (horários por dia da semana)
 * Imutável, valida overlaps e conflitos
 *
 * @package LimpVix\Domain\Professional\ValueObjects
 */

namespace LimpVix\Domain\Professional\ValueObjects;

defined('ABSPATH') || exit;

final class WeeklyAvailability
{
    private array $schedule; // ['monday' => [['start' => '08:00', 'end' => '12:00'], ...], ...]

    private const VALID_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @param array $schedule Horários por dia: ['monday' => [['start' => '08:00', 'end' => '12:00']], ...]
     * @throws \InvalidArgumentException
     */
    public function __construct(array $schedule)
    {
        $this->validateSchedule($schedule);
        $this->schedule = $schedule;
    }

    /**
     * Cria a partir de JSON string
     *
     * @param string $json
     * @return self
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON inválido para WeeklyAvailability');
        }

        return new self($data);
    }

    /**
     * Cria disponibilidade padrão (seg-sex 08:00-18:00)
     *
     * @return self
     */
    public static function defaultSchedule(): self
    {
        return new self([
            'monday' => [['start' => '08:00', 'end' => '18:00']],
            'tuesday' => [['start' => '08:00', 'end' => '18:00']],
            'wednesday' => [['start' => '08:00', 'end' => '18:00']],
            'thursday' => [['start' => '08:00', 'end' => '18:00']],
            'friday' => [['start' => '08:00', 'end' => '18:00']],
            'saturday' => [],
            'sunday' => [],
        ]);
    }

    /**
     * Verifica se está disponível em um dia/horário específico
     *
     * @param string $dayOfWeek 'monday', 'tuesday', etc
     * @param string $time Horário '08:00', '14:30', etc
     * @return bool
     */
    public function isAvailableAt(string $dayOfWeek, string $time): bool
    {
        $dayOfWeek = strtolower($dayOfWeek);

        if (!isset($this->schedule[$dayOfWeek])) {
            return false;
        }

        $slots = $this->schedule[$dayOfWeek];
        if (empty($slots)) {
            return false;
        }

        foreach ($slots as $slot) {
            if ($this->timeIsBetween($time, $slot['start'], $slot['end'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se está disponível em uma data/hora específica
     *
     * @param \DateTimeImmutable $dateTime
     * @return bool
     */
    public function isAvailableAtDateTime(\DateTimeImmutable $dateTime): bool
    {
        $dayOfWeek = strtolower($dateTime->format('l')); // 'Monday' -> 'monday'
        $time = $dateTime->format('H:i');

        return $this->isAvailableAt($dayOfWeek, $time);
    }

    /**
     * Retorna total de horas disponíveis por semana
     *
     * @return float
     */
    public function getTotalWeeklyHours(): float
    {
        $totalMinutes = 0;

        foreach ($this->schedule as $slots) {
            foreach ($slots as $slot) {
                $start = \DateTime::createFromFormat('H:i', $slot['start']);
                $end = \DateTime::createFromFormat('H:i', $slot['end']);
                $diff = $end->diff($start);
                $totalMinutes += ($diff->h * 60) + $diff->i;
            }
        }

        return round($totalMinutes / 60, 2);
    }

    /**
     * Retorna dias disponíveis (com pelo menos 1 slot)
     *
     * @return array
     */
    public function getAvailableDays(): array
    {
        $days = [];
        foreach ($this->schedule as $day => $slots) {
            if (!empty($slots)) {
                $days[] = $day;
            }
        }
        return $days;
    }

    /**
     * Retorna slots de um dia específico
     *
     * @param string $dayOfWeek
     * @return array
     */
    public function getSlotsForDay(string $dayOfWeek): array
    {
        $dayOfWeek = strtolower($dayOfWeek);
        return $this->schedule[$dayOfWeek] ?? [];
    }

    // Getters

    public function getSchedule(): array
    {
        return $this->schedule;
    }

    public function toArray(): array
    {
        return $this->schedule;
    }

    public function toJson(): string
    {
        return json_encode($this->schedule);
    }

    /**
     * Igualdade de Value Objects
     *
     * @param self $other
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->toJson() === $other->toJson();
    }

    // Validações e helpers privados

    private function validateSchedule(array $schedule): void
    {
        foreach ($schedule as $day => $slots) {
            // Validar nome do dia
            if (!in_array($day, self::VALID_DAYS, true)) {
                throw new \InvalidArgumentException("Dia da semana inválido: $day");
            }

            // Slots devem ser array
            if (!is_array($slots)) {
                throw new \InvalidArgumentException("Slots do dia '$day' devem ser um array");
            }

            // Validar cada slot
            foreach ($slots as $slot) {
                if (!isset($slot['start'], $slot['end'])) {
                    throw new \InvalidArgumentException("Slot inválido no dia '$day': deve ter 'start' e 'end'");
                }

                $this->validateTimeFormat($slot['start']);
                $this->validateTimeFormat($slot['end']);

                if ($slot['start'] >= $slot['end']) {
                    throw new \InvalidArgumentException("Horário inválido no dia '$day': start deve ser < end");
                }
            }

            // Verificar overlaps no mesmo dia
            $this->checkOverlaps($day, $slots);
        }
    }

    private function validateTimeFormat(string $time): void
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            throw new \InvalidArgumentException("Formato de horário inválido: '$time'. Use HH:MM (ex: 08:00, 14:30)");
        }
    }

    private function checkOverlaps(string $day, array $slots): void
    {
        $count = count($slots);
        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->slotsOverlap($slots[$i], $slots[$j])) {
                    throw new \InvalidArgumentException("Slots sobrepostos no dia '$day': {$slots[$i]['start']}-{$slots[$i]['end']} e {$slots[$j]['start']}-{$slots[$j]['end']}");
                }
            }
        }
    }

    private function slotsOverlap(array $slot1, array $slot2): bool
    {
        return $slot1['start'] < $slot2['end'] && $slot2['start'] < $slot1['end'];
    }

    private function timeIsBetween(string $time, string $start, string $end): bool
    {
        return $time >= $start && $time <= $end;
    }

    public function __toString(): string
    {
        $totalHours = $this->getTotalWeeklyHours();
        $daysCount = count($this->getAvailableDays());
        return "WeeklyAvailability($daysCount dias, {$totalHours}h/semana)";
    }
}
