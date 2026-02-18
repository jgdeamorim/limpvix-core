<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Repositories;

use LimpVix\Domain\Scheduling\Professional;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceComplexity;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;
use LimpVix\Domain\Scheduling\ValueObjects\TimeWindow;

/**
 * Repository Interface: ProfessionalRepositoryInterface
 *
 * Contrato para persistência de Professionals.
 * Implementação será em Infrastructure layer (WpProfessionalRepository).
 */
interface ProfessionalRepositoryInterface
{
    /**
     * Salva Professional (create ou update)
     */
    public function save(Professional $professional): void;

    /**
     * Busca Professional por Staff ID (LimpVix)
     *
     * @return Professional|null
     */
    public function findByStaffId(int $staffId): ?Professional;

    /**
     * Busca Professional por User ID (WordPress)
     *
     * @return Professional|null
     */
    public function findByUserId(int $userId): ?Professional;

    /**
     * Busca todos Professionals ativos
     *
     * @return Professional[]
     */
    public function findAllActive(): array;

    /**
     * Busca Professionals disponíveis para alocação
     *
     * Critérios:
     * - Ativos
     * - Região cobre localização
     * - Skills compatíveis com complexidade
     * - Disponíveis no horário
     *
     * @param \DateTimeImmutable $date
     * @param TimeWindow $window
     * @param ServiceLocation $location
     * @param ServiceComplexity $complexity
     * @return Professional[]
     */
    public function findAvailableFor(
        \DateTimeImmutable $date,
        TimeWindow $window,
        ServiceLocation $location,
        ServiceComplexity $complexity
    ): array;

    /**
     * Busca Professionals por região (que cobrem uma localização)
     *
     * @param ServiceLocation $location
     * @return Professional[]
     */
    public function findByRegion(ServiceLocation $location): array;

    /**
     * Busca Professionals por skill
     *
     * @param string $skill
     * @return Professional[]
     */
    public function findBySkill(string $skill): array;

    /**
     * Busca carga diária de um profissional (schedules alocados no dia)
     *
     * @param int $professionalId
     * @param \DateTimeImmutable $date
     * @return int Minutos totais alocados
     */
    public function getDailyLoad(int $professionalId, \DateTimeImmutable $date): int;

    /**
     * Conta total de Professionals ativos
     */
    public function countActive(): int;
}
