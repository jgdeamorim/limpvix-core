<?php
/**
 * PayoutProviderInterface - Contrato para Providers de Payout
 *
 * RESPONSABILIDADE:
 * - Definir contrato único para execução de payout
 * - Agnóstico de provider (MP, PIX, Stripe, etc)
 * - Port (Hexagonal Architecture)
 *
 * PRINCÍPIOS:
 * - Interface Segregation
 * - Dependency Inversion
 * - Open/Closed
 *
 * IMPLEMENTAÇÕES:
 * - MercadoPagoPayoutProvider (MP → MP)
 * - Futuro: PixPayoutProvider
 * - Futuro: StripePayoutProvider
 *
 * IMPORTANTE:
 * - transfer() NÃO decide se pode executar
 * - transfer() NÃO valida regras de negócio
 * - transfer() apenas executa tecnicamente
 *
 * PASSO 5.5 - Payout Engine
 *
 * @package LimpVix\Modules\Payouts
 */

namespace LimpVix\Modules\Payouts;

use LimpVix\Modules\Payouts\MercadoPago\Payout;
use LimpVix\Modules\Payouts\MercadoPago\PayoutResult;

defined('ABSPATH') || exit;

interface PayoutProviderInterface
{
    /**
     * Executar transferência
     *
     * IMPORTANTE:
     * - Esta operação NÃO deve ser idempotente no provider
     * - Idempotência é responsabilidade da camada superior
     * - Não fazer retry automático
     * - Retornar resultado técnico puro
     *
     * @param Payout $payout Dados do payout
     * @return PayoutResult Resultado da execução
     */
    public function transfer(Payout $payout): PayoutResult;

    /**
     * Verificar se provider está disponível
     *
     * Verifica configuração, conectividade, etc
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Obter nome do provider
     *
     * @return string Ex: "mercadopago", "pix", "stripe"
     */
    public function getName(): string;
}
