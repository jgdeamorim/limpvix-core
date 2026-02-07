<?php
/**
 * LockBriefing - Use Case
 *
 * RESPONSABILIDADE:
 * - Fazer lock do Briefing (transição final: paid → locked)
 * - Vincular Order ID (WooCommerce)
 * - Validar via BriefingPolicy
 * - Disparar evento locked
 *
 * Este Use Case é chamado após confirmação de pagamento.
 *
 * @package LimpVix\Application\UseCases\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Application\UseCases\Briefing;

use LimpVix\Domain\Briefing\BriefingRepositoryInterface;
use LimpVix\Domain\Briefing\BriefingPolicy;
use LimpVix\Domain\Briefing\BriefingLockedEvent;
use LimpVix\Application\Results\BriefingOperationResult;

defined('ABSPATH') || exit;

class LockBriefing
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
     * @param int $orderId Order ID vinculada (WooCommerce)
     * @return BriefingOperationResult
     */
    public function execute(string $uuid, int $orderId): BriefingOperationResult
    {
        try {
            // 1. Validar Order ID
            if ($orderId <= 0) {
                return BriefingOperationResult::failure("Order ID inválido");
            }

            // 2. Buscar Briefing
            $briefing = $this->repository->findByUuid($uuid);

            if ($briefing === null) {
                return BriefingOperationResult::failure("Briefing não encontrado");
            }

            // 3. Validar Policy
            if (!$this->policy->canLock($briefing)) {
                return BriefingOperationResult::failure(
                    "Briefing não pode ser locked no estado atual (deve estar 'paid')"
                );
            }

            // 4. Fazer lock
            $briefing->lock($orderId);

            // 5. Persistir
            $saved = $this->repository->save($briefing);

            if (!$saved) {
                return BriefingOperationResult::failure("Erro ao salvar Briefing");
            }

            // 6. Disparar evento
            $this->dispatchLockedEvent($briefing);

            // 7. Retornar sucesso
            return BriefingOperationResult::success($briefing, [
                'locked' => true,
                'order_id' => $orderId,
                'requires_contract' => $briefing->requiresContract()
            ]);

        } catch (\DomainException $e) {
            return BriefingOperationResult::failure("Regra de negócio violada: " . $e->getMessage());
        } catch (\Exception $e) {
            return BriefingOperationResult::failure("Erro ao fazer lock: " . $e->getMessage());
        }
    }

    /**
     * Disparar evento locked
     *
     * Este evento dispara:
     * - Criação de contrato (se requiresContract)
     * - Criação de agenda recorrente
     * - Notificações
     *
     * @param \LimpVix\Domain\Briefing\Briefing $briefing
     * @return void
     */
    private function dispatchLockedEvent($briefing): void
    {
        $event = new BriefingLockedEvent(
            $briefing->getUuid(),
            $briefing->getOrderId(),
            $briefing->getUserId(),
            $briefing->requiresContract()
        );

        if (function_exists('do_action')) {
            do_action('limpvix_briefing_locked', $event->toArray());
        }
    }
}
