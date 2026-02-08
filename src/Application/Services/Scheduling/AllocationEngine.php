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

/**
 * Application Service: AllocationEngine
 *
 * Motor de alocação inteligente de profissionais.
 * Implementa algoritmo de score-based allocation.
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

    public function __construct(
        ProfessionalRepositoryInterface $professionalRepository,
        ScheduleRepositoryInterface $scheduleRepository,
        ProximityScorer $proximityScorer,
        AvailabilityCalculator $availabilityCalculator
    ) {
        $this->professionalRepository = $professionalRepository;
        $this->scheduleRepository = $scheduleRepository;
        $this->proximityScorer = $proximityScorer;
        $this->availabilityCalculator = $availabilityCalculator;
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
     * Calcula score total de alocação (0-100)
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
        // 1. Proximidade (0-40 pontos)
        $proximityScore = $this->proximityScorer->calculateScore(
            $professional,
            $location
        );

        // 2. Disponibilidade (0-30 pontos)
        $availabilityScore = $this->availabilityCalculator->calculateScore(
            $professional,
            $window->getRequestedTime(),
            $currentDailyLoad
        );

        // 3. Rating/Experiência (0-20 pontos)
        $ratingScore = $this->calculateRatingScore($professional);

        // 4. Carga atual (0-10 pontos) - quanto menos carga, maior o score
        $loadScore = $this->calculateLoadScore(
            $currentDailyLoad,
            $professional->getMaxDailyHours()
        );

        return $proximityScore + $availabilityScore + $ratingScore + $loadScore;
    }

    /**
     * Calcula score de rating/experiência (0-20 pontos)
     */
    private function calculateRatingScore(Professional $professional): float
    {
        // Rating médio (0-5) → 0-10 pontos
        $ratingPoints = ($professional->getAverageRating() / 5) * 10;

        // Experiência (0-50+ serviços) → 0-10 pontos
        $experiencePoints = min(10, $professional->getCompletedServices() / 5);

        return $ratingPoints + $experiencePoints;
    }

    /**
     * Calcula score de carga (0-10 pontos)
     * Quanto MENOS carga, maior o score
     */
    private function calculateLoadScore(int $currentDailyLoad, int $maxDailyHours): float
    {
        $maxDailyMinutes = $maxDailyHours * 60;

        if ($currentDailyLoad >= $maxDailyMinutes) {
            return 0; // Totalmente carregado
        }

        // Proporcional à capacidade livre
        $freeCapacity = ($maxDailyMinutes - $currentDailyLoad) / $maxDailyMinutes;

        return $freeCapacity * 10;
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

        // Para simplificar, retornar a data solicitada se todos estão disponíveis
        // Em produção, isso deveria calcular a interseção de todos os slots
        foreach ($professionals as $professional) {
            if (!$professional->isAvailableOn($date)) {
                return null;
            }
        }

        return $date;
    }
}
