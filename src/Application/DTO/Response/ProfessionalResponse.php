<?php
/**
 * ProfessionalResponse - DTO for Professional API responses
 *
 * @package LimpVix\Application\DTO\Response
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Response;

defined('ABSPATH') || exit;

final class ProfessionalResponse extends BaseResponseDTO
{
    public function __construct(
        private readonly array $professionalData
    ) {}

    public static function fromData(array $data): self
    {
        return new self($data);
    }

    public function toArray(): array
    {
        return $this->professionalData;
    }
}
