<?php
/**
 * OrderStatus - Value Object para Status da OS
 *
 * RESPONSABILIDADE:
 * - Representar status válidos da OS
 * - Definir transições permitidas
 * - Type-safe enum (via métodos estáticos)
 *
 * PRINCÍPIOS:
 * - Imutável
 * - Validação na criação
 * - Comparação por valor
 *
 * @package LimpVix\Domain\Order
 */

namespace LimpVix\Domain\Order;

defined('ABSPATH') || exit;

class OrderStatus
{
    // Estados possíveis
    public const CREATED = 'created';
    public const VALIDATED = 'validated';
    public const SCHEDULED = 'scheduled';
    public const CONFIRMED = 'confirmed';
    public const IN_PROGRESS = 'in_progress';
    public const DONE = 'done';
    public const CANCELED_OK = 'canceled_ok';
    public const CANCELED_PENALTY = 'canceled_penalty';
    public const NO_SHOW = 'no_show';
    public const FAILED = 'failed';

    /**
     * Valor do status
     *
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
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException("Status inválido: {$value}");
        }

        $this->value = $value;
    }

    // ========================================
    // FACTORY METHODS (Type-safe "enum")
    // ========================================

    public static function CREATED(): self
    {
        return new self(self::CREATED);
    }

    public static function VALIDATED(): self
    {
        return new self(self::VALIDATED);
    }

    public static function SCHEDULED(): self
    {
        return new self(self::SCHEDULED);
    }

    public static function CONFIRMED(): self
    {
        return new self(self::CONFIRMED);
    }

    public static function IN_PROGRESS(): self
    {
        return new self(self::IN_PROGRESS);
    }

    public static function DONE(): self
    {
        return new self(self::DONE);
    }

    public static function CANCELED_OK(): self
    {
        return new self(self::CANCELED_OK);
    }

    public static function CANCELED_PENALTY(): self
    {
        return new self(self::CANCELED_PENALTY);
    }

    public static function NO_SHOW(): self
    {
        return new self(self::NO_SHOW);
    }

    public static function FAILED(): self
    {
        return new self(self::FAILED);
    }

    /**
     * Criar a partir de string
     *
     * @param string $value
     * @return self
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    // ========================================
    // MÉTODOS DE VALIDAÇÃO
    // ========================================

    /**
     * Verifica se valor é válido
     *
     * @param string $value
     * @return bool
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::getAllValues(), true);
    }

    /**
     * Retorna todos os valores possíveis
     *
     * @return array
     */
    public static function getAllValues(): array
    {
        return [
            self::CREATED,
            self::VALIDATED,
            self::SCHEDULED,
            self::CONFIRMED,
            self::IN_PROGRESS,
            self::DONE,
            self::CANCELED_OK,
            self::CANCELED_PENALTY,
            self::NO_SHOW,
            self::FAILED,
        ];
    }

    // ========================================
    // REGRAS DE TRANSIÇÃO
    // ========================================

    /**
     * Retorna transições permitidas a partir do status atual
     *
     * @return array
     */
    public function getAllowedTransitions(): array
    {
        $transitions = [
            self::CREATED => [self::VALIDATED, self::FAILED],
            self::VALIDATED => [self::SCHEDULED, self::FAILED],
            self::SCHEDULED => [self::CONFIRMED, self::CANCELED_OK, self::CANCELED_PENALTY],
            self::CONFIRMED => [self::IN_PROGRESS, self::CANCELED_OK, self::CANCELED_PENALTY, self::NO_SHOW],
            self::IN_PROGRESS => [self::DONE, self::FAILED],
            self::DONE => [],
            self::CANCELED_OK => [],
            self::CANCELED_PENALTY => [],
            self::NO_SHOW => [],
            self::FAILED => [],
        ];

        return $transitions[$this->value] ?? [];
    }

    // ========================================
    // CHECAGENS DE ESTADO
    // ========================================

    /**
     * Verifica se é um estado final
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return in_array($this->value, [
            self::DONE,
            self::CANCELED_OK,
            self::CANCELED_PENALTY,
            self::NO_SHOW,
            self::FAILED,
        ], true);
    }

    /**
     * Verifica se é um tipo de cancelamento
     *
     * @return bool
     */
    public function isCanceled(): bool
    {
        return in_array($this->value, [
            self::CANCELED_OK,
            self::CANCELED_PENALTY,
            self::NO_SHOW,
        ], true);
    }

    /**
     * Verifica se é sucesso (concluído)
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->value === self::DONE;
    }

    /**
     * Verifica se está em andamento
     *
     * @return bool
     */
    public function isInProgress(): bool
    {
        return in_array($this->value, [
            self::SCHEDULED,
            self::CONFIRMED,
            self::IN_PROGRESS,
        ], true);
    }

    // ========================================
    // VALUE OBJECT METHODS
    // ========================================

    /**
     * Retorna valor como string
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Compara com outro status
     *
     * @param OrderStatus $other
     * @return bool
     */
    public function equals(OrderStatus $other): bool
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

    /**
     * Retorna label humanizado
     *
     * @return string
     */
    public function getLabel(): string
    {
        $labels = [
            self::CREATED => 'Criada',
            self::VALIDATED => 'Validada',
            self::SCHEDULED => 'Agendada',
            self::CONFIRMED => 'Confirmada',
            self::IN_PROGRESS => 'Em Andamento',
            self::DONE => 'Concluída',
            self::CANCELED_OK => 'Cancelada (OK)',
            self::CANCELED_PENALTY => 'Cancelada (Penalidade)',
            self::NO_SHOW => 'Cliente Ausente',
            self::FAILED => 'Falhou',
        ];

        return $labels[$this->value] ?? $this->value;
    }
}
