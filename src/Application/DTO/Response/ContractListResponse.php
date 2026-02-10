<?php
/**
 * ContractListResponse - DTO for Contract list API responses
 *
 * RESPONSABILIDADE:
 * - Converter array de Contracts para formato de lista
 * - Adicionar metadata de paginação
 * - Formato otimizado para listagens
 *
 * @package LimpVix\Application\DTO\Response
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Response;

defined('ABSPATH') || exit;

final class ContractListResponse extends BaseResponseDTO
{
    /**
     * @param array $contracts Array de Contract aggregates
     * @param int $total Total de registros
     * @param int|null $page Página atual (opcional)
     * @param int|null $perPage Registros por página (opcional)
     */
    public function __construct(
        private readonly array $contracts,
        private readonly int $total,
        private readonly ?int $page = null,
        private readonly ?int $perPage = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'success' => true,
            'data' => array_map(
                fn($contract) => ContractResponse::fromAggregate($contract)->toArray(),
                $this->contracts
            ),
            'total' => $this->total,
        ];

        if ($this->page !== null && $this->perPage !== null) {
            $data['pagination'] = [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total_pages' => (int) ceil($this->total / $this->perPage),
            ];
        }

        return $data;
    }
}
