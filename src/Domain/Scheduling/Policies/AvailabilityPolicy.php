<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Policies;

use LimpVix\Domain\Scheduling\Professional;
use LimpVix\Domain\Scheduling\ValueObjects\TimeSlot;
use LimpVix\Domain\Scheduling\ValueObjects\WeeklyAvailability;

/**
 * Policy: AvailabilityPolicy
 *
 * Regras de negócio para disponibilidade de profissionais.
 */
final class AvailabilityPolicy
{
    private const MIN_SLOT_DURATION_MINUTES = 60; // Mínimo 1 hora
    private const MAX_DAILY_HOURS = 10; // Máximo 10 horas por dia
    private const MIN_BREAK_BETWEEN_SLOTS_MINUTES = 30; // 30min de intervalo mínimo

    /**
     * Valida se disponibilidade semanal é válida
     *
     * @return array{valid: bool, errors: string[]}
     */
    public static function validateWeeklyAvailability(WeeklyAvailability $availability): array
    {
        $errors = [];

        // Deve ter pelo menos 1 dia disponível
        $availableDays = $availability->getAvailableDays();

        if (count($availableDays) === 0) {
            $errors[] = 'Must have at least one available day';
        }

        // Total de horas por semana não pode ser excessivo (máx 50h)
        $totalHours = $availability->getTotalHoursPerWeek();

        if ($totalHours > 50) {
            $errors[] = sprintf(
                'Total weekly hours (%d) exceeds maximum (50)',
                $totalHours
            );
        }

        if ($totalHours < 20) {
            $errors[] = sprintf(
                'Total weekly hours (%d) below minimum (20)',
                $totalHours
            );
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Verifica se profissional pode aceitar mais um slot no dia
     */
    public static function canAcceptSlot(
        Professional $professional,
        TimeSlot $slot,
        array $existingSlotsInDay = []
    ): bool {
        // 1. Slot deve estar dentro da disponibilidade
        if (!$professional->getAvailability()->isAvailableAt($slot->getStart())) {
            return false;
        }

        // 2. Não pode exceder max diário
        $totalDurationMinutes = $slot->getDurationInMinutes();

        foreach ($existingSlotsInDay as $existingSlot) {
            $totalDurationMinutes += $existingSlot->getDurationInMinutes();
        }

        $maxDailyMinutes = $professional->getMaxDailyHours() * 60;

        if ($totalDurationMinutes > $maxDailyMinutes) {
            return false;
        }

        // 3. Não pode sobrepor slots existentes
        foreach ($existingSlotsInDay as $existingSlot) {
            if ($slot->overlaps($existingSlot)) {
                return false;
            }
        }

        // 4. Deve ter intervalo mínimo entre slots
        if (!self::hasMinimumBreakBetweenSlots($slot, $existingSlotsInDay)) {
            return false;
        }

        return true;
    }

    /**
     * Verifica se há intervalo mínimo entre slots
     */
    private static function hasMinimumBreakBetweenSlots(
        TimeSlot $newSlot,
        array $existingSlots
    ): bool {
        foreach ($existingSlots as $existingSlot) {
            // Se novo slot termina antes do existente começar
            if ($newSlot->getEnd() <= $existingSlot->getStart()) {
                $breakMinutes = ($existingSlot->getStart()->getTimestamp() - $newSlot->getEnd()->getTimestamp()) / 60;

                if ($breakMinutes < self::MIN_BREAK_BETWEEN_SLOTS_MINUTES) {
                    return false;
                }
            }

            // Se novo slot começa depois do existente terminar
            if ($newSlot->getStart() >= $existingSlot->getEnd()) {
                $breakMinutes = ($newSlot->getStart()->getTimestamp() - $existingSlot->getEnd()->getTimestamp()) / 60;

                if ($breakMinutes < self::MIN_BREAK_BETWEEN_SLOTS_MINUTES) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Calcula slots disponíveis para um profissional em uma data
     *
     * @param Professional $professional
     * @param \DateTimeImmutable $date
     * @param int $requiredDurationMinutes
     * @param array $existingSlotsInDay Slots já alocados neste dia
     * @return TimeSlot[] Slots disponíveis
     */
    public static function findAvailableSlots(
        Professional $professional,
        \DateTimeImmutable $date,
        int $requiredDurationMinutes,
        array $existingSlotsInDay = []
    ): array {
        $availability = $professional->getAvailability();
        $dayOfWeek = strtolower($date->format('l')); // 'Monday' → 'monday'

        if (!$availability->isAvailableOn($dayOfWeek)) {
            return [];
        }

        $dailySlots = $availability->getSlotsFor($dayOfWeek);
        $availableSlots = [];

        foreach ($dailySlots as $dailySlot) {
            // Criar slots de 1 hora dentro do slot diário
            $current = $dailySlot->getStart();
            $end = $dailySlot->getEnd();

            while ($current->getTimestamp() + ($requiredDurationMinutes * 60) <= $end->getTimestamp()) {
                $slotEnd = $current->modify("+{$requiredDurationMinutes} minutes");
                $candidateSlot = TimeSlot::create($current, $slotEnd);

                if (self::canAcceptSlot($professional, $candidateSlot, $existingSlotsInDay)) {
                    $availableSlots[] = $candidateSlot;
                }

                // Avançar 30 minutos para próximo slot candidato
                $current = $current->modify('+30 minutes');
            }
        }

        return $availableSlots;
    }

    /**
     * Verifica se múltiplos profissionais têm janela coincidente
     *
     * @param Professional[] $professionals
     * @param \DateTimeImmutable $requestedTime
     * @param int $durationMinutes
     * @return bool
     */
    public static function haveCoincidentWindow(
        array $professionals,
        \DateTimeImmutable $requestedTime,
        int $durationMinutes
    ): bool {
        if (count($professionals) < 2) {
            return true; // 1 profissional sempre tem janela
        }

        // Todos devem estar disponíveis no mesmo horário
        foreach ($professionals as $professional) {
            if (!$professional->isAvailableOn($requestedTime)) {
                return false;
            }

            // Verificar se tem capacidade para a duração
            if (!$professional->canTakeSlot(
                TimeSlot::fromStartAndDuration($requestedTime, $durationMinutes)
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calcula carga diária de um profissional
     *
     * @param TimeSlot[] $slots
     * @return int Minutos totais
     */
    public static function calculateDailyLoad(array $slots): int
    {
        $totalMinutes = 0;

        foreach ($slots as $slot) {
            $totalMinutes += $slot->getDurationInMinutes();
        }

        return $totalMinutes;
    }

    /**
     * Verifica se profissional está próximo da capacidade máxima diária
     */
    public static function isNearCapacity(
        Professional $professional,
        int $currentDailyLoadMinutes,
        float $threshold = 0.8 // 80%
    ): bool {
        $maxDailyMinutes = $professional->getMaxDailyHours() * 60;
        $utilizationPercent = $currentDailyLoadMinutes / $maxDailyMinutes;

        return $utilizationPercent >= $threshold;
    }
}
