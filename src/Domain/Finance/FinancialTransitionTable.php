<?php
/**
 * FinancialTransitionTable - Mapa Formal de Transições
 *
 * RESPONSABILIDADE:
 * - Definir TODAS as transições permitidas
 * - Mapa estático (não dinâmico)
 * - Validação estrutural (não de negócio)
 *
 * PRINCÍPIOS:
 * - Tabela de transições explícita
 * - Fail-fast (rejeitar inválidas)
 * - Estados finais protegidos
 *
 * TRANSIÇÕES PERMITIDAS:
 *
 * CREATED → PAID          (pagamento confirmado)
 * PAID → HELD             (serviço agendado)
 * HELD → REVIEW           (serviço completado)
 * HELD → REFUNDED         (cancelamento)
 * REVIEW → AUTHORIZED     (aprovado)
 * REVIEW → BLOCKED        (suspeita)
 * AUTHORIZED → TRANSFERRED (executado)
 * BLOCKED → REVIEW        (liberado)
 * BLOCKED → REFUNDED      (reembolso)
 * * → BLOCKED             (admin/disputa)
 *
 * ESTADOS FINAIS (NUNCA TRANSITAM):
 * - TRANSFERRED
 * - REFUNDED
 *
 * PASSO 5.1 - FSM Financeira
 *
 * @package LimpVix\Domain\Finance
 */

namespace LimpVix\Domain\Finance;

defined('ABSPATH') || exit;

class FinancialTransitionTable
{
    /**
     * Mapa de transições permitidas
     *
     * Formato: [estado_origem => [estados_destino_permitidos]]
     *
     * @var array
     */
    private const TRANSITIONS = [
        'CREATED' => [
            'PAID'      // Pagamento confirmado
        ],
        'PAID' => [
            'HELD',     // Serviço agendado
            'BLOCKED'   // Admin bloqueou
        ],
        'HELD' => [
            'REVIEW',   // Serviço completado
            'REFUNDED', // Cancelamento
            'BLOCKED'   // Admin bloqueou
        ],
        'REVIEW' => [
            'AUTHORIZED', // Aprovado (feedback/timer)
            'BLOCKED',    // Suspeita/disputa
            'REFUNDED'    // Reembolso (admin)
        ],
        'AUTHORIZED' => [
            'TRANSFERRED', // Executado
            'BLOCKED'      // Admin bloqueou
        ],
        'BLOCKED' => [
            'REVIEW',   // Admin liberou
            'REFUNDED'  // Reembolso decidido
        ],
        'TRANSFERRED' => [
            // Estado final - nunca transita
        ],
        'REFUNDED' => [
            // Estado final - nunca transita
        ]
    ];

    /**
     * Verificar se transição é permitida (estruturalmente)
     *
     * Esta validação é ESTRUTURAL, não de negócio.
     * A validação de negócio está em FinancialPolicy.
     *
     * @param FinancialStatus $from Estado origem
     * @param FinancialStatus $to Estado destino
     * @return bool
     */
    public static function isTransitionAllowed(FinancialStatus $from, FinancialStatus $to): bool
    {
        // Estados finais NUNCA transitam
        if ($from->isFinal()) {
            return false;
        }

        // Mesma origem e destino = não é transição
        if ($from->equals($to)) {
            return false;
        }

        $fromValue = $from->getValue();
        $toValue = $to->getValue();

        // Verificar se transição existe no mapa
        if (!isset(self::TRANSITIONS[$fromValue])) {
            return false;
        }

        return in_array($toValue, self::TRANSITIONS[$fromValue], true);
    }

    /**
     * Obter estados possíveis a partir de um estado
     *
     * @param FinancialStatus $from Estado origem
     * @return FinancialStatus[] Array de estados possíveis
     */
    public static function getPossibleTransitions(FinancialStatus $from): array
    {
        $fromValue = $from->getValue();

        if (!isset(self::TRANSITIONS[$fromValue])) {
            return [];
        }

        return array_map(
            fn($state) => FinancialStatus::fromValue($state),
            self::TRANSITIONS[$fromValue]
        );
    }

    /**
     * Obter mapa completo de transições
     *
     * @return array
     */
    public static function getTransitionMap(): array
    {
        return self::TRANSITIONS;
    }

    /**
     * Validar se pode bloquear de qualquer estado
     *
     * BLOCKED é especial: pode vir de qualquer estado (exceto finais)
     *
     * @param FinancialStatus $from
     * @return bool
     */
    public static function canBlock(FinancialStatus $from): bool
    {
        // Não pode bloquear estados finais
        if ($from->isFinal()) {
            return false;
        }

        return true;
    }
}
