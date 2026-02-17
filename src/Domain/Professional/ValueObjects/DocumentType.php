<?php

declare(strict_types=1);

namespace LimpVix\Domain\Professional\ValueObjects;

/**
 * Document Type Value Object
 *
 * Representa os tipos de documentos aceitos para profissionais
 */
final class DocumentType
{
    public const CPF_FRONT = 'cpf_front';
    public const CPF_BACK = 'cpf_back';
    public const RG_FRONT = 'rg_front';
    public const RG_BACK = 'rg_back';
    public const SELFIE = 'selfie';
    public const PROOF_OF_ADDRESS = 'proof_of_address';
    public const CERTIFICATE_NR35 = 'certificate_nr35';
    public const CERTIFICATE_NR10 = 'certificate_nr10';
    public const CERTIFICATE_NR06 = 'certificate_nr06';
    public const CERTIFICATE_OTHER = 'certificate_other';

    private const VALID_TYPES = [
        self::CPF_FRONT,
        self::CPF_BACK,
        self::RG_FRONT,
        self::RG_BACK,
        self::SELFIE,
        self::PROOF_OF_ADDRESS,
        self::CERTIFICATE_NR35,
        self::CERTIFICATE_NR10,
        self::CERTIFICATE_NR06,
        self::CERTIFICATE_OTHER,
    ];

    private const TYPE_LABELS = [
        self::CPF_FRONT => 'CPF (Frente)',
        self::CPF_BACK => 'CPF (Verso)',
        self::RG_FRONT => 'RG (Frente)',
        self::RG_BACK => 'RG (Verso)',
        self::SELFIE => 'Selfie',
        self::PROOF_OF_ADDRESS => 'Comprovante de Endereço',
        self::CERTIFICATE_NR35 => 'Certificado NR-35 (Trabalho em Altura)',
        self::CERTIFICATE_NR10 => 'Certificado NR-10 (Eletricidade)',
        self::CERTIFICATE_NR06 => 'Certificado NR-06 (EPI)',
        self::CERTIFICATE_OTHER => 'Outro Certificado',
    ];

    private const CERTIFICATE_TYPES = [
        self::CERTIFICATE_NR35,
        self::CERTIFICATE_NR10,
        self::CERTIFICATE_NR06,
        self::CERTIFICATE_OTHER,
    ];

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Invalid document type: {$value}. Valid types: " . implode(', ', self::VALID_TYPES)
            );
        }

        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function cpfFront(): self
    {
        return new self(self::CPF_FRONT);
    }

    public static function cpfBack(): self
    {
        return new self(self::CPF_BACK);
    }

    public static function rgFront(): self
    {
        return new self(self::RG_FRONT);
    }

    public static function rgBack(): self
    {
        return new self(self::RG_BACK);
    }

    public static function selfie(): self
    {
        return new self(self::SELFIE);
    }

    public static function proofOfAddress(): self
    {
        return new self(self::PROOF_OF_ADDRESS);
    }

    public static function certificateNr35(): self
    {
        return new self(self::CERTIFICATE_NR35);
    }

    public static function certificateNr10(): self
    {
        return new self(self::CERTIFICATE_NR10);
    }

    public static function certificateNr06(): self
    {
        return new self(self::CERTIFICATE_NR06);
    }

    public static function certificateOther(): self
    {
        return new self(self::CERTIFICATE_OTHER);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return self::TYPE_LABELS[$this->value] ?? $this->value;
    }

    public function isCertificate(): bool
    {
        return in_array($this->value, self::CERTIFICATE_TYPES, true);
    }

    public function requiresExpiry(): bool
    {
        return $this->isCertificate();
    }

    public function equals(DocumentType $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function all(): array
    {
        return self::VALID_TYPES;
    }

    public static function allWithLabels(): array
    {
        return self::TYPE_LABELS;
    }
}
