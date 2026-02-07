<?php
/**
 * CommunicationProviderInterface - Interface
 *
 * RESPONSABILIDADE:
 * - Contrato para providers de comunicação (WhatsApp, SMS, Email, Push)
 * - Abstração permite trocar provider sem modificar Use Cases
 *
 * IMPLEMENTAÇÕES ESPERADAS:
 * - WhatsApp360DialogProvider (já existe)
 * - TwilioSmsProvider (já existe)
 * - EmailProvider (futuro)
 * - PushNotificationProvider (futuro)
 *
 * @package LimpVix\Application\Services\Communication
 * @since 0.3.0
 */

namespace LimpVix\Application\Services\Communication;

defined('ABSPATH') || exit;

interface CommunicationProviderInterface
{
    /**
     * Enviar mensagem
     *
     * @param array $params Parâmetros de envio
     *   - channel: string (whatsapp, sms, email, push)
     *   - recipient: string (phone, email, device_token)
     *   - content: string (mensagem renderizada)
     *   - message_id: string (ID único da mensagem)
     * @return array Resultado do envio
     *   - success: bool
     *   - provider_response: mixed (resposta do provider)
     *   - error_type: string (se falhou)
     *   - error_message: string (se falhou)
     */
    public function send(array $params): array;

    /**
     * Verificar status de entrega de mensagem
     *
     * @param string $messageId ID da mensagem
     * @return array Status de entrega
     *   - status: string (sent, delivered, read, failed)
     *   - delivered_at: string|null
     *   - read_at: string|null
     */
    public function checkDeliveryStatus(string $messageId): array;

    /**
     * Verificar se provider suporta canal
     *
     * @param string $channel Canal (whatsapp, sms, email, push)
     * @return bool
     */
    public function supportsChannel(string $channel): bool;
}
