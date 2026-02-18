<?php
declare(strict_types=1);

namespace LimpVix\Domain\Feedback;

use LimpVix\Domain\Feedback\Events\FeedbackCreated;
use LimpVix\Domain\Feedback\Events\FeedbackApproved;
use LimpVix\Domain\Feedback\Events\FeedbackRejected;

defined('ABSPATH') || exit;

/**
 * Feedback Aggregate Root
 *
 * BUSINESS RULES:
 * - Only one feedback per order (enforced by DB UNIQUE constraint)
 * - Rating must be 1-5
 * - Rating < 3 automatically requires evidence validation
 * - Feedback can only be approved/rejected by admin
 * - Once approved, score calculation is triggered
 *
 * INTEGRATION POINTS:
 * - Payout hold logic (rating ≤ 2 holds payout)
 * - Professional score calculation
 * - Admin moderation queue
 *
 * Part of: Flow 4 - Feedback System
 *
 * @package LimpVix\Domain\Feedback
 */
final class Feedback
{
    private const MIN_RATING = 1;
    private const MAX_RATING = 5;
    private const EVIDENCE_REQUIRED_THRESHOLD = 3;

    private int $id;
    private int $orderId;
    private int $professionalId;
    private int $clientId;
    private int $rating;
    private ?string $comment;
    private bool $evidenceRequired;
    private bool $validatedByAdmin;
    private string $validationStatus; // pending, approved, rejected
    private ?int $validatedBy;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $validatedAt;

    // Resolution fields (added in Migration 030)
    private ?string $resolutionText = null;
    private ?string $resolutionSeverity = null; // grave|medio|leve
    private ?int $resolutionBy = null;
    private ?string $resolutionByName = null;
    private ?\DateTimeImmutable $resolutionAt = null;
    private ?float $scorePenalty = null;

    /** @var array Domain events */
    private array $events = [];

    private function __construct(
        int $id,
        int $orderId,
        int $professionalId,
        int $clientId,
        int $rating,
        ?string $comment,
        bool $evidenceRequired,
        bool $validatedByAdmin,
        string $validationStatus,
        ?int $validatedBy,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $validatedAt
    ) {
        $this->id = $id;
        $this->orderId = $orderId;
        $this->professionalId = $professionalId;
        $this->clientId = $clientId;
        $this->rating = $rating;
        $this->comment = $comment;
        $this->evidenceRequired = $evidenceRequired;
        $this->validatedByAdmin = $validatedByAdmin;
        $this->validationStatus = $validationStatus;
        $this->validatedBy = $validatedBy;
        $this->createdAt = $createdAt;
        $this->validatedAt = $validatedAt;
    }

    /**
     * Create new Feedback
     *
     * RULES:
     * - Rating must be 1-5
     * - Rating < 3 automatically sets evidenceRequired = true
     * - Initial status is 'pending'
     */
    public static function create(
        int $orderId,
        int $professionalId,
        int $clientId,
        int $rating,
        ?string $comment = null
    ): self {
        // Validate rating range
        if ($rating < self::MIN_RATING || $rating > self::MAX_RATING) {
            throw new \InvalidArgumentException(
                sprintf('Rating must be between %d and %d, got %d', self::MIN_RATING, self::MAX_RATING, $rating)
            );
        }

        // Rating < 3 requires evidence validation
        $evidenceRequired = $rating < self::EVIDENCE_REQUIRED_THRESHOLD;

        $feedback = new self(
            id: 0, // Will be set by repository after persist
            orderId: $orderId,
            professionalId: $professionalId,
            clientId: $clientId,
            rating: $rating,
            comment: $comment,
            evidenceRequired: $evidenceRequired,
            validatedByAdmin: false,
            validationStatus: 'pending',
            validatedBy: null,
            createdAt: new \DateTimeImmutable(),
            validatedAt: null
        );

        // Record domain event
        $feedback->recordEvent(new FeedbackCreated(
            $feedback->orderId,
            $feedback->professionalId,
            $feedback->clientId,
            $feedback->rating,
            $feedback->evidenceRequired
        ));

        return $feedback;
    }

