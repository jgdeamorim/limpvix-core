<?php
/**
 * ListOffers - Use Case for listing professional offers
 *
 * @package LimpVix\Application\UseCases\Professional
 * @since 0.10.0
 */

namespace LimpVix\Application\UseCases\Professional;

use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;

defined('ABSPATH') || exit;

final class ListOffers
{
    public function __construct(
        private WpMarketplaceProfessionalRepository $repository
    ) {}

    /**
     * Execute Use Case
     *
     * @param int $professionalId Professional ID
     * @param int $limit Maximum number of offers to return
     * @return array Array of offers
     * @throws \RuntimeException If professional not found
     */
    public function execute(int $professionalId, int $limit = 50): array
    {
        global $wpdb;

        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            throw new \RuntimeException('Profissional não encontrado');
        }

        $table = $wpdb->prefix . 'limpvix_contract_offers';

        $offers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE professional_id = %d ORDER BY offered_at DESC LIMIT %d",
            $professionalId,
            $limit
        ), ARRAY_A);

        return $offers ?: [];
    }
}
