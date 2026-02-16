<?php
declare(strict_types=1);

/**
 * Execution - Aggregate Root com State Machine + Geo + SLA (Sprint 1 - Dia 2-3)
 *
 * RESPONSABILIDADE:
 * - Representar execução real de um serviço
 * - Garantir check-in/checkout obrigatórios
 * - Validar evidências
 * - Validar geofence (150m)
 * - Validar janela temporal (±60min)
 * - Rastrear SLA
 *
 * REGRAS CRÍTICAS (DIA 3):
 * ❌ checkout sem check-in
 * ❌ validate sem evidência
 * ❌ check-in fora da geofence (150m) → SLA violation
 * ❌ check-in fora da janela (±60min) → SLA violation
 *
 * @package LimpVix\Domain\Execution
 */

namespace LimpVix\Domain\Execution;

use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;
use LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransitionException;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;
use LimpVix\Domain\Execution\ValueObjects\TimeWindow;
use LimpVix\Domain\Execution\ValueObjects\SlaViolation;

defined('ABSPATH') || exit;

class Execution
{
    private const DEFAULT_GEOFENCE_RADIUS_METERS = 150;

    private string $executionUuid;
    private string $orderUuid;
    private int $professionalId;
    private ExecutionStatusEnum $status;
    
    // Scheduled data (for SLA validation)
    private ?\DateTimeImmutable $scheduledStartTime = null;
    private ?GeoLocation $serviceLocation = null;
    private int $geofenceRadiusMeters;
    
    // Check-in data
    private ?\DateTimeImmutable $checkInAt = null;
    private ?GeoLocation $checkInGeo = null;
    
    // Check-out data
    private ?\DateTimeImmutable $checkOutAt = null;
    private ?GeoLocation $checkOutGeo = null;
    private ?EvidenceCollection $evidence = null;
    
    // SLA tracking
    private array $slaViolations = [];

    // Feedback Window (GAP #1)
    private ?\DateTimeImmutable $feedbackWindowExpiresAt = null;

    // Issues (GAP #4)
    private ?ValueObjects\IssueCollection $issues = null;

    public function __construct(
        string $executionUuid,
        string $orderUuid,
        int $professionalId,
        ExecutionStatusEnum $status,
        ?\DateTimeImmutable $scheduledStartTime = null,
        ?GeoLocation $serviceLocation = null,
        int $geofenceRadiusMeters = self::DEFAULT_GEOFENCE_RADIUS_METERS,
        ?\DateTimeImmutable $checkInAt = null,
        ?GeoLocation $checkInGeo = null,
        ?\DateTimeImmutable $checkOutAt = null,
        ?GeoLocation $checkOutGeo = null,
        ?EvidenceCollection $evidence = null,
        array $slaViolations = [],
        ?\DateTimeImmutable $feedbackWindowExpiresAt = null
    ) {
        $this->executionUuid = $executionUuid;
        $this->orderUuid = $orderUuid;
        $this->professionalId = $professionalId;
        $this->status = $status;
        $this->scheduledStartTime = $scheduledStartTime;
        $this->serviceLocation = $serviceLocation;
        $this->geofenceRadiusMeters = $geofenceRadiusMeters;
        $this->checkInAt = $checkInAt;
        $this->checkInGeo = $checkInGeo;
        $this->checkOutAt = $checkOutAt;
        $this->checkOutGeo = $checkOutGeo;
        $this->evidence = $evidence;
        $this->slaViolations = $slaViolations;
        $this->feedbackWindowExpiresAt = $feedbackWindowExpiresAt;
    }

    /**
     * Factory: Criar Execution em estado inicial
     */
    public static function create(
        string $executionUuid,
        string $orderUuid,
        int $professionalId,
        \DateTimeImmutable $scheduledStartTime,
        GeoLocation $serviceLocation
    ): self {
        return new self(
            $executionUuid,
            $orderUuid,
            $professionalId,
            ExecutionStatusEnum::CREATED,
            $scheduledStartTime,
            $serviceLocation
        );
    }

    // ========================================
    // STATE MACHINE - MÉTODOS DE TRANSIÇÃO
    // ========================================

