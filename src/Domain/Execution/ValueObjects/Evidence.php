<?php
declare(strict_types=1);

namespace LimpVix\Domain\Execution\ValueObjects;

/**
 * Evidence - Evidência de Execução (Sprint 1 - Dia 1 + GAP #2)
 *
 * Value Object imutável representando evidência (foto ou vídeo).
 * Obrigatório para validar execução.
 *
 * PRINCÍPIOS:
 * - Imutável (todas propriedades readonly)
 * - Auto-validação (tipo válido, URL não vazia)
 * - Sem side effects
 * - Suporta categorização (EPI vs location vs issue)
 *
 * CATEGORIAS:
 * - epi_checkin: Vídeo selfie de EPI no check-in
 * - epi_checkout: Fotos de EPI no check-out
 * - location: Fotos/vídeos do local (before/after/during)
 * - room: Fotos/vídeos de cômodos (P0.2 - antes/depois obrigatório)
 * - issue: Evidências de problemas reportados
 *
 * @package LimpVix\Domain\Execution\ValueObjects
 * @since 0.12.0 (GAP #2 - Evidence categorization)
 */
final readonly class Evidence
{
    private const TYPE_PHOTO = 'photo';
    private const TYPE_VIDEO = 'video';

    private const CATEGORY_EPI_CHECKIN = 'epi_checkin';
    private const CATEGORY_EPI_CHECKOUT = 'epi_checkout';
    private const CATEGORY_LOCATION = 'location';
    private const CATEGORY_ROOM = 'room';
    private const CATEGORY_ISSUE = 'issue';

    private const STAGE_CHECK_IN = 'check_in';
    private const STAGE_EXECUTION = 'execution';
    private const STAGE_CHECK_OUT = 'check_out';

    private const STATUS_PENDING = 'pending';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_REJECTED = 'rejected';

    /**
     * Construtor
     *
     * @param string $type Tipo (photo ou video)
     * @param string $url URL da evidência
     * @param \DateTimeImmutable $timestamp Timestamp da captura
     * @param string $category Categoria (default: location)
     * @param int|null $uploadedBy User ID de quem fez upload
     * @param string|null $stage Estágio quando foi capturado
     * @param string $status Status de aprovação (default: pending)
     * @param string|null $roomType Tipo de cômodo (P0.2: quarto_1, banheiro_1, sala, cozinha, etc.)
     * @throws \InvalidArgumentException Se tipo, URL ou category inválidos
     */
    public function __construct(
        public string $type,
        public string $url,
        public \DateTimeImmutable $timestamp,
        public string $category = self::CATEGORY_LOCATION,
        public ?int $uploadedBy = null,
        public ?string $stage = null,
        public string $status = self::STATUS_PENDING,
        public ?string $roomType = null
    ) {
        if (!in_array($type, [self::TYPE_PHOTO, self::TYPE_VIDEO], true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid evidence type: %s (must be photo or video)', $type)
            );
        }

        if (empty(trim($url))) {
            throw new \InvalidArgumentException('Evidence URL cannot be empty');
        }

        $validCategories = [
            self::CATEGORY_EPI_CHECKIN,
            self::CATEGORY_EPI_CHECKOUT,
            self::CATEGORY_LOCATION,
            self::CATEGORY_ROOM,
            self::CATEGORY_ISSUE
        ];

        if (!in_array($category, $validCategories, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid evidence category: %s', $category)
            );
        }

        if ($stage !== null) {
            $validStages = [self::STAGE_CHECK_IN, self::STAGE_EXECUTION, self::STAGE_CHECK_OUT];
            if (!in_array($stage, $validStages, true)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid evidence stage: %s', $stage)
                );
            }
        }

        $validStatuses = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];
        if (!in_array($status, $validStatuses, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid evidence status: %s', $status)
            );
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
     * Verifica se é evidência de EPI (check-in ou check-out)
     *
     * @return bool
     */
    public function isEpi(): bool
    {
        return $this->category === self::CATEGORY_EPI_CHECKIN
            || $this->category === self::CATEGORY_EPI_CHECKOUT;
    }

    /**
     * Verifica se é evidência de EPI no check-in
     *
     * @return bool
     */
    public function isEpiCheckIn(): bool
    {
        return $this->category === self::CATEGORY_EPI_CHECKIN;
    }

    /**
     * Verifica se é evidência de EPI no check-out
     *
     * @return bool
     */
    public function isEpiCheckOut(): bool
    {
        return $this->category === self::CATEGORY_EPI_CHECKOUT;
    }

    /**
     * Verifica se é evidência de localização
     *
     * @return bool
     */
    public function isLocation(): bool
    {
        return $this->category === self::CATEGORY_LOCATION;
    }

    /**
     * Verifica se é evidência de issue (problema)
     *
     * @return bool
     */
    public function isIssue(): bool
    {
        return $this->category === self::CATEGORY_ISSUE;
    }

    /**
     * Verifica se evidência está aprovada
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Verifica se evidência está pendente
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Verifica se evidência foi rejeitada
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Factory: Criar evidência de EPI check-in (vídeo selfie)
     *
     * @param string $url URL do vídeo
     * @param int $uploadedBy Professional user ID
     * @param \DateTimeImmutable|null $timestamp
     * @return self
     */
    public static function epiCheckInVideo(
        string $url,
        int $uploadedBy,
        ?\DateTimeImmutable $timestamp = null
    ): self {
        return new self(
            self::TYPE_VIDEO,
            $url,
            $timestamp ?? new \DateTimeImmutable(),
            self::CATEGORY_EPI_CHECKIN,
            $uploadedBy,
            self::STAGE_CHECK_IN,
            self::STATUS_PENDING
        );
    }

    /**
     * Factory: Criar evidência de localização (foto do ambiente)
     *
     * @param string $url URL da foto/vídeo
     * @param int $uploadedBy User ID
     * @param string|null $stage
     * @param \DateTimeImmutable|null $timestamp
     * @return self
     */
    public static function locationEvidence(
        string $url,
        int $uploadedBy,
        ?string $stage = null,
        ?\DateTimeImmutable $timestamp = null
    ): self {
        $type = str_ends_with(strtolower($url), ['.mp4', '.mov', '.avi']) ? self::TYPE_VIDEO : self::TYPE_PHOTO;

        return new self(
            $type,
            $url,
            $timestamp ?? new \DateTimeImmutable(),
            self::CATEGORY_LOCATION,
            $uploadedBy,
            $stage,
            self::STATUS_PENDING
        );
    }

    /**
     * Verifica se é evidência de cômodo
     *
     * @return bool
     * @since P0.2
     */
    public function isRoom(): bool
    {
        return $this->category === self::CATEGORY_ROOM;
    }

    /**
     * Factory: Criar evidência de cômodo no check-in (foto antes do serviço)
     *
     * P0.2: Fotos de todos os cômodos são obrigatórias em qualquer serviço.
     *
     * @param string $url URL da foto/vídeo
     * @param string $roomType Tipo de cômodo (quarto_1, banheiro_1, sala, cozinha, etc.)
     * @param int $uploadedBy Professional user ID
     * @param \DateTimeImmutable|null $timestamp
     * @return self
     * @since P0.2
     */
    public static function roomCheckIn(
        string $url,
        string $roomType,
        int $uploadedBy,
        ?\DateTimeImmutable $timestamp = null
    ): self {
        return new self(
            self::TYPE_PHOTO,
            $url,
            $timestamp ?? new \DateTimeImmutable(),
            self::CATEGORY_ROOM,
            $uploadedBy,
            self::STAGE_CHECK_IN,
            self::STATUS_PENDING,
            $roomType
        );
    }

    /**
     * Factory: Criar evidência de cômodo no check-out (foto depois do serviço)
     *
     * P0.2: Fotos de todos os cômodos são obrigatórias em qualquer serviço.
     *
     * @param string $url URL da foto/vídeo
     * @param string $roomType Tipo de cômodo (quarto_1, banheiro_1, sala, cozinha, etc.)
     * @param int $uploadedBy Professional user ID
     * @param \DateTimeImmutable|null $timestamp
     * @return self
     * @since P0.2
     */
    public static function roomCheckOut(
        string $url,
        string $roomType,
        int $uploadedBy,
        ?\DateTimeImmutable $timestamp = null
    ): self {
        return new self(
            self::TYPE_PHOTO,
            $url,
            $timestamp ?? new \DateTimeImmutable(),
            self::CATEGORY_ROOM,
            $uploadedBy,
            self::STAGE_CHECK_OUT,
            self::STATUS_PENDING,
            $roomType
        );
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
