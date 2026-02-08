<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Repositories;

use LimpVix\Domain\Scheduling\Schedule;

/**
 * Repository Interface: ScheduleRepositoryInterface
 *
 * Contrato para persistência de Schedules.
 * Implementação será em Infrastructure layer (WpScheduleRepository).
 */
interface ScheduleRepositoryInterface
{
    /**
     * Salva Schedule (create ou update)
     */
    public function save(Schedule $schedule): void;

    /**
     * Busca Schedule por UUID
     *
     * @return Schedule|null
     */
    public function findByUuid(string $uuid): ?Schedule;

    /**
     * Busca Schedule por Order UUID
     *
     * @return Schedule|null
     */
    public function findByOrderUuid(string $orderUuid): ?Schedule;

    /**
     * Busca Schedules por profissional e data
     *
     * @param int $professionalId
     * @param \DateTimeImmutable $date
     * @return Schedule[]
     */
    public function findByProfessionalAndDate(
        int $professionalId,
        \DateTimeImmutable $date
    ): array;

    /**
     * Busca Schedules por status
     *
     * @param string $status
     * @param int $limit
     * @param int $offset
     * @return Schedule[]
     */
    public function findByStatus(string $status, int $limit = 50, int $offset = 0): array;

    /**
     * Busca Schedules com violação de SLA
     *
     * @param \DateTimeImmutable|null $since Desde quando
     * @return Schedule[]
     */
    public function findWithSlaViolations(?\DateTimeImmutable $since = null): array;

    /**
     * Busca Schedules pendentes de alocação
     *
     * @return Schedule[]
     */
    public function findPendingAllocation(): array;

    /**
     * Busca Schedules por data
     *
     * @param \DateTimeImmutable $date
     * @return Schedule[]
     */
    public function findByDate(\DateTimeImmutable $date): array;

    /**
     * Deleta Schedule
     */
    public function delete(string $uuid): void;

    /**
     * Conta total de Schedules por status
     */
    public function countByStatus(string $status): int;
}
