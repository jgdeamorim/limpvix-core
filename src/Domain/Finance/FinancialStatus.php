<?php
/**
 * FinancialStatus - Estados Financeiros da Order
 *
 * RESPONSABILIDADE:
 * - Representar todos os estados financeiros possíveis
 * - Garantir imutabilidade
 * - Prevenir estados inválidos
 *
 * PRINCÍPIOS:
 * - Value Object (imutável)
 * - Tipo seguro (factory methods)
 * - Sem lógica de transição (vai em FinancialPolicy)
 *
 * ESTADOS:
 * - CREATED: Order criada, pagamento pendente
 * - PAID: Pagamento confirmado (WooCommerce)
 * - HELD: Segurado (aguardando execução do serviço)
 * - REVIEW: Em análise (feedback, timer 24h)
 * - AUTHORIZED: Aprovado para repasse
 * - TRANSFERRED: Transferido ao profissional (FINAL)
 * - BLOCKED: Bloqueado (admin/disputa)
 * - REFUNDED: Reembolsado ao cliente (FINAL)
 *
 * PASSO 5.1 - FSM Financeira
 *
 * @package LimpVix\Domain\Finance
 */

namespace LimpVix\Domain\Finance;

defined('ABSPATH') || exit;

class FinancialStatus
{
    /**
     * Estados permitidos
     */
    private const CREATED = 'CREATED';
    private const PAID = 'PAID';
    private const HELD = 'HELD';
    private const REVIEW = 'REVIEW';
    private const AUTHORIZED = 'AUTHORIZED';
    private const TRANSFERRED = 'TRANSFERRED';
    private const BLOCKED = 'BLOCKED';
    private const REFUNDED = 'REFUNDED';

    /**
     * Estados finais (imutáveis)
     */
    private const FINAL_STATES = [
        self::TRANSFERRED,
        self::REFUNDED
    ];

    /**
     * Valor do estado
     *
     * @var string
     */
    private $value;

    /**
     * Construtor privado (use factory methods)
     *
     * @param string $value
     */
    private function __construct(string $value)
    {
        if (!$this->isValid($value)) {
            throw new \InvalidArgumentException("Estado financeiro inválido: {$value}");
        }

        $this->value = $value;
    }

    /**
     * Factory: CREATED
     *
     * @return self
     */
    public static function CREATED(): self
    {
        return new self(self::CREATED);
    }

    /**
     * Factory: PAID
     *
     * @return self
     */
    public static function PAID(): self
    {
        return new self(self::PAID);
    }

    /**
     * Factory: HELD
     *
     * @return self
     */
    public static function HELD(): self
    {
        return new self(self::HELD);
    }

    /**
     * Factory: REVIEW
     *
     * @return self
     */
    public static function REVIEW(): self
    {
        return new self(self::REVIEW);
    }

    /**
     * Factory: AUTHORIZED
     *
     * @return self
     */
    public static function AUTHORIZED(): self
    {
        return new self(self::AUTHORIZED);
    }

    /**
     * Factory: TRANSFERRED
     *
     * @return self
     */
    public static function TRANSFERRED(): self
    {
        return new self(self::TRANSFERRED);
    }

    /**
     * Factory: BLOCKED
     *
     * @return self
     */
    public static function BLOCKED(): self
    {
        return new self(self::BLOCKED);
    }

    /**
     * Factory: REFUNDED
     *
     * @return self
     */
    public static function REFUNDED(): self
    {
        return new self(self::REFUNDED);
    }

    /**
     * Factory: from string value
     *
     * @param string $value
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromValue(string $value): self
    {
        return new self($value);
    }

    /**
     * Obter valor do estado
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Verificar igualdade
     *
     * @param FinancialStatus $other
     * @return bool
     */
    public function equals(FinancialStatus $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Verificar se é estado final (imutável)
     *
     * Estados finais NUNCA podem transicionar
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return in_array($this->value, self::FINAL_STATES, true);
    }

    /**
     * Verificar se pode transicionar
     *
     * Estados finais não podem transicionar para nenhum outro estado
     *
     * @return bool
     */
    public function canTransition(): bool
    {
        return !$this->isFinal();
    }

    /**
     * Validar se valor é válido
     *
     * @param string $value
     * @return bool
     */
    private function isValid(string $value): bool
    {
        return in_array($value, [
            self::CREATED,
            self::PAID,
            self::HELD,
            self::REVIEW,
            self::AUTHORIZED,
            self::TRANSFERRED,
            self::BLOCKED,
            self::REFUNDED
        ], true);
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
     * Obter todos os estados possíveis
     *
     * @return array
     */
    public static function getAllStates(): array
    {
        return [
            self::CREATED,
            self::PAID,
            self::HELD,
            self::REVIEW,
            self::AUTHORIZED,
            self::TRANSFERRED,
            self::BLOCKED,
            self::REFUNDED
        ];
    }

    /**
     * Obter estados finais
     *
     * @return array
     */
    public static function getFinalStates(): array
    {
        return self::FINAL_STATES;
    }
}
