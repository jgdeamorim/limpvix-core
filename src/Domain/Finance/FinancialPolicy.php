<?php
/**
 * FinancialPolicy - Políticas e Guards de Transições Financeiras
 *
 * RESPONSABILIDADE:
 * - Aplicar regras de negócio em transições
 * - Guards que previnem transições inválidas
 * - Validação de contexto
 *
 * PRINCÍPIOS:
 * - Fail-fast (rejeitar cedo)
 * - Explícito > Implícito
 * - Domain puro (sem infra)
 *
 * GUARDS PRINCIPAIS:
 * - PAID → HELD: Pagamento confirmado + Serviço agendado
 * - REVIEW → AUTHORIZED: Feedback positivo OU Timer 24h + Sem disputa
 * - AUTHORIZED → TRANSFERRED: Nenhum payout anterior
 * - * → BLOCKED: Admin pode bloquear qualquer estado (exceto finais)
 *
 * PASSO 5.1 - FSM Financeira
 *
 * @package LimpVix\Domain\Finance
 */

namespace LimpVix\Domain\Finance;

defined('ABSPATH') || exit;

class FinancialPolicy
{
    /**
     * Rating mínimo para aprovação automática
     */
    private const MIN_POSITIVE_RATING = 4;

    /**
     * Verificar se pode transicionar
     *
     * Esta é a decisão FINAL que combina:
     * 1. Validação estrutural (FinancialTransitionTable)
     * 2. Validação de negócio (guards)
     *
     * @param FinancialStatus $from Estado origem
     * @param FinancialStatus $to Estado destino
     * @param FinancialContext $context Contexto da decisão
     * @return bool
     */
    public function canTransition(
        FinancialStatus $from,
        FinancialStatus $to,
        FinancialContext $context
    ): bool {
        // 1. Validação estrutural (mapa de transições)
        if (!FinancialTransitionTable::isTransitionAllowed($from, $to)) {
            return false;
        }

        // 2. Aplicar guards específicos por transição
        return $this->applyGuard($from, $to, $context);
    }

    /**
     * Aplicar guard específico para cada transição
     *
     * @param FinancialStatus $from
     * @param FinancialStatus $to
     * @param FinancialContext $context
     * @return bool
     */
    private function applyGuard(
        FinancialStatus $from,
        FinancialStatus $to,
        FinancialContext $context
    ): bool {
        $fromValue = $from->getValue();
        $toValue = $to->getValue();

        // Guards específicos por transição
        $method = 'guard' . $fromValue . 'To' . $toValue;

        if (method_exists($this, $method)) {
            return $this->$method($context);
        }

        // Se não tem guard específico, permitir
        // (validação estrutural já passou)
        return true;
    }

    // ========================================
    // GUARDS ESPECÍFICOS
    // ========================================

    /**
     * Guard: CREATED → PAID
     *
     * @param FinancialContext $context
     * @return bool
     */
    private function guardCREATEDToPAID(FinancialContext $context): bool
    {
        return $context->isPaymentConfirmed();
    }

    /**
     * Guard: PAID → HELD
     *
     * @param FinancialContext $context
     * @return bool
     */
    private function guardPAIDToHELD(FinancialContext $context): bool
    {
        return $context->isServiceScheduled();
    }

    /**
     * Guard: HELD → REVIEW
     *
     * @param FinancialContext $context
     * @return bool
     */
    private function guardHELDToREVIEW(FinancialContext $context): bool
    {
        return $context->isServiceCompleted();
    }

