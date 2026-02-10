<?php
/**
 * ExecutionId - Value Object para identificar Execuções
 *
 * RESPONSABILIDADE:
 * - Representar identificador único de uma execução
 * - Validar que ID é válido (>= 0)
 * - Comparação de igualdade entre IDs
 *
 * IMUTABILIDADE:
 * - Valor não pode ser alterado após criação
 *
 * @package LimpVix\Domain\Execution
 * @since 0.9.0
 */

namespace LimpVix\Domain\Execution;

defined('ABSPATH') || exit;

final class ExecutionId
{
    private int $value;

    private function __construct(int $value)
    {
        $this->ensureValid($value);
        $this->value = $value;
    }

    /**
     * Criar ExecutionId a partir de inteiro
     *
     * @param int $value ID da execução
     * @return self
     */
    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    /**
     * Converter para inteiro
     *
     * @return int
     */
    public function toInt(): int
    {
        return $this->value;
    }

    /**
     * Comparar igualdade com outro ExecutionId
     *
     * @param ExecutionId $other
     * @return bool
     */
    public function equals(ExecutionId $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Validar que ID é válido
     *
     * REGRAS:
     * - ID deve ser >= 0 (0 = novo registro antes de persistir)
     *
     * @param int $value
     * @return void
     * @throws \InvalidArgumentException
     */
    private function ensureValid(int $value): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('ExecutionId must be >= 0');
        }
    }

    /**
     * Representação string do ID
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
