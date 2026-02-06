<?php
/**
 * FeedbackCaseStatus - Value Object
 *
 * Representa os estados possíveis de um caso de feedback negativo C2.
 *
 * Estados:
 * - pending: Aguardando atendimento manual
 * - in_progress: Em atendimento
 * - resolved: Resolvido
 *
 * @package LimpVix\Domain\Support
 * @since 0.1.4
 */

namespace LimpVix\Domain\Support;

defined('ABSPATH') || exit;

class FeedbackCaseStatus
{
    /**
     * Status válidos
     */
    private const PENDING = 'pending';
    private const IN_PROGRESS = 'in_progress';
    private const RESOLVED = 'resolved';

    /**
     * Todos os status válidos
     */
    private const VALID_STATUSES = [
        self::PENDING,
        self::IN_PROGRESS,
        self::RESOLVED
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
     * Factory: Pendente
     *
     * @return self
     */
    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    /**
     * Factory: Em atendimento
     *
     * @return self
     */
    public static function inProgress(): self
    {
        return new self(self::IN_PROGRESS);
    }

    /**
     * Factory: Resolvido
     *
     * @return self
     */
    public static function resolved(): self
    {
        return new self(self::RESOLVED);
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
     * Verificar se é pendente
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->value === self::PENDING;
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
     * Verificar se está resolvido
     *
     * @return bool
     */
    public function isResolved(): bool
    {
        return $this->value === self::RESOLVED;
    }

    /**
     * Verificar se é final (resolvido)
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return $this->isResolved();
    }

    /**
     * Comparar com outro status
     *
     * @param FeedbackCaseStatus $other
     * @return bool
     */
    public function equals(FeedbackCaseStatus $other): bool
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
