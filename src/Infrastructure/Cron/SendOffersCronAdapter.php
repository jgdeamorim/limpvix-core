<?php
/**
 * SendOffersCronAdapter - Fallback Cron Job for Auto-Sending Offers
 *
 * RESPONSABILIDADE:
 * - Executar a cada 1 hora (hourly)
 * - Encontrar contratos ativos SEM offers pendentes
 * - Enviar offers automaticamente (fallback do evento imediato)
 * - Registrar logs de execução
 *
 * HYBRID AUTOMATION - PHASE 2: FALLBACK
 * Este cron é parte do modelo HÍBRIDO de automação:
 * - 99% dos casos: Evento imediato funciona (onContractActivated)
 * - 1% edge cases: Este cron recupera automaticamente
 *
 * WORKFLOW:
 * 1. Buscar contratos com status 'active'
 * 2. Sem profissional alocado (allocated_professional_id IS NULL)
 * 3. Sem offers pendentes (LEFT JOIN com offers = NULL)
 * 4. Criados há >5 minutos (evita race condition com evento)
 * 5. Para cada contrato, chamar SendOffers use case
 * 6. Registrar estatísticas de execução
 *
 * CRON SCHEDULE:
 * - Hook: limpvix_fallback_send_offers
 * - Frequency: hourly
 * - Recurrence: 'hourly'
 *
 * IDEMPOTÊNCIA:
 * - SendOffers use case já verifica offers existentes
 * - Query LEFT JOIN garante não pegar contratos com offers
 * - Safe para rodar múltiplas vezes
 *
 * RATE LIMITING:
 * - Processa máximo 20 contratos por execução
 * - Evita timeout em servidores com muitos contratos pendentes
 *
 * @package LimpVix\Infrastructure\Cron
 * @since 1.0.0 (HYBRID AUTOMATION)
 */

namespace LimpVix\Infrastructure\Cron;

defined('ABSPATH') || exit;

final class SendOffersCronAdapter
{
    /**
     * Register cron job
     *
     * @return void
     */
    public static function register(): void
    {
        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('limpvix_fallback_send_offers')) {
            wp_schedule_event(time(), 'hourly', 'limpvix_fallback_send_offers');
        }

        // Register cron handler
        add_action('limpvix_fallback_send_offers', [self::class, 'execute']);
    }

    /**
     * Execute cron job (fallback send offers)
     *
     * Called by WordPress cron: limpvix_fallback_send_offers
     *
     * @return array Execution statistics
     */
    public static function execute(): array
    {
        $startTime = microtime(true);

        error_log('[LimpVix] SendOffersCronAdapter (FALLBACK): Starting execution...');

        $stats = [
            'contracts_found' => 0,
            'offers_sent' => 0,
            'offers_failed' => 0,
            'execution_time' => 0,
        ];

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'limpvix_contracts';
            $offersTable = $wpdb->prefix . 'limpvix_contract_offers';

            // Find active contracts without offers (created >5 min ago to avoid race)
            $contracts = $wpdb->get_results("
                SELECT c.id, c.contract_number
                FROM {$table} c
                LEFT JOIN {$offersTable} o
                    ON c.id = o.contract_id AND o.status = 'pending'
                WHERE c.status = 'active'
                AND c.allocated_professional_id IS NULL
                AND o.id IS NULL
                AND c.created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ORDER BY c.created_at ASC
                LIMIT 20
            ");

            $stats['contracts_found'] = count($contracts);

            if (empty($contracts)) {
                error_log('[LimpVix] SendOffersCronAdapter (FALLBACK): No contracts need offers - all good!');
                $stats['execution_time'] = round(microtime(true) - $startTime, 2);
                return $stats;
            }

            // Get SendOffers use case
            $useCases = $GLOBALS['limpvix_contract_use_cases'] ?? [];

            if (!isset($useCases['send_offers'])) {
                error_log('[LimpVix] SendOffersCronAdapter (FALLBACK): SendOffers use case not available');
                $stats['execution_time'] = round(microtime(true) - $startTime, 2);
                return $stats;
            }

            /** @var \LimpVix\Application\UseCase\Briefing\SendOffers $sendOffersUseCase */
            $sendOffersUseCase = $useCases['send_offers'];

            // Process each contract
            foreach ($contracts as $contract) {
                try {
                    $result = $sendOffersUseCase->execute($contract->id);

                    $stats['offers_sent']++;

                    error_log(sprintf(
                        '[LimpVix] [HYBRID-FALLBACK] SendOffers recovered: %d offers sent for Contract #%d (%s)',
                        $result['offers_sent'],
                        $contract->id,
                        $contract->contract_number ?? 'N/A'
                    ));

                } catch (\RuntimeException $e) {
                    // Expected errors (no coordinates, no professionals, etc.)
                    error_log(sprintf(
                        '[LimpVix] [HYBRID-FALLBACK] SendOffers expected condition for Contract #%d: %s',
                        $contract->id,
                        $e->getMessage()
                    ));

                } catch (\Exception $e) {
                    $stats['offers_failed']++;

                    error_log(sprintf(
                        '[LimpVix] [HYBRID-FALLBACK] SendOffers failed for Contract #%d: %s',
                        $contract->id,
                        $e->getMessage()
                    ));
                }
            }

        } catch (\Exception $e) {
            error_log(sprintf(
                '[LimpVix] SendOffersCronAdapter (FALLBACK) error: %s',
                $e->getMessage()
            ));
        }

        $stats['execution_time'] = round(microtime(true) - $startTime, 2);

        error_log(sprintf(
            '[LimpVix] SendOffersCronAdapter (FALLBACK) completed: %d contracts found, %d offers sent, %d failed (%.2fs)',
            $stats['contracts_found'],
            $stats['offers_sent'],
            $stats['offers_failed'],
            $stats['execution_time']
        ));

        return $stats;
    }

    /**
     * Unregister cron job (cleanup on plugin deactivation)
     *
     * @return void
     */
    public static function unregister(): void
    {
        $timestamp = wp_next_scheduled('limpvix_fallback_send_offers');

        if ($timestamp) {
            wp_unschedule_event($timestamp, 'limpvix_fallback_send_offers');
        }
    }
}
