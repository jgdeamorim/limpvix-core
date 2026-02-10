<?php
/**
 * ContractExecution - Aggregate Root para Execuções de Contrato
 *
 * RESPONSABILIDADE:
 * - Representar uma execução específica de um contrato
 * - Gerenciar ciclo de vida: draft → scheduled → in_progress → completed/cancelled/no_show
 * - Validar transições de estado
 * - Disparar Domain Events
 *
 * REGRAS DE NEGÓCIO:
 * - Execução pertence a um contrato
 * - Profissional alocado deve executar o serviço
 * - Apenas execuções scheduled podem iniciar
 * - Apenas execuções in_progress podem ser completadas
 * - Execuções em estado final não podem mudar
 *
 * @package LimpVix\Domain\Execution
 * @since 0.9.0
 */

namespace LimpVix\Domain\Execution;

use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Execution\Events\ExecutionScheduled;
use LimpVix\Domain\Execution\Events\ExecutionStarted;
use LimpVix\Domain\Execution\Events\ExecutionCompleted;
use LimpVix\Domain\Execution\Events\ExecutionCancelled;
use LimpVix\Domain\Execution\Events\ExecutionNoShow;
use LimpVix\Domain\Execution\Events\ExecutionRescheduled;

defined('ABSPATH') || exit;

final class ContractExecution
{
    private ExecutionId $id;
    private ContractId $contractId;
    private int $professionalUserId;
    private \DateTimeImmutable $scheduledDate;
    private ?\DateTimeImmutable $startedAt;
    private ?\DateTimeImmutable $completedAt;
    private ExecutionStatus $status;
    private ?string $notes;
    private array $photos;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    /** @var array Domain Events */
    private array $events = [];

    private function __construct(
        ExecutionId $id,
        ContractId $contractId,
        int $professionalUserId,
        \DateTimeImmutable $scheduledDate,
        ExecutionStatus $status,
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $completedAt = null,
        ?string $notes = null,
        array $photos = [],
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null
    ) {
        $this->id = $id;
        $this->contractId = $contractId;
        $this->professionalUserId = $professionalUserId;
        $this->scheduledDate = $scheduledDate;
        $this->status = $status;
        $this->startedAt = $startedAt;
        $this->completedAt = $completedAt;
        $this->notes = $notes;
        $this->photos = $photos;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
    }

    // ============================================
    // Factory Methods
    // ============================================

    /**
     * Criar nova execução (estado: draft)
     *
     * @param ContractId $contractId
     * @param int $professionalUserId
     * @param \DateTimeImmutable $scheduledDate
     * @return self
     */
    public static function create(
        ContractId $contractId,
        int $professionalUserId,
        \DateTimeImmutable $scheduledDate
    ): self {
        return new self(
            ExecutionId::fromInt(0), // ID temporário, será definido após persistência
            $contractId,
            $professionalUserId,
            $scheduledDate,
            ExecutionStatus::draft()
        );
    }

    /**
     * Reconstituir execução a partir do banco
     *
     * @param array $data
     * @return self
     */
    public static function reconstitute(array $data): self
    {
        return new self(
            ExecutionId::fromInt((int) $data['id']),
            ContractId::fromInt((int) $data['contract_id']),
            (int) $data['professional_user_id'],
            new \DateTimeImmutable($data['scheduled_date']),
            ExecutionStatus::fromString($data['status']),
            isset($data['started_at']) ? new \DateTimeImmutable($data['started_at']) : null,
            isset($data['completed_at']) ? new \DateTimeImmutable($data['completed_at']) : null,
            $data['notes'] ?? null,
            !empty($data['photos']) ? json_decode($data['photos'], true) : [],
            new \DateTimeImmutable($data['created_at']),
            new \DateTimeImmutable($data['updated_at'])
        );
    }

    // ============================================
    // State Transitions
    // ============================================

    /**
     * Agendar execução (draft → scheduled)
     *
     * @param \DateTimeImmutable $scheduledDate
     * @return void
     * @throws \LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransition
     */
    public function scheduleExecution(\DateTimeImmutable $scheduledDate): void
    {
        $newStatus = ExecutionStatus::scheduled();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->scheduledDate = $scheduledDate;
        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ExecutionScheduled($this));
    }

    /**
     * Iniciar execução (scheduled → in_progress)
     *
     * @return void
     * @throws \LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransition
     */
    public function startExecution(): void
    {
        $newStatus = ExecutionStatus::inProgress();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->startedAt = new \DateTimeImmutable();
        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ExecutionStarted($this));
    }

    /**
     * Completar execução (in_progress → completed)
     *
     * @param string|null $notes
     * @param array $photos
     * @return void
     * @throws \LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransition
     */
    public function completeExecution(?string $notes = null, array $photos = []): void
    {
        $newStatus = ExecutionStatus::completed();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->completedAt = new \DateTimeImmutable();
        $this->notes = $notes;
        $this->photos = $photos;
        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ExecutionCompleted($this));
    }

    /**
     * Cancelar execução (* → cancelled)
     *
     * @param string $reason
     * @return void
     * @throws \LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransition
     */
    public function cancelExecution(string $reason): void
    {
        $newStatus = ExecutionStatus::cancelled();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->notes = $reason;
        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ExecutionCancelled($this, $reason));
    }

    /**
     * Marcar profissional como não comparecido (scheduled → no_show)
     *
     * @return void
     * @throws \LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransition
     */
    public function markNoShow(): void
    {
        $newStatus = ExecutionStatus::noShow();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ExecutionNoShow($this));
    }

    /**
     * Reagendar execução (scheduled → scheduled com nova data)
     *
     * @param \DateTimeImmutable $newScheduledDate
     * @return void
     * @throws \LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransition
     */
    public function rescheduleExecution(\DateTimeImmutable $newScheduledDate): void
    {
        // Apenas execuções scheduled podem ser reagendadas
        if (!$this->status->isScheduled()) {
            throw new \DomainException('Only scheduled executions can be rescheduled');
        }

        $oldDate = $this->scheduledDate;
        $this->scheduledDate = $newScheduledDate;
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ExecutionRescheduled($this, $oldDate, $newScheduledDate));
    }

    // ============================================
    // Getters
    // ============================================

    public function getId(): ExecutionId
    {
        return $this->id;
    }

    public function getContractId(): ContractId
    {
        return $this->contractId;
    }

    public function getProfessionalUserId(): int
    {
        return $this->professionalUserId;
    }

    public function getScheduledDate(): \DateTimeImmutable
    {
        return $this->scheduledDate;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getStatus(): ExecutionStatus
    {
        return $this->status;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getPhotos(): array
    {
        return $this->photos;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // ============================================
    // Setters (apenas para persistência)
    // ============================================

    /**
     * Definir ID após persistência
     *
     * IMPORTANTE: Usar apenas no Repository após insert
     *
     * @param ExecutionId $id
     * @return void
     */
    public function setId(ExecutionId $id): void
    {
        $this->id = $id;
    }

    // ============================================
    // Domain Events
    // ============================================

    /**
     * Registrar Domain Event
     *
     * @param object $event
     * @return void
     */
    private function recordEvent(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Liberar Domain Events (para EventDispatcher)
     *
     * @return array
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }
}
