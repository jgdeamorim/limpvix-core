<?php
/**
 * TimerCronAdapter - Adaptador para Timer de Review (24h)
 *
 * RESPONSABILIDADE:
 * - Executar via WP Cron
 * - Buscar orders em REVIEW há mais de 24h
 * - Para cada order, chamar Use Case apropriado
 * - Registrar resultados
 *
 * PRINCÍPIOS:
 * - Adapter Pattern
 * - Zero regras de negócio
 * - Zero decisões financeiras
 * - Apenas tradução: cron → lista de orders → use case
 *
 * IMPORTANTE:
 * - NÃO decide se pode autorizar (Policy decide)
 * - NÃO calcula tempo (usa dados do banco)
 * - NÃO acessa ledger diretamente
 * - Apenas: busca orders → itera → chama use case
 *
 * CRON:
 * - limpvix_process_review_timer
 * - Executa a cada hora
 *
 * PASSO 5.4 - Adaptadores de Eventos
 *
 * @package LimpVix\Infrastructure\Adapters
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Application\UseCases\ProcessTimerExpired;
use LimpVix\Domain\Finance\LedgerRepositoryInterface;

defined('ABSPATH') || exit;

class TimerCronAdapter
{
    /**
     * Use Case
     *
     * @var ProcessTimerExpired
     */
    private $useCase;

    /**
     * Ledger Repository (para verificar última transição)
     *
     * @var LedgerRepositoryInterface
     */
    private $ledgerRepository;

    /**
     * Construtor
     *
     * @param ProcessTimerExpired $useCase
     * @param LedgerRepositoryInterface $ledgerRepository
     */
    public function __construct(
        ProcessTimerExpired $useCase,
        LedgerRepositoryInterface $ledgerRepository
    ) {
        $this->useCase = $useCase;
        $this->ledgerRepository = $ledgerRepository;
    }

    /**
     * Registrar cron
     *
     * @return void
     */
    public function register(): void
    {
        // Hook do cron
        add_action('limpvix_process_review_timer', [$this, 'processReviewTimers']);

        // Agendar evento se não estiver agendado
        if (!wp_next_scheduled('limpvix_process_review_timer')) {
            wp_schedule_event(time(), 'hourly', 'limpvix_process_review_timer');
        }
    }

    /**
     * Processar timers de review
     *
     * @return void
     */
    public function processReviewTimers(): void
    {
        try {
            // 1. Buscar orders em REVIEW há mais de 24h
            $orders = $this->findOrdersInReviewPast24h();

            $processed = 0;
            $succeeded = 0;
            $rejected = 0;

            // 2. Processar cada order
            foreach ($orders as $order) {
                $processed++;

                $result = $this->processOrder($order);

                if ($result->isSuccess()) {
                    $succeeded++;
                } else {
                    $rejected++;
                }
            }

            // 3. Log de resumo
            $this->logSummary($processed, $succeeded, $rejected);

        } catch (\Exception $e) {
            $this->logError($e);
        }
    }

    /**
     * Buscar orders em REVIEW há mais de 24h
     *
     * @return array Array de orders [uuid, created_at]
     */
    private function findOrdersInReviewPast24h(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_orders';

        // Buscar orders em REVIEW criadas há mais de 24h
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            "SELECT uuid, created_at
            FROM {$table}
            WHERE financial_status = 'REVIEW'
            AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY created_at ASC
            LIMIT 100",
            ARRAY_A
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Processar uma order específica
     *
     * @param array $order Dados da order [uuid, created_at]
     * @return \LimpVix\Application\Results\TransitionFinancialStatusResult
     */
    private function processOrder(array $order)
    {
        $orderUuid = $order['uuid'];

        // Verificar condições através do ledger e ordem
        $hasDispute = $this->checkDispute($orderUuid);
        $professionalValid = $this->checkProfessionalValid($orderUuid);
        $hasPreviousPayout = $this->checkPreviousPayout($orderUuid);

        // Executar Use Case
        return $this->useCase->execute(
            $orderUuid,
            $hasDispute,
            $professionalValid,
            $hasPreviousPayout
        );
    }

    /**
     * Verificar se tem disputa aberta
     *
     * @param string $orderUuid
     * @return bool
     */
    private function checkDispute(string $orderUuid): bool
    {
        // TODO: Implementar verificação de disputa
        // Por ora, retornar false (assumir que não tem)
        return false;
    }

    /**
     * Verificar se profissional é válido
     *
     * @param string $orderUuid
     * @return bool
     */
    private function checkProfessionalValid(string $orderUuid): bool
    {
        // TODO: Implementar verificação de profissional
        // Por ora, retornar true (assumir que é válido)
        return true;
    }

    /**
     * Verificar se já teve payout anterior
     *
     * @param string $orderUuid
     * @return bool
     */
    private function checkPreviousPayout(string $orderUuid): bool
    {
        // Verificar no ledger se já transitou para TRANSFERRED
        $entries = $this->ledgerRepository->findByOrder($orderUuid);

        foreach ($entries as $entry) {
            if ($entry->getToStatus()->getValue() === 'TRANSFERRED') {
                return true;
            }
        }

        return false;
    }

    /**
     * Log de resumo
     *
     * @param int $processed
     * @param int $succeeded
     * @param int $rejected
     * @return void
     */
    private function logSummary(int $processed, int $succeeded, int $rejected): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_timer_cron_summary', [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'rejected' => $rejected,
            'timestamp' => current_time('mysql')
        ]);
    }

    /**
     * Log de erro
     *
     * @param \Exception $exception
     * @return void
     */
    private function logError(\Exception $exception): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_adapter_error', [
            'adapter' => 'TimerCronAdapter',
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
