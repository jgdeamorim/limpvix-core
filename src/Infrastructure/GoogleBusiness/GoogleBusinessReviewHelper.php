<?php
/**
 * GoogleBusinessReviewHelper - Gerador de Links de Review
 *
 * @package LimpVix\Infrastructure\GoogleBusiness
 */

namespace LimpVix\Infrastructure\GoogleBusiness;

use LimpVix\Admin\Settings\GoogleBusinessSettings;

defined('ABSPATH') || exit;

final class GoogleBusinessReviewHelper
{
    /**
     * Gerar link de review do Google para um cliente
     *
     * @param int $customerId ID do cliente
     * @return string|null URL de review ou null se não configurado
     */
    public static function generateReviewUrl(int $customerId): ?string
    {
        $placeId = self::getPlaceId();

        if (!$placeId) {
            return null;
        }

        // URL de review do Google
        return sprintf(
            'https://search.google.com/local/writereview?placeid=%s',
            $placeId
        );
    }

    /**
     * Obter Place ID (cached)
     *
     * @return string|null
     */
    private static function getPlaceId(): ?string
    {
        $placeId = get_option('limpvix_google_place_id');

        if ($placeId) {
            return $placeId;
        }

        // Buscar Place ID via API
        $placeId = self::fetchPlaceIdFromApi();

        if ($placeId) {
            update_option('limpvix_google_place_id', $placeId);
        }

        return $placeId;
    }

    /**
     * Buscar Place ID via Google Business Profile API
     *
     * @return string|null
     */
    private static function fetchPlaceIdFromApi(): ?string
    {
        $accessToken = GoogleBusinessSettings::getValidAccessToken();

        if (!$accessToken) {
            return null;
        }

        // 1. Buscar accounts
        $accountsResponse = wp_remote_get(
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts',
            [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($accountsResponse)) {
            return null;
        }

        $accountsData = json_decode(wp_remote_retrieve_body($accountsResponse), true);
        $accountName = $accountsData['accounts'][0]['name'] ?? null;

        if (!$accountName) {
            return null;
        }

        // 2. Buscar locations
        $locationsResponse = wp_remote_get(
            "https://mybusinessbusinessinformation.googleapis.com/v1/{$accountName}/locations",
            [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($locationsResponse)) {
            return null;
        }

        $locationsData = json_decode(wp_remote_retrieve_body($locationsResponse), true);

        // 3. Extrair Place ID da primeira localização
        foreach ($locationsData['locations'] ?? [] as $location) {
            if (isset($location['metadata']['placeId'])) {
                return $location['metadata']['placeId'];
            }
        }

        return null;
    }

    /**
     * Verificar se está configurado para enviar reviews
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return GoogleBusinessSettings::isConnected() && !empty(self::getPlaceId());
    }

    /**
     * Enviar convite de review para cliente com 5 estrelas
     *
     * @param int $customerId ID do cliente
     * @param string $orderUuid UUID do pedido
     * @return bool Sucesso
     */
    public static function sendReviewInvite(int $customerId, string $orderUuid): bool
    {
        if (!self::isConfigured()) {
            return false;
        }

        // Gerar link de review
        $reviewUrl = self::generateReviewUrl($customerId);

        if (!$reviewUrl) {
            return false;
        }

        // Buscar dados do cliente
        global $wpdb;
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT name, phone FROM {$wpdb->prefix}limpvix_customers WHERE id = %d",
            $customerId
        ), ARRAY_A);

        if (!$customer || !$customer['phone']) {
            return false;
        }

        $customerName = explode(' ', $customer['name'])[0]; // Primeiro nome

        // Montar mensagem
        $message = sprintf(
            "Olá %s! 🌟\n\n" .
            "Ficamos muito felizes com sua avaliação 5 estrelas!\n\n" .
            "Você pode compartilhar sua experiência no Google também?\n" .
            "É rápido e ajuda outros clientes:\n\n" .
            "%s\n\n" .
            "Obrigado! ❤️\n" .
            "Equipe Limpvix",
            $customerName,
            $reviewUrl
        );

        // Enviar via provider de comunicação (WhatsApp prioritário)
        $sent = self::sendViaWhatsApp($customer['phone'], $message, $orderUuid, $customerId);

        if ($sent) {
            // Incrementar contador
            $count = (int) get_option('limpvix_google_reviews_sent', 0);
            update_option('limpvix_google_reviews_sent', $count + 1);

            // Log
            do_action('limpvix_log_event', 'google_review_invite_sent', [
                'customer_id' => $customerId,
                'order_uuid' => $orderUuid,
                'review_url' => $reviewUrl,
            ]);
        }

        return $sent;
    }

    /**
     * Enviar mensagem via WhatsApp
     *
     * @param string $phone Telefone
     * @param string $message Mensagem
     * @param string $orderUuid UUID do pedido
     * @param int $customerId ID do cliente
     * @return bool
     */
    private static function sendViaWhatsApp(
        string $phone,
        string $message,
        string $orderUuid,
        int $customerId
    ): bool {
        // Verificar se provider de WhatsApp está disponível
        if (!class_exists('LimpVix\\Infrastructure\\Communication\\Providers\\WhatsApp360DialogProvider')) {
            return false;
        }

        $repository = new \LimpVix\Infrastructure\Communication\Repositories\MessageRepository();
        $provider = new \LimpVix\Infrastructure\Communication\Providers\WhatsApp360DialogProvider($repository);

        if (!$provider->isConfigured()) {
            return false;
        }

        // Enviar mensagem de texto (não template)
        // NOTA: Para texto livre, pode ser necessário usar SMS
        // WhatsApp Business API exige templates pré-aprovados
        // Alternativa: criar template específico para review

        // Por enquanto, usar SMS como fallback
        return self::sendViaSms($phone, $message, $orderUuid, $customerId);
    }

    /**
     * Enviar mensagem via SMS (fallback)
     *
     * @param string $phone Telefone
     * @param string $message Mensagem
     * @param string $orderUuid UUID do pedido
     * @param int $customerId ID do cliente
     * @return bool
     */
    private static function sendViaSms(
        string $phone,
        string $message,
        string $orderUuid,
        int $customerId
    ): bool {
        if (!class_exists('LimpVix\\Infrastructure\\Communication\\Providers\\TwilioSmsProvider')) {
            return false;
        }

        $repository = new \LimpVix\Infrastructure\Communication\Repositories\MessageRepository();
        $provider = new \LimpVix\Infrastructure\Communication\Providers\TwilioSmsProvider($repository);

        if (!$provider->isConfigured()) {
            return false;
        }

        // Formatar telefone
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (!str_starts_with($phone, '55')) {
            $phone = '55' . $phone;
        }
        $phone = '+' . $phone;

        // Enviar
        $result = $provider->send($phone, $message, $orderUuid, $customerId, 0);

        return $result['success'] ?? false;
    }
}