    /**
     * Approve feedback (admin action)
     *
     * EFFECTS:
     * - Sets validatedByAdmin = true
     * - Sets validationStatus = 'approved'
     * - Records validated_at timestamp
     * - Triggers FeedbackApproved event (unlocks payout if rating ≤ 2)
     */
    public function approve(int $validatedBy): void
    {
        if ($this->validationStatus === 'approved') {
            throw new \DomainException('Feedback already approved');
        }

        $this->validatedByAdmin = true;
        $this->validationStatus = 'approved';
        $this->validatedBy = $validatedBy;
        $this->validatedAt = new \DateTimeImmutable();

        // Record domain event
        $this->recordEvent(new FeedbackApproved(
            $this->id,
            $this->orderId,
            $this->professionalId,
            $this->rating,
            $validatedBy
        ));
    }

    /**
     * Reject feedback (admin action)
     *
     * EFFECTS:
     * - Sets validation_status = 'rejected'
     * - Does NOT affect professional score
     * - Does NOT unlock payout
     */
    public function reject(int $validatedBy, string $reason): void
    {
        if ($this->validationStatus === 'rejected') {
            throw new \DomainException('Feedback already rejected');
        }

        $this->validationStatus = 'rejected';
        $this->validatedBy = $validatedBy;
        $this->validatedAt = new \DateTimeImmutable();

        // Record domain event
        $this->recordEvent(new FeedbackRejected(
            $this->id,
            $this->orderId,
            $this->professionalId,
            $validatedBy,
            $reason
        ));
    }

    /**
     * Resolve a blocking feedback (admin action before releasing payout).
     *
     * Records who resolved the issue, what was done, and the severity.
     * Severity determines the score_penalty applied during score calculation:
     *   - grave  → −1.50 pts on the individual rating
     *   - medio  → −0.75 pts on the individual rating
     *   - leve   → no penalty
     *
     * Internally calls approve() so blocksPayout() returns false afterwards.
     */
    public function resolve(
        int $resolvedBy,
        string $resolvedByName,
        string $resolutionText,
        string $severity
    ): void {
        if (!in_array($severity, ['grave', 'medio', 'leve'], true)) {
            throw new \InvalidArgumentException(
                "Gravidade inválida: '{$severity}'. Use: grave, medio, leve"
            );
        }

        if (empty(trim($resolutionText))) {
            throw new \InvalidArgumentException('O texto da resolução é obrigatório.');
        }

        $this->resolutionText     = $resolutionText;
        $this->resolutionSeverity = $severity;
        $this->resolutionBy       = $resolvedBy;
        $this->resolutionByName   = $resolvedByName ?: 'Admin';
        $this->resolutionAt       = new \DateTimeImmutable();
        $this->scorePenalty       = match($severity) {
            'grave' => 1.50,
            'medio' => 0.75,
            default => 0.00,
        };

        // Resolution implicitly approves the feedback → blocksPayout() returns false
        $this->approve($resolvedBy);
    }

    // Resolution getters
    public function getResolutionText(): ?string { return $this->resolutionText; }
    public function getResolutionSeverity(): ?string { return $this->resolutionSeverity; }
    public function getResolutionBy(): ?int { return $this->resolutionBy; }
    public function getResolutionByName(): ?string { return $this->resolutionByName; }
    public function getResolutionAt(): ?\DateTimeImmutable { return $this->resolutionAt; }
    public function getScorePenalty(): float { return $this->scorePenalty ?? 0.0; }

    /**
     * Check if feedback requires evidence validation
     */
    public function requiresEvidence(): bool
    {
        return $this->evidenceRequired;
    }

    /**
     * Check if feedback is approved
     */
    public function isApproved(): bool
    {
        return $this->validationStatus === 'approved';
    }

