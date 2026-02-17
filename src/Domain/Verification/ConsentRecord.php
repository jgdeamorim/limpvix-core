<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification;

/**
 * ConsentRecord — Aggregate de consentimento LGPD
 *
 * LGPD Art. 7 — background check exige consentimento SEPARADO dos Termos Gerais.
 *
 * REGRAS:
 * - Consentimento versionado (nova versão = novo registro)
 * - Hash do texto exibido (prova do que o usuário aceitou)
 * - IP registrado para evidência de autoria
 * - Imutável após criação
 */
final class ConsentRecord
{
    private const VALID_TYPES = ['BACKGROUND_CHECK', 'TERMS_OF_SERVICE', 'PRIVACY_POLICY'];

    private function __construct(
        private readonly string             $id,
        private readonly int                $userId,
        private readonly string             $consentType,
        private readonly string             $consentVersion,
        private readonly \DateTimeImmutable $acceptedAt,
        private readonly ?string            $ipAddress,
        private readonly string             $hashSnapshot, // SHA-256 do texto exibido
    ) {}

    public static function record(
        int     $userId,
        string  $consentType,
        string  $consentVersion,
        string  $consentText,   // texto exato exibido ao usuário
        ?string $ipAddress = null,
    ): self {
        if (!in_array($consentType, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid consent type: {$consentType}");
        }

        if (empty(trim($consentVersion))) {
            throw new \InvalidArgumentException('Consent version cannot be empty');
        }

        if (empty(trim($consentText))) {
            throw new \InvalidArgumentException('Consent text cannot be empty');
        }

        return new self(
            id:              wp_generate_uuid4(),
            userId:          $userId,
            consentType:     $consentType,
            consentVersion:  $consentVersion,
            acceptedAt:      new \DateTimeImmutable(),
            ipAddress:       $ipAddress,
            hashSnapshot:    hash('sha256', $consentText),
        );
    }

    public static function reconstitute(array $data): self
    {
        return new self(
            id:             $data['id'],
            userId:         (int) $data['user_id'],
            consentType:    $data['consent_type'],
            consentVersion: $data['consent_version'],
            acceptedAt:     new \DateTimeImmutable($data['accepted_at']),
            ipAddress:      $data['ip_address'] ?? null,
            hashSnapshot:   $data['hash_snapshot'],
        );
    }

    public function getId(): string             { return $this->id; }
    public function getUserId(): int            { return $this->userId; }
    public function getConsentType(): string    { return $this->consentType; }
    public function getConsentVersion(): string { return $this->consentVersion; }
    public function getAcceptedAt(): \DateTimeImmutable { return $this->acceptedAt; }
    public function getIpAddress(): ?string     { return $this->ipAddress; }
    public function getHashSnapshot(): string   { return $this->hashSnapshot; }

    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'user_id'         => $this->userId,
            'consent_type'    => $this->consentType,
            'consent_version' => $this->consentVersion,
            'accepted_at'     => $this->acceptedAt->format('Y-m-d H:i:s'),
            'ip_address'      => $this->ipAddress,
            'hash_snapshot'   => $this->hashSnapshot,
        ];
    }
}
