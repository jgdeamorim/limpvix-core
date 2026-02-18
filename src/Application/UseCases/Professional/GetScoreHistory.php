<?php
/**
 * GetScoreHistory - Use Case for getting professional score history
 *
 * @package LimpVix\Application\UseCases\Professional
 * @since 0.10.0
 */

namespace LimpVix\Application\UseCases\Professional;

use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;

defined('ABSPATH') || exit;

final class GetScoreHistory
{
    public function __construct(
        private WpMarketplaceProfessionalRepository $repository
    ) {}

    /**
     * Execute Use Case
     *
     * @param int $professionalId Professional ID
     * @param int $limit Maximum number of history entries
     * @return array Score history
     * @throws \RuntimeException If professional not found
     */
    public function execute(int $professionalId, int $limit = 50): array
    {
        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            throw new \RuntimeException('Profissional não encontrado');
        }

        return $this->repository->getScoreHistory($professionalId, $limit);
    }
}
