<?php
/**
 * Offer Notification Listener
 *
 * Listens to offer-related events and sends notifications to professionals
 *
 * EVENTS:
 * - limpvix_send_offer_notification: Individual offer sent
 * - limpvix_offers_sent: Batch of offers sent (admin notification)
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 0.9.0
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Application\Services\ProfessionalNotifier;
use LimpVix\Application\Services\AdminNotificationService;
use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;
use LimpVix\Infrastructure\Persistence\WpContractRepository;

defined('ABSPATH') || exit;

final class OfferNotificationListener
{
    /**
     * Initialize listener
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();

        // Individual offer notification
        add_action('limpvix_send_offer_notification', [$instance, 'handleOfferNotification'], 10, 2);

        // Batch offers sent (admin notification)
        add_action('limpvix_offers_sent', [$instance, 'handleOffersSent'], 10, 3);
    }

    /**
     * Handle individual offer notification
     *
     * @param int $professionalId Professional ID (NOT user_id)
     * @param int $offerId Offer ID
     * @return void
     */
    public function handleOfferNotification(int $professionalId, int $offerId): void
    {
        try {
            // Initialize dependencies
            $professionalRepo = $GLOBALS['limpvix_professional_repository'] 
                ?? new WpMarketplaceProfessionalRepository();
            $contractRepo = $GLOBALS['limpvix_contract_repository'] 
                ?? new WpContractRepository();

            $notifier = new ProfessionalNotifier($professionalRepo, $contractRepo);

            // Send notification
            $sent = $notifier->sendOfferNotification($professionalId, $offerId);

            if ($sent) {
                error_log(sprintf(
                    '[LimpVix] OfferNotificationListener: Notification sent to Professional #%d for Offer #%d',
                    $professionalId,
                    $offerId
                ));
            } else {
                error_log(sprintf(
                    '[LimpVix] OfferNotificationListener: Failed to send notification to Professional #%d for Offer #%d',
                    $professionalId,
                    $offerId
                ));
            }

        } catch (\Exception $e) {
            error_log('[LimpVix] OfferNotificationListener: Error - ' . $e->getMessage());
        }
    }

    /**
     * Handle batch offers sent event (admin notification)
     *
     * @param int $contractId Contract ID
     * @param int $count Number of offers sent
     * @param array $offers Array of offers
     * @return void
     */
    public function handleOffersSent(int $contractId, int $count, array $offers): void
    {
        try {
            // Initialize dependencies
            $professionalRepo = $GLOBALS['limpvix_professional_repository'] 
                ?? new WpMarketplaceProfessionalRepository();
            $contractRepo = $GLOBALS['limpvix_contract_repository'] 
                ?? new WpContractRepository();

            $adminNotificationService = new AdminNotificationService($contractRepo, $professionalRepo);

            // Notify admin about offers sent
            $this->notifyAdminOffersSent($contractId, $count, $offers, $adminNotificationService);

            error_log(sprintf(
                '[LimpVix] OfferNotificationListener: %d offers sent for Contract #%d',
                $count,
                $contractId
            ));

        } catch (\Exception $e) {
            error_log('[LimpVix] OfferNotificationListener: Error in handleOffersSent - ' . $e->getMessage());
        }
    }

    /**
     * Notify admin about offers sent
     *
     * @param int $contractId
     * @param int $count
     * @param array $offers
     * @param AdminNotificationService $service
     * @return void
     */
    private function notifyAdminOffersSent(
        int $contractId,
        int $count,
        array $offers,
        AdminNotificationService $service
    ): void {
        // Build top professionals summary
        $topProfessionals = array_slice($offers, 0, 3);
        $professionalsText = array_map(function($offer) {
            return sprintf(
                '%s (Score: %d/100, Distância: %.1f km)',
                $offer['professional_name'] ?? 'N/A',
                (int) $offer['match_score'],
                $offer['distance_km'] ?? 0
            );
        }, $topProfessionals);

        // Get contract details
        global $wpdb;
        $contract = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}limpvix_contracts WHERE id = %d",
                $contractId
            ),
            ARRAY_A
        );

        if (!$contract) {
            return;
        }

        // Send admin email
        $adminEmail = get_option('admin_email');
        $subject = sprintf('[LimpVix] %d Ofertas Enviadas - Contrato #%d', $count, $contractId);

        $message = sprintf(
            '<html><body style="font-family: Arial, sans-serif;">
            <h2>Ofertas Enviadas com Sucesso</h2>
            
            <p><strong>Contrato:</strong> #%d</p>
            <p><strong>Serviço:</strong> %s</p>
            <p><strong>Local:</strong> %s</p>
            <p><strong>Ofertas Enviadas:</strong> %d profissionais</p>
            
            <h3>Top 3 Profissionais (por score de match):</h3>
            <ol>
                %s
            </ol>
            
            <p><a href="%s">Ver Detalhes do Contrato</a></p>
            </body></html>',
            $contractId,
            esc_html($contract['service_type'] ?? 'N/A'),
            esc_html($contract['service_address'] ?? 'N/A'),
            $count,
            '<li>' . implode('</li><li>', array_map('esc_html', $professionalsText)) . '</li>',
            admin_url('admin.php?page=limpvix-contracts&action=view&id=' . $contractId)
        );

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($adminEmail, $subject, $message, $headers);
    }
}
