<?php
/**
 * Contract - Aggregate Root para Contratos Recorrentes
 *
 * RESPONSABILIDADE:
 * - Encapsular estado e comportamento de um contrato recorrente
 * - Garantir invariantes de negócio
 * - Orquestrar transições de status
 * - Emitir Domain Events para auditoria
 *
 * INVARIANTES:
 * - Status só pode transicionar conforme ContractStatus state machine
 * - Contrato cancelled/completed não pode voltar a ativo
 * - monthly_value deve ser sempre positivo
 * - start_date deve ser sempre no futuro (na criação)
 *
 * @package LimpVix\Domain\Contract
 * @since 0.8.0
 */

namespace LimpVix\Domain\Contract;

use LimpVix\Domain\Contract\ValueObjects\ContractStatus;
use LimpVix\Domain\Contract\Events\ContractCreated;
use LimpVix\Domain\Contract\Events\ContractActivated;
use LimpVix\Domain\Contract\Events\ContractPaused;
use LimpVix\Domain\Contract\Events\ContractResumed;
use LimpVix\Domain\Contract\Events\ContractCancelled;
use LimpVix\Domain\Contract\Events\ContractCompleted;
use LimpVix\Domain\Contract\Events\ContractExpired;
use LimpVix\Domain\Contract\Exceptions\InvalidContractTransition;

defined('ABSPATH') || exit;

class Contract
{
    private ContractId $id;
    private int $clientUserId;
    private string $contractNumber;
    private ContractStatus $status;
    private string $contractType; // 'weekly', 'biweekly', 'monthly'
    private int $recurrenceDay;
    private \DateTimeImmutable $startDate;
    private ?\DateTimeImmutable $endDate;
    private string $serviceCode;
    private string $propertyType;
    private float $monthlyValue;
    private ?int $allocatedProfessionalId;
    private bool $autoRenew;
    private ?\DateTimeImmutable $nextExecutionDate;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    /** @var array<DomainEvent> */
    private array $domainEvents = [];

    /**
     * Construtor privado - usar factories
     */
    private function __construct(
        ContractId $id,
        int $clientUserId,
        string $contractNumber,
        ContractStatus $status,
        string $contractType,
        int $recurrenceDay,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        string $serviceCode,
        string $propertyType,
        float $monthlyValue,
        ?int $allocatedProfessionalId,
        bool $autoRenew,
        ?\DateTimeImmutable $nextExecutionDate,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt
    ) {
        $this->id = $id;
        $this->clientUserId = $clientUserId;
        $this->contractNumber = $contractNumber;
        $this->status = $status;
        $this->contractType = $contractType;
        $this->recurrenceDay = $recurrenceDay;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->serviceCode = $serviceCode;
        $this->propertyType = $propertyType;
        $this->monthlyValue = $monthlyValue;
        $this->allocatedProfessionalId = $allocatedProfessionalId;
        $this->autoRenew = $autoRenew;
        $this->nextExecutionDate = $nextExecutionDate;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Factory: Criar novo contrato (status = draft)
     */
    public static function create(
        int $clientUserId,
        string $contractNumber,
        string $contractType,
        int $recurrenceDay,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        string $serviceCode,
        string $propertyType,
        float $monthlyValue,
        bool $autoRenew = true
    ): self {
        // Validações
        self::ensureValidContractNumber($contractNumber);
        self::ensureValidMonthlyValue($monthlyValue);
        self::ensureValidContractType($contractType);
        self::ensureValidRecurrenceDay($recurrenceDay, $contractType);

        $now = new \DateTimeImmutable();
        $id = ContractId::fromInt(0); // Será gerado pelo repository

        $contract = new self(
            $id,
            $clientUserId,
            $contractNumber,
            ContractStatus::draft(),
            $contractType,
            $recurrenceDay,
            $startDate,
            $endDate,
            $serviceCode,
            $propertyType,
            $monthlyValue,
            null, // Sem profissional alocado ainda
            $autoRenew,
            null, // Será calculado após ativação
            $now,
            $now
        );

        $contract->recordEvent(new ContractCreated($contract));

        return $contract;
    }

    /**
     * Factory: Reconstruir do banco de dados
     */
    public static function fromPersistence(
        int $id,
        int $clientUserId,
        string $contractNumber,
        string $statusString,
        string $contractType,
        int $recurrenceDay,
        string $startDate,
        ?string $endDate,
        string $serviceCode,
        string $propertyType,
        float $monthlyValue,
        ?int $allocatedProfessionalId,
        bool $autoRenew,
        ?string $nextExecutionDate,
        string $createdAt,
        string $updatedAt
    ): self {
        return new self(
            ContractId::fromInt($id),
            $clientUserId,
            $contractNumber,
            ContractStatus::fromString($statusString),
            $contractType,
            $recurrenceDay,
            new \DateTimeImmutable($startDate),
            $endDate ? new \DateTimeImmutable($endDate) : null,
            $serviceCode,
            $propertyType,
            $monthlyValue,
            $allocatedProfessionalId,
            $autoRenew,
            $nextExecutionDate ? new \DateTimeImmutable($nextExecutionDate) : null,
            new \DateTimeImmutable($createdAt),
            new \DateTimeImmutable($updatedAt)
        );
    }

    /**
     * Transição: DRAFT → PENDING_ALLOCATION
     */
    public function submitForAllocation(): void
    {
        $newStatus = ContractStatus::pendingAllocation();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Transição: PENDING_ALLOCATION → ACTIVE
     */
    public function activate(int $professionalId): void
    {
        $newStatus = ContractStatus::active();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->allocatedProfessionalId = $professionalId;
        $this->status = $newStatus;
        $this->nextExecutionDate = $this->calculateNextExecutionDate();
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ContractActivated($this, $professionalId));
    }

    /**
     * Transição: ACTIVE → PAUSED
     */
    public function pause(string $reason = ''): void
    {
        $newStatus = ContractStatus::paused();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->status = $newStatus;
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ContractPaused($this, $reason));
    }

