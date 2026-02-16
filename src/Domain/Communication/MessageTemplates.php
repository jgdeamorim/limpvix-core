<?php
/**
 * MessageTemplates - Templates Canônicos de Mensagens
 *
 * Templates validados para produção alinhados com:
 * - FSM financeira
 * - LGPD + CDC
 * - UX não invasivo
 * - Booknetic + Google Meu Negócio
 *
 * @package LimpVix\Domain\Communication
 */

namespace LimpVix\Domain\Communication;

defined('ABSPATH') || exit;

final class MessageTemplates
{
    // ========================================
    // FLUXOS PARA CLIENTES (IMPLIXIA)
    // ========================================

    /**
     * C1.1 - Solicitação de Feedback D+1 (WhatsApp - prioridade)
     */
    public static function clientFeedbackD1(array $vars): string
    {
        return sprintf(
            "Olá %s! 👋\n\n" .
            "O serviço realizado ontem atendeu suas expectativas?\n\n" .
            "Sua avaliação ajuda a Limpvix a manter a qualidade ⭐\n" .
            "Leva menos de 1 minuto:\n\n" .
            "👉 Avaliar agora: %s\n\n" .
            "Equipe Limpvix",
            $vars['customer_name'],
            $vars['feedback_url']
        );
    }

    /**
     * C1.2 - Solicitação de Feedback D+3 (WhatsApp ou SMS)
     */
    public static function clientFeedbackD3(array $vars): string
    {
        return sprintf(
            "Oi %s!\n\n" .
            "Ainda não vimos sua avaliação do serviço 😊\n" .
            "Se puder, sua opinião é muito importante:\n\n" .
            "👉 Avaliar: %s\n\n" .
            "Obrigado!",
            $vars['customer_name'],
            $vars['feedback_url']
        );
    }

    /**
     * C1.3 - Solicitação de Feedback D+6/D+7 (SMS - última)
     */
    public static function clientFeedbackD6(array $vars): string
    {
        return sprintf(
            "%s, esta é a última mensagem 😊\n" .
            "Avalie o serviço da Limpvix em 1 minuto:\n\n" .
            "%s",
            $vars['customer_name'],
            $vars['feedback_url']
        );
    }

    /**
     * C2 - Feedback NEGATIVO (≤3 estrelas)
     *
     * BLOQUEIO DELIBERADO - Nenhuma mensagem automática
     * Requer contato humano via admin
     *
     * @return null Sempre retorna null (bloqueio intencional)
     */
    public static function clientFeedbackNegative(array $vars): ?string
    {
        // REGRA DE OURO: Cliente NUNCA recebe mensagem automática após ≤3⭐
        return null;
    }

    /**
     * C3 - Feedback 5⭐ → Google Meu Negócio
     */
    public static function clientGoogleReviewInvite(array $vars): string
    {
        return sprintf(
            "Obrigado pela sua avaliação, %s! 🌟\n\n" .
            "Se puder, compartilhe sua experiência no Google — isso ajuda muito a Limpvix 💙\n\n" .
            "👉 Avaliar no Google: %s\n\n" .
            "Muito obrigado!",
            $vars['customer_name'],
            $vars['google_review_url']
        );
    }

    // ========================================
    // FLUXOS PARA PROFISSIONAIS (STAFF)
    // ========================================

    /**
     * P1 - Serviço concluído (informativo)
     */
    public static function staffServiceCompleted(array $vars): string
    {
        return sprintf(
            "Olá %s 👋\n\n" .
            "O serviço %s foi concluído com sucesso.\n\n" .
            "Status financeiro atual:\n" .
            "⏳ Aguardando avaliação do cliente\n\n" .
            "Você será avisado assim que houver atualização.",
            $vars['staff_name'],
            $vars['service_name']
        );
    }

    /**
     * P2 - Liberação automática (5⭐ ou timeout)
     */
    public static function staffPaymentAuthorized(array $vars): string
    {
        return sprintf(
            "Boa notícia, %s! ✅\n\n" .
            "O pagamento do serviço %s foi AUTORIZADO.\n\n" .
            "💰 Valor: R$ %s\n" .
            "📆 Repasse previsto conforme cronograma.\n\n" .
            "Equipe Limpvix",
            $vars['staff_name'],
            $vars['service_name'],
            number_format($vars['amount'], 2, ',', '.')
        );
    }

