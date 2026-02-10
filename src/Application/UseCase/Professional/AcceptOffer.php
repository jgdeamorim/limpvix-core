<?php
/**
 * AcceptOffer - Use Case for accepting contract offers
 *
 * RESPONSABILIDADE:
 * - Validar oferta (existe, pending, não expirada)
 * - Aceitar oferta (transação: aceitar, expirar outras, alocar contrato)
 * - Despachar evento OfferAccepted
 * - Atualizar last_activity do professional
 *
 * REGRAS DE NEGÓCIO:
 * - First-to-accept: primeira oferta aceita expira as outras
 * - Transação garante consistência (tudo ou nada)
 * - Oferta expirada ou com status != pending não pode ser aceita
 *
 * @package LimpVix\Application\UseCase\Professional
 * @since 0.10.0
 */

namespace LimpVix\Application\UseCase\Professional;

use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;
use LimpVix\Domain\Contract\ContractRepositoryInterface;

defined('ABSPATH') || exit;

final class AcceptOffer
{
    public function __construct(
        private WpMarketplaceProfessionalRepository $professionalRepo,
        private ContractRepositoryInterface $contractRepo
    ) {}

    /**
     * Execute Use Case
     *
     * @param int $professionalId Professional ID
     * @param int $offerId Offer ID
     * @return array Result with offer_id and contract_id
     * @throws \RuntimeException If offer not found, expired, or not available
     */
    public function execute(int $professionalId, int $offerId): array
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
            throw new \RuntimeException("Oferta não está mais disponível (status: {$offer['status']})");
        }

        // Validar expiração
        if (strtotime($offer['expires_at']) < time()) {
            throw new \RuntimeException('Oferta expirada');
        }

        // TRANSAÇÃO: Aceitar oferta + Expirar outras + Alocar contrato
        $wpdb->query('START TRANSACTION');

        try {
            // 1. Atualizar oferta para 'accepted'
            $wpdb->update(
                $table,
                [
                    'status' => 'accepted',
                    'responded_at' => current_time('mysql')
                ],
                ['id' => $offerId],
                ['%s', '%s'],
                ['%d']
            );

            // 2. Expirar outras ofertas pendentes do mesmo contrato
            $wpdb->update(
                $table,
                ['status' => 'expired'],
                [
                    'contract_id' => $offer['contract_id'],
                    'status' => 'pending'
                ],
                ['%s'],
                ['%d', '%s']
            );

            // 3. Alocar profissional ao contrato
            $contractsTable = $wpdb->prefix . 'limpvix_contracts';
            $wpdb->update(
                $contractsTable,
                [
                    'allocated_professional_id' => $professionalId,
                    'allocation_status' => 'allocated',
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $offer['contract_id']],
                ['%d', '%s', '%s'],
                ['%d']
            );

            $wpdb->query('COMMIT');

            // 4. Despachar evento (fora da transação)
            do_action('limpvix_offer_accepted', [
                'offer_id' => $offerId,
                'professional_id' => $professionalId,
                'contract_id' => $offer['contract_id'],
            ]);

            // 5. Atualizar last_activity
            $this->professionalRepo->updateLastActivity($professionalId);

            // Retornar resultado
            return [
                'offer_id' => $offerId,
                'contract_id' => (int) $offer['contract_id'],
            ];

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            throw new \RuntimeException('Erro ao aceitar oferta: ' . $e->getMessage());
        }
    }
}
