<?php

declare(strict_types=1);

namespace LimpVix\Domain\Professional;

use LimpVix\Domain\Professional\ValueObjects\DocumentType;
use LimpVix\Domain\Professional\ValueObjects\DocumentStatus;
use DateTimeImmutable;

/**
 * Professional Document Entity
 *
 * Representa um documento enviado por profissional para verificação KYC
 * Aggregate Root para documentos de profissionais
 */
final class ProfessionalDocument
{
    private ?int $id = null;
    private int $professionalId;
    private DocumentType $documentType;
    private string $filePath;
    private ?int $attachmentId;
    private ?string $mimeType;
    private ?int $fileSize;
    private ?string $originalFilename;
    private DocumentStatus $status;
    private ?int $reviewedBy;
    private ?DateTimeImmutable $reviewedAt;
    private ?string $rejectionReason;
    private ?DateTimeImmutable $expiresAt;
    private ?array $metadata;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    private function __construct(
        int $professionalId,
        DocumentType $documentType,
        string $filePath,
        ?int $attachmentId = null,
        ?string $mimeType = null,
        ?int $fileSize = null,
        ?string $originalFilename = null,
        ?DateTimeImmutable $expiresAt = null,
        ?array $metadata = null
    ) {
        $this->professionalId = $professionalId;
        $this->documentType = $documentType;
        $this->filePath = $filePath;
        $this->attachmentId = $attachmentId;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->originalFilename = $originalFilename;
        $this->status = DocumentStatus::pending();
        $this->reviewedBy = null;
        $this->reviewedAt = null;
        $this->rejectionReason = null;
        $this->expiresAt = $expiresAt;
        $this->metadata = $metadata;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Create new document upload
     */
    public static function create(
        int $professionalId,
        DocumentType $documentType,
        string $filePath,
        ?int $attachmentId = null,
        ?string $mimeType = null,
        ?int $fileSize = null,
        ?string $originalFilename = null,
        ?DateTimeImmutable $expiresAt = null,
        ?array $metadata = null
    ): self {
        return new self(
            $professionalId,
            $documentType,
            $filePath,
            $attachmentId,
            $mimeType,
            $fileSize,
            $originalFilename,
            $expiresAt,
            $metadata
        );
    }

    /**
     * Reconstitute from persistence
     */
    public static function reconstitute(array $data): self
    {
        $instance = new self(
            (int) $data['professional_id'],
            DocumentType::fromString($data['document_type']),
            $data['file_path'],
            isset($data['attachment_id']) ? (int) $data['attachment_id'] : null,
            $data['mime_type'] ?? null,
            isset($data['file_size']) ? (int) $data['file_size'] : null,
            $data['original_filename'] ?? null,
            isset($data['expires_at']) ? new DateTimeImmutable($data['expires_at']) : null,
            isset($data['metadata']) ? json_decode($data['metadata'], true) : null
        );

        $instance->id = isset($data['id']) ? (int) $data['id'] : null;
        $instance->status = DocumentStatus::fromString($data['status'] ?? 'pending');
        $instance->reviewedBy = isset($data['reviewed_by']) ? (int) $data['reviewed_by'] : null;
        $instance->reviewedAt = isset($data['reviewed_at']) ? new DateTimeImmutable($data['reviewed_at']) : null;
        $instance->rejectionReason = $data['rejection_reason'] ?? null;
        $instance->createdAt = new DateTimeImmutable($data['created_at'] ?? 'now');
        $instance->updatedAt = new DateTimeImmutable($data['updated_at'] ?? 'now');

        return $instance;
    }

    /**
     * Approve document
     */
    public function approve(int $reviewerId): void
    {
        if (!$this->status->canTransitionTo(DocumentStatus::approved())) {
            throw new \DomainException(
                "Cannot approve document with status {$this->status->getValue()}"
            );
        }

        $this->status = DocumentStatus::approved();
        $this->reviewedBy = $reviewerId;
        $this->reviewedAt = new DateTimeImmutable();
        $this->rejectionReason = null;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Reject document
     */
    public function reject(int $reviewerId, string $reason): void
    {
        if (!$this->status->canTransitionTo(DocumentStatus::rejected())) {
            throw new \DomainException(
                "Cannot reject document with status {$this->status->getValue()}"
            );
        }

        if (empty(trim($reason))) {
            throw new \InvalidArgumentException('Rejection reason is required');
        }

        $this->status = DocumentStatus::rejected();
        $this->reviewedBy = $reviewerId;
        $this->reviewedAt = new DateTimeImmutable();
        $this->rejectionReason = $reason;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Mark as expired (for certificates)
     */
    public function markAsExpired(): void
    {
        if (!$this->documentType->requiresExpiry()) {
            throw new \DomainException(
                "Document type {$this->documentType->getValue()} does not support expiry"
            );
        }

        if (!$this->status->canTransitionTo(DocumentStatus::expired())) {
            throw new \DomainException(
                "Cannot expire document with status {$this->status->getValue()}"
            );
        }

        $this->status = DocumentStatus::expired();
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Check if document is expired
     */
    public function isExpired(): bool
    {
        if ($this->status->isExpired()) {
            return true;
        }

        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new DateTimeImmutable();
    }

    /**
     * Check if document needs review
     */
    public function needsReview(): bool
    {
        return $this->status->isPending();
    }

    /**
     * Update metadata
     */
    public function updateMetadata(array $metadata): void
    {
        $this->metadata = array_merge($this->metadata ?? [], $metadata);
        $this->updatedAt = new DateTimeImmutable();
    }

    // Getters
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProfessionalId(): int
    {
        return $this->professionalId;
    }

    public function getDocumentType(): DocumentType
    {
        return $this->documentType;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getAttachmentId(): ?int
    {
        return $this->attachmentId;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function getReviewedBy(): ?int
    {
        return $this->reviewedBy;
    }

    public function getReviewedAt(): ?DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Convert to array for persistence
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'professional_id' => $this->professionalId,
            'document_type' => $this->documentType->getValue(),
            'file_path' => $this->filePath,
            'attachment_id' => $this->attachmentId,
            'mime_type' => $this->mimeType,
            'file_size' => $this->fileSize,
            'original_filename' => $this->originalFilename,
            'status' => $this->status->getValue(),
            'reviewed_by' => $this->reviewedBy,
            'reviewed_at' => $this->reviewedAt?->format('Y-m-d H:i:s'),
            'rejection_reason' => $this->rejectionReason,
            'expires_at' => $this->expiresAt?->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata ? json_encode($this->metadata) : null,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
