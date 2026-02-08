<?php
declare(strict_types=1);

namespace LimpVix\Domain\Execution\ValueObjects;

/**
 * EvidenceCollection - Coleção de Evidências (Sprint 1 - Dia 1)
 *
 * Value Object imutável representando coleção de evidências.
 * Garante pelo menos 1 evidência presente.
 *
 * PRINCÍPIOS:
 * - Imutável (lista de Evidence é readonly)
 * - Auto-validação (mínimo 1 evidência)
 * - Sem side effects
 *
 * @package LimpVix\Domain\Execution\ValueObjects
 */
final readonly class EvidenceCollection
{
    /**
     * @var array<Evidence>
     */
    private array $evidences;

    /**
     * Construtor
     *
     * @param array<Evidence> $evidences Lista de evidências
     * @throws \InvalidArgumentException Se coleção vazia
     */
    public function __construct(array $evidences)
    {
        if (empty($evidences)) {
            throw new \InvalidArgumentException(
                'Evidence collection cannot be empty (at least 1 evidence required)'
            );
        }

        // Garantir que todos elementos são Evidence
        foreach ($evidences as $evidence) {
            if (!$evidence instanceof Evidence) {
                throw new \InvalidArgumentException(
                    'All elements must be Evidence instances'
                );
            }
        }

        $this->evidences = array_values($evidences); // Re-index
    }

    /**
     * Factory: Criar coleção com uma única evidência
     *
     * @param Evidence $evidence
     * @return self
     */
    public static function single(Evidence $evidence): self
    {
        return new self([$evidence]);
    }

    /**
     * Conta quantas evidências existem
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->evidences);
    }

    /**
     * Verifica se tem pelo menos uma foto
     *
     * @return bool
     */
    public function hasPhotos(): bool
    {
        foreach ($this->evidences as $evidence) {
            if ($evidence->isPhoto()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica se tem pelo menos um vídeo
     *
     * @return bool
     */
    public function hasVideos(): bool
    {
        foreach ($this->evidences as $evidence) {
            if ($evidence->isVideo()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retorna todas evidências
     *
     * @return array<Evidence>
     */
    public function all(): array
    {
        return $this->evidences;
    }

    /**
     * Filtra apenas fotos
     *
     * @return array<Evidence>
     */
    public function photos(): array
    {
        return array_filter(
            $this->evidences,
            fn(Evidence $e) => $e->isPhoto()
        );
    }

    /**
     * Filtra apenas vídeos
     *
     * @return array<Evidence>
     */
    public function videos(): array
    {
        return array_filter(
            $this->evidences,
            fn(Evidence $e) => $e->isVideo()
        );
    }

    /**
     * Converte para array de URLs
     *
     * @return array<string>
     */
    public function toUrls(): array
    {
        return array_map(
            fn(Evidence $e) => $e->url,
            $this->evidences
        );
    }
}
