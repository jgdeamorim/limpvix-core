<?php
declare(strict_types=1);

namespace LimpVix\Domain\Order\Enums;

/**
 * Order Status Enum (Sprint 0)
 *
 * Estados válidos do ciclo de vida de uma Order (State Machine).
 * Enum PHP 8.1+ - fonte única de verdade para estados.
 *
 * Substitui OrderStatus (Value Object legado) gradualmente.
 *
 * @package LimpVix\Domain\Order\Enums
 */
enum OrderStatusEnum: string
{
    case CREATED = 'created';
    case CONFIRMED = 'confirmed';
    case SCHEDULED = 'scheduled';
    case IN_EXECUTION = 'in_execution';
    case COMPLETED = 'completed';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';
    case DISPUTED = 'disputed';

    /**
     * Verifica se o status atual está em um dos estados fornecidos
     *
     * @param array<OrderStatusEnum> $statuses
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
        return $this->isIn([
            self::CLOSED,
            self::CANCELLED,
        ]);
    }

    /**
     * Verifica se permite cancelamento
     *
     * @return bool
     */
    public function canBeCancelled(): bool
    {
        return $this->isIn([
            self::CREATED,
            self::CONFIRMED,
            self::SCHEDULED,
        ]);
    }

    /**
     * Verifica se ordem está em execução
     *
     * @return bool
     */
    public function isInExecution(): bool
    {
        return $this === self::IN_EXECUTION;
    }

    /**
     * Verifica se ordem foi completada
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->isIn([
            self::COMPLETED,
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
            self::CONFIRMED => 'Confirmada',
            self::SCHEDULED => 'Agendada',
            self::IN_EXECUTION => 'Em Execução',
            self::COMPLETED => 'Completada',
            self::CLOSED => 'Fechada',
            self::CANCELLED => 'Cancelada',
            self::DISPUTED => 'Em Disputa',
        };
    }
}
