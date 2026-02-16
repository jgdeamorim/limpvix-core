<?php
/**
 * SendTemplatedMessage - Use Case
 *
 * Orquestrar envio de mensagem usando template versionado com retry automático
 *
 * @package LimpVix\Application\UseCases\Communication
 * @since 0.3.0
 */

namespace LimpVix\Application\UseCases\Communication;

use LimpVix\Domain\Communication\MessageTemplate;
use LimpVix\Domain\Communication\MessageDelivery;
use LimpVix\Domain\Communication\MessageSentEvent;
use LimpVix\Domain\Communication\MessageFailedEvent;
use LimpVix\Domain\Communication\RetryPolicy;

defined('ABSPATH') || exit;

class SendTemplatedMessage
{
    private $templateRepository;
    private $messageLogRepository;
    private $messageQueueService;
    private $communicationProvider;
    private $eventDispatcher;

    public function __construct(
        $templateRepository,
        $messageLogRepository,
        $messageQueueService,
        $communicationProvider,
        $eventDispatcher
    ) {
        $this->templateRepository = $templateRepository;
        $this->messageLogRepository = $messageLogRepository;
        $this->messageQueueService = $messageQueueService;
        $this->communicationProvider = $communicationProvider;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function execute(array $input): array
    {
        $this->validateInput($input);

        $templateId = $input['template_id'];
        $recipient = $input['recipient'];
        $variables = $input['variables'];
        $eventId = $input['event_id'] ?? null;
        $eventType = $input['event_type'] ?? null;

        try {
            $template = $this->templateRepository->findLatestActive($templateId);
            if (!$template) {
                throw new \RuntimeException("Template {$templateId} não encontrado");
            }

            $renderedContent = $template->render($variables);
            $messageId = $this->generateMessageId();
            $delivery = MessageDelivery::createPending($messageId, 3);

            $sendResult = $this->sendViaProvider(
                $template->getChannel(),
                $recipient,
                $renderedContent,
                $messageId
            );

            if ($sendResult['success']) {
                $delivery = $delivery->markAsSent();
                $this->messageLogRepository->save([
                    'message_id' => $messageId,
                    'template_id' => $template->getTemplateId(),
                    'template_version' => $template->getVersion(),
                    'recipient' => $recipient,
                    'channel' => $template->getChannel(),
                    'status' => 'sent',
                    'event_id' => $eventId,
                    'delivery' => $delivery->toArray(),
                ]);

                $this->eventDispatcher->dispatch(new MessageSentEvent(
                    $messageId,
                    $template->getTemplateId(),
                    $template->getVersion(),
                    $recipient,
                    $template->getChannel(),
                    $eventId,
                    $eventType
                ));

                return ['success' => true, 'message_id' => $messageId];
            } else {
                $errorType = $sendResult['error_type'] ?? 'unknown_error';
                $errorMessage = $sendResult['error_message'] ?? 'Erro desconhecido';
                $delivery = $delivery->markAsFailed($errorMessage);

                $this->messageLogRepository->save([
                    'message_id' => $messageId,
                    'template_id' => $template->getTemplateId(),
                    'status' => 'failed',
                    'error_type' => $errorType,
                    'delivery' => $delivery->toArray(),
                ]);

                $this->eventDispatcher->dispatch(new MessageFailedEvent(
                    $messageId,
                    $template->getTemplateId(),
                    $recipient,
                    $template->getChannel(),
                    $errorMessage,
                    $delivery->getRetryCount(),
                    $delivery->canRetry()
                ));

                if (RetryPolicy::shouldRetry($delivery, $errorType)) {
                    $nextRetryAt = RetryPolicy::getNextRetryAt($delivery->getRetryCount());
                    $this->messageQueueService->scheduleRetry([
                        'message_id' => $messageId,
                        'template_id' => $templateId,
                        'recipient' => $recipient,
                        'variables' => $variables,
                        'retry_count' => $delivery->getRetryCount() + 1,
                        'scheduled_at' => $nextRetryAt->format('Y-m-d H:i:s'),
                    ]);
                }

                return ['success' => false, 'message_id' => $messageId, 'will_retry' => $delivery->canRetry()];
            }
        } catch (\Exception $e) {
            error_log('[LimpVix] SendTemplatedMessage failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function validateInput(array $input): void
    {
        if (empty($input['template_id']) || empty($input['recipient']) || !is_array($input['variables'] ?? null)) {
            throw new \InvalidArgumentException('template_id, recipient, variables são obrigatórios');
        }
    }

    private function sendViaProvider(string $channel, string $recipient, string $content, string $messageId): array
    {
        try {
            return $this->communicationProvider->send([
                'channel' => $channel,
                'recipient' => $recipient,
                'content' => $content,
                'message_id' => $messageId,
            ]);
        } catch (\Exception $e) {
            return ['success' => false, 'error_type' => 'provider_error', 'error_message' => $e->getMessage()];
        }
    }

    private function generateMessageId(): string
    {
        return 'msg_' . uniqid() . '_' . bin2hex(random_bytes(8));
    }
}
