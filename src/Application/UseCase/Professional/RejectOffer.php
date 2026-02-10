<?php
/**
 * RejectOffer - Use Case for rejecting contract offers
 *
 * RESPONSABILIDADE:
 * - Validar oferta (existe, pending)
 * - Rejeitar oferta com motivo
 * - Despachar evento OfferRejected
 * - Atualizar last_activity do professional
 *
 * @package LimpVix\Application\UseCase\Professional
 * @since 0.10.0
 */

namespace LimpVix\Application\UseCase\Professional;

use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;

defined('ABSPATH') || exit;

final class RejectOffer
{
    public function __construct(
        private WpMarketplaceProfessionalRepository $professionalRepo
    ) {}

    /**
     * Execute Use Case
     *
     * @param int $professionalId Professional ID
     * @param int $offerId Offer ID
     * @param string $reason Rejection reason
     * @param string|null $notes Optional notes
     * @return void
     * @throws \RuntimeException If offer not found or cannot be rejected
     */
    public function execute(int $professionalId, int $offerId, string $reason, ?string $notes = null): void
    {
        global $wpdb;

        // Validar profissional existe
        $professional = $this->professionalRepo->findById($professionalId);
        if (!$professional) {
            throw new \RuntimeException('Profissional não encontrado');
        }

        $table = $wpdb->prefix . 'limpvix_contract_offers';

        // Buscar oferta
        $offer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND professional_id = %d",
            $offerId,
            $professionalId
        ), ARRAY_A);

        if (!$offer) {
            throw new \RuntimeException('Oferta não encontrada');
        }

        // Validar status
        if ($offer['status'] !== 'pending') {
            throw new \RuntimeException("Oferta não pode ser rejeitada (status: {$offer['status']})");
        }

        // Atualizar oferta para 'rejected'
        $wpdb->update(
            $table,
            [
                'status' => 'rejected',
                'responded_at' => current_time('mysql'),
                'rejection_reason' => $reason,
                'rejection_notes' => $notes,
            ],
            ['id' => $offerId],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        // Despachar evento
        do_action('limpvix_offer_rejected', [
            'offer_id' => $offerId,
            'professional_id' => $professionalId,
            'contract_id' => $offer['contract_id'],
            'reason' => $reason,
        ]);

        // Atualizar last_activity
        $this->professionalRepo->updateLastActivity($professionalId);
    }
}
