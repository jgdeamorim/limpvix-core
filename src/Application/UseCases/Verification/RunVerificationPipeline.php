<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Verification;

use LimpVix\Domain\Verification\ProfessionalVerification;
use LimpVix\Domain\Verification\ProfessionalVerificationRepositoryInterface;
use LimpVix\Domain\Verification\Services\PolicyEngine;
use LimpVix\Infrastructure\Verification\Providers\VerificationProviderFactory;

/**
 * RunVerificationPipeline — Orquestra as 4 camadas do pipeline
 *
 * OTP (pré-requisito) → KYC → Background → Risk Engine → Status Final
 *
 * Em modo teste (sem credenciais), usa Mock Providers automaticamente.
 * Em produção (com credenciais), usa PPID + Exato automaticamente.
 * Zero mudança de código necessária para a transição.
 */
final class RunVerificationPipeline
{
    public function __construct(
        private readonly ProfessionalVerificationRepositoryInterface $repository,
        private readonly PolicyEngine $policyEngine,
    ) {}

    /**
     * Executa o pipeline completo para um usuário
     *
     * @param int   $userId      WordPress user ID
     * @param array $professional Dados do profissional {cpf, full_name, birth_date, document_url, selfie_url}
     * @return ProfessionalVerification
     */
    public function execute(int $userId, array $professional): ProfessionalVerification
    {
        // Recupera verificação existente ou inicia nova
        $verification = $this->repository->findByUserId($userId)
            ?? ProfessionalVerification::initiate($userId);

        // ── Camada 2: KYC ────────────────────────────────────────────────────
        $kycProvider = VerificationProviderFactory::kycProvider();

        $kycResult = $kycProvider->verify(
            cpf:         $professional['cpf'],
            fullName:    $professional['full_name'],
            birthDate:   $professional['birth_date'],
            documentUrl: $professional['document_url'] ?? '',
            selfieUrl:   $professional['selfie_url']   ?? '',
        );

        $verification->applyKycResult($kycResult);
        $this->repository->save($verification);

        // Se KYC falhou, encerra aqui
        if ($kycResult->fraudFlag || $kycResult->status->value !== 'APPROVED') {
            $this->applyFinalStatus($verification);
            return $verification;
        }

        // ── Camada 3: Background Check ────────────────────────────────────────
        $verification->requestBackgroundCheck();
        $this->repository->save($verification);

        $bgProvider = VerificationProviderFactory::backgroundProvider();

        $bgResult = $bgProvider->check(
            cpf:      $professional['cpf'],
            fullName: $professional['full_name'],
            birthDate: $professional['birth_date'],
        );

        $verification->applyBackgroundResult($bgResult);

        // ── Camada 4: Risk Engine → Status Final ──────────────────────────────
        $this->applyFinalStatus($verification);

        return $verification;
    }

    private function applyFinalStatus(ProfessionalVerification $verification): void
    {
        $kycResult = $verification->getKycResult();
        $bgResult  = $verification->getBackgroundResult();

        if ($kycResult === null) {
            return;
        }

        // Background ainda não concluído — usa resultado neutro provisório
        if ($bgResult === null) {
            $finalStatus = $this->policyEngine->evaluate(
                otpVerified:       $verification->isOtpVerified(),
                kycResult:         $kycResult,
                backgroundResult:  \LimpVix\Domain\Verification\ValueObjects\BackgroundResult::approved('pending', 1),
                behaviorScore:     100.0,
            );
        } else {
            $finalStatus = $this->policyEngine->evaluate(
                otpVerified:      $verification->isOtpVerified(),
                kycResult:        $kycResult,
                backgroundResult: $bgResult,
                behaviorScore:    100.0,
            );
        }

        $verification->applyFinalStatus($finalStatus);
        $this->repository->save($verification);
    }
}
