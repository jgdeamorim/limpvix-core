<?php
/**
 * CreateBriefing - Use Case
 *
 * RESPONSABILIDADE:
 * - Criar novo Briefing (estado inicial: draft)
 * - Gerar UUID único
 * - Persistir via repository
 * - Disparar evento briefing.created
 *
 * @package LimpVix\Application\UseCases\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Application\UseCases\Briefing;

use LimpVix\Domain\Briefing\Briefing;
use LimpVix\Domain\Briefing\PropertyType;
use LimpVix\Domain\Briefing\BriefingRepositoryInterface;
use LimpVix\Domain\Briefing\BriefingCreatedEvent;
use LimpVix\Application\Results\BriefingOperationResult;

defined('ABSPATH') || exit;

class CreateBriefing
{
    /**
     * @var BriefingRepositoryInterface
     */
    private $repository;

    /**
     * Construtor
     *
     * @param BriefingRepositoryInterface $repository
     */
    public function __construct(BriefingRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Executar
     *
     * @param int $userId WordPress user ID
     * @param string $propertyType 'residential' ou 'commercial'
     * @return BriefingOperationResult
     */
    public function execute(int $userId, string $propertyType): BriefingOperationResult
    {
        try {
            // 1. Validar inputs
            if ($userId <= 0) {
                return BriefingOperationResult::failure("User ID inválido");
            }

            // 2. Gerar UUID único
            $uuid = $this->generateUuid();

            // 3. Criar PropertyType
            $type = PropertyType::fromString($propertyType);

            // 4. Criar Briefing (estado: draft)
            $briefing = Briefing::create($uuid, $userId, $type);

            // 5. Persistir
            $saved = $this->repository->save($briefing);

            if (!$saved) {
                return BriefingOperationResult::failure("Erro ao salvar Briefing");
            }

            // 6. Disparar evento
            $this->dispatchCreatedEvent($briefing);

            // 7. Retornar sucesso
            return BriefingOperationResult::success($briefing, [
                'uuid' => $uuid,
                'status' => 'draft'
            ]);

        } catch (\InvalidArgumentException $e) {
            return BriefingOperationResult::failure("Dados inválidos: " . $e->getMessage());
        } catch (\Exception $e) {
            return BriefingOperationResult::failure("Erro ao criar Briefing: " . $e->getMessage());
        }
    }

    /**
     * Gerar UUID único
     *
     * @return string
     */
    private function generateUuid(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        // Fallback
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Disparar evento briefing.created
     *
     * @param Briefing $briefing
     * @return void
     */
    private function dispatchCreatedEvent(Briefing $briefing): void
    {
        $event = new BriefingCreatedEvent(
            $briefing->getUuid(),
            $briefing->getUserId(),
            $briefing->getPropertyType()->getValue()
        );

        // WordPress action hook
        if (function_exists('do_action')) {
            do_action('limpvix_briefing_created', $event->toArray());
        }
    }
}
