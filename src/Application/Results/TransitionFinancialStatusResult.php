<?php
/**
 * TransitionFinancialStatusResult - Resultado de Transição Financeira
 *
 * RESPONSABILIDADE:
 * - Encapsular resultado de uma tentativa de transição
 * - Informar sucesso/falha
 * - Carregar razão de rejeição (se falhou)
 * - Carregar dados da transição (se sucedeu)
 *
 * PRINCÍPIOS:
 * - Result Object Pattern
 * - Imutável
 * - Type-safe
 * - Explícito sobre sucesso/falha
 *
 * USO:
 * ```php
 * $result = $useCase->execute($command);
 *
 * if ($result->isSuccess()) {
 *     echo $result->getFromStatus()->getValue();
 *     echo $result->getToStatus()->getValue();
 *     echo $result->getLedgerUuid();
 * } else {
 *     echo $result->getRejectReason();
 * }
 * ```
 *
 * PASSO 5.3 - Use Cases de Decisão
 *
 * @package LimpVix\Application\Results
 */

namespace LimpVix\Application\Results;

use LimpVix\Domain\Finance\FinancialStatus;

defined('ABSPATH') || exit;

class TransitionFinancialStatusResult
{
    /**
     * Sucesso?
     *
     * @var bool
     */
    private $success;

    /**
     * UUID da Order
     *
     * @var string
     */
    private $orderUuid;

    /**
     * Estado de origem (se sucesso)
     *
     * @var FinancialStatus|null
     */
    private $fromStatus;

    /**
     * Estado de destino (se sucesso)
     *
     * @var FinancialStatus|null
     */
    private $toStatus;

    /**
     * UUID do registro no ledger (se sucesso)
     *
     * @var string|null
     */
    private $ledgerUuid;

    /**
     * Razão de rejeição (se falha)
     *
     * @var string|null
     */
    private $rejectReason;

    /**
     * Factory: Sucesso
     *
     * @param string $orderUuid
     * @param FinancialStatus $fromStatus
     * @param FinancialStatus $toStatus
     * @param string $ledgerUuid
     * @return self
     */
    public static function success(
        string $orderUuid,
        FinancialStatus $fromStatus,
        FinancialStatus $toStatus,
        string $ledgerUuid
    ): self {
        $result = new self();
        $result->success = true;
        $result->orderUuid = $orderUuid;
        $result->fromStatus = $fromStatus;
        $result->toStatus = $toStatus;
        $result->ledgerUuid = $ledgerUuid;
        $result->rejectReason = null;

        return $result;
    }

    /**
     * Factory: Falha
     *
     * @param string $orderUuid
     * @param string $rejectReason
     * @return self
     */
    public static function rejected(
        string $orderUuid,
        string $rejectReason
    ): self {
        $result = new self();
        $result->success = false;
        $result->orderUuid = $orderUuid;
        $result->fromStatus = null;
        $result->toStatus = null;
        $result->ledgerUuid = null;
        $result->rejectReason = $rejectReason;

        return $result;
    }

    /**
     * Construtor privado (use factories)
     */
    private function __construct()
    {
        // Use factories: success() ou rejected()
    }

    /**
     * Verificar sucesso
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Verificar falha
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return !$this->success;
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
     * @return FinancialStatus|null
     */
    public function getFromStatus(): ?FinancialStatus
    {
        return $this->fromStatus;
    }

    /**
     * Obter estado de destino
     *
     * @return FinancialStatus|null
     */
    public function getToStatus(): ?FinancialStatus
    {
        return $this->toStatus;
    }

    /**
     * Obter UUID do ledger
     *
     * @return string|null
     */
    public function getLedgerUuid(): ?string
    {
        return $this->ledgerUuid;
    }

    /**
     * Obter razão de rejeição
     *
     * @return string|null
     */
    public function getRejectReason(): ?string
    {
        return $this->rejectReason;
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = [
            'success' => $this->success,
            'order_uuid' => $this->orderUuid,
        ];

        if ($this->success) {
            $data['from_status'] = $this->fromStatus->getValue();
            $data['to_status'] = $this->toStatus->getValue();
            $data['ledger_uuid'] = $this->ledgerUuid;
        } else {
            $data['reject_reason'] = $this->rejectReason;
        }

        return $data;
    }

    /**
     * Representação em string
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->success) {
            return sprintf(
                'SUCCESS: %s → %s (ledger: %s)',
                $this->fromStatus->getValue(),
                $this->toStatus->getValue(),
                $this->ledgerUuid
            );
        }

        return sprintf(
            'REJECTED: %s',
            $this->rejectReason
        );
    }
}
