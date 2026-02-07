<?php
/**
 * VerifyBriefingPhone - Use Case
 *
 * RESPONSABILIDADE:
 * - Validar Firebase ID Token (SMS OTP)
 * - Marcar telefone como verificado
 * - Transicionar: pending_phone_verification → awaiting_payment
 * - Disparar evento phone_verified
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
     * @param string $firebaseIdToken Token do Firebase Authentication
     * @param string|null $firebaseUid UID do Firebase (opcional)
     * @return BriefingOperationResult
     */
    public function execute(
        string $uuid,
        string $firebaseIdToken,
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

            // 3. Validar Firebase Token
            // TODO: Implementar FirebaseAuthAdapter na FASE 3
            // Por enquanto, simulamos validação
            $tokenValid = $this->validateFirebaseToken($firebaseIdToken);

            if (!$tokenValid) {
                return BriefingOperationResult::failure("Token Firebase inválido");
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
            $this->dispatchPhoneVerifiedEvent($briefing, $firebaseUid);

            // 8. Retornar sucesso
            return BriefingOperationResult::success($briefing, [
                'phone_verified' => true,
                'status' => 'awaiting_payment'
            ]);

        } catch (\DomainException $e) {
            return BriefingOperationResult::failure("Regra de negócio violada: " . $e->getMessage());
        } catch (\Exception $e) {
            return BriefingOperationResult::failure("Erro ao verificar telefone: " . $e->getMessage());
        }
    }

    /**
     * Validar Firebase Token
     *
     * TODO: Implementar com FirebaseAuthAdapter na FASE 3
     *
     * @param string $token
     * @return bool
     */
    private function validateFirebaseToken(string $token): bool
    {
        // Por enquanto, aceitar qualquer token não-vazio
        // FASE 3 implementará validação real
        return !empty($token);
    }

    /**
     * Disparar evento phone_verified
     *
     * @param \LimpVix\Domain\Briefing\Briefing $briefing
     * @param string|null $firebaseUid
     * @return void
     */
    private function dispatchPhoneVerifiedEvent($briefing, ?string $firebaseUid): void
    {
        $event = new BriefingPhoneVerifiedEvent(
            $briefing->getUuid(),
            $briefing->getUserId(),
            $firebaseUid
        );

        if (function_exists('do_action')) {
            do_action('limpvix_briefing_phone_verified', $event->toArray());
        }
    }
}
