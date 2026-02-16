<?php
/**
 * Frequency - Value Object
 *
 * Representa a frequência de execução do serviço.
 *
 * Tipos:
 * - avulso: Serviço único (não gera contrato)
 * - weekly: Semanal (1-5x por semana)
 * - monthly: Mensal (1-4x por mês)
 *
 * Regra de negócio:
 * - frequency != avulso → requiresContract() = true
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class Frequency
{
    /**
     * Tipos de frequência
     */
    private const AVULSO = 'avulso';
    private const WEEKLY = 'weekly';
    private const MONTHLY = 'monthly';

    /**
     * Todos os tipos válidos
     */
    private const VALID_TYPES = [
        self::AVULSO,
        self::WEEKLY,
        self::MONTHLY
    ];

    /**
     * @var string
     */
    private $type;

    /**
     * @var int Execuções por período (1-5 para semanal, 1-4 para mensal, 1 para avulso)
     */
    private $executionsPerPeriod;

    /**
     * Construtor privado
     *
     * @param string $type Tipo de frequência
     * @param int $executionsPerPeriod Execuções por período
     * @throws \InvalidArgumentException
     */
    private function __construct(string $type, int $executionsPerPeriod = 1)
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de frequência inválido: {$type}");
        }

        // Validar execuções por período
        if ($type === self::WEEKLY && ($executionsPerPeriod < 1 || $executionsPerPeriod > 5)) {
            throw new \InvalidArgumentException("Frequência semanal deve ter entre 1-5 execuções por semana");
        }

        if ($type === self::MONTHLY && ($executionsPerPeriod < 1 || $executionsPerPeriod > 4)) {
            throw new \InvalidArgumentException("Frequência mensal deve ter entre 1-4 execuções por mês");
        }

        if ($type === self::AVULSO && $executionsPerPeriod !== 1) {
            throw new \InvalidArgumentException("Frequência avulsa deve ter exatamente 1 execução");
        }

        $this->type = $type;
        $this->executionsPerPeriod = $executionsPerPeriod;
    }

    /**
     * Factory: Avulso (serviço único)
     *
     * @return self
     */
    public static function avulso(): self
    {
        return new self(self::AVULSO, 1);
    }

    /**
     * Factory: Semanal
     *
     * @param int $timesPerWeek Execuções por semana (1-5)
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function weekly(int $timesPerWeek): self
    {
        return new self(self::WEEKLY, $timesPerWeek);
    }

    /**
     * Factory: Mensal
     *
     * @param int $timesPerMonth Execuções por mês (1-4)
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function monthly(int $timesPerMonth): self
    {
        return new self(self::MONTHLY, $timesPerMonth);
    }

    /**
     * Factory: A partir de array
     *
     * @param array $data ['type' => string, 'executions_per_period' => int]
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? self::AVULSO;
        $executions = $data['executions_per_period'] ?? 1;

        switch ($type) {
            case self::WEEKLY:
                return self::weekly($executions);
            case self::MONTHLY:
                return self::monthly($executions);
            case self::AVULSO:
            default:
                return self::avulso();
        }
    }

    /**
     * Obter tipo
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Obter execuções por período
     *
     * @return int
     */
    public function getExecutionsPerPeriod(): int
    {
        return $this->executionsPerPeriod;
    }

    /**
     * Verificar se é avulso
     *
     * @return bool
     */
    public function isAvulso(): bool
    {
        return $this->type === self::AVULSO;
    }

    /**
     * Verificar se é semanal
     *
     * @return bool
     */
    public function isWeekly(): bool
    {
        return $this->type === self::WEEKLY;
    }

    /**
     * Verificar se é mensal
     *
     * @return bool
     */
    public function isMonthly(): bool
    {
        return $this->type === self::MONTHLY;
    }

    /**
     * Verificar se requer contrato
     *
     * Regra: frequency != avulso → contrato obrigatório
     *
     * @return bool
     */
    public function requiresContract(): bool
    {
        return !$this->isAvulso();
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'executions_per_period' => $this->executionsPerPeriod,
            'requires_contract' => $this->requiresContract()
        ];
    }

    /**
     * Comparar com outra frequência
     *
     * @param Frequency $other
     * @return bool
     */
    public function equals(Frequency $other): bool
    {
        return $this->type === $other->type
            && $this->executionsPerPeriod === $other->executionsPerPeriod;
    }

    /**
     * Representação em string
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->isAvulso()) {
            return 'avulso';
        }

        $period = $this->isWeekly() ? 'semana' : 'mês';
        return "{$this->executionsPerPeriod}x por {$period}";
    }
}