    /**
     * Guard: REVIEW → AUTHORIZED
     *
     * REGRAS CANÔNICAS DE FEEDBACK:
     * ⭐⭐⭐⭐⭐ (5 estrelas) → autorização imediata
     * ⭐⭐⭐⭐ (4 estrelas) → autorização após 24h de carência
     * ⭐⭐⭐ ou menos (≤3 estrelas) → bloqueio automático
     * ❌ Sem feedback → autorização após 24h (timeout)
     *
     * BLOQUEIOS ABSOLUTOS (impedem qualquer autorização):
     * - Disputa aberta no Mercado Pago
     * - Profissional inválido (conta não verificada)
     * - Payout já processado anteriormente (idempotência)
     *
     * BASE LEGAL:
     * - Termos de Uso LimpVix (Seção 8 - Política de Pagamentos)
     * - Lei 13.709/2018 (LGPD) - Transparência
     * - CDC Art. 30 - Informação clara ao consumidor
     *
     * @param FinancialContext $context Contexto com dados da ordem
     * @return bool true = autoriza payout | false = bloqueia
     */
    private function guardREVIEWToAUTHORIZED(FinancialContext $context): bool
    {
        // ========================================
        // 1. BLOQUEIOS ABSOLUTOS
        // ========================================

        // Disputa aberta no Mercado Pago → BLOQUEIA
        // Motivo: Cliente contestou pagamento, aguardar resolução
        if ($context->hasDispute()) {
            return false;
        }

        // Profissional inválido → BLOQUEIA
        // Motivo: Conta não verificada ou suspensa
        if (!$context->isProfessionalValid()) {
            return false;
        }

        // Payout já processado → BLOQUEIA
        // Motivo: Idempotência - evitar pagamento duplicado
        if ($context->hasPreviousPayout()) {
            return false;
        }

        // ========================================
        // 2. DECISÃO POR FEEDBACK DO CLIENTE
        // ========================================

        $rating = $context->getFeedbackRating(); // 1-5 ou null

        // CASO 1: Cliente deu feedback explícito
        if ($rating !== null) {
            // ⭐⭐⭐⭐⭐ (5 estrelas) → AUTORIZA IMEDIATAMENTE
            // Justificativa: Cliente muito satisfeito, risco baixíssimo
            if ($rating === 5) {
                return true;
            }

            // ⭐⭐⭐⭐ (4 estrelas) → AUTORIZA APÓS 24H
            // Justificativa: Cliente satisfeito, mas aguardar janela de disputa
            if ($rating === 4) {
                return $context->isTimerExpired(24); // 24h de carência
            }

            // ⭐⭐⭐, ⭐⭐, ⭐ (3, 2 ou 1 estrela) → BLOQUEIA AUTOMATICAMENTE
            // Justificativa: Cliente insatisfeito, alto risco de disputa/estorno
            // Ação: Admin deve analisar manualmente
            return false;
        }

        // CASO 2: Cliente NÃO deu feedback (timeout)
        // ❌ Sem feedback → AUTORIZA APÓS 24H
        // Justificativa: Cliente não se manifestou, assumir ausência de problemas
        // Timer de 24h dá janela para cliente reportar problemas
        return $context->isTimerExpired(24); // 24h de timeout
    }

    /**
     * Guard: AUTHORIZED → TRANSFERRED
     *
     * @param FinancialContext $context
     * @return bool
     */
    private function guardAUTHORIZEDToTRANSFERRED(FinancialContext $context): bool
    {
        // Já não pode ter payout anterior (idempotência)
        return !$context->hasPreviousPayout();
    }

    /**
     * Guard: BLOCKED → REVIEW
     *
     * Liberação após bloqueio
     *
     * @param FinancialContext $context
     * @return bool
     */
    private function guardBLOCKEDToREVIEW(FinancialContext $context): bool
    {
        // Admin pode liberar
        return true;
    }

    /**
     * Guard: HELD → REFUNDED
     *
     * @param FinancialContext $context
     * @return bool
     */
    private function guardHELDToREFUNDED(FinancialContext $context): bool
    {
        return $context->isRefundRequested();
    }

    /**
     * Guard: REVIEW → REFUNDED
     *
     * @param FinancialContext $context
     * @return bool
     */
    private function guardREVIEWToREFUNDED(FinancialContext $context): bool
    {
        return $context->isRefundRequested();
    }

    /**
     * Guard: BLOCKED → REFUNDED
     *
     * @param FinancialContext $context
     * @return bool
     */
    private function guardBLOCKEDToREFUNDED(FinancialContext $context): bool
    {
        return $context->isRefundRequested();
    }

    /**
     * Guard: * → BLOCKED
     *
     * Admin ou sistema pode bloquear de qualquer estado (exceto finais)
     *
     * @param FinancialContext $context
     * @return bool
     */
    private function guardAnyToBLOCKED(FinancialContext $context): bool
    {
        // Admin sempre pode bloquear (validação estrutural já verificou se é final)
        return true;
    }

    // ========================================
    // HELPERS PÚBLICOS
    // ========================================

    /**
     * Obter razão de rejeição (debug/admin)
     *
     * @param FinancialStatus $from
     * @param FinancialStatus $to
     * @param FinancialContext $context
     * @return string|null Razão ou null se permitido
     */
    public function getRejectReason(
        FinancialStatus $from,
        FinancialStatus $to,
        FinancialContext $context
    ): ?string {
        // Estrutural
        if (!FinancialTransitionTable::isTransitionAllowed($from, $to)) {
            if ($from->isFinal()) {
                return 'Estado final não pode transicionar';
            }
            return 'Transição não permitida estruturalmente';
        }

        // Guards
        if (!$this->applyGuard($from, $to, $context)) {
            // Tentar identificar razão específica
            $toValue = $to->getValue();

            if ($toValue === 'AUTHORIZED') {
                if ($context->hasDispute()) return 'Tem disputa aberta';
                if (!$context->isProfessionalValid()) return 'Profissional inválido';
                if ($context->hasPreviousPayout()) return 'Já tem payout anterior';

                $rating = $context->getFeedbackRating();
                if ($rating !== null && $rating <= 3) {
                    return 'Feedback negativo (≤3 estrelas) - análise manual necessária';
                }
                if ($rating === 4 && !$context->isTimerExpired(24)) {
                    return 'Feedback 4 estrelas - aguardando carência de 24h';
                }
                if ($rating === null && !$context->isTimerExpired(24)) {
                    return 'Sem feedback - aguardando timeout de 24h';
                }

                return 'Condições de autorização não satisfeitas';
            }

            return 'Guard de negócio não satisfeito';
        }

        return null; // Permitido
    }
}
