<?php
/**
 * ProfessionalAllocationPolicy - Policy para determinar alocação de profissionais
 *
 * RESPONSABILIDADE:
 * - Calcular quantos profissionais são necessários para um Briefing
 * - Aplicar regras de negócio baseadas em:
 *   - Duração estimada (> 5h = múltiplos profissionais)
 *   - Complexity (complex = mínimo 2 profissionais se > 150m²)
 *   - Package (premium = mínimo 2 profissionais)
 *   - m² (> 200m² = sempre múltiplos)
 *
 * PRINCÍPIOS:
 * - Policy Pattern (regras de negócio isoladas)
 * - Configurável via options (thresholds)
 * - Reasoning detalhado para cada decisão
 *
 * REGRAS PRINCIPAIS:
 * 1. Duração base: > 5h (300min) → 1 profissional adicional a cada 5h
 * 2. Complexity complex + área grande (> 150m²) → mínimo 2
 * 3. Package premium → mínimo 2
 * 4. Área muito grande (> 200m²) → sempre múltiplos
 * 5. Pós-obra → sempre múltiplos (mínimo 2)
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.4.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class ProfessionalAllocationPolicy
{
    /**
     * Thresholds configuráveis
     */
    private const DEFAULT_CONFIG = [
        'base_duration_per_professional' => 300, // 5h por profissional
        'large_area_threshold' => 150, // m² para considerar área grande
        'very_large_area_threshold' => 200, // m² para forçar múltiplos
        'complex_min_professionals' => 2, // Mínimo para complexity complex
        'premium_min_professionals' => 2, // Mínimo para package premium
        'max_professionals_allowed' => 5 // Máximo absoluto
    ];

    /**
     * Calcular profissionais necessários para um Briefing
     *
     * @param Briefing $briefing
     * @return ProfessionalAllocation
     */
    public static function calculate(Briefing $briefing): ProfessionalAllocation
    {
        $metrics = $briefing->getMetrics();
        $complexity = $briefing->getComplexity();
        $package = $briefing->getPackage();

        // Se não há métricas, retornar single por padrão
        if (!$metrics) {
            return ProfessionalAllocation::single();
        }

        $config = self::getConfig();
        $m2 = $metrics->getEstimatedM2();
        $totalDuration = $metrics->getDurationMinutes() + $metrics->getBufferMinutes();
        $reasoning = [];
        $requiredCount = 1;

        // 1. Cálculo base por duração
        $countByDuration = (int) ceil($totalDuration / $config['base_duration_per_professional']);
        if ($countByDuration > 1) {
            $reasoning[] = "Duração total: " . self::formatDuration($totalDuration) . " (> 5h)";
            $reasoning[] = "Cálculo: " . ceil($totalDuration / 60) . "h ÷ 5h = {$countByDuration} profissionais";
        }
        $requiredCount = max($requiredCount, $countByDuration);

        // 2. Área muito grande (forçar múltiplos)
        if ($m2 > $config['very_large_area_threshold']) {
            $countByArea = (int) ceil($m2 / 100); // 1 profissional a cada 100m²
            $reasoning[] = "Área muito grande: {$m2}m² (> {$config['very_large_area_threshold']}m²)";
            $reasoning[] = "Requer múltiplos profissionais para eficiência";
            $requiredCount = max($requiredCount, $countByArea, 2);
        }

        // 3. Complexity complex + área grande
        if ($complexity && $complexity->getLevel()->isComplex()) {
            if ($m2 > $config['large_area_threshold']) {
                $reasoning[] = "Complexidade: Complex + Área: {$m2}m² (> {$config['large_area_threshold']}m²)";
                $reasoning[] = "Serviço complexo em área grande requer mínimo {$config['complex_min_professionals']} profissionais";
                $requiredCount = max($requiredCount, $config['complex_min_professionals']);
            }
        }

        // 4. Premium execution level or legacy package premium (mínimo 2 profissionais)
        $isPremium = false;
        if ($package && $package->getType()->isPremium()) {
            $isPremium = true;
        }
        // New system: check ExecutionLevel if available on briefing
        if (!$isPremium && method_exists($briefing, 'getExecutionLevel')) {
            $executionLevel = $briefing->getExecutionLevel();
            if ($executionLevel && method_exists($executionLevel, 'getType') && $executionLevel->getType()->isPremium()) {
                $isPremium = true;
            }
        }
        if ($isPremium) {
            $reasoning[] = "Pacote/Nivel Premium contratado";
            $reasoning[] = "Minimo {$config['premium_min_professionals']} profissionais para qualidade premium";
            $requiredCount = max($requiredCount, $config['premium_min_professionals']);
        }

        // 5. Pós-obra (sempre múltiplos)
        if (self::isPostConstruction($briefing)) {
            $reasoning[] = "Serviço: Limpeza pós-obra";
            $reasoning[] = "Sempre requer múltiplos profissionais (mínimo 2)";
            $requiredCount = max($requiredCount, 2);
        }

        // 6. Cap no máximo permitido
        if ($requiredCount > $config['max_professionals_allowed']) {
            $reasoning[] = "Limitado ao máximo de {$config['max_professionals_allowed']} profissionais";
            $requiredCount = $config['max_professionals_allowed'];
        }

        // 7. Se apenas 1, adicionar reasoning
        if ($requiredCount === 1) {
            $reasoning[] = "Serviço padrão: 1 profissional";
            $reasoning[] = "Área: {$m2}m², Duração: " . self::formatDuration($totalDuration);
        }

        return new ProfessionalAllocation(
            $requiredCount,
            $reasoning,
            $config['max_professionals_allowed']
        );
    }

    /**
     * Verificar se é pós-obra
     *
     * @param Briefing $briefing
     * @return bool
     */
    private static function isPostConstruction(Briefing $briefing): bool
    {
        $frequency = $briefing->getFrequency();

        if (!$frequency) {
            return false;
        }

        $frequencyData = $frequency->toArray();
        $cleaningType = $frequencyData['cleaning_type'] ?? '';

        return in_array($cleaningType, ['pos_obra', 'limpeza_pesada'], true);
    }

    /**
     * Calcular profissionais para múltiplos briefings (batch)
     *
     * @param Briefing[] $briefings
     * @return array ['briefing_uuid' => ProfessionalAllocation]
     */
    public static function calculateBatch(array $briefings): array
    {
        $results = [];

        foreach ($briefings as $briefing) {
            $results[$briefing->getUuid()] = self::calculate($briefing);
        }

        return $results;
    }

    /**
     * Get configuration
     *
     * @return array
     */
    private static function getConfig(): array
    {
        $saved = get_option('limpvix_professional_allocation_config', []);
        return array_merge(self::DEFAULT_CONFIG, $saved);
    }

    /**
     * Update configuration (admin)
     *
     * @param array $config
     * @return bool
     */
    public static function updateConfig(array $config): bool
    {
        // Validar config
        $required = [
            'base_duration_per_professional',
            'large_area_threshold',
            'complex_min_professionals',
            'max_professionals_allowed'
        ];

        foreach ($required as $key) {
            if (!isset($config[$key]) || !is_numeric($config[$key])) {
                return false;
            }
        }

        return update_option('limpvix_professional_allocation_config', $config);
    }

    /**
     * Reset configuration para default
     *
     * @return bool
     */
    public static function resetConfig(): bool
    {
        return delete_option('limpvix_professional_allocation_config');
    }

    /**
     * Formatar duração para exibição
     *
     * @param int $minutes
     * @return string
     */
    private static function formatDuration(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h{$mins}min";
        } elseif ($hours > 0) {
            return "{$hours}h";
        } else {
            return "{$mins}min";
        }
    }

    /**
     * Simular cálculo (para preview no admin)
     *
     * @param float $m2
     * @param int $durationMinutes
     * @param string|null $complexityLevel
     * @param string|null $packageType
     * @return array
     */
    public static function simulate(
        float $m2,
        int $durationMinutes,
        ?string $complexityLevel = null,
        ?string $packageType = null
    ): array {
        $config = self::getConfig();
        $reasoning = [];
        $requiredCount = 1;

        // Duração
        $countByDuration = (int) ceil($durationMinutes / $config['base_duration_per_professional']);
        if ($countByDuration > 1) {
            $reasoning[] = "Duração: " . self::formatDuration($durationMinutes) . " → {$countByDuration} profissionais";
        }
        $requiredCount = max($requiredCount, $countByDuration);

        // Área
        if ($m2 > $config['very_large_area_threshold']) {
            $reasoning[] = "Área muito grande: {$m2}m²";
            $requiredCount = max($requiredCount, 2);
        }

        // Complexity
        if ($complexityLevel === 'complex' && $m2 > $config['large_area_threshold']) {
            $reasoning[] = "Complex + área grande";
            $requiredCount = max($requiredCount, $config['complex_min_professionals']);
        }

        // Package or ExecutionLevel premium
        if ($packageType === 'premium' || $packageType === 'premium_execution') {
            $reasoning[] = "Pacote/Nivel Premium";
            $requiredCount = max($requiredCount, $config['premium_min_professionals']);
        }

        // Cap
        $requiredCount = min($requiredCount, $config['max_professionals_allowed']);

        return [
            'required_count' => $requiredCount,
            'reasoning' => $reasoning,
            'display' => $requiredCount === 1 ? "1 profissional" : "{$requiredCount} profissionais"
        ];
    }
}
