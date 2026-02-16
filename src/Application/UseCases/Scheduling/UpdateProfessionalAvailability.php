<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Scheduling;

use LimpVix\Domain\Scheduling\Professional;
use LimpVix\Domain\Scheduling\ValueObjects\WeeklyAvailability;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceRegion;
use LimpVix\Domain\Scheduling\Policies\AvailabilityPolicy;
use LimpVix\Domain\Scheduling\Repositories\ProfessionalRepositoryInterface;

/**
 * Use Case: UpdateProfessionalAvailability
 *
 * Atualiza disponibilidade semanal de um profissional.
 * Valida disponibilidade antes de salvar.
 */
final class UpdateProfessionalAvailability
{
    private ProfessionalRepositoryInterface $professionalRepository;

    public function __construct(ProfessionalRepositoryInterface $professionalRepository)
    {
        $this->professionalRepository = $professionalRepository;
    }

    /**
     * Executa atualização de disponibilidade
     *
     * @param int $professionalId
     * @param WeeklyAvailability $availability
     * @param ServiceRegion|null $serviceRegion
     * @param int|null $maxDailyHours
     * @return array{success: bool, errors: array}
     */
    public function execute(
        int $professionalId,
        WeeklyAvailability $availability,
        ?ServiceRegion $serviceRegion = null,
        ?int $maxDailyHours = null
    ): array {
        // 1. Buscar Professional
        $professional = $this->professionalRepository->findByStaffId($professionalId);

        if ($professional === null) {
            return [
                'success' => false,
                'errors' => ['Professional not found'],
            ];
        }

        // 2. Validar disponibilidade usando AvailabilityPolicy
        $validation = AvailabilityPolicy::validateWeeklyAvailability($availability);

        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }

        // 3. Atualizar Professional
        $professional->updateAvailability($availability);

        if ($serviceRegion !== null) {
            $professional->updateServiceRegion($serviceRegion);
        }

        // 4. Salvar Professional
        $this->professionalRepository->save($professional);

        // 5. Retornar sucesso
        return [
            'success' => true,
            'professional_id' => $professionalId,
            'total_weekly_hours' => $availability->getTotalHoursPerWeek(),
            'available_days' => $availability->getAvailableDays(),
            'errors' => [],
        ];
    }
}
