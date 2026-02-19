<?php

declare(strict_types=1);

namespace LimpVix\Application\Services\Scheduling;

use LimpVix\Domain\Scheduling\Professional;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceComplexity;
use LimpVix\Domain\Scheduling\ValueObjects\TimeWindow;
use LimpVix\Domain\Scheduling\Policies\AllocationPolicy;
use LimpVix\Domain\Scheduling\Repositories\ProfessionalRepositoryInterface;
use LimpVix\Domain\Scheduling\Repositories\ScheduleRepositoryInterface;
use LimpVix\Application\Services\Matching\ProfessionalMatcher;

/**
 * Application Service: AllocationEngine
 *
 * Motor de alocação inteligente de profissionais.
 * Delegates scoring to unified ProfessionalMatcher (G-MATCHING-DUAL).
 *
 * Score baseado em:
 * - Proximidade: 40%
 * - Disponibilidade: 30%
 * - Rating: 20%
 * - Carga: 10%
 *
 * CRÍTICO: Este é o coração do sistema de agendamento.
 */
final class AllocationEngine
{
    private ProfessionalRepositoryInterface $professionalRepository;
    private ScheduleRepositoryInterface $scheduleRepository;
    private ProximityScorer $proximityScorer;
    private AvailabilityCalculator $availabilityCalculator;
    private ProfessionalMatcher $matcher;

    public function __construct(
        ProfessionalRepositoryInterface $professionalRepository,
        ScheduleRepositoryInterface $scheduleRepository,
        ProximityScorer $proximityScorer,
        AvailabilityCalculator $availabilityCalculator,
        ?ProfessionalMatcher $matcher = null
    ) {
        $this->professionalRepository = $professionalRepository;
        $this->scheduleRepository = $scheduleRepository;
        $this->proximityScorer = $proximityScorer;
        $this->availabilityCalculator = $availabilityCalculator;
        $this->matcher = $matcher ?? ProfessionalMatcher::fromConfig();
    }

