<?php
/**
 * FeedbackCriteria - Value Object
 *
 * RESPONSABILIDADE:
 * - Representar um critério de avaliação específico
 * - Validar score (1-5)
 * - Garantir imutabilidade
 *
 * CRITÉRIOS POR CATEGORIA:
 * - Limpeza Básica: limpeza_geral, banheiro, cozinha, vidros, chao
 * - Pós-Obra: remocao_entulho, limpeza_paredes, limpeza_teto
 * - Teto: qualidade_limpeza, cuidado_com_moveis
 * - Esquadrias: limpeza_esquadrias, limpeza_vidros, acabamento
 *
 * @package LimpVix\Domain\Feedback
 * @since 0.3.0
 */

namespace LimpVix\Domain\Feedback;

defined('ABSPATH') || exit;

class FeedbackCriteria
{
    private string $criteriaId;
    private string $label;
    private int $score; // 1-5
    private ?string $observation;

    /**
     * Critérios válidos por categoria
     */
    const VALID_CRITERIA = [
        'limpeza_basica' => [
            'limpeza_geral' => 'Limpeza Geral',
            'banheiro' => 'Banheiro',
            'cozinha' => 'Cozinha',
            'vidros' => 'Vidros',
            'chao' => 'Chão',
        ],
        'pos_obra' => [
            'remocao_entulho' => 'Remoção de Entulho',
            'limpeza_paredes' => 'Limpeza de Paredes',
            'limpeza_teto' => 'Limpeza de Teto',
            'limpeza_geral' => 'Limpeza Geral Final',
        ],
        'teto' => [
            'qualidade_limpeza' => 'Qualidade da Limpeza',
            'cuidado_com_moveis' => 'Cuidado com Móveis',
            'limpeza_completa' => 'Limpeza Completa',
        ],
        'esquadrias' => [
            'limpeza_esquadrias' => 'Limpeza de Esquadrias',
            'limpeza_vidros' => 'Limpeza de Vidros',
            'acabamento' => 'Acabamento',
        ],
        'geral' => [
            'pontualidade' => 'Pontualidade',
            'profissionalismo' => 'Profissionalismo',
            'cordialidade' => 'Cordialidade',
        ],
    ];

    public function __construct(
        string $criteriaId,
        string $label,
        int $score,
        ?string $observation = null
    ) {
        $this->validateScore($score);

        $this->criteriaId = $criteriaId;
        $this->label = $label;
        $this->score = $score;
        $this->observation = $observation;
    }

    /**
     * Criar critério a partir de ID e categoria
     *
     * @param string $criteriaId ID do critério
     * @param string $category Categoria do serviço
     * @param int $score Score (1-5)
     * @param string|null $observation Observação
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromCategory(
        string $criteriaId,
        string $category,
        int $score,
        ?string $observation = null
    ): self {
        $validCriteria = self::VALID_CRITERIA[$category] ?? [];

        if (!isset($validCriteria[$criteriaId])) {
            throw new \InvalidArgumentException(
                "Critério '{$criteriaId}' não é válido para categoria '{$category}'"
            );
        }

        return new self(
            $criteriaId,
            $validCriteria[$criteriaId],
            $score,
            $observation
        );
    }

    /**
     * Obter todos critérios para uma categoria
     *
     * @param string $category Categoria
     * @return array
     */
    public static function getRequiredCriteriaForCategory(string $category): array
    {
        return self::VALID_CRITERIA[$category] ?? [];
    }

    /**
     * Verificar se critério é aprovado (score >= 3)
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->score >= 3;
    }

    /**
     * Verificar se critério é excelente (score >= 4)
     *
     * @return bool
     */
    public function isExcellent(): bool
    {
        return $this->score >= 4;
    }

    /**
     * Validar score (1-5)
     *
     * @param int $score Score
     * @throws \InvalidArgumentException
     */
    private function validateScore(int $score): void
    {
        if ($score < 1 || $score > 5) {
            throw new \InvalidArgumentException('Score deve estar entre 1 e 5');
        }
    }

    // Getters
    public function getCriteriaId(): string { return $this->criteriaId; }
    public function getLabel(): string { return $this->label; }
    public function getScore(): int { return $this->score; }
    public function getObservation(): ?string { return $this->observation; }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'criteria_id' => $this->criteriaId,
            'label' => $this->label,
            'score' => $this->score,
            'observation' => $this->observation,
        ];
    }
}
