<?php
/**
 * TransitionFinancialStatusCommand - Comando para Transição Financeira
 *
 * RESPONSABILIDADE:
 * - Encapsular dados necessários para uma transição financeira
 * - DTO imutável (Command Pattern)
 * - Validação básica de campos obrigatórios
 *
 * PRINCÍPIOS:
 * - Command (CQRS leve)
 * - Imutável
 * - Validação fail-fast
 * - Type-safe
 *
 * USO:
 * ```php
 * $command = new TransitionFinancialStatusCommand(
 *     orderUuid: '550e8400-...',
 *     toStatus: FinancialStatus::AUTHORIZED(),
 *     reason: 'positive_feedback',
 *     actor: 'system',
 *     actorId: null,
 *     context: new FinancialContext([...])
 * );
 *
 * $result = $useCase->execute($command);
 * ```
 *
 * PASSO 5.3 - Use Cases de Decisão
 *
 * @package LimpVix\Application\Commands
 */

namespace LimpVix\Application\Commands;

use LimpVix\Domain\Finance\FinancialStatus;
use LimpVix\Domain\Finance\FinancialContext;

defined('ABSPATH') || exit;

class TransitionFinancialStatusCommand
{
    /**
     * UUID da Order
     *
     * @var string
     */
    private $orderUuid;

    /**
     * Estado de destino
     *
     * @var FinancialStatus
     */
    private $toStatus;

    /**
     * Razão da transição
     *
     * @var string
     */
    private $reason;

    /**
     * Ator da transição
     *
     * @var string
     */
    private $actor;

    /**
     * ID do ator (opcional)
     *
     * @var int|null
     */
    private $actorId;

    /**
     * Contexto para validação
     *
     * @var FinancialContext
     */
    private $context;

    /**
     * Construtor
     *
     * @param string $orderUuid
     * @param FinancialStatus $toStatus
     * @param string $reason
     * @param string $actor
     * @param int|null $actorId
     * @param FinancialContext $context
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $orderUuid,
        FinancialStatus $toStatus,
        string $reason,
        string $actor,
        ?int $actorId = null,
        FinancialContext $context = null
    ) {
        // Validações básicas
        if (empty($orderUuid)) {
            throw new \InvalidArgumentException('orderUuid não pode ser vazio');
        }

        if (empty($reason)) {
            throw new \InvalidArgumentException('reason não pode ser vazio');
        }

        if (empty($actor)) {
            throw new \InvalidArgumentException('actor não pode ser vazio');
        }

        $this->orderUuid = $orderUuid;
        $this->toStatus = $toStatus;
        $this->reason = $reason;
        $this->actor = $actor;
        $this->actorId = $actorId;
        $this->context = $context ?? new FinancialContext();
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
     * Obter estado de destino
     *
     * @return FinancialStatus
     */
    public function getToStatus(): FinancialStatus
    {
        return $this->toStatus;
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
     * Obter contexto
     *
     * @return FinancialContext
     */
    public function getContext(): FinancialContext
    {
        return $this->context;
    }
}
