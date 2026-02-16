<?php
/**
 * FeedbackChecklist - Value Object
 *
 * RESPONSABILIDADE:
 * - Representar coleção de critérios avaliados
 * - Calcular score médio
 * - Validar completude do checklist
 *
 * @package LimpVix\Domain\Feedback
 * @since 0.3.0
 */

namespace LimpVix\Domain\Feedback;

defined('ABSPATH') || exit;

class FeedbackChecklist
{
    /**
     * @var FeedbackCriteria[]
     */
    private array $criteria;

    private string $serviceCategory;

    /**
     * @param FeedbackCriteria[] $criteria Array de critérios
     * @param string $serviceCategory Categoria do serviço
     * @throws \InvalidArgumentException
     */
    public function __construct(array $criteria, string $serviceCategory)
    {
        $this->validateCriteria($criteria);
        $this->criteria = $criteria;
        $this->serviceCategory = $serviceCategory;
    }

    /**
     * Criar checklist vazio para categoria
     *
     * @param string $category Categoria do serviço
     * @return self
     */
    public static function empty(string $category): self
    {
        return new self([], $category);
    }

    /**
     * Validar critérios
     *
     * @param array $criteria Critérios
     * @throws \InvalidArgumentException
     */
    private function validateCriteria(array $criteria): void
    {
        foreach ($criteria as $criterion) {
            if (!$criterion instanceof FeedbackCriteria) {
                throw new \InvalidArgumentException(
                    'Todos os itens devem ser instâncias de FeedbackCriteria'
                );
            }
        }
    }

    /**
     * Adicionar critério
     *
     * @param FeedbackCriteria $criteria Critério
     * @return self Nova instância (imutável)
     */
    public function addCriteria(FeedbackCriteria $criteria): self
    {
        $newCriteria = $this->criteria;
        $newCriteria[] = $criteria;

        return new self($newCriteria, $this->serviceCategory);
    }

    /**
     * Verificar se checklist está completo
     *
     * Valida se todos critérios obrigatórios da categoria foram preenchidos
     *
     * @return bool
     */
    public function isComplete(): bool
    {
        $requiredCriteria = FeedbackCriteria::getRequiredCriteriaForCategory($this->serviceCategory);
        $filledCriteriaIds = array_map(
            fn($c) => $c->getCriteriaId(),
            $this->criteria
        );

        foreach (array_keys($requiredCriteria) as $requiredId) {
            if (!in_array($requiredId, $filledCriteriaIds, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calcular score médio (1-5)
     *
     * @return float
     */
    public function getAverageScore(): float
    {
        if (empty($this->criteria)) {
            return 0.0;
        }

        $totalScore = array_reduce(
            $this->criteria,
            fn($carry, $c) => $carry + $c->getScore(),
            0
        );

        return round($totalScore / count($this->criteria), 2);
    }

    /**
     * Verificar se feedback é aprovado (média >= 3.0)
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->getAverageScore() >= 3.0;
    }

    /**
     * Verificar se feedback é excelente (média >= 4.5)
     *
     * @return bool
     */
    public function isExcellent(): bool
    {
        return $this->getAverageScore() >= 4.5;
    }

    /**
     * Obter critérios reprovados (score < 3)
     *
     * @return FeedbackCriteria[]
     */
    public function getFailedCriteria(): array
    {
        return array_filter(
            $this->criteria,
            fn($c) => !$c->isApproved()
        );
    }

    /**
     * Contar critérios
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->criteria);
    }

    /**
     * Obter todos critérios
     *
     * @return FeedbackCriteria[]
     */
    public function getCriteria(): array
    {
        return $this->criteria;
    }

    /**
     * Obter categoria do serviço
     *
     * @return string
     */
    public function getServiceCategory(): string
    {
        return $this->serviceCategory;
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'service_category' => $this->serviceCategory,
            'criteria' => array_map(
                fn($c) => $c->toArray(),
                $this->criteria
            ),
            'average_score' => $this->getAverageScore(),
            'is_complete' => $this->isComplete(),
            'is_approved' => $this->isApproved(),
        ];
    }

    /**
     * Converter para JSON
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
