<?php
/**
 * Payout - Value Object para Repasse
 *
 * RESPONSABILIDADE:
 * - Representar dados necessários para transferência MP → MP
 * - Validação estrutural (não de negócio)
 * - Imutável após criação
 *
 * PRINCÍPIOS:
 * - Value Object (imutável)
 * - Validação fail-fast
 * - Type-safe
 * - Agnóstico de provider
 *
 * IMPORTANTE:
 * - NÃO decide se pode pagar (Policy decide)
 * - NÃO calcula comissão (já vem calculado)
 * - NÃO valida saldo (MP valida)
 * - Apenas carrega dados técnicos
 *
 * CAMPOS:
 * - payoutId: UUID único (idempotência)
 * - amount: Valor líquido já calculado
 * - currency: Sempre "BRL"
 * - receiverMpUserId: MP User ID do profissional
 * - description: Texto para auditoria
 *
 * PASSO 5.5 - Payout Engine
 *
 * @package LimpVix\Modules\Payouts\MercadoPago
 */

namespace LimpVix\Modules\Payouts\MercadoPago;

defined('ABSPATH') || exit;

final class Payout
{
    /**
     * UUID único do payout (PK, idempotência)
     *
     * @var string
     */
    private $payoutId;

    /**
     * Valor do repasse (líquido)
     *
     * @var float
     */
    private $amount;

    /**
     * Moeda (sempre BRL)
     *
     * @var string
     */
    private $currency;

    /**
     * MP User ID do receptor
     *
     * @var string
     */
    private $receiverMpUserId;

    /**
     * Descrição para auditoria
     *
     * @var string
     */
    private $description;

    /**
     * Metadata adicional (opcional)
     *
     * @var array
     */
    private $metadata;

    /**
     * Construtor
     *
     * @param string $payoutId UUID único
     * @param float $amount Valor do repasse
     * @param string $receiverMpUserId MP User ID
     * @param string $description Descrição
     * @param string $currency Moeda (default: BRL)
     * @param array $metadata Metadata adicional
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $payoutId,
        float $amount,
        string $receiverMpUserId,
        string $description,
        string $currency = 'BRL',
        array $metadata = []
    ) {
        // Validações estruturais
        $this->validatePayoutId($payoutId);
        $this->validateAmount($amount);
        $this->validateReceiverMpUserId($receiverMpUserId);
        $this->validateCurrency($currency);

        $this->payoutId = $payoutId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->receiverMpUserId = $receiverMpUserId;
        $this->description = $description;
        $this->metadata = $metadata;
    }

    /**
     * Validar payout ID
     *
     * @param string $payoutId
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validatePayoutId(string $payoutId): void
    {
        if (empty($payoutId)) {
            throw new \InvalidArgumentException('Payout ID não pode ser vazio');
        }

        // Validar formato UUID (básico)
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payoutId)) {
            throw new \InvalidArgumentException('Payout ID deve ser um UUID válido');
        }
    }

    /**
     * Validar amount
     *
     * @param float $amount
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount deve ser maior que zero');
        }

        // Mercado Pago: limite mínimo R$ 0,01
        if ($amount < 0.01) {
            throw new \InvalidArgumentException('Amount mínimo: R$ 0,01');
        }

        // Limite máximo preventivo (R$ 100.000)
        if ($amount > 100000.00) {
            throw new \InvalidArgumentException('Amount excede limite máximo (R$ 100.000)');
        }
    }

    /**
     * Validar receiver MP User ID
     *
     * @param string $receiverId
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateReceiverMpUserId(string $receiverId): void
    {
        if (empty($receiverId)) {
            throw new \InvalidArgumentException('Receiver MP User ID não pode ser vazio');
        }

        // MP User ID é numérico
        if (!is_numeric($receiverId)) {
            throw new \InvalidArgumentException('Receiver MP User ID deve ser numérico');
        }
    }

    /**
     * Validar currency
     *
     * @param string $currency
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateCurrency(string $currency): void
    {
        // Por ora, apenas BRL
        if ($currency !== 'BRL') {
            throw new \InvalidArgumentException('Currency deve ser BRL');
        }
    }

    /**
     * Obter payout ID
     *
     * @return string
     */
    public function getPayoutId(): string
    {
        return $this->payoutId;
    }

    /**
     * Obter amount
     *
     * @return float
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * Obter currency
     *
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Obter receiver MP User ID
     *
     * @return string
     */
    public function getReceiverMpUserId(): string
    {
        return $this->receiverMpUserId;
    }

    /**
     * Obter description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Obter metadata
     *
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Converter para payload da API MP
     *
     * @return array
     */
    public function toMercadoPagoPayload(): array
    {
        return [
            'amount' => $this->amount,
            'currency_id' => $this->currency,
            'receiver_id' => $this->receiverMpUserId,
            'description' => $this->description
        ];
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'payout_id' => $this->payoutId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'receiver_mp_user_id' => $this->receiverMpUserId,
            'description' => $this->description,
            'metadata' => $this->metadata
        ];
    }
}
