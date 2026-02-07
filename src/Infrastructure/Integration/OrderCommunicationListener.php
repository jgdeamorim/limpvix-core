<?php
/**
 * OrderCommunicationListener - Infrastructure Integration
 *
 * RESPONSABILIDADE:
 * - Ouvir eventos de Order/Briefing
 * - Disparar envio de mensagens automáticas
 * - Conectar eventos de domínio com Use Case SendTemplatedMessage
 *
 * EVENTOS OBSERVADOS:
 * - limpvix_order_created → T-BOOKING-01 (Confirmação de agendamento)
 * - limpvix_briefing_locked → T-REMINDER-24H (Lembrete 24h antes)
 * - limpvix_schedule_allocated → T-ON-THE-WAY (Profissional a caminho)
 * - limpvix_check_in_performed → T-CHECKIN (Check-in realizado)
 * - limpvix_check_out_performed → T-CHECKOUT (Serviço concluído)
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 0.3.0
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Application\UseCases\Communication\SendTemplatedMessage;

defined('ABSPATH') || exit;

class OrderCommunicationListener
{
    private $sendTemplatedMessageUseCase;

    public function __construct(SendTemplatedMessage $sendTemplatedMessageUseCase)
    {
        $this->sendTemplatedMessageUseCase = $sendTemplatedMessageUseCase;
    }

    /**
     * Registrar listeners
     *
     * @return void
     */
    public function register(): void
    {
        // Order criada → Confirmação de agendamento
        add_action('limpvix_order_created', [$this, 'onOrderCreated'], 10, 1);

        // Briefing locked → Lembrete 24h (agendado via WP Cron)
        add_action('limpvix_briefing_locked', [$this, 'scheduleReminder24h'], 10, 1);

        // Check-in → Notificação de início
        add_action('limpvix_schedule_check_in_performed', [$this, 'onCheckInPerformed'], 10, 1);

        // Checkout → Notificação de conclusão
        add_action('limpvix_schedule_check_out_performed', [$this, 'onCheckOutPerformed'], 10, 1);
    }

    /**
     * Order criada → Enviar confirmação
     *
     * @param array $orderData Dados da order
     * @return void
     */
    public function onOrderCreated(array $orderData): void
    {
        try {
            // Obter dados do cliente
            $clientPhone = $this->getClientPhone($orderData['customer_id'] ?? null);

            if (!$clientPhone) {
                error_log('[LimpVix] Cannot send booking confirmation: client phone not found');
                return;
            }

            // Extrair dados necessários
            $variables = [
                'client_name' => $orderData['customer_name'] ?? 'Cliente',
                'appointment_date' => $this->formatDate($orderData['requested_date'] ?? null),
                'appointment_time' => $this->formatTime($orderData['requested_time'] ?? null),
                'professional_name' => 'Profissional LimpVix', // Será atualizado após alocação
            ];

            // Enviar mensagem
            $this->sendTemplatedMessageUseCase->execute([
                'template_id' => 'T-BOOKING-01',
                'recipient' => $clientPhone,
                'variables' => $variables,
                'event_id' => $orderData['uuid'] ?? null,
                'event_type' => 'order_created',
            ]);
        } catch (\Exception $e) {
            error_log('[LimpVix] Failed to send booking confirmation: ' . $e->getMessage());
        }
    }

    /**
     * Agendar lembrete 24h antes
     *
     * @param array $briefingData Dados do briefing
     * @return void
     */
    public function scheduleReminder24h(array $briefingData): void
    {
        try {
            // Calcular timestamp 24h antes do agendamento
            $appointmentTime = strtotime($briefingData['requested_date'] . ' ' . $briefingData['requested_time']);
            $reminderTime = $appointmentTime - (24 * 60 * 60); // 24h antes

            // Só agendar se for no futuro
            if ($reminderTime <= time()) {
                return;
            }

            // Agendar WP Cron
            wp_schedule_single_event(
                $reminderTime,
                'limpvix_send_reminder_24h',
                [
                    'order_uuid' => $briefingData['order_uuid'],
                    'briefing_id' => $briefingData['id'],
                ]
            );
        } catch (\Exception $e) {
            error_log('[LimpVix] Failed to schedule 24h reminder: ' . $e->getMessage());
        }
    }

    /**
     * Check-in realizado → Enviar notificação
     *
     * @param array $checkInData Dados do check-in
     * @return void
     */
    public function onCheckInPerformed(array $checkInData): void
    {
        try {
            $clientPhone = $this->getClientPhone($checkInData['customer_id'] ?? null);

            if (!$clientPhone) {
                return;
            }

            $variables = [
                'professional_name' => $checkInData['professional_name'] ?? 'Profissional',
                'checkin_time' => $this->formatTime($checkInData['timestamp'] ?? null),
            ];

            $this->sendTemplatedMessageUseCase->execute([
                'template_id' => 'T-CHECKIN',
                'recipient' => $clientPhone,
                'variables' => $variables,
                'event_id' => $checkInData['schedule_uuid'] ?? null,
                'event_type' => 'check_in_performed',
            ]);
        } catch (\Exception $e) {
            error_log('[LimpVix] Failed to send check-in notification: ' . $e->getMessage());
        }
    }

    /**
     * Checkout realizado → Enviar notificação e solicitar feedback
     *
     * @param array $checkOutData Dados do checkout
     * @return void
     */
    public function onCheckOutPerformed(array $checkOutData): void
    {
        try {
            $clientPhone = $this->getClientPhone($checkOutData['customer_id'] ?? null);

            if (!$clientPhone) {
                return;
            }

            $variables = [
                'professional_name' => $checkOutData['professional_name'] ?? 'Profissional',
                'checkout_time' => $this->formatTime($checkOutData['timestamp'] ?? null),
                'feedback_url' => $this->getFeedbackUrl($checkOutData['order_uuid'] ?? null),
            ];

            $this->sendTemplatedMessageUseCase->execute([
                'template_id' => 'T-CHECKOUT',
                'recipient' => $clientPhone,
                'variables' => $variables,
                'event_id' => $checkOutData['schedule_uuid'] ?? null,
                'event_type' => 'check_out_performed',
            ]);
        } catch (\Exception $e) {
            error_log('[LimpVix] Failed to send checkout notification: ' . $e->getMessage());
        }
    }

    /**
     * Obter telefone do cliente
     *
     * @param int|null $customerId ID do cliente
     * @return string|null
     */
    private function getClientPhone(?int $customerId): ?string
    {
        if (!$customerId) {
            return null;
        }

        // Buscar telefone do cliente no WordPress user meta
        $phone = get_user_meta($customerId, 'billing_phone', true);

        if (empty($phone)) {
            return null;
        }

        // Normalizar telefone (remover espaços, caracteres especiais)
        return preg_replace('/[^0-9+]/', '', $phone);
    }

    /**
     * Formatar data
     *
     * @param string|null $date Data
     * @return string
     */
    private function formatDate(?string $date): string
    {
        if (!$date) {
            return '';
        }

        $dt = new \DateTime($date);
        return $dt->format('d/m/Y');
    }

    /**
     * Formatar hora
     *
     * @param string|null $time Hora
     * @return string
     */
    private function formatTime(?string $time): string
    {
        if (!$time) {
            return '';
        }

        $dt = new \DateTime($time);
        return $dt->format('H:i');
    }

    /**
     * Obter URL de feedback
     *
     * @param string|null $orderUuid UUID da order
     * @return string
     */
    private function getFeedbackUrl(?string $orderUuid): string
    {
        if (!$orderUuid) {
            return home_url('/feedback');
        }

        return home_url('/feedback?order=' . $orderUuid);
    }
}
