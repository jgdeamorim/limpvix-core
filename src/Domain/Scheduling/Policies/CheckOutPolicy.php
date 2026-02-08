<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Policies;

use LimpVix\Domain\Scheduling\ValueObjects\CheckIn;
use LimpVix\Domain\Scheduling\ValueObjects\CheckOut;
use LimpVix\Domain\Scheduling\ValueObjects\MediaCollection;

/**
 * Policy: CheckOutPolicy
 *
 * Regras de negócio para validação de check-out.
 */
final class CheckOutPolicy
{
    private const MIN_PHOTOS_REQUIRED = 1; // Pelo menos 1 foto do resultado
    private const DURATION_VARIANCE_TOLERANCE_PERCENT = 50; // ±50% de tolerância

    /**
     * Valida se check-out pode ser aceito
     *
     * Critérios:
     * - Check-in foi realizado
     * - Mídia válida (fotos do resultado)
     * - Duração razoável (não zero, não absurdamente longa)
     *
     * @return array{valid: bool, errors: string[]}
     */
    public static function validate(
        ?CheckIn $checkIn,
        MediaCollection $media,
        int $actualDurationMinutes,
        int $estimatedDurationMinutes
    ): array {
        $errors = [];

        // 1. Check-in deve ter sido feito
        if ($checkIn === null) {
            $errors[] = 'Check-in not performed';
        }

        // 2. Validar mídia
        if (!self::validateMedia($media)) {
            $errors[] = 'Insufficient media (at least 1 photo required)';
        }

        // 3. Validar duração
        $durationValidation = self::validateDuration(
            $actualDurationMinutes,
            $estimatedDurationMinutes
        );

        if (!$durationValidation['valid']) {
            $errors = array_merge($errors, $durationValidation['errors']);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Valida mídia do check-out
     * Requer pelo menos 1 foto do resultado
     */
    public static function validateMedia(MediaCollection $media): bool
    {
        return $media->hasAtLeast(self::MIN_PHOTOS_REQUIRED);
    }

    /**
     * Valida duração real do serviço
     *
     * @return array{valid: bool, errors: string[]}
     */
    public static function validateDuration(
        int $actualDurationMinutes,
        int $estimatedDurationMinutes
    ): array {
        $errors = [];

        // Duração deve ser positiva
        if ($actualDurationMinutes <= 0) {
            $errors[] = 'Duration must be positive';
        }

        // Duração não pode ser absurdamente curta (< 30min)
        if ($actualDurationMinutes < 30) {
            $errors[] = 'Duration too short (minimum 30 minutes)';
        }

        // Avisar se variação for muito grande (não bloqueia, apenas avisa)
        $variance = abs(
            (($actualDurationMinutes - $estimatedDurationMinutes) / $estimatedDurationMinutes) * 100
        );

        if ($variance > self::DURATION_VARIANCE_TOLERANCE_PERCENT) {
            // Não adiciona erro, apenas retorna warning
            // Isso pode ser logado mas não bloqueia o checkout
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'variance_percent' => $variance ?? 0,
            'within_tolerance' => ($variance ?? 0) <= self::DURATION_VARIANCE_TOLERANCE_PERCENT,
        ];
    }

    /**
     * Verifica se duração está dentro da tolerância esperada
     */
    public static function isDurationWithinTolerance(
        int $actualDurationMinutes,
        int $estimatedDurationMinutes,
        int $tolerancePercent = self::DURATION_VARIANCE_TOLERANCE_PERCENT
    ): bool {
        $variance = abs(
            (($actualDurationMinutes - $estimatedDurationMinutes) / $estimatedDurationMinutes) * 100
        );

        return $variance <= $tolerancePercent;
    }

    /**
     * Calcula ajuste de preço baseado na duração real vs estimada
     *
     * Se duração real > estimada em mais de 50%, pode haver ajuste
     *
     * @return array{should_adjust: bool, adjustment_percent: float}
     */
    public static function calculatePriceAdjustment(
        int $actualDurationMinutes,
        int $estimatedDurationMinutes
    ): array {
        $variance = (($actualDurationMinutes - $estimatedDurationMinutes) / $estimatedDurationMinutes) * 100;

        // Se excedeu em mais de 50%, sugerir ajuste proporcional
        if ($variance > self::DURATION_VARIANCE_TOLERANCE_PERCENT) {
            return [
                'should_adjust' => true,
                'adjustment_percent' => $variance - self::DURATION_VARIANCE_TOLERANCE_PERCENT,
                'reason' => sprintf(
                    'Duration exceeded estimate by %.1f%% (actual: %dmin, estimated: %dmin)',
                    $variance,
                    $actualDurationMinutes,
                    $estimatedDurationMinutes
                ),
            ];
        }

        return [
            'should_adjust' => false,
            'adjustment_percent' => 0,
            'reason' => 'Duration within acceptable tolerance',
        ];
    }

    /**
     * Verifica se checkout é válido para liberar payout
     */
    public static function canAuthorizePayout(CheckOut $checkOut): bool
    {
        // Checkout válido sempre pode autorizar payout
        // Ajustes de preço são tratados separadamente
        return $checkOut->getMedia()->count() > 0;
    }
}
