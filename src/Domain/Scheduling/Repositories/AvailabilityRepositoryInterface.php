<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Repositories;

use LimpVix\Domain\Scheduling\ValueObjects\WeeklyAvailability;
use LimpVix\Domain\Scheduling\ValueObjects\TimeSlot;

/**
 * Repository Interface: AvailabilityRepositoryInterface
 *
 * Contrato para persistência de disponibilidade de profissionais.
 * Implementação será em Infrastructure layer (WpAvailabilityRepository).
 */
interface AvailabilityRepositoryInterface
{
    /**
     * Salva disponibilidade de um profissional
     *
     * @param int $professionalId
     * @param WeeklyAvailability $availability
     */
    public function save(int $professionalId, WeeklyAvailability $availability): void;

    /**
     * Busca disponibilidade de um profissional
     *
     * @param int $professionalId
     * @return WeeklyAvailability|null
     */
    public function findByProfessional(int $professionalId): ?WeeklyAvailability;

    /**
     * Busca profissionais disponíveis em um dia da semana
     *
     * @param string $dayOfWeek 'monday', 'tuesday', etc.
     * @return int[] Professional IDs
     */
    public function findAvailableOnDay(string $dayOfWeek): array;

    /**
     * Busca slots disponíveis de um profissional em uma data
     *
     * @param int $professionalId
     * @param \DateTimeImmutable $date
     * @param int $durationMinutes Duração necessária
     * @return TimeSlot[]
     */
    public function findAvailableSlots(
        int $professionalId,
        \DateTimeImmutable $date,
        int $durationMinutes
    ): array;

    /**
     * Verifica se profissional está disponível em horário específico
     *
     * @param int $professionalId
     * @param \DateTimeImmutable $timestamp
     * @return bool
     */
    public function isAvailableAt(int $professionalId, \DateTimeImmutable $timestamp): bool;

    /**
     * Deleta disponibilidade de um profissional
     *
     * @param int $professionalId
     */
    public function delete(int $professionalId): void;
}
