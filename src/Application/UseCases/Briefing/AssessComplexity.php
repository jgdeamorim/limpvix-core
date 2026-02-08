<?php
/**
 * AssessComplexity - Use Case
 *
 * RESPONSABILIDADE:
 * - Avaliar complexidade de um Briefing
 * - Aplicar BriefingComplexityPolicy para determinar nível
 * - Registrar assessment no ledger (event sourcing)
 * - Pode ser chamado automaticamente ou manualmente
 *
 * PRINCÍPIOS:
 * - Use Case pattern (Application Layer)
 * - Single Responsibility
 * - Fail-safe (retorna Result)
 *
 * REGRAS DE NEGÓCIO:
 * - Só pode avaliar se Briefing NÃO estiver locked
 * - Complexity pode ser reavaliada se métricas mudarem
 * - Policy determinística (mesmas entradas = mesma saída)
 *
 * @package LimpVix\Application\UseCases\Briefing
 * @since 0.4.0
 */

namespace LimpVix\Application\UseCases\Briefing;

use LimpVix\Domain\Briefing\Briefing;
use LimpVix\Domain\Briefing\Complexity;
use LimpVix\Domain\Briefing\BriefingComplexityPolicy;
use LimpVix\Infrastructure\Persistence\WpBriefingRepository;

defined('ABSPATH') || exit;

class AssessComplexity
{
    /**
     * @var WpBriefingRepository
     */
    private $briefingRepository;

    /**
     * Construtor
     *
     * @param WpBriefingRepository $briefingRepository
     */
    public function __construct(WpBriefingRepository $briefingRepository)
    {
        $this->briefingRepository = $briefingRepository;
    }

    /**
     * Executar assessment de complexidade
     *
     * @param string $briefingUuid UUID do Briefing
     * @param bool $force Forçar reavaliação mesmo se já existe
     * @return array ['success' => bool, 'message' => string, 'data' => array|null]
     */
    public function execute(string $briefingUuid, bool $force = false): array
    {
        try {
            // 1. Buscar Briefing
            $briefing = $this->briefingRepository->findByUuid($briefingUuid);

            if (!$briefing) {
                return $this->fail("Briefing não encontrado: {$briefingUuid}");
            }

            // 2. Verificar se está locked
            if ($briefing->isLocked()) {
                return $this->fail("Briefing locked não pode ter complexidade avaliada");
            }

            // 3. Verificar se já tem complexity e não é força
            if ($briefing->getComplexity() !== null && !$force) {
                return $this->success([
                    'briefing_uuid' => $briefingUuid,
                    'complexity' => $briefing->getComplexity()->toArray(),
                    'message' => 'Complexity já foi avaliada (use force=true para reavaliar)',
                    'skipped' => true
                ]);
            }

            // 4. Verificar se tem métricas (necessário para assessment)
            if (!$briefing->getMetrics()) {
                return $this->fail("Briefing não possui métricas calculadas");
            }

            // 5. Determinar complexity via Policy
            $complexity = BriefingComplexityPolicy::determine($briefing);

            // 6. Aplicar complexity ao Briefing
            $briefing->assessComplexity($complexity);

            // 7. Persistir Briefing
            $this->briefingRepository->save($briefing);

            // 8. Registrar evento no ledger
            $this->briefingRepository->appendEvent([
                'briefing_uuid' => $briefingUuid,
                'event_type' => $force ? 'complexity_reassessed' : 'complexity_assessed',
                'from_status' => null,
                'to_status' => $briefing->getStatus()->getValue(),
                'actor' => 'system',
                'event_data' => json_encode([
                    'complexity_level' => $complexity->getLevel()->getValue(),
                    'multiplier' => $complexity->getMultiplier(),
                    'reasoning' => $complexity->getReasoning(),
                    'force' => $force,
                    'timestamp' => current_time('mysql')
                ])
            ]);

            // 9. Retornar sucesso
            return $this->success([
                'briefing_uuid' => $briefingUuid,
                'complexity' => $complexity->toArray(),
                'message' => "Complexidade avaliada: {$complexity->getLevel()->getDisplayName()} ({$complexity->getMultiplierDisplay()})"
            ]);

        } catch (\Exception $e) {
            error_log("AssessComplexity::execute error: " . $e->getMessage());
            return $this->fail("Erro ao avaliar complexidade: " . $e->getMessage());
        }
    }

    /**
     * Executar assessment para múltiplos briefings
     *
     * @param string[] $briefingUuids
     * @param bool $force
     * @return array
     */
    public function executeBatch(array $briefingUuids, bool $force = false): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => []
        ];

        foreach ($briefingUuids as $uuid) {
            $result = $this->execute($uuid, $force);

            if ($result['success']) {
                if (isset($result['data']['skipped']) && $result['data']['skipped']) {
                    $results['skipped']++;
                } else {
                    $results['success']++;
                }
            } else {
                $results['failed']++;
            }

            $results['details'][] = [
                'uuid' => $uuid,
                'success' => $result['success'],
                'message' => $result['message']
            ];
        }

        return $results;
    }

    /**
     * Retornar sucesso
     *
     * @param array $data
     * @return array
     */
    private function success(array $data): array
    {
        return [
            'success' => true,
            'message' => 'OK',
            'data' => $data
        ];
    }

    /**
     * Retornar falha
     *
     * @param string $message
     * @return array
     */
    private function fail(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null
        ];
    }
}
