<?php
declare(strict_types=1);

/**
 * Execution - Aggregate Root com State Machine (Sprint 1 - Dia 2)
 *
 * RESPONSABILIDADE:
 * - Representar execução real de um serviço
 * - Garantir check-in/checkout obrigatórios
 * - Validar evidências
 * - Rastrear SLA
 *
 * PRINCÍPIOS:
 * - Entity (tem identidade - UUID)
 * - State Machine formal (ExecutionStatusEnum)
 * - Transições explícitas e validadas
 * - Estados terminais imutáveis
 * - Evidência obrigatória
 *
 * BREAKING CHANGE (Sprint 1):
 * - Execution é fonte de verdade (não Booknetic)
 * - Check-in/out obrigatórios
 * - Evidência obrigatória no checkout
 *
 * REGRAS CRÍTICAS (DIA 2):
 * ❌ checkout sem check-in
 * ❌ validate sem evidência
 * ❌ qualquer transição após CLOSED
 *
 * @package LimpVix\Domain\Execution
 */

namespace LimpVix\Domain\Execution;

use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;
use LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransitionException;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;

defined('ABSPATH') || exit;

class Execution
{
    private string $executionUuid;
    private string $orderUuid;
    private int $professionalId;
    private ExecutionStatusEnum $status;
    
    // Check-in data
    private ?\DateTimeImmutable $checkInAt = null;
    private ?GeoLocation $checkInGeo = null;
    
    // Check-out data
    private ?\DateTimeImmutable $checkOutAt = null;
    private ?GeoLocation $checkOutGeo = null;
    private ?EvidenceCollection $evidence = null;
    
    // SLA tracking
    private ?string $slaStatus = null;

    public function __construct(
        string $executionUuid,
        string $orderUuid,
        int $professionalId,
        ExecutionStatusEnum $status,
        ?\DateTimeImmutable $checkInAt = null,
        ?GeoLocation $checkInGeo = null,
        ?\DateTimeImmutable $checkOutAt = null,
        ?GeoLocation $checkOutGeo = null,
        ?EvidenceCollection $evidence = null,
        ?string $slaStatus = null
    ) {
        $this->executionUuid = $executionUuid;
        $this->orderUuid = $orderUuid;
        $this->professionalId = $professionalId;
        $this->status = $status;
        $this->checkInAt = $checkInAt;
        $this->checkInGeo = $checkInGeo;
        $this->checkOutAt = $checkOutAt;
        $this->checkOutGeo = $checkOutGeo;
        $this->evidence = $evidence;
        $this->slaStatus = $slaStatus;
    }

    /**
     * Factory: Criar Execution em estado inicial
     */
    public static function create(
        string $executionUuid,
        string $orderUuid,
        int $professionalId
    ): self {
        return new self(
            $executionUuid,
            $orderUuid,
            $professionalId,
            ExecutionStatusEnum::CREATED
        );
    }

    // ========================================
    // STATE MACHINE - MÉTODOS DE TRANSIÇÃO
    // ========================================

    /**
     * Realizar check-in (com geolocalização)
     *
     * REGRA: Primeiro passo obrigatório
     */
    public function checkIn(GeoLocation $geo): void
    {
        $this->guardTransition(ExecutionStatusEnum::CHECKED_IN);
        
        $this->status = ExecutionStatusEnum::CHECKED_IN;
        $this->checkInAt = new \DateTimeImmutable();
        $this->checkInGeo = $geo;
    }

    /**
     * Iniciar execução (após check-in)
     */
    public function startExecution(): void
    {
        $this->guardTransition(ExecutionStatusEnum::IN_EXECUTION);
        $this->status = ExecutionStatusEnum::IN_EXECUTION;
    }

    /**
     * Realizar check-out (com geolocalização e evidências)
     *
     * REGRA CRÍTICA: Evidência obrigatória
     */
    public function checkOut(GeoLocation $geo, EvidenceCollection $evidence): void
    {
        // Validação crítica: check-in deve ter sido feito
        if (!$this->status->isCheckedIn()) {
            throw InvalidExecutionTransitionException::checkInRequired(
                $this->status,
                ExecutionStatusEnum::CHECKED_OUT
            );
        }

        $this->guardTransition(ExecutionStatusEnum::CHECKED_OUT);
        
        $this->status = ExecutionStatusEnum::CHECKED_OUT;
        $this->checkOutAt = new \DateTimeImmutable();
        $this->checkOutGeo = $geo;
        $this->evidence = $evidence;
    }