    /**
     * Transição: PAUSED → ACTIVE
     */
    public function resume(): void
    {
        $newStatus = ContractStatus::active();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->status = $newStatus;
        $this->nextExecutionDate = $this->calculateNextExecutionDate();
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ContractResumed($this));
    }

    /**
     * Transição: * → CANCELLED (terminal)
     */
    public function cancel(string $reason = ''): void
    {
        $newStatus = ContractStatus::cancelled();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->status = $newStatus;
        $this->nextExecutionDate = null;
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ContractCancelled($this, $reason));
    }

    /**
     * Transição: ACTIVE → COMPLETED (terminal)
     */
    public function complete(): void
    {
        $newStatus = ContractStatus::completed();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->status = $newStatus;
        $this->nextExecutionDate = null;
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ContractCompleted($this));
    }

    /**
     * Transição: ACTIVE/PAUSED → EXPIRED (por expiração de end_date)
     */
    public function expire(): void
    {
        if ($this->status->isTerminal()) {
            return; // Já está em estado terminal
        }

        $newStatus = ContractStatus::expired();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->status = $newStatus;
        $this->nextExecutionDate = null;
        $this->updatedAt = new \DateTimeImmutable();

        $this->recordEvent(new ContractExpired($this));
    }

    /**
     * Renovar contrato (se auto_renew = true)
     *
     * Transição: COMPLETED/EXPIRED → ACTIVE (via state machine)
     */
    public function renew(\DateTimeImmutable $newEndDate): void
    {
        if (!$this->autoRenew) {
            throw new InvalidContractTransition(
                'Contract cannot be renewed: auto_renew is disabled'
            );
        }

        $newStatus = ContractStatus::active();
        $this->status->ensureCanTransitionTo($newStatus);

        $this->status = $newStatus;
        $this->endDate = $newEndDate;
        $this->nextExecutionDate = $this->calculateNextExecutionDate();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Renovar contrato com pagamento recorrente confirmado
     *
     * Diferente do renew() normal, este método:
     * - É chamado automaticamente após pagamento confirmado
     * - Requer pagamento completed (validação de segurança)
     * - Estende end_date do contrato ativo
     * - Dispara evento ContractRenewed para tracking
     *
     * @param \LimpVix\Domain\Finance\RecurringPayment $payment
     * @param \DateTimeImmutable $newEndDate
     * @return void
     * @throws InvalidContractTransition
     * @throws \DomainException
     */
    public function renewWithPayment($payment, \DateTimeImmutable $newEndDate): void
    {
        // Validate payment is completed
        if (!$payment->getStatus()->isCompleted()) {
            throw new \DomainException(
                sprintf(
                    'Cannot renew contract without completed payment (payment status: %s)',
                    $payment->getStatus()->getValue()
                )
            );
        }

        // Validate contract belongs to this payment
        if ($payment->getContractId() !== $this->id->toInt()) {
            throw new \DomainException(
                sprintf(
                    'Payment contract_id (%d) does not match Contract id (%d)',
                    $payment->getContractId(),
                    $this->id->toInt()
                )
            );
        }

        // Validate contract is active (auto-renew only works for active contracts)
        if (!$this->status->isActive()) {
            throw new InvalidContractTransition(
                sprintf(
                    'Cannot auto-renew contract: status is %s (expected: active)',
                    $this->status->getValue()
                )
            );
        }

        // Update end_date (extend contract period)
        $this->endDate = $newEndDate;

        // Recalculate next execution date
        $this->nextExecutionDate = $this->calculateNextExecutionDate();

        // Update timestamp
        $this->updatedAt = new \DateTimeImmutable();

        // Record domain event for tracking
        $this->recordEvent(new Events\ContractRenewed(
            $this,
            $payment,
            $this->updatedAt,
            $this->endDate
        ));

        error_log(sprintf(
            '[LimpVix] Contract %d auto-renewed via payment %s. New end_date: %s',
            $this->id->toInt(),
            $payment->getPaymentUuid(),
            $newEndDate->format('Y-m-d')
        ));
    }

    /**
     * Atualizar próxima execução (após execução concluída)
     */
    public function scheduleNextExecution(): void
    {
        if (!$this->status->isActive()) {
            throw new InvalidContractTransition(
                'Cannot schedule execution: contract is not active'
            );
        }

        $this->nextExecutionDate = $this->calculateNextExecutionDate();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Reallocate professional to this contract
     *
     * Allows admin to change the allocated professional while maintaining
     * contract integrity and audit trail.
     *
     * VALIDATIONS:
     * - Contract must be in active or paused status
     * - Contract must have a professional currently allocated
     * - New professional must be different from current one
     *
     * SIDE EFFECTS:
     * - Records ProfessionalReallocated domain event
     * - Updates updatedAt timestamp
     *
     * @param int $newProfessionalId New professional ID
     * @param string $reason Reason for reallocation
     * @param int|null $reallocatedBy User ID who initiated reallocation
     * @return void
     * @throws \DomainException if contract state doesn't allow reallocation
     */
    public function reallocateProfessional(
        int $newProfessionalId,
        string $reason,
        ?int $reallocatedBy = null
    ): void {
        // Validate contract allows reallocation
        if (!$this->status->isActive() && !$this->status->isPaused()) {
            throw new \DomainException(
                sprintf(
                    "Contract status '%s' does not allow reallocation (must be active or paused)",
                    $this->status->getValue()
                )
            );
        }

        if ($this->allocatedProfessionalId === null) {
            throw new \DomainException(
                'Contract has no professional allocated yet'
            );
        }

        if ($this->allocatedProfessionalId === $newProfessionalId) {
            throw new \DomainException(
                sprintf(
                    'New professional (ID: %d) is already allocated to this contract',
                    $newProfessionalId
                )
            );
        }

        // Store old professional for event
        $oldProfessionalId = $this->allocatedProfessionalId;

        // Update allocation
        $this->allocatedProfessionalId = $newProfessionalId;
        $this->updatedAt = new \DateTimeImmutable();

        // Record domain event
        $this->recordEvent(new Events\ProfessionalReallocated(
            $this->id,
            $oldProfessionalId,
            $newProfessionalId,
            $reason,
            $reallocatedBy
        ));
    }

    /**
     * Check if contract is currently allocated to a professional
     *
     * @return bool
     */
    public function isProfessionalAllocated(): bool
    {
        return $this->allocatedProfessionalId !== null;
    }

    /**
     * Check if contract can be reallocated
     *
     * @return bool
     */
    public function canBeReallocated(): bool
    {
        return $this->isProfessionalAllocated()
            && ($this->status->isActive() || $this->status->isPaused());
    }

    /**
     * Calcular próxima data de execução baseado em recorrência
     */
    private function calculateNextExecutionDate(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $baseDate = $this->nextExecutionDate ?? $now;

        switch ($this->contractType) {
            case 'weekly':
                return $baseDate->modify('+1 week');

            case 'biweekly':
                return $baseDate->modify('+2 weeks');

            case 'monthly':
                // Agendar para o dia específico do mês
                $nextMonth = $baseDate->modify('+1 month');
                $year = (int) $nextMonth->format('Y');
                $month = (int) $nextMonth->format('m');
                $day = min($this->recurrenceDay, (int) date('t', mktime(0, 0, 0, $month, 1, $year)));

                return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

            default:
                throw new \DomainException("Invalid contract type: {$this->contractType}");
        }
    }

    /**
     * Verificar se contrato pode ser modificado
     */
    public function canBeModified(): bool
    {
        return !$this->status->isTerminal();
    }

    /**
     * Verificar se está ativo
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Verificar se expirou (passou end_date)
     */
    public function hasExpired(): bool
    {
        if ($this->endDate === null) {
            return false;
        }

        $now = new \DateTimeImmutable();
        return $now > $this->endDate;
    }

    // ==================== GETTERS ====================

    public function getId(): ContractId
    {
        return $this->id;
    }

    public function getClientUserId(): int
    {
        return $this->clientUserId;
    }

    public function getContractNumber(): string
    {
        return $this->contractNumber;
    }

    public function getStatus(): ContractStatus
    {
        return $this->status;
    }

    public function getContractType(): string
    {
        return $this->contractType;
    }

    public function getRecurrenceDay(): int
    {
        return $this->recurrenceDay;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getServiceCode(): string
    {
        return $this->serviceCode;
    }

    public function getPropertyType(): string
    {
        return $this->propertyType;
    }

    public function getMonthlyValue(): float
    {
        return $this->monthlyValue;
    }

    public function getAllocatedProfessionalId(): ?int
    {
        return $this->allocatedProfessionalId;
    }

    public function isAutoRenew(): bool
    {
        return $this->autoRenew;
    }

    public function getNextExecutionDate(): ?\DateTimeImmutable
    {
        return $this->nextExecutionDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // ==================== DOMAIN EVENTS ====================

    private function recordEvent(object $event): void
    {
        $this->domainEvents[] = $event;
    }

    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    // ==================== VALIDAÇÕES ====================

    private static function ensureValidMonthlyValue(float $value): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException(
                'Monthly value must be positive'
            );
        }
    }

    private static function ensureValidContractType(string $type): void
    {
        $validTypes = ['weekly', 'biweekly', 'monthly'];
        if (!in_array($type, $validTypes, true)) {
            throw new \InvalidArgumentException(
                "Invalid contract type: {$type}. Must be one of: " . implode(', ', $validTypes)
            );
        }
    }

    private static function ensureValidRecurrenceDay(int $day, string $type): void
    {
        if ($type === 'monthly' && ($day < 1 || $day > 31)) {
            throw new \InvalidArgumentException(
                "Recurrence day must be between 1 and 31 for monthly contracts"
            );
        }
    }

    /**
     * Validar formato do contract_number
     *
     * Formato esperado: LMPVX-YYYYMM-NNNNNN
     * Exemplo: LMPVX-202602-000123
     *
     * @param string $contractNumber
     * @return void
     * @throws \InvalidArgumentException Se formato inválido
     */
    private static function ensureValidContractNumber(string $contractNumber): void
    {
        if (empty($contractNumber)) {
            throw new \InvalidArgumentException('Contract number cannot be empty');
        }

        // Importar validation do generator service se disponível
        // Caso contrário, validação inline
        $pattern = '/^LMPVX-20[0-9]{2}(0[1-9]|1[0-2])-[0-9]{6}(-R\\d+)?$/';

        if (preg_match($pattern, $contractNumber) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid contract_number format: {$contractNumber}. Expected format: LMPVX-YYYYMM-NNNNNN (ex: LMPVX-202602-000123)"
            );
        }
    }
}
