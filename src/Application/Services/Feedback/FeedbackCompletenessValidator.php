<?php
/**
 * FeedbackCompletenessValidator - Application Service
 *
 * RESPONSABILIDADE:
 * - Validar se feedback está completo antes de submeter
 * - Verificar critérios obrigatórios por categoria
 * - Validar fotos mínimas
 *
 * @package LimpVix\Application\Services\Feedback
 * @since 0.3.0
 */

namespace LimpVix\Application\Services\Feedback;

use LimpVix\Domain\Feedback\FeedbackCriteria;

defined('ABSPATH') || exit;

class FeedbackCompletenessValidator
{
    /**
     * Validar completude do feedback
     *
     * @param array $feedbackData Dados do feedback
     * @return array Resultado da validação
     *   - is_complete: bool
     *   - missing_criteria: array
     *   - missing_photos: bool
     */
    public function validate(array $feedbackData): array
    {
        $category = $feedbackData['service_category'] ?? null;
        $criteria = $feedbackData['criteria'] ?? [];
        $photos = $feedbackData['photos'] ?? [];

        // Validar categoria
        if (!$category) {
            return [
                'is_complete' => false,
                'error' => 'Categoria do serviço não informada',
            ];
        }

        // Obter critérios obrigatórios
        $requiredCriteria = FeedbackCriteria::getRequiredCriteriaForCategory($category);

        // Verificar quais critérios estão faltando
        $filledCriteriaIds = array_column($criteria, 'criteria_id');
        $missingCriteria = [];

        foreach (array_keys($requiredCriteria) as $requiredId) {
            if (!in_array($requiredId, $filledCriteriaIds, true)) {
                $missingCriteria[] = [
                    'criteria_id' => $requiredId,
                    'label' => $requiredCriteria[$requiredId],
                ];
            }
        }

        // Verificar fotos
        $hasMinimumPhotos = is_array($photos) && count($photos) >= 2;

        // Verificar se está completo
        $isComplete = empty($missingCriteria) && $hasMinimumPhotos;

        return [
            'is_complete' => $isComplete,
            'missing_criteria' => $missingCriteria,
            'missing_photos' => !$hasMinimumPhotos,
            'photos_count' => count($photos),
            'criteria_count' => count($criteria),
            'required_criteria_count' => count($requiredCriteria),
        ];
    }

    /**
     * Obter critérios obrigatórios para categoria
     *
     * @param string $category Categoria
     * @return array
     */
    public function getRequiredCriteria(string $category): array
    {
        return FeedbackCriteria::getRequiredCriteriaForCategory($category);
    }
}