    /**
     * Validar execução
     *
     * REGRA CRÍTICA: Evidência deve existir
     */
    public function validate(): void
    {
        // Validação crítica: evidência deve estar presente
        if ($this->evidence === null) {
            throw InvalidExecutionTransitionException::evidenceRequired(
                $this->status,
                ExecutionStatusEnum::VALIDATED
            );
        }

        $this->guardTransition(ExecutionStatusEnum::VALIDATED);
        $this->status = ExecutionStatusEnum::VALIDATED;
    }

    /**
     * Fechar execução (estado terminal)
     */
    public function close(): void
    {
        $this->guardTransition(ExecutionStatusEnum::CLOSED);
        $this->status = ExecutionStatusEnum::CLOSED;
    }

    /**
     * Marcar violação de SLA
     */
    public function markSlaViolation(): void
    {
        $this->slaStatus = 'VIOLATED';
    }

    // ========================================
    // GUARD TRANSITION (STATE MACHINE CORE)
    // ========================================

    /**
     * Valida se transição é permitida
     */
    private function guardTransition(ExecutionStatusEnum $to): void
    {
        $from = $this->status;

        // Estados terminais não permitem transições
        if ($from->isTerminal()) {
            throw InvalidExecutionTransitionException::terminalState($from);
        }

        // Validar transições permitidas
        $allowed = $this->getAllowedTransitions();

        if (!in_array($to, $allowed, true)) {
            throw InvalidExecutionTransitionException::forbidden(
                $from,
                $to,
                'Transition not allowed by State Machine rules'
            );
        }
    }

    /**
     * Retorna transições permitidas a partir do estado atual
     */
    private function getAllowedTransitions(): array
    {
        return match ($this->status) {
            ExecutionStatusEnum::CREATED => [
                ExecutionStatusEnum::CHECKED_IN,
            ],
            ExecutionStatusEnum::CHECKED_IN => [
                ExecutionStatusEnum::IN_EXECUTION,
            ],
            ExecutionStatusEnum::IN_EXECUTION => [
                ExecutionStatusEnum::CHECKED_OUT,
            ],
            ExecutionStatusEnum::CHECKED_OUT => [
                ExecutionStatusEnum::VALIDATED,
            ],
            ExecutionStatusEnum::VALIDATED => [
                ExecutionStatusEnum::CLOSED,
            ],
            ExecutionStatusEnum::CLOSED => [],
        };
    }

    // ========================================
    // GETTERS
    // ========================================

    public function getExecutionUuid(): string
    {
        return $this->executionUuid;
    }

    public function getOrderUuid(): string
    {
        return $this->orderUuid;
    }

    public function getProfessionalId(): int
    {
        return $this->professionalId;
    }

    public function getStatus(): ExecutionStatusEnum
    {
        return $this->status;
    }

    public function getCheckInAt(): ?\DateTimeImmutable
    {
        return $this->checkInAt;
    }

    public function getCheckInGeo(): ?GeoLocation
    {
        return $this->checkInGeo;
    }

    public function getCheckOutAt(): ?\DateTimeImmutable
    {
        return $this->checkOutAt;
    }

    public function getCheckOutGeo(): ?GeoLocation
    {
        return $this->checkOutGeo;
    }

    public function getEvidence(): ?EvidenceCollection
    {
        return $this->evidence;
    }

    public function getSlaStatus(): ?string
    {
        return $this->slaStatus;
    }

    public function hasEvidence(): bool
    {
        return $this->evidence !== null;
    }

    public function equals(Execution $other): bool
    {
        return $this->executionUuid === $other->executionUuid;
    }

    /**
     * Calcula duração da execução (se completada)
     *
     * @return int|null Duração em minutos
     */
    public function getDurationMinutes(): ?int
    {
        if ($this->checkInAt === null || $this->checkOutAt === null) {
            return null;
        }

        $diff = $this->checkOutAt->getTimestamp() - $this->checkInAt->getTimestamp();
        return (int) round($diff / 60);
    }
}
