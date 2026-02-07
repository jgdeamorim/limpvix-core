<?php
/**
 * StructuredFeedback - Aggregate Root
 *
 * RESPONSABILIDADE:
 * - Representar feedback estruturado completo
 * - Validar completude antes de submeter
 * - Calcular score final
 * - Gerenciar estado (draft → submitted → validated)
 *
 * REGRAS DE NEGÓCIO:
 * - Checklist deve estar completo
 * - Mínimo 2 fotos obrigatórias
 * - Comentário geral opcional
 * - Uma vez submitted, não pode ser editado
 *
 * @package LimpVix\Domain\Feedback
 * @since 0.3.0
 */

namespace LimpVix\Domain\Feedback;

defined('ABSPATH') || exit;

class StructuredFeedback
{
    private string $uuid;
    private string $orderUuid;
    private int $customerId;
    private string $serviceCategory;

    private FeedbackChecklist $checklist;
    private FeedbackPhotos $photos;
    private ?string $generalComment;

    private string $status; // draft|submitted|validated|disputed
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $submittedAt;

    /**
     * Criar novo feedback (draft)
     *
     * @param string $uuid UUID
     * @param string $orderUuid UUID da order
     * @param int $customerId ID do cliente
     * @param string $serviceCategory Categoria do serviço
     * @return self
     */
    public static function createDraft(
        string $uuid,
        string $orderUuid,
        int $customerId,
        string $serviceCategory
    ): self {
        $feedback = new self();
        $feedback->uuid = $uuid;
        $feedback->orderUuid = $orderUuid;
        $feedback->customerId = $customerId;
        $feedback->serviceCategory = $serviceCategory;
        $feedback->checklist = FeedbackChecklist::empty($serviceCategory);
        $feedback->status = 'draft';
        $feedback->createdAt = new \DateTimeImmutable();
        $feedback->submittedAt = null;
        $feedback->generalComment = null;

        return $feedback;
    }

    /**
     * Preencher checklist
     *
     * @param FeedbackChecklist $checklist Checklist
     * @throws \RuntimeException Se já foi submetido
     */
    public function fillChecklist(FeedbackChecklist $checklist): void
    {
        $this->ensureIsDraft();
        $this->checklist = $checklist;
    }

    /**
     * Anexar fotos
     *
     * @param FeedbackPhotos $photos Fotos
     * @throws \RuntimeException Se já foi submetido
     */
    public function attachPhotos(FeedbackPhotos $photos): void
    {
        $this->ensureIsDraft();
        $this->photos = $photos;
    }

    /**
     * Adicionar comentário geral
     *
     * @param string $comment Comentário
     * @throws \RuntimeException Se já foi submetido
     */
    public function addGeneralComment(string $comment): void
    {
        $this->ensureIsDraft();
        $this->generalComment = $comment;
    }

    /**
     * Submeter feedback
     *
     * @throws \RuntimeException Se feedback não está completo
     */
    public function submit(): void
    {
        $this->ensureIsDraft();

        // Validar completude
        if (!$this->isReadyToSubmit()) {
            throw new \RuntimeException('Feedback não está completo para submissão');
        }

        $this->status = 'submitted';
        $this->submittedAt = new \DateTimeImmutable();
    }

    /**
     * Marcar como validado
     *
     * Usado quando admin valida o feedback
     */
    public function markAsValidated(): void
    {
        if ($this->status !== 'submitted') {
            throw new \RuntimeException('Feedback deve estar submitted para ser validado');
        }

        $this->status = 'validated';
    }

    /**
     * Marcar como disputado
     *
     * Usado quando profissional contesta o feedback
     */
    public function markAsDisputed(): void
    {
        if ($this->status !== 'submitted') {
            throw new \RuntimeException('Feedback deve estar submitted para ser disputado');
        }

        $this->status = 'disputed';
    }

    /**
     * Verificar se está pronto para submeter
     *
     * @return bool
     */
    public function isReadyToSubmit(): bool
    {
        // Checklist deve estar completo
        if (!$this->checklist->isComplete()) {
            return false;
        }

        // Deve ter fotos
        if (!isset($this->photos) || !$this->photos->hasMinimumPhotos()) {
            return false;
        }

        return true;
    }

    /**
     * Calcular score final (média do checklist)
     *
     * @return float
     */
    public function getFinalScore(): float
    {
        return $this->checklist->getAverageScore();
    }

    /**
     * Verificar se feedback é positivo (score >= 3.0)
     *
     * @return bool
     */
    public function isPositive(): bool
    {
        return $this->checklist->isApproved();
    }

    /**
     * Obter problemas detectados
     *
     * Retorna critérios com score < 3
     *
     * @return array
     */
    public function getDetectedIssues(): array
    {
        return array_map(
            fn($c) => [
                'criteria' => $c->getLabel(),
                'score' => $c->getScore(),
                'observation' => $c->getObservation(),
            ],
            $this->checklist->getFailedCriteria()
        );
    }

    /**
     * Garantir que está em draft
     *
     * @throws \RuntimeException
     */
    private function ensureIsDraft(): void
    {
        if ($this->status !== 'draft') {
            throw new \RuntimeException('Feedback já foi submetido e não pode ser editado');
        }
    }

    // Getters
    public function getUuid(): string { return $this->uuid; }
    public function getOrderUuid(): string { return $this->orderUuid; }
    public function getCustomerId(): int { return $this->customerId; }
    public function getServiceCategory(): string { return $this->serviceCategory; }
    public function getChecklist(): FeedbackChecklist { return $this->checklist; }
    public function getPhotos(): ?FeedbackPhotos { return $this->photos ?? null; }
    public function getGeneralComment(): ?string { return $this->generalComment; }
    public function getStatus(): string { return $this->status; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'order_uuid' => $this->orderUuid,
            'customer_id' => $this->customerId,
            'service_category' => $this->serviceCategory,
            'checklist' => $this->checklist->toArray(),
            'photos' => $this->photos ? $this->photos->toArray() : [],
            'general_comment' => $this->generalComment,
            'status' => $this->status,
            'final_score' => $this->getFinalScore(),
            'is_positive' => $this->isPositive(),
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'submitted_at' => $this->submittedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
