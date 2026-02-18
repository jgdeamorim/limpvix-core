<?php
/**
 * UpdateAvailability - Use Case for updating professional availability
 *
 * @package LimpVix\Application\UseCases\Professional
 * @since 0.10.0
 */

namespace LimpVix\Application\UseCases\Professional;

use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;
use LimpVix\Domain\Professional\ValueObjects\WeeklyAvailability;

defined('ABSPATH') || exit;

final class UpdateAvailability
{
    public function __construct(
        private WpMarketplaceProfessionalRepository $repository
    ) {}

    /**
     * Execute Use Case
     *
     * @param int $professionalId Professional ID
     * @param array $availabilityData Availability data
     * @return array Updated professional data
     * @throws \RuntimeException If professional not found
     */
    public function execute(int $professionalId, array $availabilityData): array
    {
        $professional = $this->repository->findById($professionalId);

        if (!$professional) {
            throw new \RuntimeException('Profissional não encontrado');
        }

        // Create WeeklyAvailability Value Object
        $availability = WeeklyAvailability::fromJson(json_encode($availabilityData));

        // Update professional
        $professional->updateAvailability($availability);

        // Save
        $this->repository->save($professional);

        return [
            'professional_id' => $professionalId,
            'availability' => $availability->toArray()
        ];
    }
}
