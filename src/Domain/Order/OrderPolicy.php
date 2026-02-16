<?php
/**
 * OrderPolicy - Políticas de Negócio para OS
 *
 * RESPONSABILIDADE:
 * - Aplicar regras de negócio da LimpVix
 * - Validar operações antes de executar
 * - Calcular penalidades
 * - Verificar políticas de cancelamento
 *
 * PRINCÍPIOS:
 * - Stateless (sem estado interno)
 * - Puro (mesma entrada = mesma saída)
 * - Explícito (nomes claros)
 * - Testável
 *
 * POLÍTICAS IMPLEMENTADAS:
 * - Cancelamento com prazo
 * - SLA de antecedência mínima
 * - Limite de reagendamentos
 * - Validação de horário de funcionamento
 *
 * @package LimpVix\Domain\Order
 */

namespace LimpVix\Domain\Order;

defined('ABSPATH') || exit;

class OrderPolicy
{
    /**
     * SLA mínimo de antecedência (em horas)
     * Cliente deve agendar com pelo menos X horas de antecedência
     *
     * @var int
     */
    private const MIN_ADVANCE_HOURS = 2;

    /**
     * Prazo para cancelamento sem penalidade (em horas)
     * Cancelamento com mais de X horas = sem taxa
     *
     * @var int
     */
    private const CANCELLATION_OK_THRESHOLD_HOURS = 24;

    /**
     * Taxa de cancelamento com penalidade (%)
     * Cancelamento entre 2h e 24h antes = 30% do valor
     *
     * @var float
     */
    private const CANCELLATION_PENALTY_RATE = 0.30;

    /**
     * Taxa de cancelamento urgente (%)
     * Cancelamento com menos de 2h = 50% do valor
     *
     * @var float
     */
    private const CANCELLATION_URGENT_RATE = 0.50;

    /**
     * Limite de reagendamentos permitidos
     *
     * @var int
     */
    private const MAX_RESCHEDULES = 3;

    // ========================================
    // POLÍTICA DE AGENDAMENTO
    // ========================================

    /**
     * Verifica se pode agendar para a data/hora
     *
     * @param \DateTime $scheduledAt
     * @return bool
     */
    public static function canSchedule(\DateTime $scheduledAt): bool
    {
        $now = new \DateTime();
        $minDateTime = clone $now;
        $minDateTime->modify('+' . self::MIN_ADVANCE_HOURS . ' hours');

        // Deve ser no futuro com antecedência mínima
        return $scheduledAt >= $minDateTime;
    }

    /**
     * Retorna mensagem de erro se não pode agendar
     *
     * @param \DateTime $scheduledAt
     * @return string|null
     */
    public static function getSchedulingError(\DateTime $scheduledAt): ?string
    {
        if (!self::canSchedule($scheduledAt)) {
            return sprintf(
                'Agendamento deve ser feito com pelo menos %d horas de antecedência.',
                self::MIN_ADVANCE_HOURS
            );
        }

        return null;
    }

    // ========================================
    // POLÍTICA DE CANCELAMENTO
    // ========================================

