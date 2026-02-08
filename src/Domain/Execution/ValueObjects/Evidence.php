<?php
declare(strict_types=1);

namespace LimpVix\Domain\Execution\ValueObjects;

/**
 * Evidence - Evidência de Execução (Sprint 1 - Dia 1)
 *
 * Value Object imutável representando evidência (foto ou vídeo).
 * Obrigatório para validar execução.
 *
 * PRINCÍPIOS:
 * - Imutável (todas propriedades readonly)
 * - Auto-validação (tipo válido, URL não vazia)
 * - Sem side effects
 *
 * @package LimpVix\Domain\Execution\ValueObjects
 */
final readonly class Evidence
{
    private const TYPE_PHOTO = 'photo';
    private const TYPE_VIDEO = 'video';

    /**
     * Construtor
     *
     * @param string $type Tipo (photo ou video)
     * @param string $url URL da evidência
     * @param \DateTimeImmutable $timestamp Timestamp da captura
     * @throws \InvalidArgumentException Se tipo ou URL inválidos
     */
    public function __construct(
        public string $type,
        public string $url,
        public \DateTimeImmutable $timestamp
    ) {
        if (!in_array($type, [self::TYPE_PHOTO, self::TYPE_VIDEO], true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid evidence type: %s (must be photo or video)', $type)
            );
        }

        if (empty(trim($url))) {
            throw new \InvalidArgumentException('Evidence URL cannot be empty');
        }
    }

    /**
     * Factory: Criar evidência de foto
     *
     * @param string $url URL da foto
     * @param \DateTimeImmutable|null $timestamp Timestamp (default: now)
     * @return self
     */
    public static function photo(string $url, ?\DateTimeImmutable $timestamp = null): self
    {
        return new self(
            self::TYPE_PHOTO,
            $url,
            $timestamp ?? new \DateTimeImmutable()
        );
    }

    /**
     * Factory: Criar evidência de vídeo
     *
     * @param string $url URL do vídeo
     * @param \DateTimeImmutable|null $timestamp Timestamp (default: now)
     * @return self
     */
    public static function video(string $url, ?\DateTimeImmutable $timestamp = null): self
    {
        return new self(
            self::TYPE_VIDEO,
            $url,
            $timestamp ?? new \DateTimeImmutable()
        );
    }

    /**
     * Verifica se é foto
     *
     * @return bool
     */
    public function isPhoto(): bool
    {
        return $this->type === self::TYPE_PHOTO;
    }

    /**
     * Verifica se é vídeo
     *
     * @return bool
     */
    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    /**
     * Igualdade baseada em URL
     *
     * @param Evidence $other
     * @return bool
     */
    public function equals(Evidence $other): bool
    {
        return $this->url === $other->url;
    }
}
