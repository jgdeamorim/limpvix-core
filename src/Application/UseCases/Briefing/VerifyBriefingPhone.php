<?php
/**
 * VerifyBriefingPhone - Use Case
 *
 * RESPONSABILIDADE:
 * - Validar OTP via Firebase ID Token OU Twilio Verify (fallback)
 * - Marcar telefone como verificado
 * - Transicionar: pending_phone_verification → awaiting_payment
 * - Disparar evento phone_verified
 *
 * ESTRATÉGIA OTP (cascata):
 * 1. Firebase Phone Auth (ID Token) — se configurado
 * 2. Twilio Verify API (SMS/WhatsApp OTP) — fallback
 * 3. Se nenhum configurado → modo permissivo (dev only)
 *
 * @package LimpVix\Application\UseCases\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Application\UseCases\Briefing;

use LimpVix\Domain\Briefing\BriefingRepositoryInterface;
use LimpVix\Domain\Briefing\BriefingPolicy;
use LimpVix\Domain\Briefing\BriefingStatus;
use LimpVix\Domain\Briefing\BriefingPhoneVerifiedEvent;
use LimpVix\Application\Results\BriefingOperationResult;

defined('ABSPATH') || exit;

class VerifyBriefingPhone
{
    /**
     * @var BriefingRepositoryInterface
     */
    private $repository;

    /**
     * @var BriefingPolicy
     */
    private $policy;

    /**
     * Construtor
     *
     * @param BriefingRepositoryInterface $repository
     * @param BriefingPolicy $policy
     */
    public function __construct(
        BriefingRepositoryInterface $repository,
        BriefingPolicy $policy
    ) {
        $this->repository = $repository;
        $this->policy = $policy;
    }

    /**
     * Executar
     *
     * @param string $uuid UUID do Briefing
     * @param string $token Firebase ID Token OU Twilio verification key (sid|phone)
     * @param string|null $otpCode Código OTP de 6 dígitos (obrigatório para Twilio, ignorado para Firebase)
     * @param string|null $firebaseUid UID do Firebase (opcional)
     * @return BriefingOperationResult
     */
    public function execute(
        string $uuid,
        string $token,
        ?string $otpCode = null,
        ?string $firebaseUid = null
    ): BriefingOperationResult {
        try {
            // 1. Buscar Briefing
            $briefing = $this->repository->findByUuid($uuid);

            if ($briefing === null) {
                return BriefingOperationResult::failure("Briefing não encontrado");
            }

            // 2. Validar estado
            if (!$briefing->getStatus()->isPendingPhoneVerification()) {
                return BriefingOperationResult::failure(
                    "Briefing deve estar em 'pending_phone_verification' para verificar telefone"
                );
            }

            // 3. Validar OTP (Firebase → Twilio fallback)
            $verificationResult = $this->verifyPhoneOtp($token, $otpCode);

            if (!$verificationResult['valid']) {
                return BriefingOperationResult::failure(
                    $verificationResult['error'] ?? "Verificação OTP falhou"
                );
            }

            // 4. Marcar telefone como verificado
            $briefing->verifyPhone();

            // 5. Transicionar estado
            $currentStatus = $briefing->getStatus();
            $newStatus = BriefingStatus::awaitingPayment();

            if (!$this->policy->canTransition($currentStatus, $newStatus, $briefing)) {
                return BriefingOperationResult::failure(
                    "Não foi possível transicionar para 'awaiting_payment'"
                );
            }

            $briefing->transitionTo($newStatus);

            // 6. Persistir
            $saved = $this->repository->save($briefing);

            if (!$saved) {
                return BriefingOperationResult::failure("Erro ao salvar Briefing");
            }

            // 7. Disparar evento
            $providerUid = $firebaseUid ?? ($verificationResult['uid'] ?? null);
            $this->dispatchPhoneVerifiedEvent($briefing, $providerUid, $verificationResult['provider']);

            // 8. Retornar sucesso
            return BriefingOperationResult::success($briefing, [
                'phone_verified' => true,
                'status' => 'awaiting_payment',
                'otp_provider' => $verificationResult['provider'],
            ]);

        } catch (\DomainException $e) {
            return BriefingOperationResult::failure("Regra de negócio violada: " . $e->getMessage());
        } catch (\Exception $e) {
            return BriefingOperationResult::failure("Erro ao verificar telefone: " . $e->getMessage());
        }
    }

    /**
     * Verificar OTP via cascata: Firebase → Twilio → permissivo
     *
     * @param string $token Firebase ID Token ou Twilio key (sid|phone)
     * @param string|null $otpCode Código OTP (Twilio)
     * @return array{valid: bool, provider: string, uid: ?string, error: ?string}
     */
    private function verifyPhoneOtp(string $token, ?string $otpCode): array
    {
        if (empty($token)) {
            return ['valid' => false, 'provider' => 'none', 'uid' => null, 'error' => 'Token vazio'];
        }

        // 1. Tentar Firebase primeiro
        $firebaseResult = $this->tryFirebase($token);
        if ($firebaseResult !== null) {
            return $firebaseResult;
        }

        // 2. Fallback: Twilio Verify
        $twilioResult = $this->tryTwilio($token, $otpCode);
        if ($twilioResult !== null) {
            return $twilioResult;
        }

        // 3. Nenhum provider configurado — modo permissivo (dev/staging)
        error_log('[LimpVix][OTP] No OTP provider configured — permissive mode');
        return [
            'valid' => true,
            'provider' => 'permissive',
            'uid' => null,
            'error' => null,
        ];
    }

    /**
     * Tentar validação via Firebase Phone Auth
     *
     * @return array|null null se Firebase não configurado
     */
    private function tryFirebase(string $token): ?array
    {
        if (!class_exists(\LimpVix\Infrastructure\Auth\FirebasePhoneVerificationAdapter::class)) {
            return null;
        }

        $adapter = new \LimpVix\Infrastructure\Auth\FirebasePhoneVerificationAdapter();

        if (!$adapter->isConfigured()) {
            return null; // Não configurado → tentar próximo provider
        }

        $result = $adapter->verifyIdToken($token);

        // Se Firebase retornou fallback (not configured), não contar como tentativa
        if (!empty($result['fallback'])) {
            return null;
        }

        if ($result['valid']) {
            error_log('[LimpVix][OTP] Phone verified via Firebase');
            return [
                'valid' => true,
                'provider' => 'firebase',
                'uid' => $result['uid'] ?? null,
                'error' => null,
            ];
        }

        // Firebase configurado mas token inválido — ainda tenta Twilio como fallback
        error_log('[LimpVix][OTP] Firebase validation failed, trying Twilio fallback: ' . ($result['error'] ?? 'unknown'));
        return null;
    }

    /**
     * Tentar validação via Twilio Verify API
     *
     * @param string $token Verification key no formato "sid|phone"
     * @param string|null $otpCode Código de 6 dígitos
     * @return array|null null se Twilio não configurado
     */
    private function tryTwilio(string $token, ?string $otpCode): ?array
    {
        if (!class_exists(\LimpVix\Infrastructure\SMS\TwilioOtpProvider::class)) {
            return null;
        }

        $twilio = new \LimpVix\Infrastructure\SMS\TwilioOtpProvider();

        if (!$twilio->isConfigured()) {
            return null; // Não configurado → tentar próximo provider
        }

        if (empty($otpCode)) {
            return [
                'valid' => false,
                'provider' => 'twilio',
                'uid' => null,
                'error' => 'Código OTP obrigatório para verificação Twilio',
            ];
        }

        try {
            $isValid = $twilio->verify($token, $otpCode);

            if ($isValid) {
                error_log('[LimpVix][OTP] Phone verified via Twilio');
            }

            return [
                'valid' => $isValid,
                'provider' => 'twilio',
                'uid' => null,
                'error' => $isValid ? null : 'Código OTP inválido ou expirado',
            ];
        } catch (\Exception $e) {
            error_log('[LimpVix][OTP] Twilio verification error: ' . $e->getMessage());
            return [
                'valid' => false,
                'provider' => 'twilio',
                'uid' => null,
                'error' => 'Erro na verificação Twilio: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Disparar evento phone_verified
     *
     * @param \LimpVix\Domain\Briefing\Briefing $briefing
     * @param string|null $providerUid UID do provider (Firebase UID, etc)
     * @param string $provider Provider usado (firebase, twilio, permissive)
     * @return void
     */
    private function dispatchPhoneVerifiedEvent($briefing, ?string $providerUid, string $provider = 'firebase'): void
    {
        $event = new BriefingPhoneVerifiedEvent(
            $briefing->getUuid(),
            $briefing->getUserId(),
            $providerUid
        );

        if (function_exists('do_action')) {
            $eventData = $event->toArray();
            $eventData['otp_provider'] = $provider;
            do_action('limpvix_briefing_phone_verified', $eventData);
        }
    }
}
