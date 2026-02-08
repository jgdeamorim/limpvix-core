<?php
declare(strict_types=1);

namespace LimpVix\Domain\Finance\Enums;

/**
 * Financial Status Enum (Sprint 0)
 *
 * Estados válidos do ciclo de vida financeiro (State Machine).
 * Enum PHP 8.1+ - fonte única de verdade para estados financeiros.
 *
 * Substitui FinancialStatus (Value Object legado) gradualmente.
 *
 * @package LimpVix\Domain\Finance\Enums
 */
enum FinancialStatusEnum: string
{
    case PENDING = 'pending';
    case AUTHORIZED = 'authorized';
    case CAPTURED = 'captured';
    case HELD = 'held';
    case PAYOUT_AUTHORIZED = 'payout_authorized';
    case PAYOUT_COMPLETED = 'payout_completed';
    case RELEASED = 'released';
    case REFUNDED = 'refunded';
    case FAILED = 'failed';

    /**
     * Verifica se o status atual está em um dos estados fornecidos
     *
     * @param array<FinancialStatusEnum> $statuses
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
            self::PAYOUT_COMPLETED,
            self::REFUNDED,
            self::FAILED,
        ]);
    }

    /**
     * Verifica se pagamento foi capturado
     *
     * @return bool
     */
    public function isCaptured(): bool
    {
        return $this->isIn([
            self::CAPTURED,
            self::HELD,
            self::PAYOUT_AUTHORIZED,
            self::PAYOUT_COMPLETED,
        ]);
    }

    /**
     * Verifica se permite refund
     *
     * @return bool
     */
    public function canBeRefunded(): bool
    {
        return $this->isIn([
            self::AUTHORIZED,
            self::CAPTURED,
            self::HELD,
        ]);
    }

    /**
     * Verifica se payout foi autorizado
     *
     * @return bool
     */
    public function isPayoutAuthorized(): bool
    {
        return $this->isIn([
            self::PAYOUT_AUTHORIZED,
            self::PAYOUT_COMPLETED,
        ]);
    }

    /**
     * Verifica se está em hold (aguardando check-in)
     *
     * @return bool
     */
    public function isOnHold(): bool
    {
        return $this === self::HELD;
    }

    /**
     * Retorna representação legível do status
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pendente',
            self::AUTHORIZED => 'Autorizado',
            self::CAPTURED => 'Capturado',
            self::HELD => 'Em Espera',
            self::PAYOUT_AUTHORIZED => 'Payout Autorizado',
            self::PAYOUT_COMPLETED => 'Payout Completado',
            self::RELEASED => 'Liberado',
            self::REFUNDED => 'Reembolsado',
            self::FAILED => 'Falhou',
        };
    }
}
