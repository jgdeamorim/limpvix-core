<?php
/**
 * FinancialContext - Contexto para Validação de Transições
 *
 * RESPONSABILIDADE:
 * - Carregar dados necessários para decisões financeiras
 * - DTO (Data Transfer Object) imutável
 * - Sem lógica de negócio
 *
 * PRINCÍPIOS:
 * - Value Object (imutável)
 * - Array associativo tipado
 * - Validação básica
 *
 * USO:
 * ```php
 * $context = new FinancialContext([
 *     'service_completed' => true,
 *     'feedback_rating' => 5,
 *     'has_dispute' => false,
 *     'timer_expired' => false,
 *     'professional_valid' => true
 * ]);
 *
 * $policy->canTransition(from, to, $context);
 * ```
 *
 * PASSO 5.1 - FSM Financeira
 *
 * @package LimpVix\Domain\Finance
 */

namespace LimpVix\Domain\Finance;

defined('ABSPATH') || exit;

class FinancialContext
{
    /**
     * Dados do contexto
     *
     * @var array
     */
    private $data;

    /**
     * Construtor
     *
     * @param array $data Dados do contexto
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Obter valor do contexto
     *
     * @param string $key Chave
     * @param mixed $default Valor padrão
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Verificar se chave existe
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Obter todos os dados
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->data;
    }

    // ========================================
    // HELPERS ESPECÍFICOS (TIPO SEGURO)
    // ========================================

    /**
     * Serviço foi completado?
     *
     * @return bool
     */
    public function isServiceCompleted(): bool
    {
        return (bool) $this->get('service_completed', false);
    }

    /**
     * Obter rating do feedback (1-5)
     *
     * @return int|null
     */
    public function getFeedbackRating(): ?int
    {
        $rating = $this->get('feedback_rating');
        return is_numeric($rating) ? (int) $rating : null;
    }

    /**
     * Tem disputa aberta?
     *
     * @return bool
     */
    public function hasDispute(): bool
    {
        return (bool) $this->get('has_dispute', false);
    }

    /**
     * Timer de review expirou (24h)?
     *
     * @return bool
     */
    public function isTimerExpired(): bool
    {
        return (bool) $this->get('timer_expired', false);
    }

    /**
     * Profissional é válido?
     *
     * @return bool
     */
    public function isProfessionalValid(): bool
    {
        return (bool) $this->get('professional_valid', true);
    }

    /**
     * Pagamento foi confirmado?
     *
     * @return bool
     */
    public function isPaymentConfirmed(): bool
    {
        return (bool) $this->get('payment_confirmed', false);
    }

    /**
     * Serviço foi agendado?
     *
     * @return bool
     */
    public function isServiceScheduled(): bool
    {
        return (bool) $this->get('service_scheduled', false);
    }

    /**
     * Já existe payout anterior?
     *
     * @return bool
     */
    public function hasPreviousPayout(): bool
    {
        return (bool) $this->get('has_previous_payout', false);
    }

    /**
     * Admin bloqueou manualmente?
     *
     * @return bool
     */
    public function isAdminBlocked(): bool
    {
        return (bool) $this->get('admin_blocked', false);
    }

    /**
     * Reembolso foi solicitado?
     *
     * @return bool
     */
    public function isRefundRequested(): bool
    {
        return (bool) $this->get('refund_requested', false);
    }
}
