<?php
declare(strict_types=1);

namespace LimpVix\Domain\Execution\Enums;

/**
 * Execution Status Enum (Sprint 1 - Dia 1)
 *
 * Estados do RASTREAMENTO EM TEMPO REAL de uma execução de serviço.
 * Usado pelo aggregate Execution (check-in GPS, evidências, SLA, geofence).
 *
 * IMPORTANTE: NÃO confundir com ExecutionStatus (Value Object) que é usado
 * pelo aggregate ContractExecution para rastrear o ciclo de AGENDAMENTO
 * (draft → scheduled → in_progress → completed/cancelled/no_show).
 *
 * Esta enum rastreia: check-in → execução → check-out → validação → fechamento
 * ExecutionStatus rastreia: criação → agendamento → progresso → conclusão
 *
 * Tabela: wp_limpvix_executions (coluna status)
 *
 * @package LimpVix\Domain\Execution\Enums
 */
enum ExecutionStatusEnum: string
{
    case CREATED = 'created';
    case CHECKED_IN = 'checked_in';
    case IN_EXECUTION = 'in_execution';
    case CHECKED_OUT = 'checked_out';
    case VALIDATED = 'validated';
    case CLOSED = 'closed';

    /**
     * Verifica se o status atual está em um dos estados fornecidos
     *
     * @param array<ExecutionStatusEnum> $statuses
     * @return bool
     */
    public function isIn(array $statuses): bool
    {
        return in_array($this, $statuses, true);
    }

    /**
     * Verifica se é um estado terminal (não permite mais transições)
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return $this === self::CLOSED;
    }

    /**
     * Verifica se check-in foi realizado
     *
     * @return bool
     */
    public function isCheckedIn(): bool
    {
        return $this->isIn([
            self::CHECKED_IN,
            self::IN_EXECUTION,
            self::CHECKED_OUT,
            self::VALIDATED,
            self::CLOSED,
        ]);
    }

    /**
     * Verifica se check-out foi realizado
     *
     * @return bool
     */
    public function isCheckedOut(): bool
    {
        return $this->isIn([
            self::CHECKED_OUT,
            self::VALIDATED,
            self::CLOSED,
        ]);
    }

    /**
     * Verifica se execução foi validada
     *
     * @return bool
     */
    public function isValidated(): bool
    {
        return $this->isIn([
            self::VALIDATED,
            self::CLOSED,
        ]);
    }

    /**
     * Retorna representação legível do status
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::CREATED => 'Criada',
            self::CHECKED_IN => 'Check-in Realizado',
            self::IN_EXECUTION => 'Em Execução',
            self::CHECKED_OUT => 'Check-out Realizado',
            self::VALIDATED => 'Validada',
            self::CLOSED => 'Fechada',
        };
    }
}
