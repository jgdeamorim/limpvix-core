<?php
/**
 * BriefingMetricsCalculator - Serviço de Cálculo de Métricas
 *
 * RESPONSABILIDADE:
 * - Calcular m² estimado baseado na estrutura do imóvel
 * - Calcular tempo de execução baseado em m² e tipo de limpeza
 * - Aplicar fatores multiplicadores (limpeza pesada, pós-obra, etc)
 * - Calcular buffer operacional
 *
 * PRINCÍPIOS:
 * - Service da camada Application (orquestração)
 * - Usa Value Objects do Domain
 * - Tabelas parametrizáveis (futuro: configuração admin)
 * - Fórmulas baseadas em especificação de negócio
 *
 * FÓRMULAS:
 * - m² base: (quartos×12) + (banheiros×4) + (sala?20:0) + ...
 * - Ajuste por tipo limpeza: m² × fator (pesada +10%, pós-obra +20%)
 * - Tempo base: m² × 3 minutos
 * - Fatores tempo: pesada +40%, pós-obra +70%, animais +15%, etc
 * - Buffer: 30-60 minutos (configurável)
 *
 * @package LimpVix\Application\Services
 * @since 0.2.0
 */

namespace LimpVix\Application\Services;

use LimpVix\Domain\Briefing\PropertyStructure;
use LimpVix\Domain\Briefing\EstimatedMetrics;

defined('ABSPATH') || exit;

class BriefingMetricsCalculator
{
    /**
     * Tabela de m² por cômodo (parametrizável)
     * Mesma tabela usada em PropertyStructure, mas pode ter ajustes futuros
     */
    private const M2_TABLE = [
        'bedroom' => 12,
        'bathroom' => 4,
        'living_room' => 20,
        'kitchen' => 10,
        'office' => 10,
        'external_area' => 25,
    ];

    /**
     * Tempo base por m² (minutos)
     */
    private const MINUTES_PER_M2 = 3;

    /**
     * Buffer operacional padrão (minutos)
     */
    private const DEFAULT_BUFFER_MINUTES = 30;

    /**
     * Fatores de ajuste de m² por tipo de limpeza
     *
     * Valores percentuais que serão somados (ex: 0.10 = +10%)
     */
    private const M2_FACTORS = [
        'limpeza_pesada' => 0.10,     // +10% m² virtual
        'pos_obra' => 0.20,            // +20% m² virtual
        'pre_mudanca' => 0.15,         // +15% m² virtual
        'limpeza_basica' => 0.0,       // sem ajuste
        'limpeza_completa' => 0.0,     // sem ajuste
    ];

    /**
     * Fatores de ajuste de tempo por tipo de limpeza
     *
     * Valores percentuais que serão somados (ex: 0.40 = +40%)
     */
    private const TIME_FACTORS = [
        'limpeza_pesada' => 0.40,      // +40% tempo
        'pos_obra' => 0.70,             // +70% tempo
        'pre_mudanca' => 0.30,          // +30% tempo
        'limpeza_basica' => 0.0,        // sem ajuste
        'limpeza_completa' => 0.05,     // +5% tempo
    ];

    /**
     * Fatores adicionais de tempo (condições do imóvel)
     */
    private const ADDITIONAL_TIME_FACTORS = [
        'tem_animais' => 0.15,         // +15% tempo
        'tem_criancas' => 0.10,        // +10% tempo
        'materiais_limpvix' => 0.10,   // +10% tempo (levar materiais)
    ];

    /**
     * Calcular m² estimado
     *
     * @param PropertyStructure $structure Estrutura do imóvel
     * @param array $cleaningTypes Tipos de limpeza selecionados ['limpeza_pesada', 'pos_obra', etc]
     * @return float m² final ajustado
     */
    public function calculateM2(PropertyStructure $structure, array $cleaningTypes = []): float
    {
        // m² base da estrutura
        $baseM2 = $structure->calculateEstimatedM2();

        // Aplicar fatores de ajuste por tipo de limpeza
        $factor = 1.0;
        foreach ($cleaningTypes as $type) {
            if (isset(self::M2_FACTORS[$type])) {
                $factor += self::M2_FACTORS[$type];
            }
        }

        return $baseM2 * $factor;
    }

