<?php
/**
 * Order - Entidade de Domínio para Order
 *
 * RESPONSABILIDADE:
 * - Representar uma order no contexto financeiro
 * - Aggregation Root mínima
 * - Apenas dados necessários para decisões financeiras
 *
 * PRINCÍPIOS:
 * - Entity (tem identidade - UUID)
 * - Imutável (após criação)
 * - Sem lógica de negócio (está em Policy)
 * - Minimal Entity (apenas o necessário)
 *
 * IMPORTANTE:
 * - Esta não é uma representação completa de WC_Order
 * - É um Domain Model focado em finanças
 * - Status financeiro aqui é CACHE (ledger = verdade)
 *
 * PASSO 5.3 - Use Cases de Decisão
 *
 * @package LimpVix\Domain\Order
 */

namespace LimpVix\Domain\Order;

use LimpVix\Domain\Finance\FinancialStatus;

defined('ABSPATH') || exit;

class Order
{
    /**
     * UUID da order
     *
     * @var string
     */
    private $uuid;

    /**
     * ID interno (WooCommerce order ID)
     *
     * @var int
     */
    private $id;

    /**
     * Status financeiro (CACHE)
     *
     * @var FinancialStatus
     */
    private $financialStatus;

    /**
     * Construtor
     *
     * @param string $uuid
     * @param int $id
     * @param FinancialStatus $financialStatus
     */
    public function __construct(
        string $uuid,
        int $id,
        FinancialStatus $financialStatus
    ) {
        $this->uuid = $uuid;
        $this->id = $id;
        $this->financialStatus = $financialStatus;
    }

    /**
     * Obter UUID
     *
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * Obter ID interno
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Obter status financeiro (CACHE)
     *
     * @return FinancialStatus
     */
    public function getFinancialStatus(): FinancialStatus
    {
        return $this->financialStatus;
    }

    /**
     * Verificar igualdade
     *
     * @param Order $other
     * @return bool
     */
    public function equals(Order $other): bool
    {
        return $this->uuid === $other->uuid;
    }
}
