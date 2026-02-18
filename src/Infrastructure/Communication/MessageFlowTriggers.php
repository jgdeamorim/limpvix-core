<?php
/**
 * MessageFlowTriggers - Disparadores de Fluxos de Mensagens
 *
 * Conecta eventos do sistema aos templates canônicos
 *
 * @package LimpVix\Infrastructure\Communication
 */

namespace LimpVix\Infrastructure\Communication;

use LimpVix\Domain\Communication\MessageTemplates;
use LimpVix\Infrastructure\GoogleBusiness\GoogleBusinessReviewHelper;
use LimpVix\Infrastructure\Communication\Providers\TwilioSmsProvider;
use LimpVix\Infrastructure\Communication\Providers\WhatsApp360DialogProvider;
use LimpVix\Infrastructure\Communication\Repositories\MessageRepository;

defined('ABSPATH') || exit;

final class MessageFlowTriggers
{
    /**
     * Registrar todos os triggers
     */
    public static function register(): void
    {
        // C1: Feedback do cliente (já tratado pelo FeedbackRemindersCron)
        // Nenhuma action adicional necessária

        // C2: Feedback negativo (bloqueio deliberado)
        add_action('limpvix_feedback_negative_received', [__CLASS__, 'onFeedbackNegative'], 10, 2);

        // C3: Feedback 5 estrelas → Google Review
        add_action('limpvix_feedback_positive_received', [__CLASS__, 'onFeedback5Stars'], 10, 2);

        // P1: Execução validada → Notificar profissional
        add_action('limpvix_execution_validated', [__CLASS__, 'onExecutionValidated'], 10, 1);

        // P2: Pagamento autorizado → Notificar staff
        add_action('limpvix_payment_authorized', [__CLASS__, 'onPaymentAuthorized'], 10, 2);

        // P3: Pagamento bloqueado → Notificar staff
        add_action('limpvix_payment_blocked', [__CLASS__, 'onPaymentBlocked'], 10, 2);
    }

    // ========================================
    // FLUXO C2: FEEDBACK NEGATIVO (≤3⭐)
    // ========================================

    /**
     * Handler: Feedback negativo recebido
     *
     * BLOQUEIO DELIBERADO - Nenhuma mensagem automática
     * Sinaliza para admin que precisa de contato humano
     *
     * @param string $orderUuid UUID do pedido
     * @param int $rating Nota (1-5)
     */
    public static function onFeedbackNegative(string $orderUuid, int $rating): void
    {
        global $wpdb;

        // Verificar se deve bloquear mensagens
        $validation = MessageTemplates::canSendAutomatically(['order_uuid' => $orderUuid]);

        if (!$validation['can_send']) {
            // Já bloqueado por outra razão
            MessageTemplates::logFlowStopped([
                'flow_type' => 'C2_feedback_negative',
                'order_uuid' => $orderUuid,
                'reason' => $validation['reason'],
                'final_attempt' => 0,
            ]);
            return;
        }

        // REGRA DE OURO: Bloquear automação e sinalizar admin
        $wpdb->update(
            $wpdb->prefix . 'limpvix_feedback_reminders',
            [
                'status' => 'manual_review_required',
                'stop_reason' => 'negative_feedback',
                'updated_at' => current_time('mysql'),
            ],
            ['order_uuid' => $orderUuid],
            ['%s', '%s', '%s'],
            ['%s']
        );

        // Log de bloqueio
        MessageTemplates::logFlowStopped([
            'flow_type' => 'C2_feedback_negative',
            'order_uuid' => $orderUuid,
            'reason' => 'negative_feedback_manual_only',
            'final_attempt' => 0,
        ]);

        // Notificar admin (opcional)
        do_action('limpvix_notify_admin', 'negative_feedback', [
            'order_uuid' => $orderUuid,
            'rating' => $rating,
            'requires_manual_contact' => true,
        ]);
    }

    // ========================================
    // FLUXO C3: FEEDBACK 5⭐ → GOOGLE REVIEW
    // ========================================

