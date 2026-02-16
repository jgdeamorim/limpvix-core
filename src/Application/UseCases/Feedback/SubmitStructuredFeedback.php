<?php
/**
 * SubmitStructuredFeedback - Use Case
 *
 * RESPONSABILIDADE:
 * - Orquestrar submissão de feedback estruturado
 * - Validar completude do checklist
 * - Persistir feedback
 * - Disparar eventos (FeedbackSubmittedEvent)
 *
 * FLUXO:
 * 1. Validar input
 * 2. Criar/recuperar StructuredFeedback
 * 3. Preencher checklist e fotos
 * 4. Submeter feedback
 * 5. Persistir
 * 6. Disparar evento
 *
 * @package LimpVix\Application\UseCases\Feedback
 * @since 0.3.0
 */

namespace LimpVix\Application\UseCases\Feedback;

use LimpVix\Domain\Feedback\StructuredFeedback;
use LimpVix\Domain\Feedback\FeedbackChecklist;
use LimpVix\Domain\Feedback\FeedbackCriteria;
use LimpVix\Domain\Feedback\FeedbackPhotos;
use LimpVix\Domain\Feedback\FeedbackSubmittedEvent;

defined('ABSPATH') || exit;

class SubmitStructuredFeedback
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
     * @param array $input Dados do feedback
     *   - order_uuid: string
     *   - customer_id: int
     *   - service_category: string (limpeza_basica, pos_obra, teto, esquadrias)
     *   - criteria: array [
     *       ['criteria_id' => 'limpeza_geral', 'score' => 5, 'observation' => '...'],
     *       ['criteria_id' => 'banheiro', 'score' => 4, 'observation' => null],
     *       ...
     *     ]
     *   - photos: array ['url1', 'url2', ...]
     *   - general_comment: string (opcional)
     * @return array Resultado
     */
    public function execute(array $input): array
    {
        try {
            $this->validateInput($input);

            // Verificar se já existe feedback para esta order
            $existingFeedback = $this->feedbackRepository->findByOrderUuid($input['order_uuid']);

            if ($existingFeedback && $existingFeedback->getStatus() !== 'draft') {
                throw new \RuntimeException('Feedback já foi submetido para esta order');
            }

            // Criar ou recuperar feedback
            if ($existingFeedback) {
                $feedback = $existingFeedback;
            } else {
                $feedback = StructuredFeedback::createDraft(
                    $this->generateUuid(),
                    $input['order_uuid'],
                    $input['customer_id'],
                    $input['service_category']
                );
            }

            // Construir checklist
            $checklist = $this->buildChecklist($input['criteria'], $input['service_category']);
            $feedback->fillChecklist($checklist);

            // Anexar fotos
            $photos = new FeedbackPhotos($input['photos']);
            $feedback->attachPhotos($photos);

            // Adicionar comentário geral (se fornecido)
            if (!empty($input['general_comment'])) {
                $feedback->addGeneralComment($input['general_comment']);
            }

            // Submeter feedback
            $feedback->submit();

            // Persistir
            $this->feedbackRepository->save($feedback);

            // Disparar evento
            $event = new FeedbackSubmittedEvent(
                $feedback->getUuid(),
                $feedback->getOrderUuid(),
                $feedback->getCustomerId(),
                $feedback->getFinalScore(),
                $feedback->isPositive()
            );

            $this->eventDispatcher->dispatch($event);

            return [
                'success' => true,
                'feedback_uuid' => $feedback->getUuid(),
                'final_score' => $feedback->getFinalScore(),
                'is_positive' => $feedback->isPositive(),
            ];
        } catch (\Exception $e) {
            error_log('[LimpVix] SubmitStructuredFeedback failed: ' . $e->getMessage());

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
        $required = ['order_uuid', 'customer_id', 'service_category', 'criteria', 'photos'];

        foreach ($required as $field) {
            if (!isset($input[$field])) {
                throw new \InvalidArgumentException("Campo '{$field}' é obrigatório");
            }
        }

        if (!is_array($input['criteria']) || empty($input['criteria'])) {
            throw new \InvalidArgumentException('Criteria deve ser um array não vazio');
        }

        if (!is_array($input['photos']) || count($input['photos']) < 2) {
            throw new \InvalidArgumentException('Mínimo 2 fotos obrigatórias');
        }
    }

    /**
     * Construir checklist a partir de array de critérios
     *
     * @param array $criteriaData Array de critérios
     * @param string $category Categoria do serviço
     * @return FeedbackChecklist
     */
    private function buildChecklist(array $criteriaData, string $category): FeedbackChecklist
    {
        $checklist = FeedbackChecklist::empty($category);

        foreach ($criteriaData as $criterionData) {
            $criterion = FeedbackCriteria::fromCategory(
                $criterionData['criteria_id'],
                $category,
                $criterionData['score'],
                $criterionData['observation'] ?? null
            );

            $checklist = $checklist->addCriteria($criterion);
        }

        return $checklist;
    }

    /**
     * Gerar UUID
     *
     * @return string
     */
    private function generateUuid(): string
    {
        return 'feedback_' . uniqid() . '_' . bin2hex(random_bytes(8));
    }
}
