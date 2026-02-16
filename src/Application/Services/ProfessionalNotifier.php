<?php
/**
 * Professional Notifier Service
 *
 * Sends notifications to professionals via multiple channels:
 * - Email (WordPress wp_mail)
 * - SMS (Twilio - if configured)
 * - Push Notification (structure prepared)
 *
 * @package LimpVix\Application\Services
 * @since 0.9.0
 */

namespace LimpVix\Application\Services;

use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;
use LimpVix\Domain\Contract\ContractRepositoryInterface;

defined('ABSPATH') || exit;

final class ProfessionalNotifier
{
    private ProfessionalRepositoryInterface $professionalRepository;
    private ContractRepositoryInterface $contractRepository;

    public function __construct(
        ProfessionalRepositoryInterface $professionalRepository,
        ContractRepositoryInterface $contractRepository
    ) {
        $this->professionalRepository = $professionalRepository;
        $this->contractRepository = $contractRepository;
    }

    /**
     * Send offer notification to professional
     *
     * @param int $professionalId Professional ID (NOT user_id)
     * @param int $offerId Offer ID
     * @return bool Success
     */
    public function sendOfferNotification(int $professionalId, int $offerId): bool
    {
        try {
            // Get professional
            $professional = $this->professionalRepository->findById($professionalId);
            if (!$professional) {
                error_log("[LimpVix] ProfessionalNotifier: Professional #{$professionalId} not found");
                return false;
            }

            // Get offer details
            $offer = $this->getOfferDetails($offerId);
            if (!$offer) {
                error_log("[LimpVix] ProfessionalNotifier: Offer #{$offerId} not found");
                return false;
            }

            $sentChannels = [];

            // 1. Send Email (always)
            if ($this->sendEmailNotification($professional, $offer)) {
                $sentChannels[] = 'email';
            }

            // 2. Send SMS (if Twilio configured)
            if ($this->isTwilioConfigured() && $professional->getPhone()) {
                if ($this->sendSMSNotification($professional, $offer)) {
                    $sentChannels[] = 'sms';
                }
            }

            // 3. Send Push Notification (if configured)
            // TODO: Implement push notification when mobile app is ready
            // if ($this->isPushConfigured()) {
            //     $this->sendPushNotification($professional, $offer);
            //     $sentChannels[] = 'push';
            // }

            error_log(sprintf(
                '[LimpVix] Offer notification sent to Professional #%d via: %s',
                $professionalId,
                implode(', ', $sentChannels)
            ));

            return !empty($sentChannels);

        } catch (\Exception $e) {
            error_log('[LimpVix] ProfessionalNotifier: Error - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send email notification
     *
     * @param object $professional Professional aggregate
     * @param array $offer Offer details
     * @return bool Success
     */
    private function sendEmailNotification($professional, array $offer): bool
    {
        $to = $professional->getEmail();
        $subject = 'Nova Oportunidade de Trabalho - LimpVix';

        $message = $this->buildEmailMessage($professional, $offer);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: LimpVix <noreply@limpvix.com>',
        ];

        return wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Build HTML email message
     */
    private function buildEmailMessage($professional, array $offer): string
    {
        $expiresAt = new \DateTime($offer['expires_at']);
        $expiresIn = $expiresAt->diff(new \DateTime())->format('%h horas');

        $acceptUrl = admin_url('admin.php?page=limpvix-offers&action=accept&offer_id=' . $offer['id']);

        return sprintf(
            '<html><body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px; background: #f5f5f5;">
                <div style="background: white; padding: 30px; border-radius: 8px;">
                    <h2 style="color: #2563eb; margin-top: 0;">🧹 Nova Oportunidade de Trabalho!</h2>
                    
                    <p>Olá <strong>%s</strong>,</p>
                    
                    <p>Você recebeu uma nova oferta de trabalho:</p>
                    
                    <div style="background: #f0f9ff; padding: 20px; border-left: 4px solid #2563eb; margin: 20px 0;">
                        <p style="margin: 5px 0;"><strong>Serviço:</strong> %s</p>
                        <p style="margin: 5px 0;"><strong>Local:</strong> %s</p>
                        <p style="margin: 5px 0;"><strong>Valor:</strong> R$ %s</p>
                        <p style="margin: 5px 0;"><strong>Distância:</strong> %.1f km</p>
                        <p style="margin: 5px 0;"><strong>Score de Match:</strong> %d/100</p>
                    </div>
                    
                    <p style="color: #dc2626;"><strong>⏰ Esta oferta expira em %s</strong></p>
                    
                    <p style="text-align: center; margin: 30px 0;">
                        <a href="%s" style="display: inline-block; background: #2563eb; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                            ✅ Aceitar Oferta
                        </a>
                    </p>
                    
                    <p style="font-size: 12px; color: #666; margin-top: 30px;">
                        Esta é uma notificação automática. Por favor, não responda este email.
                    </p>
                </div>
            </div>
            </body></html>',
            esc_html($professional->getFullName()),
            esc_html($offer['service_type'] ?? 'Limpeza'),
            esc_html($offer['service_address'] ?? 'Endereço não disponível'),
            number_format($offer['proposed_amount'], 2, ',', '.'),
            $offer['distance_km'] ?? 0,
            (int) $offer['match_score'],
            $expiresIn,
            esc_url($acceptUrl)
        );
    }

    /**
     * Send SMS notification via Twilio
     *
     * @param object $professional Professional aggregate
     * @param array $offer Offer details
     * @return bool Success
     */
    private function sendSMSNotification($professional, array $offer): bool
    {
        if (!$this->isTwilioConfigured()) {
            return false;
        }

        $phone = $professional->getPhone();
        if (!$phone) {
            return false;
        }

        $message = sprintf(
            "🧹 LimpVix: Nova oferta de trabalho!\n\n" .
            "Valor: R$ %.2f\n" .
            "Distância: %.1f km\n" .
            "Match: %d/100\n\n" .
            "Aceite em: %s\n" .
            "Expira em 24h",
            $offer['proposed_amount'],
            $offer['distance_km'] ?? 0,
            (int) $offer['match_score'],
            home_url('/offers/' . $offer['id'])
        );

        try {
            $accountSid = get_option('limpvix_twilio_account_sid');
            $authToken = get_option('limpvix_twilio_auth_token');
            $fromNumber = get_option('limpvix_twilio_from_number');

            $response = wp_remote_post(
                "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json",
                [
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode("{$accountSid}:{$authToken}"),
                    ],
                    'body' => [
                        'From' => $fromNumber,
                        'To' => $this->formatPhone($phone),
                        'Body' => $message,
                    ],
                ]
            );

            return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 201;

        } catch (\Exception $e) {
            error_log('[LimpVix] SMS notification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get offer details from database
     *
     * @param int $offerId
     * @return array|null
     */
    private function getOfferDetails(int $offerId): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contract_offers';

        $offer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    o.*,
                    c.service_address,
                    c.service_type,
                    o.match_metadata->>'$.distance_km' as distance_km
                FROM {$table} o
                LEFT JOIN {$wpdb->prefix}limpvix_contracts c ON o.contract_id = c.id
                WHERE o.id = %d",
                $offerId
            ),
            ARRAY_A
        );

        if (!$offer) {
            return null;
        }

        // Parse match_metadata JSON if exists
        if (!empty($offer['match_metadata'])) {
            $metadata = json_decode($offer['match_metadata'], true);
            $offer = array_merge($offer, $metadata);
        }

        return $offer;
    }

    /**
     * Check if Twilio is configured
     */
    private function isTwilioConfigured(): bool
    {
        return !empty(get_option('limpvix_twilio_account_sid'))
            && !empty(get_option('limpvix_twilio_auth_token'))
            && !empty(get_option('limpvix_twilio_from_number'));
    }

    /**
     * Format phone to E.164 standard
     */
    private function formatPhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($cleaned) === 11) {
            return '+55' . $cleaned; // Brazil
        }

        return '+' . $cleaned;
    }
}