    /**
     * Handler: Feedback 5 estrelas recebido
     *
     * Envia convite para avaliar no Google Meu Negócio
     *
     * @param string $orderUuid UUID do pedido
     * @param int $customerId ID do cliente
     */
    public static function onFeedback5Stars(string $orderUuid, int $customerId): void
    {
        // Verificar se Google Business está configurado
        if (!GoogleBusinessReviewHelper::isConfigured()) {
            return;
        }

        // Verificar se pode enviar
        $validation = MessageTemplates::canSendAutomatically([
            'order_uuid' => $orderUuid,
            'customer_id' => $customerId,
        ]);

        if (!$validation['can_send']) {
            MessageTemplates::logFlowStopped([
                'flow_type' => 'C3_google_review',
                'order_uuid' => $orderUuid,
                'reason' => $validation['reason'],
                'final_attempt' => 0,
            ]);
            return;
        }

        // Verificar se já enviou convite Google Review para este pedido
        global $wpdb;
        $alreadySent = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_messages
             WHERE order_uuid = %s AND type = 'google_review_invite'",
            $orderUuid
        ));

        if ($alreadySent > 0) {
            return; // Já enviou, não insistir
        }

        // Enviar convite via helper (que usa os templates)
        $sent = GoogleBusinessReviewHelper::sendReviewInvite($customerId, $orderUuid);

        if ($sent) {
            MessageTemplates::logMessageSent([
                'channel' => 'sms', // ou whatsapp, dependendo do provider
                'template' => 'google_review',
                'recipient_type' => 'customer',
                'recipient_id' => $customerId,
                'order_uuid' => $orderUuid,
                'attempt' => 1,
                'status' => 'sent',
            ]);
        }
    }

    // ========================================
    // FLUXO P1: SERVIÇO CONCLUÍDO
    // ========================================

    /**
     * Handler: Serviço concluído
     *
     * Notifica profissional sobre conclusão e status financeiro
     *
     * @param mixed $appointmentData Dados da execução
     */
    public static function onServiceCompleted($appointmentData): void
    {
        // Verificar se notificação de staff está ativa
        if (!get_option('limpvix_notify_staff_enabled', false)) {
            return;
        }

        $staffId = $appointmentData->staffId ?? null;
        $serviceId = $appointmentData->serviceId ?? null;

        if (!$staffId || !$serviceId) {
            return;
        }

        // Buscar dados do staff
        global $wpdb;
        $staff = $wpdb->get_row($wpdb->prepare(
            "SELECT full_name AS name, phone FROM {$wpdb->prefix}limpvix_professionals WHERE id = %d",
            $staffId
        ), ARRAY_A);

        if (!$staff || !$staff['phone']) {
            return;
        }

        // Buscar nome do serviço
        $service = $wpdb->get_row($wpdb->prepare(
            "SELECT service_type AS name FROM {$wpdb->prefix}limpvix_contracts WHERE id = %d",
            $serviceId
        ), ARRAY_A);

        $serviceName = $service['name'] ?? 'Serviço';
        $staffName = explode(' ', $staff['name'])[0];

        // Gerar mensagem
        $message = MessageTemplates::get('staff_completed', 1, [
            'staff_name' => $staffName,
            'service_name' => $serviceName,
        ]);

        if (!$message) {
            return;
        }

        // Enviar via SMS (staff não precisa de WhatsApp template)
        $sent = self::sendViaSMS($staff['phone'], $message, '', $staffId);

        if ($sent) {
            MessageTemplates::logMessageSent([
                'channel' => 'sms',
                'template' => 'staff_completed',
                'recipient_type' => 'staff',
                'recipient_id' => $staffId,
                'order_uuid' => '', // Appointment não tem order_uuid ainda
                'attempt' => 1,
                'status' => 'sent',
            ]);
        }
    }

    // ========================================
    // FLUXO P2: PAGAMENTO AUTORIZADO
    // ========================================

    /**
     * Handler: Pagamento autorizado
     *
     * Notifica profissional sobre liberação
     *
     * @param string $orderUuid UUID do pedido
     * @param array $paymentData Dados do pagamento
     */
    public static function onPaymentAuthorized(string $orderUuid, array $paymentData): void
    {
        // Verificar se notificação de staff está ativa
        if (!get_option('limpvix_notify_staff_enabled', false)) {
            return;
        }

        $staffId = $paymentData['staff_id'] ?? null;
        $amount = $paymentData['amount'] ?? 0;

        if (!$staffId) {
            return;
        }

        // Buscar dados do staff
        global $wpdb;
        $staff = $wpdb->get_row($wpdb->prepare(
            "SELECT full_name AS name, phone FROM {$wpdb->prefix}limpvix_professionals WHERE id = %d",
            $staffId
        ), ARRAY_A);

        if (!$staff || !$staff['phone']) {
            return;
        }

        $staffName = explode(' ', $staff['name'])[0];
        $serviceName = $paymentData['service_name'] ?? 'Serviço';

        // Gerar mensagem
        $message = MessageTemplates::get('staff_authorized', 1, [
            'staff_name' => $staffName,
            'service_name' => $serviceName,
            'amount' => $amount,
        ]);

        if (!$message) {
            return;
        }

        // Enviar via SMS
        $sent = self::sendViaSMS($staff['phone'], $message, $orderUuid, $staffId);

        if ($sent) {
            MessageTemplates::logMessageSent([
                'channel' => 'sms',
                'template' => 'staff_authorized',
                'recipient_type' => 'staff',
                'recipient_id' => $staffId,
                'order_uuid' => $orderUuid,
                'attempt' => 1,
                'status' => 'sent',
            ]);
        }
    }

    // ========================================
    // FLUXO P3: PAGAMENTO BLOQUEADO
    // ========================================

    /**
     * Handler: Pagamento bloqueado
     *
     * Notifica profissional sobre análise manual
     *
     * @param string $orderUuid UUID do pedido
     * @param array $blockData Dados do bloqueio
     */
    public static function onPaymentBlocked(string $orderUuid, array $blockData): void
    {
        // Verificar se notificação de staff está ativa
        if (!get_option('limpvix_notify_staff_enabled', false)) {
            return;
        }

        $staffId = $blockData['staff_id'] ?? null;

        if (!$staffId) {
            return;
        }

        // Buscar dados do staff
        global $wpdb;
        $staff = $wpdb->get_row($wpdb->prepare(
            "SELECT full_name AS name, phone FROM {$wpdb->prefix}limpvix_professionals WHERE id = %d",
            $staffId
        ), ARRAY_A);

        if (!$staff || !$staff['phone']) {
            return;
        }

        $staffName = explode(' ', $staff['name'])[0];
        $serviceName = $blockData['service_name'] ?? 'Serviço';

        // Gerar mensagem (linguagem neutra, sem acusação)
        $message = MessageTemplates::get('staff_review', 1, [
            'staff_name' => $staffName,
            'service_name' => $serviceName,
        ]);

        if (!$message) {
            return;
        }

        // Enviar via SMS
        $sent = self::sendViaSMS($staff['phone'], $message, $orderUuid, $staffId);

        if ($sent) {
            MessageTemplates::logMessageSent([
                'channel' => 'sms',
                'template' => 'staff_review',
                'recipient_type' => 'staff',
                'recipient_id' => $staffId,
                'order_uuid' => $orderUuid,
                'attempt' => 1,
                'status' => 'sent',
            ]);
        }
    }

    // ========================================
    // HELPERS
    // ========================================

    /**
     * Enviar mensagem via SMS
     *
     * @param string $phone Telefone
     * @param string $message Mensagem
     * @param string $orderUuid UUID do pedido
     * @param int $recipientId ID do destinatário
     * @return bool
     */
    private static function sendViaSMS(
        string $phone,
        string $message,
        string $orderUuid,
        int $recipientId
    ): bool {
        if (!class_exists('LimpVix\\Infrastructure\\Communication\\Providers\\TwilioSmsProvider')) {
            return false;
        }

        $repository = new MessageRepository();
        $provider = new TwilioSmsProvider($repository);

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
        $result = $provider->send($phone, $message, $orderUuid, $recipientId, 0);

        return $result['success'] ?? false;
    }

    /**
     * Enviar mensagem via WhatsApp (com template)
     *
     * @param string $phone Telefone
     * @param string $templateName Nome do template
     * @param array $parameters Parâmetros
     * @param string $orderUuid UUID do pedido
     * @param int $recipientId ID do destinatário
     * @return bool
     */
    private static function sendViaWhatsApp(
        string $phone,
        string $templateName,
        array $parameters,
        string $orderUuid,
        int $recipientId
    ): bool {
        if (!class_exists('LimpVix\\Infrastructure\\Communication\\Providers\\WhatsApp360DialogProvider')) {
            return false;
        }

        $repository = new MessageRepository();
        $provider = new WhatsApp360DialogProvider($repository);

        if (!$provider->isConfigured()) {
            return false;
        }

        // Formatar telefone
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Enviar
        $result = $provider->sendTemplate(
            $phone,
            $templateName,
            $parameters,
            $orderUuid,
            $recipientId,
            1
        );

        return $result['success'] ?? false;
    }
}
