<?php
/**
 * BriefingPolicy - Políticas e Guards de Transições do Briefing
 *
 * RESPONSABILIDADE:
 * - Aplicar regras de negócio nas transições de estado
 * - Guards que previnem transições inválidas
 * - Validação de contexto do Briefing
 *
 * PRINCÍPIOS:
 * - Fail-fast (rejeitar cedo)
 * - Explícito > Implícito
 * - Domain puro (sem infra)
 * - Regras de negócio isoladas aqui
 *
 * GUARDS PRINCIPAIS:
 * - draft → in_progress: Sem restrições (início do preenchimento)
 * - in_progress → pending_phone_verification: Todos steps obrigatórios completos
 * - pending_phone_verification → awaiting_payment: Telefone verificado
 * - awaiting_payment → paid: Pagamento confirmado
 * - paid → locked: Order ID vinculada
 * - locked → *: BLOQUEADO (estado final, sem transições)
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class BriefingPolicy
{
    /**
     * Verificar se pode transicionar entre estados
     *
     * Combina:
     * 1. Validação estrutural (BriefingStatus::canTransitionTo)
     * 2. Validação de negócio (guards específicos)
     *
     * @param BriefingStatus $from Estado origem
     * @param BriefingStatus $to Estado destino
     * @param Briefing $briefing Contexto completo do Briefing
     * @return bool
     */
    public function canTransition(
        BriefingStatus $from,
        BriefingStatus $to,
        Briefing $briefing
    ): bool {
        // 1. Validação estrutural (State Machine)
        if (!$from->canTransitionTo($to)) {
            return false;
        }

        // 2. Aplicar guard específico por transição
        return $this->applyGuard($from, $to, $briefing);
    }

    /**
     * Verificar se pode fazer lock (transição final)
     *
     * @param Briefing $briefing
     * @return bool
     */
    public function canLock(Briefing $briefing): bool
    {
        // Deve estar no estado 'paid'
        if (!$briefing->getStatus()->isPaid()) {
            return false;
        }

        // Order ID deve estar presente
        // (será fornecida no momento do lock)
        return true;
    }

    /**
     * Aplicar guard específico para cada transição
     *
     * @param BriefingStatus $from
     * @param BriefingStatus $to
     * @param Briefing $briefing
     * @return bool
     */
    private function applyGuard(
        BriefingStatus $from,
        BriefingStatus $to,
        Briefing $briefing
    ): bool {
        $fromValue = $this->normalizeStatusName($from->getValue());
        $toValue = $this->normalizeStatusName($to->getValue());

        // Construir nome do método guard
        $method = 'guard' . $fromValue . 'To' . $toValue;

        if (method_exists($this, $method)) {
            return $this->$method($briefing);
        }

        // Se não tem guard específico, permitir
        // (validação estrutural já passou)
        return true;
    }

    /**
     * Normalizar nome do status para método
     * pending_phone_verification → PendingPhoneVerification
     *
     * @param string $status
     * @return string
     */
    private function normalizeStatusName(string $status): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $status)));
    }

    // ========================================
    // GUARDS ESPECÍFICOS
    // ========================================

    /**
     * Guard: draft → in_progress
     *
     * Sem restrições - apenas iniciar o preenchimento
     *
     * @param Briefing $briefing
     * @return bool
     */
    private function guardDraftToInProgress(Briefing $briefing): bool
    {
        // Sempre permitido
        return true;
    }

    /**
     * Guard: in_progress → pending_phone_verification
     *
     * Requer:
     * - Structure definida (imóvel configurado)
     * - Frequency definida (tipo de serviço escolhido)
     * - Metrics calculadas (m² e tempo estimados)
     *
     * @param Briefing $briefing
     * @return bool
     */
    private function guardInProgressToPendingPhoneVerification(Briefing $briefing): bool
    {
        // Estrutura do imóvel deve estar definida
        if ($briefing->getStructure() === null) {
            return false;
        }

        // Frequência deve estar definida
        if ($briefing->getFrequency() === null) {
            return false;
        }

        // Métricas devem estar calculadas
        if ($briefing->getMetrics() === null) {
            return false;
        }

        return true;
    }

    /**
     * Guard: pending_phone_verification → awaiting_payment
     *
     * Requer:
     * - Telefone verificado via Firebase OTP
     *
     * @param Briefing $briefing
     * @return bool
     */
    private function guardPendingPhoneVerificationToAwaitingPayment(Briefing $briefing): bool
    {
        return $briefing->isPhoneVerified();
    }

    /**
     * Guard: awaiting_payment → paid
     *
     * Nota: Esta transição é feita pelo WooCommerce Payment Adapter
     * após confirmação do pagamento. Não há validações adicionais aqui.
     *
     * @param Briefing $briefing
     * @return bool
     */
    private function guardAwaitingPaymentToPaid(Briefing $briefing): bool
    {
        // Sempre permitir (pagamento confirmado externamente)
        return true;
    }

    /**
     * Guard: paid → locked
     *
     * Requer:
     * - Order ID será fornecida no momento do lock
     *   (validação feita em Briefing::lock())
     *
     * @param Briefing $briefing
     * @return bool
     */
    private function guardPaidToLocked(Briefing $briefing): bool
    {
        // Sempre permitir (Order ID validada no método lock())
        return true;
    }

    // ========================================
    // VALIDAÇÕES ADICIONAIS
    // ========================================

    /**
     * Validar se Briefing pode ser editado
     *
     * @param Briefing $briefing
     * @return bool
     */
    public function canEdit(Briefing $briefing): bool
    {
        // Locked não pode ser editado
        return !$briefing->isLocked();
    }

    /**
     * Validar se step específico pode ser editado
     *
     * @param Briefing $briefing
     * @param string $stepName Nome do step ('structure', 'frequency', etc)
     * @return bool
     */
    public function canEditStep(Briefing $briefing, string $stepName): bool
    {
        // Regra geral: não pode editar se locked
        if ($briefing->isLocked()) {
            return false;
        }

        // Regras específicas por step
        switch ($stepName) {
            case 'property_type':
                // Tipo de propriedade só pode ser editado em draft
                return $briefing->getStatus()->isDraft();

            case 'structure':
            case 'frequency':
            case 'contract':
                // Esses steps podem ser editados até pending_phone_verification
                return !$briefing->getStatus()->isPendingPhoneVerification()
                    && !$briefing->getStatus()->isAwaitingPayment()
                    && !$briefing->getStatus()->isPaid();

            case 'phone_verification':
                // Verificação de telefone só em pending_phone_verification
                return $briefing->getStatus()->isPendingPhoneVerification();

            default:
                // Demais steps: editáveis até awaiting_payment
                return !$briefing->getStatus()->isAwaitingPayment()
                    && !$briefing->getStatus()->isPaid();
        }
    }

    /**
     * Validar se Briefing está pronto para verificação de telefone
     *
     * @param Briefing $briefing
     * @return bool
     */
    public function isReadyForPhoneVerification(Briefing $briefing): bool
    {
        return $briefing->getStatus()->isPendingPhoneVerification()
            && !$briefing->isPhoneVerified();
    }

    /**
     * Validar se Briefing está pronto para pagamento
     *
     * @param Briefing $briefing
     * @return bool
     */
    public function isReadyForPayment(Briefing $briefing): bool
    {
        return $briefing->getStatus()->isAwaitingPayment()
            && $briefing->isPhoneVerified();
    }

    /**
     * Obter razão de rejeição de transição (para debug/UI)
     *
     * @param BriefingStatus $from
     * @param BriefingStatus $to
     * @param Briefing $briefing
     * @return string|null
     */
    public function getTransitionRejectionReason(
        BriefingStatus $from,
        BriefingStatus $to,
        Briefing $briefing
    ): ?string {
        // Validação estrutural
        if (!$from->canTransitionTo($to)) {
            return "Transição {$from->getValue()} → {$to->getValue()} não é permitida";
        }

        // Validação de guards
        $fromValue = $this->normalizeStatusName($from->getValue());
        $toValue = $this->normalizeStatusName($to->getValue());
        $method = 'guard' . $fromValue . 'To' . $toValue;

        if (!method_exists($this, $method)) {
            return null; // Sem guard específico
        }

        if ($this->$method($briefing)) {
            return null; // Guard passou
        }

        // Retornar razão específica por transição
        return $this->getGuardFailureReason($from, $to, $briefing);
    }

    /**
     * Obter razão de falha do guard
     *
     * @param BriefingStatus $from
     * @param BriefingStatus $to
     * @param Briefing $briefing
     * @return string
     */
    private function getGuardFailureReason(
        BriefingStatus $from,
        BriefingStatus $to,
        Briefing $briefing
    ): string {
        if ($from->isInProgress() && $to->isPendingPhoneVerification()) {
            if ($briefing->getStructure() === null) {
                return "Estrutura do imóvel não foi definida";
            }
            if ($briefing->getFrequency() === null) {
                return "Frequência de serviço não foi definida";
            }
            if ($briefing->getMetrics() === null) {
                return "Métricas não foram calculadas";
            }
        }

        if ($from->isPendingPhoneVerification() && $to->isAwaitingPayment()) {
            if (!$briefing->isPhoneVerified()) {
                return "Telefone não foi verificado";
            }
        }

        return "Condições de negócio não satisfeitas";
    }
}
