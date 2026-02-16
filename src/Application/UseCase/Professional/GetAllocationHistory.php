<?php
/**
 * GetAllocationHistory - Use Case for getting professional allocation history
 *
 * @package LimpVix\Application\UseCase\Professional
 * @since 0.10.0
 */

namespace LimpVix\Application\UseCase\Professional;

use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;

defined('ABSPATH') || exit;

final class GetAllocationHistory
{
    public function __construct(
        private WpMarketplaceProfessionalRepository $repository
    ) {}

    /**
     * Execute Use Case
     *
     * @param int $professionalId Professional ID
     * @return array Allocation history
     * @throws \RuntimeException If professional not found
     */
    public function execute(int $professionalId): array
    {
        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            throw new \RuntimeException('Profissional não encontrado');
        }

        return $this->repository->getAllocationHistory($professionalId);
    }
}
