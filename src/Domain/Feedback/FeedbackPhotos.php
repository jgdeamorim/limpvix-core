<?php
/**
 * FeedbackPhotos - Value Object
 *
 * RESPONSABILIDADE:
 * - Representar coleção de fotos do feedback
 * - Validar quantidade mínima (2 fotos obrigatórias)
 * - Garantir URLs válidas
 *
 * REGRAS:
 * - Mínimo 2 fotos (antes/depois ou resultado)
 * - Máximo 10 fotos
 * - URLs devem ser válidas
 *
 * @package LimpVix\Domain\Feedback
 * @since 0.3.0
 */

namespace LimpVix\Domain\Feedback;

defined('ABSPATH') || exit;

class FeedbackPhotos
{
    private array $photoUrls;

    const MIN_PHOTOS = 2;
    const MAX_PHOTOS = 10;

    /**
     * @param array $photoUrls Array de URLs de fotos
     * @throws \InvalidArgumentException
     */
    public function __construct(array $photoUrls)
    {
        $this->validatePhotos($photoUrls);
        $this->photoUrls = array_values($photoUrls); // Reindex
    }

    /**
     * Validar fotos
     *
     * @param array $photoUrls URLs das fotos
     * @throws \InvalidArgumentException
     */
    private function validatePhotos(array $photoUrls): void
    {
        // Validar quantidade mínima
        if (count($photoUrls) < self::MIN_PHOTOS) {
            throw new \InvalidArgumentException(
                sprintf('Mínimo de %d fotos obrigatórias', self::MIN_PHOTOS)
            );
        }

        // Validar quantidade máxima
        if (count($photoUrls) > self::MAX_PHOTOS) {
            throw new \InvalidArgumentException(
                sprintf('Máximo de %d fotos permitidas', self::MAX_PHOTOS)
            );
        }

        // Validar cada URL
        foreach ($photoUrls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException("URL inválida: {$url}");
            }
        }
    }

    /**
     * Verificar se tem fotos suficientes
     *
     * @return bool
     */
    public function hasMinimumPhotos(): bool
    {
        return count($this->photoUrls) >= self::MIN_PHOTOS;
    }

    /**
     * Obter contagem de fotos
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->photoUrls);
    }

    /**
     * Obter array de URLs
     *
     * @return array
     */
    public function getUrls(): array
    {
        return $this->photoUrls;
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->photoUrls;
    }

    /**
     * Converter para JSON
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->photoUrls, JSON_UNESCAPED_UNICODE);
    }
}
