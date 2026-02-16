<?php
/**
 * MessageQueueService - Application Service
 *
 * RESPONSABILIDADE:
 * - Gerenciar queue de mensagens (retry scheduling)
 * - Processar mensagens pendentes
 * - Usar WP Cron para scheduling
 *
 * @package LimpVix\Application\Services\Communication
 * @since 0.3.0
 */

namespace LimpVix\Application\Services\Communication;

defined('ABSPATH') || exit;

class MessageQueueService
{
    private $queueRepository;

    public function __construct($queueRepository)
    {
        $this->queueRepository = $queueRepository;
    }

    /**
     * Agendar retry de mensagem
     *
     * @param array $messageData Dados da mensagem para retry
     * @return bool
     */
    public function scheduleRetry(array $messageData): bool
    {
        try {
            // Salvar na queue
            $queueId = $this->queueRepository->enqueue([
                'message_id' => $messageData['message_id'],
                'template_id' => $messageData['template_id'],
                'recipient' => $messageData['recipient'],
                'variables' => json_encode($messageData['variables']),
                'event_id' => $messageData['event_id'] ?? null,
                'event_type' => $messageData['event_type'] ?? null,
                'user_id' => $messageData['user_id'] ?? null,
                'retry_count' => $messageData['retry_count'],
                'scheduled_at' => $messageData['scheduled_at'],
                'status' => 'pending',
            ]);

            // Agendar WP Cron para processar
            $timestamp = strtotime($messageData['scheduled_at']);

            wp_schedule_single_event(
                $timestamp,
                'limpvix_process_message_queue',
                [$queueId]
            );

            return true;
        } catch (\Exception $e) {
            error_log('[LimpVix] Failed to schedule retry: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Processar item da queue
     *
     * @param int $queueId ID do item na queue
     * @return bool
     */
    public function processQueueItem(int $queueId): bool
    {
        $item = $this->queueRepository->findById($queueId);

        if (!$item || $item['status'] !== 'pending') {
            return false;
        }

        // Marcar como processing
        $this->queueRepository->updateStatus($queueId, 'processing');

        try {
            // Disparar evento para Use Case processar
            do_action('limpvix_retry_message', [
                'message_id' => $item['message_id'],
                'template_id' => $item['template_id'],
                'recipient' => $item['recipient'],
                'variables' => json_decode($item['variables'], true),
                'event_id' => $item['event_id'],
                'event_type' => $item['event_type'],
                'user_id' => $item['user_id'],
                'retry_count' => $item['retry_count'],
            ]);

            // Marcar como completed
            $this->queueRepository->updateStatus($queueId, 'completed');

            return true;
        } catch (\Exception $e) {
            error_log('[LimpVix] Failed to process queue item: ' . $e->getMessage());

            // Marcar como failed
            $this->queueRepository->updateStatus($queueId, 'failed');

            return false;
        }
    }

    /**
     * Obter mensagens pendentes para processamento
     *
     * @param int $limit Limite de itens
     * @return array
     */
    public function getPendingMessages(int $limit = 10): array
    {
        return $this->queueRepository->findPending($limit);
    }

    /**
     * Limpar itens antigos da queue (completed/failed > 30 dias)
     *
     * @return int Número de itens removidos
     */
    public function cleanOldItems(): int
    {
        return $this->queueRepository->deleteOlderThan(30);
    }
}