    /**
     * P3 - Bloqueio / análise manual
     */
    public static function staffPaymentUnderReview(array $vars): string
    {
        return sprintf(
            "Olá %s.\n\n" .
            "O pagamento do serviço %s está em análise.\n\n" .
            "Nossa equipe está avaliando o ocorrido e poderá entrar em contato se necessário.\n\n" .
            "Equipe Limpvix",
            $vars['staff_name'],
            $vars['service_name']
        );
    }

    // ========================================
    // TEMPLATES WHATSAPP (360Dialog)
    // ========================================

    /**
     * Template WhatsApp D+1 (pré-aprovado no 360Dialog)
     *
     * Nome: feedback_limpvix_d1
     * Parâmetros: {{1}} = nome, {{2}} = url
     */
    public static function getWhatsAppTemplateD1(): array
    {
        return [
            'name' => 'feedback_limpvix_d1',
            'language' => 'pt_BR',
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text'], // {{1}} customer_name
                        ['type' => 'text'], // {{2}} feedback_url
                    ]
                ]
            ]
        ];
    }

    /**
     * Template WhatsApp D+3 (pré-aprovado no 360Dialog)
     *
     * Nome: feedback_limpvix_d3
     * Parâmetros: {{1}} = nome, {{2}} = url
     */
    public static function getWhatsAppTemplateD3(): array
    {
        return [
            'name' => 'feedback_limpvix_d3',
            'language' => 'pt_BR',
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text'], // {{1}} customer_name
                        ['type' => 'text'], // {{2}} feedback_url
                    ]
                ]
            ]
        ];
    }

    /**
     * Template WhatsApp Google Review (criar no 360Dialog)
     *
     * Nome: google_review_limpvix
     * Parâmetros: {{1}} = nome, {{2}} = url
     */
    public static function getWhatsAppTemplateGoogleReview(): array
    {
        return [
            'name' => 'google_review_limpvix',
            'language' => 'pt_BR',
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text'], // {{1}} customer_name
                        ['type' => 'text'], // {{2}} google_review_url
                    ]
                ]
            ]
        ];
    }

    // ========================================
    // VALIDAÇÃO DE ENVIO (REGRAS DE GOVERNANÇA)
    // ========================================

    /**
     * Verificar se pode enviar mensagem automaticamente
     *
     * ❌ NUNCA ENVIAR SE:
     * - Feedback ≤3⭐
     * - Disputa ativa
     * - Pedido estornado
     * - Opt-out do cliente
     * - Comunicação desativada
     *
     * @param array $context Contexto do pedido
     * @return array ['can_send' => bool, 'reason' => string]
     */
    public static function canSendAutomatically(array $context): array
    {
        global $wpdb;

        $orderUuid = $context['order_uuid'] ?? '';

        // 1. Verificar se comunicação está ativa globalmente
        if (!get_option('limpvix_comm_active', true)) {
            return [
                'can_send' => false,
                'reason' => 'communication_disabled',
            ];
        }

        // 2. Verificar opt-out do cliente
        $customerId = $context['customer_id'] ?? 0;
        if ($customerId) {
            $optOut = $wpdb->get_var($wpdb->prepare(
                "SELECT opt_out FROM {$wpdb->prefix}limpvix_feedback_reminders
                 WHERE customer_id = %d AND status = 'opted_out'
                 LIMIT 1",
                $customerId
            ));

            if ($optOut) {
                return [
                    'can_send' => false,
                    'reason' => 'customer_opted_out',
                ];
            }
        }

        // 3. Verificar feedback negativo (≤3⭐)
        $feedbackRating = $wpdb->get_var($wpdb->prepare(
            "SELECT feedback_rating FROM {$wpdb->prefix}limpvix_financial_context
             WHERE order_uuid = %s",
            $orderUuid
        ));

        if ($feedbackRating !== null && $feedbackRating <= 3) {
            return [
                'can_send' => false,
                'reason' => 'negative_feedback_manual_only',
            ];
        }

        // 4. Verificar disputa ativa
        $disputeStatus = $wpdb->get_var($wpdb->prepare(
            "SELECT dispute_status FROM {$wpdb->prefix}limpvix_financial_context
             WHERE order_uuid = %s",
            $orderUuid
        ));

        if ($disputeStatus === 'active') {
            return [
                'can_send' => false,
                'reason' => 'active_dispute',
            ];
        }

        // 5. Verificar estorno
        $refundStatus = $wpdb->get_var($wpdb->prepare(
            "SELECT refund_status FROM {$wpdb->prefix}limpvix_financial_context
             WHERE order_uuid = %s",
            $orderUuid
        ));

        if ($refundStatus === 'refunded') {
            return [
                'can_send' => false,
                'reason' => 'order_refunded',
            ];
        }

        // ✅ Pode enviar
        return [
            'can_send' => true,
            'reason' => null,
        ];
    }

    /**
     * Registrar envio de mensagem (auditoria)
     *
     * ✅ SEMPRE REGISTRAR:
     * - Canal usado
     * - Timestamp
     * - Tentativa
     * - Status
     * - Motivo de parada
     *
     * @param array $data Dados do envio
     */
    public static function logMessageSent(array $data): void
    {
        do_action('limpvix_log_event', 'message_sent', [
            'channel' => $data['channel'] ?? 'unknown',
            'template' => $data['template'] ?? 'custom',
            'recipient_type' => $data['recipient_type'] ?? 'customer', // customer | staff
            'recipient_id' => $data['recipient_id'] ?? 0,
            'order_uuid' => $data['order_uuid'] ?? '',
            'attempt' => $data['attempt'] ?? 1,
            'status' => $data['status'] ?? 'unknown', // sent | failed | skipped
            'reason' => $data['reason'] ?? null,
            'message_id' => $data['message_id'] ?? null,
            'timestamp' => current_time('mysql'),
        ]);
    }

    /**
     * Registrar parada de fluxo (auditoria)
     *
     * @param array $data Dados da parada
     */
    public static function logFlowStopped(array $data): void
    {
        do_action('limpvix_log_event', 'message_flow_stopped', [
            'flow_type' => $data['flow_type'] ?? 'unknown', // C1, C2, C3, P1, P2, P3
            'order_uuid' => $data['order_uuid'] ?? '',
            'reason' => $data['reason'] ?? 'unknown',
            'final_attempt' => $data['final_attempt'] ?? 0,
            'timestamp' => current_time('mysql'),
        ]);
    }

    // ========================================
    // HELPERS
    // ========================================

    /**
     * Obter template por tipo e tentativa
     *
     * @param string $type Tipo: client_feedback | google_review | staff_completed | staff_authorized | staff_review
     * @param int $attempt Tentativa (1, 2, 3)
     * @param array $vars Variáveis para substituição
     * @return string|null Mensagem ou null se bloqueado
     */
    public static function get(string $type, int $attempt, array $vars): ?string
    {
        switch ($type) {
            case 'client_feedback':
                switch ($attempt) {
                    case 1: return self::clientFeedbackD1($vars);
                    case 2: return self::clientFeedbackD3($vars);
                    case 3: return self::clientFeedbackD6($vars);
                }
                break;

            case 'client_feedback_negative':
                return self::clientFeedbackNegative($vars);

            case 'google_review':
                return self::clientGoogleReviewInvite($vars);

            case 'staff_completed':
                return self::staffServiceCompleted($vars);

            case 'staff_authorized':
                return self::staffPaymentAuthorized($vars);

            case 'staff_review':
                return self::staffPaymentUnderReview($vars);
        }

        return null;
    }

    /**
     * Verificar se tipo requer template WhatsApp
     *
     * @param string $type Tipo de mensagem
     * @param int $attempt Tentativa
     * @return bool
     */
    public static function requiresWhatsAppTemplate(string $type, int $attempt): bool
    {
        // WhatsApp Business API exige templates pré-aprovados
        // SMS pode usar texto livre

        if ($type === 'client_feedback' && in_array($attempt, [1, 2])) {
            return true; // D+1 e D+3 via WhatsApp com template
        }

        if ($type === 'google_review') {
            return true; // Google review via WhatsApp com template
        }

        return false; // Demais usam SMS com texto livre
    }

    /**
     * Obter nome do template WhatsApp
     *
     * @param string $type Tipo de mensagem
     * @param int $attempt Tentativa
     * @return string|null Nome do template ou null
     */
    public static function getWhatsAppTemplateName(string $type, int $attempt): ?string
    {
        if ($type === 'client_feedback') {
            switch ($attempt) {
                case 1: return 'feedback_limpvix_d1';
                case 2: return 'feedback_limpvix_d3';
                case 3: return 'feedback_limpvix_d6'; // Pode usar SMS também
            }
        }

        if ($type === 'google_review') {
            return 'google_review_limpvix';
        }

        return null;
    }
}
