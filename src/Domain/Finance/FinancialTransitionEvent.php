<?php
/**
 * FinancialTransitionEvent - Evento de Transição Financeira
 *
 * RESPONSABILIDADE:
 * - Representar uma transição financeira que ocorreu
 * - Domain Event (fato imutável)
 * - Payload para Ledger
 *
 * PRINCÍPIOS:
 * - Imutável (Value Object)
 * - Representa fato passado
 * - Usado para Event Sourcing leve (Ledger)
 *
 * USO:
 * ```php
 * $event = new FinancialTransitionEvent(
 *     orderUuid: '550e8400...',
 *     from: FinancialStatus::REVIEW(),
 *     to: FinancialStatus::AUTHORIZED(),
 *     reason: 'positive_feedback',
 *     actor: 'system',
 *     actorId: null
 * );
 *
 * // Gravar no Ledger (PASSO 5.2)
 * ```
 *
 * PASSO 5.1 - FSM Financeira
 *
 * @package LimpVix\Domain\Finance
 */

namespace LimpVix\Domain\Finance;

defined('ABSPATH') || exit;

class FinancialTransitionEvent
{
    /**
     * UUID da Order
     *
     * @var string
     */
    private $orderUuid;

    /**
     * Estado de origem
     *
     * @var FinancialStatus
     */
    private $from;

    /**
     * Estado de destino
     *
     * @var FinancialStatus
     */
    private $to;

    /**
     * Razão da transição
     *
     * @var string
     */
    private $reason;

    /**
     * Ator da transição
     *
     * @var string (system, admin, customer, etc)
     */
    private $actor;

    /**
     * ID do ator (se aplicável)
     *
     * @var int|null
     */
    private $actorId;

    /**
     * Timestamp do evento
     *
     * @var \DateTime
     */
    private $occurredAt;

    /**
     * Construtor
     *
     * @param string $orderUuid UUID da Order
     * @param FinancialStatus $from Estado de origem
     * @param FinancialStatus $to Estado de destino
     * @param string $reason Razão da transição
     * @param string $actor Ator (system, admin, customer)
     * @param int|null $actorId ID do ator (opcional)
     */
    public function __construct(
        string $orderUuid,
        FinancialStatus $from,
        FinancialStatus $to,
        string $reason,
        string $actor,
        ?int $actorId = null
    ) {
        $this->orderUuid = $orderUuid;
        $this->from = $from;
        $this->to = $to;
        $this->reason = $reason;
        $this->actor = $actor;
        $this->actorId = $actorId;
        $this->occurredAt = new \DateTime();
    }

    /**
     * Obter UUID da Order
     *
     * @return string
     */
    public function getOrderUuid(): string
    {
        return $this->orderUuid;
    }

    /**
     * Obter estado de origem
     *
     * @return FinancialStatus
     */
    public function getFrom(): FinancialStatus
    {
        return $this->from;
    }

    /**
     * Obter estado de destino
     *
     * @return FinancialStatus
     */
    public function getTo(): FinancialStatus
    {
        return $this->to;
    }

    /**
     * Obter razão
     *
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Obter ator
     *
     * @return string
     */
    public function getActor(): string
    {
        return $this->actor;
    }

    /**
     * Obter ID do ator
     *
     * @return int|null
     */
    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    /**
     * Obter timestamp
     *
     * @return \DateTime
     */
    public function getOccurredAt(): \DateTime
    {
        return $this->occurredAt;
    }

    /**
     * Converter para array (para Ledger)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'order_uuid' => $this->orderUuid,
            'from_status' => $this->from->getValue(),
            'to_status' => $this->to->getValue(),
            'reason' => $this->reason,
            'actor' => $this->actor,
            'actor_id' => $this->actorId,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Representação em string
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            '[%s] %s → %s (reason: %s, actor: %s)',
            $this->orderUuid,
            $this->from->getValue(),
            $this->to->getValue(),
            $this->reason,
            $this->actor
        );
    }
}