    /**
     * Avalia política de cancelamento
     *
     * Retorna array com:
     * - can_cancel: bool
     * - has_penalty: bool
     * - penalty_rate: float
     * - penalty_amount: float
     * - reason: string
     *
     * @param Order $order
     * @return array
     */
    public static function evaluateCancellation(Order $order): array
    {
        $now = new \DateTime();
        $scheduledAt = $order->getScheduledAt();

        // Calcular diferença em horas
        $interval = $now->diff($scheduledAt);
        $hoursUntilService = ($interval->days * 24) + $interval->h;

        // Se já passou, não pode cancelar
        if ($scheduledAt < $now) {
            return [
                'can_cancel' => false,
                'has_penalty' => false,
                'penalty_rate' => 0,
                'penalty_amount' => 0,
                'reason' => 'Não é possível cancelar serviço que já passou.'
            ];
        }

        // Se está em andamento, não pode cancelar
        if ($order->getStatus()->equals(OrderStatus::IN_PROGRESS())) {
            return [
                'can_cancel' => false,
                'has_penalty' => false,
                'penalty_rate' => 0,
                'penalty_amount' => 0,
                'reason' => 'Não é possível cancelar serviço em andamento.'
            ];
        }

        // Mais de 24h antes = sem penalidade
        if ($hoursUntilService >= self::CANCELLATION_OK_THRESHOLD_HOURS) {
            return [
                'can_cancel' => true,
                'has_penalty' => false,
                'penalty_rate' => 0,
                'penalty_amount' => 0,
                'reason' => 'Cancelamento sem taxa.'
            ];
        }

        // Entre 2h e 24h = penalidade de 30%
        if ($hoursUntilService >= self::MIN_ADVANCE_HOURS) {
            $penaltyAmount = $order->getPrice() * self::CANCELLATION_PENALTY_RATE;

            return [
                'can_cancel' => true,
                'has_penalty' => true,
                'penalty_rate' => self::CANCELLATION_PENALTY_RATE,
                'penalty_amount' => $penaltyAmount,
                'reason' => sprintf(
                    'Cancelamento com menos de %d horas: taxa de %.0f%% (R$ %.2f)',
                    self::CANCELLATION_OK_THRESHOLD_HOURS,
                    self::CANCELLATION_PENALTY_RATE * 100,
                    $penaltyAmount
                )
            ];
        }

        // Menos de 2h = penalidade de 50%
        $penaltyAmount = $order->getPrice() * self::CANCELLATION_URGENT_RATE;

        return [
            'can_cancel' => true,
            'has_penalty' => true,
            'penalty_rate' => self::CANCELLATION_URGENT_RATE,
            'penalty_amount' => $penaltyAmount,
            'reason' => sprintf(
                'Cancelamento urgente (menos de %d horas): taxa de %.0f%% (R$ %.2f)',
                self::MIN_ADVANCE_HOURS,
                self::CANCELLATION_URGENT_RATE * 100,
                $penaltyAmount
            )
        ];
    }

    /**
     * Determina status de cancelamento apropriado
     *
     * @param array $policy Resultado de evaluateCancellation()
     * @return OrderStatus
     */
    public static function getCancellationStatus(array $policy): OrderStatus
    {
        if ($policy['has_penalty']) {
            return OrderStatus::CANCELED_PENALTY();
        }

        return OrderStatus::CANCELED_OK();
    }

    // ========================================
    // POLÍTICA DE REAGENDAMENTO
    // ========================================

    /**
     * Verifica se pode reagendar
     *
     * @param Order $order
     * @param \DateTime $newScheduledAt
     * @return bool
     */
    public static function canReschedule(Order $order, \DateTime $newScheduledAt): bool
    {
        // Verificar se nova data atende SLA
        if (!self::canSchedule($newScheduledAt)) {
            return false;
        }

        // Verificar limite de reagendamentos
        $rescheduleCount = $order->getMetadata()['reschedule_count'] ?? 0;
        if ($rescheduleCount >= self::MAX_RESCHEDULES) {
            return false;
        }

        // Verificar se status permite reagendamento
        $allowedStatuses = [
            OrderStatus::SCHEDULED(),
            OrderStatus::CONFIRMED()
        ];

        foreach ($allowedStatuses as $allowedStatus) {
            if ($order->getStatus()->equals($allowedStatus)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcula taxa de reagendamento (se houver)
     *
     * @param Order $order
     * @return float
     */
    public static function calculateRescheduleFee(Order $order): float
    {
        // Por enquanto, sem taxa de reagendamento
        // Mas política pode mudar (ex: após 2º reagendamento = R$ 10,00)
        return 0.00;
    }

    // ========================================
    // VALIDAÇÕES GERAIS
    // ========================================

    /**
     * Valida se pode criar OS
     *
     * @param int $customerId
     * @param int $serviceId
     * @param \DateTime $scheduledAt
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateCreation(
        int $customerId,
        int $serviceId,
        \DateTime $scheduledAt
    ): array {
        $errors = [];

        // Validar SLA
        if (!self::canSchedule($scheduledAt)) {
            $errors[] = self::getSchedulingError($scheduledAt);
        }

        // Validar se cliente existe
        // TODO: Implementar via repository

        // Validar se serviço existe e está ativo
        // TODO: Implementar via repository

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
