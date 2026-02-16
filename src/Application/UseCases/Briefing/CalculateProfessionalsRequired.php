<?php
/**
 * CalculateProfessionalsRequired - Use Case
 *
 * RESPONSABILIDADE:
 * - Calcular quantidade de profissionais necessários para um Briefing
 * - Aplicar ProfessionalAllocationPolicy
 * - Registrar cálculo no ledger (auditoria)
 * - Suportar batch processing e simulação
 *
 * CASOS DE USO:
 * - Admin quer preview de alocação antes de confirmar
 * - Sistema recalcula após mudança de complexity/package
 * - Batch processing para múltiplos briefings
 *
 * @package LimpVix\Application\UseCases\Briefing
 * @since 0.5.0
 */

namespace LimpVix\Application\UseCases\Briefing;

use LimpVix\Domain\Briefing\BriefingRepositoryInterface;
use LimpVix\Domain\Briefing\ProfessionalAllocationPolicy;
use LimpVix\Infrastructure\Persistence\WpBriefingLedgerRepository;

defined('ABSPATH') || exit;

class CalculateProfessionalsRequired
{
    /**
     * @var BriefingRepositoryInterface
     */
    private $briefingRepository;

    /**
     * @var WpBriefingLedgerRepository
     */
    private $ledgerRepository;

    /**
     * Construtor
     *
     * @param BriefingRepositoryInterface $briefingRepository
     * @param WpBriefingLedgerRepository $ledgerRepository
     */
    public function __construct(
        BriefingRepositoryInterface $briefingRepository,
        WpBriefingLedgerRepository $ledgerRepository
    ) {
        $this->briefingRepository = $briefingRepository;
        $this->ledgerRepository = $ledgerRepository;
    }

