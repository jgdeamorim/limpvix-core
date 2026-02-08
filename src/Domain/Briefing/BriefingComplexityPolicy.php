<?php
/**
 * BriefingComplexityPolicy - Policy para detecção de complexidade
 *
 * RESPONSABILIDADE:
 * - Determinar nível de complexidade de um Briefing
 * - Aplicar regras de negócio para classificação
 * - Gerar reasoning (justificativa) da classificação
 *
 * PRINCÍPIOS:
 * - Policy Pattern (regras de negócio isoladas)
 * - Configurável via options (thresholds)
 * - Determinístico (mesmas entradas = mesma saída)
 *
 * REGRAS DE DETECÇÃO:
 * - Simple: limpeza_basica, < 80m², < 180min (3h)
 * - Medium: limpeza_completa, 80-150m², 180-300min (3-5h)
 * - Complex: limpeza_pesada/pós-obra, > 150m², > 300min (5h+)
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.4.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class BriefingComplexityPolicy
{
    /**
     * Thresholds configuráveis
     */
    private const DEFAULT_THRESHOLDS = [
        'simple_max_m2' => 80,
        'simple_max_duration' => 180, // 3h
        'medium_max_m2' => 150,
        'medium_max_duration' => 300, // 5h
        'simple_multiplier' => 1.0,
        'medium_multiplier' => 1.3,
        'complex_multiplier' => 1.5
    ];

    /**
     * Determinar complexidade de um Briefing
     *
     * @param Briefing $briefing
     * @return Complexity
     */
    public static function determine(Briefing $briefing): Complexity
    {
        $metrics = $briefing->getMetrics();
        $frequency = $briefing->getFrequency();

        // Se não há métricas, retornar simple por padrão
        if (!$metrics) {
            return Complexity::simple();
        }

        $m2 = $metrics->getEstimatedM2();
        $duration = $metrics->getDurationMinutes();
        $thresholds = self::getThresholds();
        $reasoning = [];

        // 1. Verificar tipo de limpeza (via frequency ou structure)
        $cleaningType = self::detectCleaningType($briefing);

        if (in_array($cleaningType, ['limpeza_pesada', 'pos_obra'], true)) {
            $reasoning[] = "Tipo de limpeza: {$cleaningType} (complexa)";
            $reasoning[] = "Área: {$m2}m²";
            $reasoning[] = "Duração estimada: " . self::formatDuration($duration);

            return new Complexity(
                ComplexityLevel::complex(),
                $thresholds['complex_multiplier'],
                $reasoning
            );
        }

        // 2. Verificar por m² e duração (Medium)
        if ($m2 > $thresholds['simple_max_m2'] || $duration > $thresholds['simple_max_duration']) {
            if ($m2 <= $thresholds['medium_max_m2'] && $duration <= $thresholds['medium_max_duration']) {
                $reasoning[] = "Área: {$m2}m² (acima de {$thresholds['simple_max_m2']}m²)";
                $reasoning[] = "Duração: " . self::formatDuration($duration);

                if ($cleaningType === 'limpeza_completa') {
                    $reasoning[] = "Tipo: limpeza completa com detalhes";
                }

                return new Complexity(
                    ComplexityLevel::medium(),
                    $thresholds['medium_multiplier'],
                    $reasoning
                );
            }
        }

        // 3. Verificar por m² e duração (Complex)
        if ($m2 > $thresholds['medium_max_m2'] || $duration > $thresholds['medium_max_duration']) {
            $reasoning[] = "Área grande: {$m2}m² (acima de {$thresholds['medium_max_m2']}m²)";
            $reasoning[] = "Duração longa: " . self::formatDuration($duration);

            if ($m2 > 200) {
                $reasoning[] = "Área muito grande (> 200m²)";
            }

            return new Complexity(
                ComplexityLevel::complex(),
                $thresholds['complex_multiplier'],
                $reasoning
            );
        }

        // 4. Default: Simple
        $reasoning[] = "Limpeza básica padrão";
        $reasoning[] = "Área: {$m2}m²";
        $reasoning[] = "Duração: " . self::formatDuration($duration);

        return new Complexity(
            ComplexityLevel::simple(),
            $thresholds['simple_multiplier'],
            $reasoning
        );
    }

    /**
     * Detectar tipo de limpeza baseado no Briefing
     *
     * @param Briefing $briefing
     * @return string
     */
    private static function detectCleaningType(Briefing $briefing): string
    {
        $frequency = $briefing->getFrequency();

        // Tentar detectar via frequency
        if ($frequency) {
            $frequencyData = $frequency->toArray();

            // Se frequency tem tipo de limpeza, usar
            if (isset($frequencyData['cleaning_type'])) {
                return $frequencyData['cleaning_type'];
            }

            // Inferir baseado no tipo de frequência
            $type = $frequencyData['type'] ?? 'avulso';

            if ($type === 'avulso') {
                // Serviço avulso pode ser qualquer tipo
                // Verificar outros indicadores
                return 'limpeza_basica'; // Default conservador
            }

            // Serviços recorrentes geralmente são limpeza básica/completa
            return 'limpeza_basica';
        }

        // Default
        return 'limpeza_basica';
    }

    /**
     * Get thresholds configuráveis
     *
     * @return array
     */
    private static function getThresholds(): array
    {
        $saved = get_option('limpvix_complexity_thresholds', []);
        return array_merge(self::DEFAULT_THRESHOLDS, $saved);
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
     * Atualizar thresholds (admin)
     *
     * @param array $thresholds
     * @return bool
     */
    public static function updateThresholds(array $thresholds): bool
    {
        // Validar thresholds
        $required = ['simple_max_m2', 'simple_max_duration', 'medium_max_m2', 'medium_max_duration'];

        foreach ($required as $key) {
            if (!isset($thresholds[$key]) || !is_numeric($thresholds[$key])) {
                return false;
            }
        }

        return update_option('limpvix_complexity_thresholds', $thresholds);
    }

    /**
     * Reset thresholds para default
     *
     * @return bool
     */
    public static function resetThresholds(): bool
    {
        return delete_option('limpvix_complexity_thresholds');
    }
}
