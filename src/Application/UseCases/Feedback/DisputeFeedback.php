<?php
/**
 * DisputeFeedback - Use Case
 *
 * RESPONSABILIDADE:
 * - Permitir profissional contestar feedback
 * - Marcar feedback como disputed
 * - Disparar arbitragem manual
 *
 * @package LimpVix\Application\UseCases\Feedback
 * @since 0.3.0
 */

namespace LimpVix\Application\UseCases\Feedback;

use LimpVix\Domain\Feedback\FeedbackDisputedEvent;

defined('ABSPATH') || exit;

class DisputeFeedback
{
    private $feedbackRepository;
    private $eventDispatcher;

    public function __construct($feedbackRepository, $eventDispatcher)
    {
        $this->feedbackRepository = $feedbackRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Executar Use Case
     *
     * @param array $input Dados da disputa
     *   - feedback_uuid: string
     *   - professional_id: int
     *   - dispute_reason: string
     * @return array Resultado
     */
    public function execute(array $input): array
    {
        try {
            $this->validateInput($input);

            // Buscar feedback
            $feedback = $this->feedbackRepository->findByUuid($input['feedback_uuid']);

            if (!$feedback) {
                throw new \RuntimeException('Feedback não encontrado');
            }

            // Marcar como disputado
            $feedback->markAsDisputed();

            // Persistir
            $this->feedbackRepository->save($feedback);

            // Disparar evento
            $event = new FeedbackDisputedEvent(
                $feedback->getUuid(),
                $feedback->getOrderUuid(),
                $input['professional_id'],
                $input['dispute_reason']
            );

            $this->eventDispatcher->dispatch($event);

            return [
                'success' => true,
                'feedback_uuid' => $feedback->getUuid(),
                'status' => $feedback->getStatus(),
            ];
        } catch (\Exception $e) {
            error_log('[LimpVix] DisputeFeedback failed: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validar input
     *
     * @param array $input Input
     * @throws \InvalidArgumentException
     */
    private function validateInput(array $input): void
    {
        $required = ['feedback_uuid', 'professional_id', 'dispute_reason'];

        foreach ($required as $field) {
            if (!isset($input[$field])) {
                throw new \InvalidArgumentException("Campo '{$field}' é obrigatório");
            }
        }

        if (strlen($input['dispute_reason']) < 20) {
            throw new \InvalidArgumentException('Motivo da disputa deve ter no mínimo 20 caracteres');
        }
    }
}
