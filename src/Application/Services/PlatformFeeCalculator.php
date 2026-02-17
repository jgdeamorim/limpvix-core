<?php
/**
 * PlatformFeeCalculator - Serviço de cálculo de taxa/comissão LimpVix
 *
 * RESPONSABILIDADE:
 * - Calcular taxa da plataforma baseado em regras de negócio
 * - Retornar breakdown detalhado (total, fee, líquido)
 * - Parametrização via configuração
 *
 * PRINCÍPIOS:
 * - Single Responsibility (apenas cálculo de taxa)
 * - Configurável (fee percentage pode variar)
 * - Auditável (logging de cálculos)
 *
 * USO:
 * ```php
 * $calculator = new PlatformFeeCalculator();
 * $breakdown = $calculator->calculate(200.00); // R$200
 * // Retorna: ['total' => 200, 'fee_pct' => 15, 'fee_amount' => 30, 'net' => 170]
 * ```
 *
 * @package LimpVix\Application\Services
 */

namespace LimpVix\Application\Services;

use LimpVix\Infrastructure\Configuration\PlatformFeeConfig;

defined('ABSPATH') || exit;

class PlatformFeeCalculator
{
    /**
     * Taxa padrão — delegado ao PlatformFeeConfig (SSOT).
     * Mantido como referência local para validação.
     *
     * @var float
     */
    private const DEFAULT_FEE_PERCENTAGE = PlatformFeeConfig::DEFAULT_FEE_PERCENTAGE;

    /**
     * Taxa configurada (override via WordPress option)
     *
     * @var float|null
     */
    private $configuredFeePercentage;

    /**
     * Construtor
     */
    public function __construct()
    {
        // Buscar taxa configurada (se existir opção admin)
        $this->configuredFeePercentage = $this->loadConfiguredFee();
    }

    /**
     * Calcular taxa da plataforma
     *
     * @param float $totalAmount Valor total da order (pago pelo cliente)
     * @param float|null $customFeePercentage Taxa customizada (override pontual)
     * @return array Breakdown: ['total_amount', 'platform_fee_percentage', 'platform_fee_amount', 'professional_net_amount']
     */
    public function calculate(float $totalAmount, ?float $customFeePercentage = null): array
    {
        // Determinar percentual a usar
        $feePercentage = $customFeePercentage ?? $this->configuredFeePercentage ?? self::DEFAULT_FEE_PERCENTAGE;

        // Validar percentual
        if ($feePercentage < 0 || $feePercentage > 100) {
            throw new \InvalidArgumentException(
                "Fee percentage deve estar entre 0 e 100 (recebido: {$feePercentage})"
            );
        }

        // Calcular valores
        $feeAmount = round($totalAmount * ($feePercentage / 100), 2);
        $netAmount = round($totalAmount - $feeAmount, 2);

        // Validar resultado
        if ($netAmount < 0) {
            throw new \RuntimeException(
                "Net amount negativo: total R\${$totalAmount} - fee R\${$feeAmount} = R\${$netAmount}"
            );
        }

        $breakdown = [
            'total_amount' => $totalAmount,
            'platform_fee_percentage' => $feePercentage,
            'platform_fee_amount' => $feeAmount,
            'professional_net_amount' => $netAmount
        ];

        // Log para auditoria
        $this->logCalculation($breakdown);

        return $breakdown;
    }

    /**
     * Obter percentual de taxa configurado
     *
     * @return float
     */
    public function getFeePercentage(): float
    {
        return $this->configuredFeePercentage ?? self::DEFAULT_FEE_PERCENTAGE;
    }

    /**
     * Carregar taxa configurada do WordPress
     *
     * @return float|null
     */
    private function loadConfiguredFee(): ?float
    {
        // Delega ao PlatformFeeConfig (Single Source of Truth).
        // getFeePercentage() já trata migração de chave legada e retorna DEFAULT se não configurado.
        $fee = PlatformFeeConfig::getFeePercentage();

        // Retornar null apenas se igual ao default — assim calculate() decide usar DEFAULT_FEE_PERCENTAGE
        // Mas aqui SSOT já retorna o valor correto, inclusive o default, então podemos retornar direto.
        return $fee;
    }

    /**
     * Log de cálculo para auditoria
     *
     * @param array $breakdown
     * @return void
     */
    private function logCalculation(array $breakdown): void
    {
        if (!function_exists('error_log')) {
            return;
        }

        error_log(sprintf(
            "[PlatformFeeCalculator] Total: R$%.2f | Fee: %.2f%% = R$%.2f | Líquido Profissional: R$%.2f",
            $breakdown['total_amount'],
            $breakdown['platform_fee_percentage'],
            $breakdown['platform_fee_amount'],
            $breakdown['professional_net_amount']
        ));
    }
}
