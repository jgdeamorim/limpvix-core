<?php
/**
 * UpdateBriefingStep - Use Case (CRÍTICO)
 *
 * RESPONSABILIDADE:
 * - Atualizar step específico do Briefing
 * - Validar via BriefingPolicy
 * - Recalcular métricas se necessário
 * - Transicionar estado se aplicável
 * - Persistir mudanças
 * - Disparar evento step_completed
 *
 * Este é o Use Case mais complexo e crítico do módulo Briefing.
 *
 * @package LimpVix\Application\UseCases\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Application\UseCases\Briefing;

use LimpVix\Domain\Briefing\Briefing;
use LimpVix\Domain\Briefing\BriefingRepositoryInterface;
use LimpVix\Domain\Briefing\BriefingPolicy;
use LimpVix\Domain\Briefing\BriefingStatus;
use LimpVix\Domain\Briefing\PropertyStructure;
use LimpVix\Domain\Briefing\Frequency;
use LimpVix\Domain\Briefing\BriefingStepCompletedEvent;
use LimpVix\Application\Services\BriefingMetricsCalculator;
use LimpVix\Application\Results\BriefingOperationResult;

defined('ABSPATH') || exit;

class UpdateBriefingStep
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
     * @var BriefingMetricsCalculator
     */
    private $metricsCalculator;

    /**
     * Construtor
     *
     * @param BriefingRepositoryInterface $repository
     * @param BriefingPolicy $policy
     * @param BriefingMetricsCalculator $metricsCalculator
     */
    public function __construct(
        BriefingRepositoryInterface $repository,
        BriefingPolicy $policy,
        BriefingMetricsCalculator $metricsCalculator
    ) {
        $this->repository = $repository;
        $this->policy = $policy;
        $this->metricsCalculator = $metricsCalculator;
    }

    /**
     * Executar
     *
     * @param string $uuid UUID do Briefing
     * @param string $stepName Nome do step ('structure', 'frequency', etc)
     * @param array $stepData Dados do step
     * @return BriefingOperationResult
     */
    public function execute(string $uuid, string $stepName, array $stepData): BriefingOperationResult
    {
        try {
            // 1. Buscar Briefing
            $briefing = $this->repository->findByUuid($uuid);

            if ($briefing === null) {
                return BriefingOperationResult::failure("Briefing não encontrado");
            }

            // 2. Validar se pode editar
            if (!$this->policy->canEdit($briefing)) {
                return BriefingOperationResult::failure("Briefing locked não pode ser editado");
            }

            // 3. Validar se pode editar este step específico
            if (!$this->policy->canEditStep($briefing, $stepName)) {
                return BriefingOperationResult::failure("Step '{$stepName}' não pode ser editado no estado atual");
            }

            // 4. Aplicar atualização do step
            $this->applyStepUpdate($briefing, $stepName, $stepData);

            // 5. Recalcular métricas se necessário
            if ($this->shouldRecalculateMetrics($stepName)) {
                $this->recalculateMetrics($briefing, $stepData);
            }

            // 6. Transicionar estado se necessário
            $this->attemptStateTransition($briefing);

            // 7. Persistir
            $saved = $this->repository->save($briefing);

            if (!$saved) {
                return BriefingOperationResult::failure("Erro ao salvar Briefing");
            }

            // 8. Disparar evento
            $this->dispatchStepCompletedEvent($briefing, $stepName);

            // 9. Retornar sucesso
            return BriefingOperationResult::success($briefing, [
                'step_name' => $stepName,
                'status' => $briefing->getStatus()->getValue()
            ]);

        } catch (\InvalidArgumentException $e) {
            return BriefingOperationResult::failure("Dados inválidos: " . $e->getMessage());
        } catch (\DomainException $e) {
            return BriefingOperationResult::failure("Regra de negócio violada: " . $e->getMessage());
        } catch (\Exception $e) {
            return BriefingOperationResult::failure("Erro ao atualizar step: " . $e->getMessage());
        }
    }

    /**
     * Aplicar atualização do step
     *
     * @param Briefing $briefing
     * @param string $stepName
     * @param array $stepData
     * @return void
     */
    private function applyStepUpdate(Briefing $briefing, string $stepName, array $stepData): void
    {
        switch ($stepName) {
            case 'cleaning_types':
                $briefing->updateCleaningTypes($stepData['cleaning_types'] ?? []);
                break;

            case 'structure':
                $structure = PropertyStructure::fromArray($stepData);
                $briefing->updateStructure($structure);
                break;

            case 'frequency':
                $frequency = Frequency::fromArray($stepData);
                $briefing->updateFrequency($frequency);
                break;

            case 'location':
                // P0.4: Trigger IBGE geo index lookup when zip_code is provided
                $zipCode = $stepData['zip_code'] ?? $stepData['cep'] ?? null;
                if ($zipCode !== null) {
                    $ibgeService = new \LimpVix\Infrastructure\Services\IBGEAreaIndexService();
                    $geoResult = $ibgeService->calculate($zipCode);

                    if ($geoResult !== null) {
                        // Save geo data to briefing via wpdb (direct update)
                        // GAP: Briefing aggregate doesn't have geo fields yet (domain model)
                        global $wpdb;
                        $briefingId = $briefing->getId();
                        if ($briefingId) {
                            $wpdb->update(
                                $wpdb->prefix . 'limpvix_briefings',
                                [
                                    'geo_index' => $geoResult['indice'],
                                    'geo_classification' => $geoResult['classificacao'],
                                    'geo_multiplier' => $geoResult['multiplicador'],
                                ],
                                ['id' => $briefingId],
                                ['%f', '%s', '%f'],
                                ['%d']
                            );
                        }
                    }
                }
                break;
        }
    }

    /**
     * Verificar se deve recalcular métricas
     *
     * @param string $stepName
     * @return bool
     */
    private function shouldRecalculateMetrics(string $stepName): bool
    {
        return in_array($stepName, ['structure', 'cleaning_types'], true);
    }

    /**
     * Recalcular métricas
     *
     * @param Briefing $briefing
     * @param array $stepData
     * @return void
     */
    private function recalculateMetrics(Briefing $briefing, array $stepData): void
    {
        $structure = $briefing->getStructure();

        if ($structure === null) {
            return; // Sem estrutura, não pode calcular
        }

        // Usar cleaningTypes do aggregate (persistido), com fallback para stepData
        $cleaningTypes = $briefing->getCleaningTypes();
        if (empty($cleaningTypes) && isset($stepData['cleaning_types'])) {
            $cleaningTypes = $stepData['cleaning_types'];
        }
        $additionalConditions = $stepData['additional_conditions'] ?? [];

        $metrics = $this->metricsCalculator->calculate(
            $structure,
            $cleaningTypes,
            $additionalConditions
        );

        $briefing->updateMetrics($metrics);
    }

    /**
     * Tentar transicionar estado
     *
     * @param Briefing $briefing
     * @return void
     */
    private function attemptStateTransition(Briefing $briefing): void
    {
        $currentStatus = $briefing->getStatus();

        // draft → in_progress (ao começar a preencher)
        if ($currentStatus->isDraft()) {
            $newStatus = BriefingStatus::inProgress();
            if ($this->policy->canTransition($currentStatus, $newStatus, $briefing)) {
                $briefing->transitionTo($newStatus);
            }
        }

        // in_progress → pending_phone_verification (ao completar todos os steps)
        if ($currentStatus->isInProgress()) {
            $newStatus = BriefingStatus::pendingPhoneVerification();
            if ($this->policy->canTransition($currentStatus, $newStatus, $briefing)) {
                $briefing->transitionTo($newStatus);
            }
        }
    }

    /**
     * Disparar evento step_completed
     *
     * @param Briefing $briefing
     * @param string $stepName
     * @return void
     */
    private function dispatchStepCompletedEvent(Briefing $briefing, string $stepName): void
    {
        $event = new BriefingStepCompletedEvent(
            $briefing->getUuid(),
            $stepName,
            $briefing->getStatus()->getValue()
        );

        if (function_exists('do_action')) {
            do_action('limpvix_briefing_step_completed', $event->toArray());
        }
    }
}
