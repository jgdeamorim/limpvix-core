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
 * REGRAS CRÍTICAS (DIA 3, refatorado P0.1):
 * ❌ checkout sem check-in
 * ❌ checkout sem evidência (checkout = validação)
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

    // Expected rooms count (P1.4 - from Briefing PropertyStructure)
    private int $expectedRoomsCount = 0;

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
        ?\DateTimeImmutable $feedbackWindowExpiresAt = null,
        int $expectedRoomsCount = 0
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
        $this->expectedRoomsCount = $expectedRoomsCount;
    }

    /**
     * Factory: Criar Execution em estado inicial
     */
    public static function create(
        string $executionUuid,
        string $orderUuid,
        int $professionalId,
        \DateTimeImmutable $scheduledStartTime,
        GeoLocation $serviceLocation,
        int $expectedRoomsCount = 0
    ): self {
        return new self(
            $executionUuid,
            $orderUuid,
            $professionalId,
            ExecutionStatusEnum::CREATED,
            $scheduledStartTime,
            $serviceLocation,
            self::DEFAULT_GEOFENCE_RADIUS_METERS,
            null, null, null, null, null, [],
            null,
            $expectedRoomsCount
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
        do_action('limpvix_execution_checked_in', $this->executionUuid, $this->orderUuid, $this->professionalId);
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
     * P0.1: Check-out com evidência = validação do serviço.
     * Absorveu a lógica do antigo validate(). Após checkout:
     * - Evidência é obrigatória e salva
     * - Feedback window de 24h é iniciada automaticamente
     * - Estado CHECKED_OUT = serviço validado (pronto para payout)
     *
     * REGRA CRÍTICA: Evidência obrigatória + check-in deve ter sido feito
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

        // P0.1: Validação de evidência absorvida do antigo validate()
        if ($evidence->isEmpty()) {
            throw InvalidExecutionTransitionException::evidenceRequired(
                $this->status,
                ExecutionStatusEnum::CHECKED_OUT
            );
        }

        $this->guardTransition(ExecutionStatusEnum::CHECKED_OUT);

        $this->status = ExecutionStatusEnum::CHECKED_OUT;
        $this->checkOutAt = new \DateTimeImmutable();
        $this->checkOutGeo = $geo;
        $this->evidence = $evidence;

        // P1.4: Validate room evidence count against expected rooms
        if ($this->expectedRoomsCount > 0) {
            $actualRooms = $evidence->countUniqueRooms('check_out');
            if ($actualRooms < $this->expectedRoomsCount) {
                error_log(sprintf(
                    '[LimpVix] WARN: Execution %s checkout with %d/%d room photos',
                    $this->executionUuid, $actualRooms, $this->expectedRoomsCount
                ));
            }
        }

        // P0.1: Iniciar feedback window automaticamente após check-out
        $this->startFeedbackWindow();

        // Disparar evento de check-out/validação
        do_action('limpvix_execution_checked_out', $this->executionUuid, $this->orderUuid, $this->professionalId);
    }

    /**
     * Check if execution service is completed and ready for payout
     *
     * P0.1: Substitui canBeValidated(). Verifica se o check-out foi
     * realizado com evidência (que É a validação).
     *
     * @return bool True if service completed with evidence
     */
    public function isServiceCompleted(): bool
    {
        return $this->status->isServiceCompleted()
            && $this->evidence !== null
            && !$this->evidence->isEmpty()
            && $this->checkInAt !== null
            && $this->checkOutAt !== null;
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
     * Called automatically after check-out (P0.1: checkout = validation)
     * to give customer 24 hours to submit feedback before payout authorization
     *
     * @since GAP #1, updated P0.1
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
            // P0.1: CHECKED_OUT → CLOSED (direto, sem VALIDATED intermediário)
            ExecutionStatusEnum::CHECKED_OUT => [
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

    public function getExpectedRoomsCount(): int
    {
        return $this->expectedRoomsCount;
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
