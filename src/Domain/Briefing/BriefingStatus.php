<?php
/**
 * BriefingStatus - Value Object
 *
 * Representa os estados da State Machine do Briefing.
 *
 * Fluxo principal:
 * draft → in_progress → pending_phone_verification →
 * awaiting_payment → paid → locked
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class BriefingStatus
{
    /**
     * Estados possíveis
     */
    private const DRAFT = 'draft';
    private const IN_PROGRESS = 'in_progress';
    private const PENDING_PHONE_VERIFICATION = 'pending_phone_verification';
    private const AWAITING_PAYMENT = 'awaiting_payment';
    private const PAID = 'paid';
    private const LOCKED = 'locked';

    /**
     * Todos os status válidos
     */
    private const VALID_STATUSES = [
        self::DRAFT,
        self::IN_PROGRESS,
        self::PENDING_PHONE_VERIFICATION,
        self::AWAITING_PAYMENT,
        self::PAID,
        self::LOCKED
    ];

    /**
     * @var string
     */
    private $value;

    /**
     * Construtor privado
     *
     * @param string $value
     * @throws \InvalidArgumentException
     */
    private function __construct(string $value)
    {
        if (!in_array($value, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Status inválido: {$value}");
        }

        $this->value = $value;
    }

    /**
     * Factory: Draft (inicial)
     *
     * @return self
     */
    public static function draft(): self
    {
        return new self(self::DRAFT);
    }

    /**
     * Factory: Em progresso
     *
     * @return self
     */
    public static function inProgress(): self
    {
        return new self(self::IN_PROGRESS);
    }

    /**
     * Factory: Aguardando verificação de telefone
     *
     * @return self
     */
    public static function pendingPhoneVerification(): self
    {
        return new self(self::PENDING_PHONE_VERIFICATION);
    }

    /**
     * Factory: Aguardando pagamento
     *
     * @return self
     */
    public static function awaitingPayment(): self
    {
        return new self(self::AWAITING_PAYMENT);
    }

    /**
     * Factory: Pago
     *
     * @return self
     */
    public static function paid(): self
    {
        return new self(self::PAID);
    }

    /**
     * Factory: Bloqueado (final)
     *
     * @return self
     */
    public static function locked(): self
    {
        return new self(self::LOCKED);
    }

    /**
     * Factory: A partir de string
     *
     * @param string $value
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * Obter valor
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Verificar se é draft
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->value === self::DRAFT;
    }

    /**
     * Verificar se está em progresso
     *
     * @return bool
     */
    public function isInProgress(): bool
    {
        return $this->value === self::IN_PROGRESS;
    }

    /**
     * Verificar se está aguardando verificação de telefone
     *
     * @return bool
     */
    public function isPendingPhoneVerification(): bool
    {
        return $this->value === self::PENDING_PHONE_VERIFICATION;
    }

    /**
     * Verificar se está aguardando pagamento
     *
     * @return bool
     */
    public function isAwaitingPayment(): bool
    {
        return $this->value === self::AWAITING_PAYMENT;
    }

    /**
     * Verificar se está pago
     *
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this->value === self::PAID;
    }

    /**
     * Verificar se está locked
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->value === self::LOCKED;
    }

    /**
     * Verificar se é estado final (locked)
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return $this->isLocked();
    }

    /**
     * Verificar se pode transicionar para outro status
     *
     * @param BriefingStatus $to Status de destino
     * @return bool
     */
    public function canTransitionTo(BriefingStatus $to): bool
    {
        // Locked não pode transicionar
        if ($this->isLocked()) {
            return false;
        }

        // Transições válidas
        $validTransitions = [
            self::DRAFT => [self::IN_PROGRESS],
            self::IN_PROGRESS => [self::PENDING_PHONE_VERIFICATION],
            self::PENDING_PHONE_VERIFICATION => [self::AWAITING_PAYMENT],
            self::AWAITING_PAYMENT => [self::PAID],
            self::PAID => [self::LOCKED],
        ];

        return isset($validTransitions[$this->value])
            && in_array($to->getValue(), $validTransitions[$this->value], true);
    }

    /**
     * Comparar com outro status
     *
     * @param BriefingStatus $other
     * @return bool
     */
    public function equals(BriefingStatus $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Representação em string
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
