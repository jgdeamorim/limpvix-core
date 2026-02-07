<?php
/**
 * MessageDelivery - Value Object
 *
 * RESPONSABILIDADE:
 * - Representar estado de entrega de mensagem
 * - Rastrear timestamps (sent, delivered, read, failed)
 * - Controlar retry attempts
 *
 * PRINCÍPIOS:
 * - Value Object (imutável após criação)
 * - Status progression: pending → sent → delivered → read
 * - Failure tracking com retry count
 *
 * @package LimpVix\Domain\Communication
 * @since 0.3.0
 */

namespace LimpVix\Domain\Communication;

defined('ABSPATH') || exit;

class MessageDelivery
{
    // Status possíveis
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_READ = 'read';
    const STATUS_FAILED = 'failed';

    private $messageId;
    private $status;
    private $retryCount;
    private $maxRetries;
    private $sentAt;
    private $deliveredAt;
    private $readAt;
    private $failedAt;
    private $failureReason;
    private $createdAt;

    public function __construct(
        string $messageId,
        string $status = self::STATUS_PENDING,
        int $retryCount = 0,
        int $maxRetries = 3,
        ?\DateTimeImmutable $sentAt = null,
        ?\DateTimeImmutable $deliveredAt = null,
        ?\DateTimeImmutable $readAt = null,
        ?\DateTimeImmutable $failedAt = null,
        ?string $failureReason = null,
        ?\DateTimeImmutable $createdAt = null
    ) {
        if (empty($messageId)) {
            throw new \InvalidArgumentException('Message ID não pode ser vazio');
        }

        if (!in_array($status, [
            self::STATUS_PENDING,
            self::STATUS_SENT,
            self::STATUS_DELIVERED,
            self::STATUS_READ,
            self::STATUS_FAILED
        ], true)) {
            throw new \InvalidArgumentException("Status inválido: {$status}");
        }

        if ($retryCount < 0) {
            throw new \InvalidArgumentException('Retry count não pode ser negativo');
        }

        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('Max retries não pode ser negativo');
        }

        $this->messageId = $messageId;
        $this->status = $status;
        $this->retryCount = $retryCount;
        $this->maxRetries = $maxRetries;
        $this->sentAt = $sentAt;
        $this->deliveredAt = $deliveredAt;
        $this->readAt = $readAt;
        $this->failedAt = $failedAt;
        $this->failureReason = $failureReason;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    /**
     * Criar novo delivery (status pending)
     *
     * @param string $messageId
     * @param int $maxRetries
     * @return self
     */
    public static function createPending(string $messageId, int $maxRetries = 3): self
    {
        return new self(
            $messageId,
            self::STATUS_PENDING,
            0,
            $maxRetries,
            null,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable()
        );
    }

    /**
     * Marcar como enviado
     *
     * @return self Nova instância (imutável)
     */
    public function markAsSent(): self
    {
        return new self(
            $this->messageId,
            self::STATUS_SENT,
            $this->retryCount,
            $this->maxRetries,
            new \DateTimeImmutable(),
            $this->deliveredAt,
            $this->readAt,
            null, // Clear failed_at
            null, // Clear failure_reason
            $this->createdAt
        );
    }

    /**
     * Marcar como entregue
     *
     * @return self
     */
    public function markAsDelivered(): self
    {
        return new self(
            $this->messageId,
            self::STATUS_DELIVERED,
            $this->retryCount,
            $this->maxRetries,
            $this->sentAt,
            new \DateTimeImmutable(),
            $this->readAt,
            null,
            null,
            $this->createdAt
        );
    }

    /**
     * Marcar como lido
     *
     * @return self
     */
    public function markAsRead(): self
    {
        return new self(
            $this->messageId,
            self::STATUS_READ,
            $this->retryCount,
            $this->maxRetries,
            $this->sentAt,
            $this->deliveredAt,
            new \DateTimeImmutable(),
            null,
            null,
            $this->createdAt
        );
    }

    /**
     * Marcar como falho
     *
     * @param string $reason Razão da falha
     * @return self
     */
    public function markAsFailed(string $reason): self
    {
        return new self(
            $this->messageId,
            self::STATUS_FAILED,
            $this->retryCount,
            $this->maxRetries,
            $this->sentAt,
            $this->deliveredAt,
            $this->readAt,
            new \DateTimeImmutable(),
            $reason,
            $this->createdAt
        );
    }

    /**
     * Incrementar retry count
     *
     * @return self
     */
    public function incrementRetry(): self
    {
        return new self(
            $this->messageId,
            $this->status,
            $this->retryCount + 1,
            $this->maxRetries,
            $this->sentAt,
            $this->deliveredAt,
            $this->readAt,
            $this->failedAt,
            $this->failureReason,
            $this->createdAt
        );
    }

    /**
     * Verificar se pode fazer retry
     *
     * @return bool
     */
    public function canRetry(): bool
    {
        return $this->status === self::STATUS_FAILED
            && $this->retryCount < $this->maxRetries;
    }

    /**
     * Verificar se delivery está completo (delivered ou read)
     *
     * @return bool
     */
    public function isComplete(): bool
    {
        return in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_READ], true);
    }

    /**
     * Verificar se delivery falhou permanentemente
     *
     * @return bool
     */
    public function hasFailedPermanently(): bool
    {
        return $this->status === self::STATUS_FAILED
            && $this->retryCount >= $this->maxRetries;
    }

    // ==================== GETTERS ====================

    public function getMessageId(): string { return $this->messageId; }
    public function getStatus(): string { return $this->status; }
    public function getRetryCount(): int { return $this->retryCount; }
    public function getMaxRetries(): int { return $this->maxRetries; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getDeliveredAt(): ?\DateTimeImmutable { return $this->deliveredAt; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function getFailedAt(): ?\DateTimeImmutable { return $this->failedAt; }
    public function getFailureReason(): ?string { return $this->failureReason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'status' => $this->status,
            'retry_count' => $this->retryCount,
            'max_retries' => $this->maxRetries,
            'sent_at' => $this->sentAt ? $this->sentAt->format('Y-m-d H:i:s') : null,
            'delivered_at' => $this->deliveredAt ? $this->deliveredAt->format('Y-m-d H:i:s') : null,
            'read_at' => $this->readAt ? $this->readAt->format('Y-m-d H:i:s') : null,
            'failed_at' => $this->failedAt ? $this->failedAt->format('Y-m-d H:i:s') : null,
            'failure_reason' => $this->failureReason,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
