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
     * Valor total da order (pago pelo cliente)
     *
     * @var float
     */
    private $totalAmount;

    /**
     * Percentual de taxa da plataforma
     *
     * @var float
     */
    private $platformFeePercentage;

    /**
     * Valor em reais da taxa da plataforma
     *
     * @var float
     */
    private $platformFeeAmount;

    /**
     * Valor líquido do profissional (após taxa)
     *
     * @var float
     */
    private $professionalNetAmount;

    /**
     * Construtor
     *
     * @param string $uuid
     * @param int $id
     * @param FinancialStatus $financialStatus
     * @param float $totalAmount
     * @param float $platformFeePercentage
     * @param float $platformFeeAmount
     * @param float $professionalNetAmount
     */
    public function __construct(
        string $uuid,
        int $id,
        FinancialStatus $financialStatus,
        float $totalAmount = 0.0,
        float $platformFeePercentage = 0.0,
        float $platformFeeAmount = 0.0,
        float $professionalNetAmount = 0.0
    ) {
        $this->uuid = $uuid;
        $this->id = $id;
        $this->financialStatus = $financialStatus;
        $this->totalAmount = $totalAmount;
        $this->platformFeePercentage = $platformFeePercentage;
        $this->platformFeeAmount = $platformFeeAmount;
        $this->professionalNetAmount = $professionalNetAmount;
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
     * Obter valor total da order
     *
     * @return float
     */
    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    /**
     * Obter percentual da taxa da plataforma
     *
     * @return float
     */
    public function getPlatformFeePercentage(): float
    {
        return $this->platformFeePercentage;
    }

    /**
     * Obter valor da taxa da plataforma
     *
     * @return float
     */
    public function getPlatformFeeAmount(): float
    {
        return $this->platformFeeAmount;
    }

    /**
     * Obter valor líquido do profissional
     *
     * @return float
     */
    public function getProfessionalNetAmount(): float
    {
        return $this->professionalNetAmount;
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
