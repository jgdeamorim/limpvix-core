<?php
/**
 * MessageQueueCronListener - Infrastructure Integration
 *
 * RESPONSABILIDADE:
 * - Registrar WP Cron hooks para processamento da queue
 * - Processar itens da queue quando WP Cron disparar
 * - Limpar itens antigos da queue (>30 dias)
 *
 * WP CRON HOOKS:
 * - limpvix_process_message_queue (disparado por scheduleRetry)
 * - limpvix_retry_message (disparado por processQueueItem)
 * - limpvix_clean_message_queue (diário, limpa itens antigos)
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 0.3.0
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Application\Services\Communication\MessageQueueService;
use LimpVix\Application\UseCases\Communication\SendTemplatedMessage;

defined('ABSPATH') || exit;

class MessageQueueCronListener
{
    private $queueService;
    private $sendTemplatedMessageUseCase;

    public function __construct(
        MessageQueueService $queueService,
        SendTemplatedMessage $sendTemplatedMessageUseCase
    ) {
        $this->queueService = $queueService;
        $this->sendTemplatedMessageUseCase = $sendTemplatedMessageUseCase;
    }

    /**
     * Registrar WP Cron hooks
     *
     * @return void
     */
    public function register(): void
    {
        // Hook para processar item específico da queue
        add_action('limpvix_process_message_queue', [$this, 'processQueueItem'], 10, 1);

        // Hook para retry de mensagem
        add_action('limpvix_retry_message', [$this, 'retryMessage'], 10, 1);

        // Hook para limpeza diária (registrar schedule se não existir)
        if (!wp_next_scheduled('limpvix_clean_message_queue')) {
            wp_schedule_event(time(), 'daily', 'limpvix_clean_message_queue');
        }

        add_action('limpvix_clean_message_queue', [$this, 'cleanOldItems'], 10, 0);
    }

    /**
     * Processar item da queue
     *
     * Disparado por WP Cron quando scheduleRetry é chamado
     *
     * @param int $queueId ID do item na queue
     * @return void
     */
    public function processQueueItem(int $queueId): void
    {
        try {
            $result = $this->queueService->processQueueItem($queueId);

            if ($result) {
                error_log("[LimpVix] Queue item {$queueId} processed successfully");
            } else {
                error_log("[LimpVix] Queue item {$queueId} processing failed");
            }
        } catch (\Exception $e) {
            error_log('[LimpVix] Error processing queue item: ' . $e->getMessage());
        }
    }

    /**
     * Retry de mensagem
     *
     * Disparado por processQueueItem via do_action('limpvix_retry_message')
     *
     * @param array $messageData Dados da mensagem
     * @return void
     */
    public function retryMessage(array $messageData): void
    {
        try {
            // Executar Use Case novamente
            $result = $this->sendTemplatedMessageUseCase->execute([
                'template_id' => $messageData['template_id'],
                'recipient' => $messageData['recipient'],
                'variables' => $messageData['variables'],
                'event_id' => $messageData['event_id'] ?? null,
                'event_type' => $messageData['event_type'] ?? null,
            ]);

            if ($result['success']) {
                error_log(sprintf(
                    '[LimpVix] Message retry successful: %s (attempt %d)',
                    $messageData['message_id'],
                    $messageData['retry_count']
                ));
            } else {
                error_log(sprintf(
                    '[LimpVix] Message retry failed: %s (attempt %d)',
                    $messageData['message_id'],
                    $messageData['retry_count']
                ));
            }
        } catch (\Exception $e) {
            error_log('[LimpVix] Error retrying message: ' . $e->getMessage());
        }
    }

    /**
     * Limpar itens antigos da queue
     *
     * Disparado diariamente por WP Cron
     *
     * @return void
     */
    public function cleanOldItems(): void
    {
        try {
            $deleted = $this->queueService->cleanOldItems();

            error_log("[LimpVix] Message queue cleanup: {$deleted} items removed");
        } catch (\Exception $e) {
            error_log('[LimpVix] Error cleaning message queue: ' . $e->getMessage());
        }
    }
}
