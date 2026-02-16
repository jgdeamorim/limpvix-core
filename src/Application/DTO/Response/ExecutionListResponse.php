<?php
/**
 * ExecutionListResponse - DTO for Execution list API responses
 *
 * @package LimpVix\Application\DTO\Response
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Response;

defined('ABSPATH') || exit;

final class ExecutionListResponse extends BaseResponseDTO
{
    /**
     * @param array $executions Array de ContractExecution aggregates
     * @param int $total Total de registros
     */
    public function __construct(
        private readonly array $executions,
        private readonly int $total,
    ) {}

    public function toArray(): array
    {
        return [
            'success' => true,
            'data' => array_map(
                fn($execution) => ExecutionResponse::fromAggregate($execution)->toArray(),
                $this->executions
            ),
            'total' => $this->total,
        ];
    }
}