    /**
     * Calcular tempo de execução (minutos)
     *
     * @param float $m2 m² calculado
     * @param array $cleaningTypes Tipos de limpeza selecionados
     * @param array $additionalConditions Condições adicionais ['tem_animais', 'tem_criancas', etc]
     * @return int Tempo em minutos
     */
    public function calculateDuration(
        float $m2,
        array $cleaningTypes = [],
        array $additionalConditions = []
    ): int {
        // Tempo base: m² × 3 minutos
        $baseMinutes = $m2 * self::MINUTES_PER_M2;

        // Aplicar fatores de tipo de limpeza
        $factor = 1.0;
        foreach ($cleaningTypes as $type) {
            if (isset(self::TIME_FACTORS[$type])) {
                $factor += self::TIME_FACTORS[$type];
            }
        }

        // Aplicar fatores de condições adicionais
        foreach ($additionalConditions as $condition) {
            if (isset(self::ADDITIONAL_TIME_FACTORS[$condition])) {
                $factor += self::ADDITIONAL_TIME_FACTORS[$condition];
            }
        }

        return (int) ceil($baseMinutes * $factor);
    }

    /**
     * Calcular buffer operacional (minutos)
     *
     * Buffer varia conforme a complexidade:
     * - Serviços simples: 30 min
     * - Serviços médios: 45 min
     * - Serviços complexos: 60 min
     *
     * @param int $durationMinutes Tempo de execução calculado
     * @param array $cleaningTypes Tipos de limpeza
     * @return int Buffer em minutos
     */
    public function calculateBuffer(int $durationMinutes, array $cleaningTypes = []): int
    {
        // Buffer base
        $buffer = self::DEFAULT_BUFFER_MINUTES;

        // Aumentar buffer para serviços complexos
        $complexTypes = ['limpeza_pesada', 'pos_obra', 'pre_mudanca'];
        $hasComplexService = !empty(array_intersect($cleaningTypes, $complexTypes));

        if ($hasComplexService) {
            $buffer = 60; // 1 hora para serviços complexos
        } elseif ($durationMinutes > 180) {
            $buffer = 45; // 45 min para serviços médios (> 3h)
        }

        return $buffer;
    }

    /**
     * Calcular métricas completas
     *
     * Método principal que combina todos os cálculos
     *
     * @param PropertyStructure $structure Estrutura do imóvel
     * @param array $cleaningTypes Tipos de limpeza ['limpeza_pesada', etc]
     * @param array $additionalConditions Condições ['tem_animais', etc]
     * @return EstimatedMetrics Métricas calculadas
     */
    public function calculate(
        PropertyStructure $structure,
        array $cleaningTypes = [],
        array $additionalConditions = []
    ): EstimatedMetrics {
        // 1. Calcular m² ajustado
        $m2 = $this->calculateM2($structure, $cleaningTypes);

        // 2. Calcular tempo de execução
        $durationMinutes = $this->calculateDuration($m2, $cleaningTypes, $additionalConditions);

        // 3. Calcular buffer operacional
        $bufferMinutes = $this->calculateBuffer($durationMinutes, $cleaningTypes);

        // 4. Retornar métricas encapsuladas
        return new EstimatedMetrics($m2, $durationMinutes, $bufferMinutes);
    }

    /**
     * Obter fatores disponíveis para m²
     *
     * Útil para UI/Admin exibir configurações
     *
     * @return array
     */
    public function getM2Factors(): array
    {
        return self::M2_FACTORS;
    }

    /**
     * Obter fatores disponíveis para tempo
     *
     * @return array
     */
    public function getTimeFa (): array
    {
        return array_merge(self::TIME_FACTORS, self::ADDITIONAL_TIME_FACTORS);
    }

    /**
     * Validar tipos de limpeza
     *
     * @param array $cleaningTypes
     * @return bool
     */
    public function validateCleaningTypes(array $cleaningTypes): bool
    {
        $validTypes = array_keys(self::TIME_FACTORS);

        foreach ($cleaningTypes as $type) {
            if (!in_array($type, $validTypes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Estimar preço baseado em métricas (opcional/futuro)
     *
     * Nota: Precificação pode ser mais complexa e ter sua própria Policy
     *
     * @param EstimatedMetrics $metrics
     * @param float $pricePerMinute Preço por minuto de trabalho
     * @return float Preço estimado
     */
    public function estimatePrice(EstimatedMetrics $metrics, float $pricePerMinute = 1.5): float
    {
        // Preço base: tempo total × preço por minuto
        $totalMinutes = $metrics->getTotalDurationMinutes();
        $basePrice = $totalMinutes * $pricePerMinute;

        // Arredondar para múltiplo de 10
        return ceil($basePrice / 10) * 10;
    }
}
