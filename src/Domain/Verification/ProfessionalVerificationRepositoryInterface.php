<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification;

interface ProfessionalVerificationRepositoryInterface
{
    public function save(ProfessionalVerification $verification): void;
    public function findByUserId(int $userId): ?ProfessionalVerification;
    public function findById(string $id): ?ProfessionalVerification;

    /** @return ProfessionalVerification[] */
    public function findExpiredBackgrounds(): array;

    public function saveConsent(ConsentRecord $consent): void;
    public function hasValidConsent(int $userId, string $consentType, string $minVersion): bool;
}