    /**
     * Encontra os N melhores profissionais para um agendamento
     *
     * @param ServiceLocation $location
     * @param ServiceComplexity $complexity
     * @param TimeWindow $window
     * @param int $requiredCount Quantidade de profissionais necessários
     * @return array Array de ['professional' => Professional, 'score' => float]
     */
    public function findBestProfessionals(
        ServiceLocation $location,
        ServiceComplexity $complexity,
        TimeWindow $window,
        int $requiredCount
    ): array {
        // 1. Buscar profissionais elegíveis
        $eligibleProfessionals = $this->professionalRepository->findAvailableFor(
            $window->getRequestedTime(),
            $window,
            $location,
            $complexity
        );

        if (empty($eligibleProfessionals)) {
            return [];
        }

        // 2. Calcular score para cada profissional
        $scoredProfessionals = [];

        foreach ($eligibleProfessionals as $professional) {
            // Calcular carga diária atual
            $currentDailyLoad = $this->professionalRepository->getDailyLoad(
                $professional->getStaffId(),
                $window->getRequestedTime()
            );

            // Calcular score total
            $score = $this->calculateScore(
                $professional,
                $location,
                $window,
                $currentDailyLoad
            );

            $scoredProfessionals[] = [
                'professional' => $professional,
                'score' => $score,
                'daily_load_minutes' => $currentDailyLoad,
            ];
        }

        // 3. Ordenar por score (maior para menor)
        usort($scoredProfessionals, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // 4. Selecionar top N profissionais
        return array_slice($scoredProfessionals, 0, $requiredCount);
    }

    /**
     * Calcula score total de alocação via ProfessionalMatcher unificado (G-MATCHING-DUAL)
     *
     * @param Professional $professional
     * @param ServiceLocation $location
     * @param TimeWindow $window
     * @param int $currentDailyLoad Carga atual em minutos
     * @return float Score de 0-100
     */
    public function calculateScore(
        Professional $professional,
        ServiceLocation $location,
        TimeWindow $window,
        int $currentDailyLoad
    ): float {
        // Calculate distance via ProximityScorer (keeps existing dependency)
        $proximityRawScore = $this->proximityScorer->calculateScore(
            $professional,
            $location
        );
        // Convert ProximityScorer's 0-40 score back to distance for unified matcher
        // ProximityScorer returns 40 at 0km and 0 at 20km+ (linear)
        $distanceKm = max(0, (40.0 - $proximityRawScore) / 2.0);

        $matchScore = $this->matcher->calculateScore(
            distanceKm: $distanceKm,
            professionalScore: (float) $professional->getAverageRating() * 20, // 0-5 → 0-100
            acceptanceRate: 1.0, // AllocationEngine pre-filters eligible professionals
            averageRating: $professional->getAverageRating(),
            completedServices: $professional->getCompletedServices(),
            currentDailyLoadMinutes: $currentDailyLoad,
            maxDailyMinutes: $professional->getMaxDailyHours() * 60
        );

        return $matchScore->total;
    }

    /**
     * Valida se múltiplos profissionais têm janela coincidente
     *
     * @param Professional[] $professionals
     * @param TimeWindow $window
     * @param int $durationMinutes
     * @return bool
     */
    public function validateCoincidentWindow(
        array $professionals,
        TimeWindow $window,
        int $durationMinutes
    ): bool {
        if (count($professionals) < 2) {
            return true; // 1 profissional sempre tem janela
        }

        // Todos devem estar disponíveis no mesmo horário
        foreach ($professionals as $professional) {
            if (!$professional->isAvailableOn($window->getRequestedTime())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Encontra o melhor slot comum para múltiplos profissionais
     *
     * @param Professional[] $professionals
     * @param \DateTimeImmutable $date
     * @param int $durationMinutes
     * @return \DateTimeImmutable|null
     */
    public function findCommonSlot(
        array $professionals,
        \DateTimeImmutable $date,
        int $durationMinutes
    ): ?\DateTimeImmutable {
        if (empty($professionals)) {
            return null;
        }

        // Single professional: just check availability
        if (count($professionals) === 1) {
            $prof = $professionals[0];
            if ($prof->isAvailableOn($date)) {
                return $date;
            }
            return null;
        }

        // Multiple professionals: calculate real slot intersection
        $dayOfWeek = strtolower($date->format('l'));

        // Collect all available time ranges per professional for this day
        $allSlotRanges = [];
        foreach ($professionals as $professional) {
            if (!$professional->isAvailableOn($date)) {
                return null; // If any professional is unavailable on this day, no common slot
            }

            $availability = $professional->getWeeklyAvailability();
            if (!$availability) {
                return null;
            }

            $slots = $availability->getSlotsFor($dayOfWeek);
            if (empty($slots)) {
                return null;
            }

            // Convert slots to [start_minutes, end_minutes] ranges from midnight
            $ranges = [];
            foreach ($slots as $slot) {
                $startMinutes = (int) $slot->getStart()->format('H') * 60 + (int) $slot->getStart()->format('i');
                $endMinutes = (int) $slot->getEnd()->format('H') * 60 + (int) $slot->getEnd()->format('i');
                $ranges[] = [$startMinutes, $endMinutes];
            }
            $allSlotRanges[] = $ranges;
        }

        // Calculate intersection of all slot ranges
        $intersections = $allSlotRanges[0];
        for ($i = 1; $i < count($allSlotRanges); $i++) {
            $intersections = $this->intersectRanges($intersections, $allSlotRanges[$i]);
            if (empty($intersections)) {
                return null;
            }
        }

        // Find first intersection that fits the required duration
        foreach ($intersections as [$start, $end]) {
            if (($end - $start) >= $durationMinutes) {
                $hours = intdiv($start, 60);
                $minutes = $start % 60;
                return $date->setTime($hours, $minutes);
            }
        }

        return null;
    }

    /**
     * Calculate intersection of two sets of time ranges
     *
     * @param array $rangesA [[start, end], ...]
     * @param array $rangesB [[start, end], ...]
     * @return array Intersected ranges
     */
    private function intersectRanges(array $rangesA, array $rangesB): array
    {
        $result = [];
        foreach ($rangesA as [$aStart, $aEnd]) {
            foreach ($rangesB as [$bStart, $bEnd]) {
                $overlapStart = max($aStart, $bStart);
                $overlapEnd = min($aEnd, $bEnd);
                if ($overlapStart < $overlapEnd) {
                    $result[] = [$overlapStart, $overlapEnd];
                }
            }
        }
        return $result;
    }
}