    /**
     * Realizar check-in (com geolocalização + validações)
     *
     * REGRA CRÍTICA (DIA 3):
     * - Valida geofence (150m)
     * - Valida time window (±60min)
     * - Se violar, marca SLA violation mas permite check-in
     */
    public function checkIn(GeoLocation $geo, ?TimeWindow $timeWindow = null): void
    {
        $this->guardTransition(ExecutionStatusEnum::CHECKED_IN);
        
        // Validar geofence
        if ($this->serviceLocation !== null) {
            $distance = $geo->distanceTo($this->serviceLocation);
            if ($distance > $this->geofenceRadiusMeters) {
                $this->slaViolations[] = SlaViolation::outOfGeofence($distance);
            }
        }
        
        // Validar time window
        if ($timeWindow !== null) {
            $now = new \DateTimeImmutable();
            if (!$timeWindow->isWithin($now)) {
                $delay = $timeWindow->calculateDelayMinutes($now);
                if ($delay > 0) {
                    $this->slaViolations[] = SlaViolation::lateCheckIn($delay);
                } else {
                    $this->slaViolations[] = SlaViolation::earlyCheckIn($delay);
                }
            }
        }
        
        $this->status = ExecutionStatusEnum::CHECKED_IN;
        $this->checkInAt = new \DateTimeImmutable();
        $this->checkInGeo = $geo;

        // GAP #3: Disparar evento de check-in para notificação ao cliente
        do_action('limpvix_execution_checked_in', $this->executionUuid, $this->orderId, $this->professionalId);
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
     * REGRA CRÍTICA (DIA 3): Evidência deve existir + SLA verificado
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

    // ========================================
    // ISSUE REPORTING (GAP #4)
    // ========================================

    /**
     * Report an issue during execution
     *
     * GAP #4: Customer or Professional can report problems
     *
     * @param string $type Issue type (quality, damage, missing_items, access, equipment, other)
     * @param string $description Problem description
     * @param string $reportedBy Who reported (customer, professional, admin)
     * @param int $reportedByUserId User ID of reporter
     * @param array $evidenceUrls URLs of photos/videos
     * @return void
     * @since GAP #4
     */
    public function reportIssue(
        string $type,
        string $description,
        string $reportedBy,
        int $reportedByUserId,
        array $evidenceUrls = []
    ): void {
        // Initialize issues collection if needed
        if ($this->issues === null) {
            $this->issues = ValueObjects\IssueCollection::empty();
        }

        // Create issue
        $issue = Issue::create($type, $description, $reportedBy, $reportedByUserId, $evidenceUrls);

        // Add to collection
        $this->issues->add($issue);

        // Dispatch event
        do_action('limpvix_execution_issue_reported', $this->executionUuid, $issue);
    }

    /**
     * Get all issues
     *
     * @return ValueObjects\IssueCollection
     */
    public function getIssues(): ValueObjects\IssueCollection
    {
        return $this->issues ?? ValueObjects\IssueCollection::empty();
    }

    /**
     * Check if execution has open issues
     *
     * @return bool
     */
    public function hasOpenIssues(): bool
    {
        return $this->getIssues()->hasOpenIssues();
    }

    // ========================================
    // FEEDBACK WINDOW (GAP #1)
    // ========================================

    /**
     * Start 24-hour feedback window
     *
     * Called after execution is VALIDATED to give customer
     * 24 hours to submit feedback before payout authorization
     *
     * @since GAP #1
     */
    public function startFeedbackWindow(): void
    {
        if ($this->feedbackWindowExpiresAt !== null) {
            // Already started, don't override
            return;
        }

        $now = new \DateTimeImmutable();
        $this->feedbackWindowExpiresAt = $now->modify('+24 hours');
    }

    /**
     * Check if feedback window is currently active (not expired)
     *
     * @return bool True if window is active and not yet expired
     * @since GAP #1
     */
    public function isFeedbackWindowActive(): bool
    {
        if ($this->feedbackWindowExpiresAt === null) {
            return false; // Window not started
        }

        $now = new \DateTimeImmutable();
        return $now < $this->feedbackWindowExpiresAt;
    }

    /**
     * Check if feedback window has expired
     *
     * @return bool True if window started and expired, false if not started or still active
     * @since GAP #1
     */
    public function isFeedbackWindowExpired(): bool
    {
        if ($this->feedbackWindowExpiresAt === null) {
            return false; // Window not started
        }

        $now = new \DateTimeImmutable();
        return $now >= $this->feedbackWindowExpiresAt;
    }

    /**
     * Get feedback window expiration time
     *
     * @return \DateTimeImmutable|null Expiration time or null if not started
     * @since GAP #1
     */
    public function getFeedbackWindowExpiresAt(): ?\DateTimeImmutable
    {
        return $this->feedbackWindowExpiresAt;
    }

    /**
     * Fechar execução (estado terminal)
     */
    public function close(): void
    {
        $this->guardTransition(ExecutionStatusEnum::CLOSED);
        $this->status = ExecutionStatusEnum::CLOSED;
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

    public function getScheduledStartTime(): ?\DateTimeImmutable
    {
        return $this->scheduledStartTime;
    }

    public function getServiceLocation(): ?GeoLocation
    {
        return $this->serviceLocation;
    }

    public function getGeofenceRadiusMeters(): int
    {
        return $this->geofenceRadiusMeters;
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

    /**
     * @return array<SlaViolation>
     */
    public function getSlaViolations(): array
    {
        return $this->slaViolations;
    }

    public function hasSlaViolations(): bool
    {
        return !empty($this->slaViolations);
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
