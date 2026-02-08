<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: MediaCollection
 *
 * Coleção de URLs de mídia (fotos/vídeos).
 * Validação de formato e contagem.
 *
 * IMUTÁVEL.
 */
final class MediaCollection
{
    private array $urls;

    private function __construct(array $urls)
    {
        if (empty($urls)) {
            throw new \InvalidArgumentException('Media collection cannot be empty');
        }

        foreach ($urls as $url) {
            $this->validateUrl($url);
        }

        $this->urls = array_values(array_unique($urls)); // Remove duplicatas
    }

    public static function fromUrls(array $urls): self
    {
        return new self($urls);
    }

    /**
     * Factory: Coleção com apenas uma foto
     */
    public static function singlePhoto(string $url): self
    {
        return new self([$url]);
    }

    /**
     * Factory: Coleção com apenas um vídeo
     */
    public static function singleVideo(string $url): self
    {
        return new self([$url]);
    }

    /**
     * Factory: A partir de array (hidratação)
     */
    public static function fromArray(array $data): self
    {
        return new self($data['urls'] ?? []);
    }

    /**
     * Adiciona nova URL (retorna nova instância - imutabilidade)
     */
    public function withUrl(string $url): self
    {
        $newUrls = $this->urls;
        $newUrls[] = $url;

        return new self($newUrls);
    }

    /**
     * Conta total de itens
     */
    public function count(): int
    {
        return count($this->urls);
    }

    /**
     * Verifica se tem pelo menos N itens
     */
    public function hasAtLeast(int $count): bool
    {
        return $this->count() >= $count;
    }

    /**
     * Verifica se contém apenas uma mídia
     */
    public function isSingle(): bool
    {
        return $this->count() === 1;
    }

    /**
     * Retorna primeira URL
     */
    public function first(): string
    {
        return $this->urls[0];
    }

    /**
     * Retorna todas URLs
     */
    public function getUrls(): array
    {
        return $this->urls;
    }

    public function toArray(): array
    {
        return [
            'urls' => $this->urls,
            'count' => $this->count(),
        ];
    }

    private function validateUrl(string $url): void
    {
        if (trim($url) === '') {
            throw new \InvalidArgumentException('Media URL cannot be empty');
        }

        // Validação básica de URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid URL format: %s', $url)
            );
        }
    }
}