    /**
     * Executar cálculo de profissionais necessários
     *
     * @param string $briefingUuid UUID do Briefing
     * @param bool $force Forçar recálculo mesmo se já calculado
     * @return array Result com allocation data
     * @throws \DomainException Se Briefing não existir
     */
    public function execute(string $briefingUuid, bool $force = false): array
    {
        // 1. Buscar Briefing
        $briefing = $this->briefingRepository->findByUuid($briefingUuid);

        if ($briefing === null) {
            return [
                'success' => false,
                'error' => 'briefing_not_found',
                'message' => 'Briefing não encontrado'
            ];
        }

        // 2. Verificar se Briefing tem métricas calculadas
        $metrics = $briefing->getMetrics();

        if ($metrics === null) {
            return [
                'success' => false,
                'error' => 'metrics_not_calculated',
                'message' => 'Métricas do Briefing não foram calculadas. Execute CalculateMetrics primeiro.'
            ];
        }

        // 3. Aplicar ProfessionalAllocationPolicy
        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // 4. Preparar resultado
        $result = [
            'success' => true,
            'briefing_uuid' => $briefingUuid,
            'allocation' => [
                'required_count' => $allocation->getRequiredCount(),
                'requires_multiple' => $allocation->requiresMultiple(),
                'reasoning' => $allocation->getReasoning(),
                'max_allowed' => $allocation->getMaxAllowed(),
                'display' => $allocation->getDisplayString()
            ],
            'context' => [
                'estimated_m2' => $metrics->getEstimatedM2(),
                'duration_minutes' => $metrics->getDurationMinutes(),
                'buffer_minutes' => $metrics->getBufferMinutes(),
                'total_duration' => $metrics->getDurationMinutes() + $metrics->getBufferMinutes(),
                'complexity_level' => $briefing->getComplexity() ? $briefing->getComplexity()->getLevel()->getValue() : null,
                'package_type' => $briefing->getPackage() ? $briefing->getPackage()->getType()->getValue() : null
            ]
        ];

        // 5. Registrar no ledger (se não for apenas preview)
        if ($force || !$this->hasRecentCalculation($briefingUuid)) {
            $this->ledgerRepository->register(
                briefingUuid: $briefingUuid,
                eventType: 'professionals_calculated',
                fromStatus: null,
                toStatus: null,
                actor: 'system',
                actorId: null,
                eventData: [
                    'required_count' => $allocation->getRequiredCount(),
                    'reasoning' => $allocation->getReasoning(),
                    'context' => $result['context']
                ]
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[LimpVix] Profissionais calculados para Briefing %s: %d profissionais',
                    $briefingUuid,
                    $allocation->getRequiredCount()
                ));
            }
        }

        return $result;
    }

    /**
     * Executar cálculo em batch para múltiplos Briefings
     *
     * @param string[] $briefingUuids Lista de UUIDs
     * @return array ['briefing_uuid' => result]
     */
    public function executeBatch(array $briefingUuids): array
    {
        $results = [];

        foreach ($briefingUuids as $uuid) {
            $results[$uuid] = $this->execute($uuid, false);
        }

        return $results;
    }

    /**
     * Simular cálculo sem persistir
     *
     * Útil para preview no admin antes de aplicar mudanças
     *
     * @param float $m2 Área estimada
     * @param int $durationMinutes Duração estimada
     * @param string|null $complexityLevel Nível de complexidade
     * @param string|null $packageType Tipo de pacote
     * @return array Resultado da simulação
     */
    public function simulate(
        float $m2,
        int $durationMinutes,
        ?string $complexityLevel = null,
        ?string $packageType = null
    ): array {
        // Usar método estático do Policy para simular
        $simulation = ProfessionalAllocationPolicy::simulate(
            $m2,
            $durationMinutes,
            $complexityLevel,
            $packageType
        );

        return [
            'success' => true,
            'simulation' => true,
            'allocation' => $simulation,
            'context' => [
                'estimated_m2' => $m2,
                'duration_minutes' => $durationMinutes,
                'complexity_level' => $complexityLevel,
                'package_type' => $packageType
            ]
        ];
    }

    /**
     * Verificar se existe cálculo recente (últimas 24h)
     *
     * Evita spam de eventos no ledger
     *
     * @param string $briefingUuid
     * @return bool
     */
    private function hasRecentCalculation(string $briefingUuid): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_briefing_ledger';

        $sql = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE briefing_uuid = %s
             AND event_type = 'professionals_calculated'
             AND occurred_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $briefingUuid
        );

        return (int) $wpdb->get_var($sql) > 0;
    }

    /**
     * Recalcular para todos os Briefings de um status
     *
     * Útil para recalcular após mudança de configuração
     *
     * @param string $status Status do Briefing (ex: 'metrics_calculated')
     * @param int $limit Limite de Briefings a processar
     * @return array Estatísticas do batch
     */
    public function recalculateByStatus(string $status, int $limit = 100): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_briefings';

        // Buscar Briefings por status
        $sql = $wpdb->prepare(
            "SELECT uuid FROM {$table} WHERE status = %s LIMIT %d",
            $status,
            $limit
        );

        $uuids = $wpdb->get_col($sql);

        if (empty($uuids)) {
            return [
                'success' => true,
                'processed' => 0,
                'message' => 'Nenhum Briefing encontrado com status ' . $status
            ];
        }

        // Processar batch
        $results = $this->executeBatch($uuids);

        // Estatísticas
        $stats = [
            'success' => true,
            'processed' => count($results),
            'status' => $status,
            'allocations' => [
                'single' => 0,
                'multiple' => 0,
                'errors' => 0
            ]
        ];

        foreach ($results as $result) {
            if (!$result['success']) {
                $stats['allocations']['errors']++;
                continue;
            }

            if ($result['allocation']['requires_multiple']) {
                $stats['allocations']['multiple']++;
            } else {
                $stats['allocations']['single']++;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix] Recálculo batch: %d briefings processados (%d single, %d multiple, %d errors)',
                $stats['processed'],
                $stats['allocations']['single'],
                $stats['allocations']['multiple'],
                $stats['allocations']['errors']
            ));
        }

        return $stats;
    }
}