    /**
     * Check if feedback is pending
     */
    public function isPending(): bool
    {
        return $this->validationStatus === 'pending';
    }

    /**
     * Check if feedback is rejected
     */
    public function isRejected(): bool
    {
        return $this->validationStatus === 'rejected';
    }

    /**
     * Check if feedback blocks payout
     *
     * RULE: Rating ≤ 2 blocks payout until admin approval
     */
    public function blocksPayout(): bool
    {
        return $this->rating <= 2 && !$this->validatedByAdmin;
    }

    /**
     * Reconstitute Feedback from persistence
     */
    public static function reconstitute(array $data): self
    {
        $feedback = new self(
            id: (int) $data['id'],
            orderId: (int) $data['order_id'],
            professionalId: (int) $data['professional_id'],
            clientId: (int) $data['client_id'],
            rating: (int) $data['rating'],
            comment: $data['comment'],
            evidenceRequired: (bool) $data['evidence_required'],
            validatedByAdmin: (bool) $data['validated_by_admin'],
            validationStatus: $data['validation_status'],
            validatedBy: isset($data['validated_by']) ? (int) $data['validated_by'] : null,
            createdAt: new \DateTimeImmutable($data['created_at']),
            validatedAt: isset($data['validated_at']) ? new \DateTimeImmutable($data['validated_at']) : null
        );

        // Restore resolution fields if present (Migration 030)
        $feedback->resolutionText     = $data['resolution_text']     ?? null;
        $feedback->resolutionSeverity = $data['resolution_severity'] ?? null;
        $feedback->resolutionBy       = isset($data['resolution_by']) ? (int) $data['resolution_by'] : null;
        $feedback->resolutionByName   = $data['resolution_by_name']  ?? null;
        $feedback->resolutionAt       = isset($data['resolution_at']) ? new \DateTimeImmutable($data['resolution_at']) : null;
        $feedback->scorePenalty       = isset($data['score_penalty'])  ? (float) $data['score_penalty'] : null;

        return $feedback;
    }

    /**
     * Convert to array for persistence
     */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'order_id'            => $this->orderId,
            'professional_id'     => $this->professionalId,
            'client_id'           => $this->clientId,
            'rating'              => $this->rating,
            'comment'             => $this->comment,
            'evidence_required'   => $this->evidenceRequired,
            'validated_by_admin'  => $this->validatedByAdmin,
            'validation_status'   => $this->validationStatus,
            'validated_by'        => $this->validatedBy,
            'created_at'          => $this->createdAt->format('Y-m-d H:i:s'),
            'validated_at'        => $this->validatedAt?->format('Y-m-d H:i:s'),
            // Resolution fields (Migration 030)
            'resolution_text'     => $this->resolutionText,
            'resolution_severity' => $this->resolutionSeverity,
            'resolution_by'       => $this->resolutionBy,
            'resolution_by_name'  => $this->resolutionByName,
            'resolution_at'       => $this->resolutionAt?->format('Y-m-d H:i:s'),
            'score_penalty'       => $this->scorePenalty,
        ];
    }

    // Getters
    public function getId(): int { return $this->id; }
    public function getOrderId(): int { return $this->orderId; }
    public function getProfessionalId(): int { return $this->professionalId; }
    public function getClientId(): int { return $this->clientId; }
    public function getRating(): int { return $this->rating; }
    public function getComment(): ?string { return $this->comment; }
    public function isValidatedByAdmin(): bool { return $this->validatedByAdmin; }
    public function getValidationStatus(): string { return $this->validationStatus; }
    public function getValidatedBy(): ?int { return $this->validatedBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getValidatedAt(): ?\DateTimeImmutable { return $this->validatedAt; }

    /**
     * Record domain event
     */
    private function recordEvent(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Get and clear domain events
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    /**
     * Set ID (only used by repository after insert)
     */
    public function setId(int $id): void
    {
        if ($this->id !== 0) {
            throw new \DomainException('Cannot change ID of existing Feedback');
        }
        $this->id = $id;
    }
}
