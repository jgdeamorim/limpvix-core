<?php
/**
 * ProfessionalRepositoryInterface
 *
 * Contrato para persistência de Profissionais
 * Seguindo Repository Pattern (DDD)
 *
 * @package LimpVix\Domain\Professional
 */

namespace LimpVix\Domain\Professional;

defined('ABSPATH') || exit;

interface ProfessionalRepositoryInterface
{
    /**
     * Busca profissional por ID
     *
     * @param int $id
     * @return Professional|null
     */
    public function findById(int $id): ?Professional;

    /**
     * Busca profissional por User ID do WordPress
     *
     * @param int $userId
     * @return Professional|null
     */
    public function findByUserId(int $userId): ?Professional;

    /**
     * Busca profissional por CPF
     *
     * @param string $cpf CPF sem máscara (11 dígitos)
     * @return Professional|null
     */
    public function findByCpf(string $cpf): ?Professional;

    /**
     * Busca profissionais elegíveis para um contrato
     * 
     * Critérios:
     * - Ativo e verificado
     * - Região cobre a localização do serviço
     * - Skills necessárias presentes
     * - Disponível na data/horário
     *
     * @param float $targetLat Latitude do serviço
     * @param float $targetLng Longitude do serviço
     * @param array $requiredSkills Skills necessárias
     * @param \DateTimeImmutable $serviceDateTime Data/hora do serviço
     * @return array Array de Professional
     */
    public function findEligibleFor(
        float $targetLat,
        float $targetLng,
        array $requiredSkills,
        \DateTimeImmutable $serviceDateTime
    ): array;

    /**
     * Busca profissionais por região (dentro de um raio)
     *
     * @param float $centerLat
     * @param float $centerLng
     * @param int $radiusKm
     * @return array
     */
    public function findByRegion(float $centerLat, float $centerLng, int $radiusKm): array;

    /**
     * Busca profissionais por skills
     *
     * @param array $skills
     * @return array
     */
    public function findBySkills(array $skills): array;

    /**
     * Busca profissionais ativos e verificados
     *
     * @return array
     */
    public function findActiveAndVerified(): array;

    /**
     * Busca profissionais suspensos
     *
     * @return array
     */
    public function findSuspended(): array;

    /**
     * Busca profissionais pendentes de verificação
     *
     * @return array
     */
    public function findPendingVerification(): array;

    /**
     * Busca profissionais por score (acima de um mínimo)
     *
     * @param float $minScore Score mínimo (0.00-5.00)
     * @return array
     */
    public function findByMinScore(float $minScore): array;

    /**
     * Persiste profissional (insert ou update)
     *
     * @param Professional $professional
     * @return void
     */
    public function save(Professional $professional): void;

    /**
     * Atualiza score de um profissional
     *
     * @param int $professionalId
     * @param float $newScore
     * @param string $reason Motivo da mudança
     * @param array $details Detalhes extras (opcional)
     * @return void
     */
    public function updateScore(int $professionalId, float $newScore, string $reason, array $details = []): void;

    /**
     * Incrementa contador de serviços completados
     *
     * @param int $professionalId
     * @return void
     */
    public function incrementCompletedServices(int $professionalId): void;

    /**
     * Incrementa contador de cancelamentos
     *
     * @param int $professionalId
     * @return void
     */
    public function incrementCancelledServices(int $professionalId): void;

    /**
     * Incrementa contador de no-shows
     *
     * @param int $professionalId
     * @return void
     */
    public function incrementNoShows(int $professionalId): void;

    /**
     * Atualiza última atividade do profissional
     *
     * @param int $professionalId
     * @return void
     */
    public function updateLastActivity(int $professionalId): void;

    /**
     * Deleta profissional (soft delete - marca como inativo)
     *
     * @param int $professionalId
     * @return void
     */
    public function delete(int $professionalId): void;

    /**
     * Conta total de profissionais ativos
     *
     * @return int
     */
    public function countActive(): int;

    /**
     * Conta total de profissionais verificados
     *
     * @return int
     */
    public function countVerified(): int;

    /**
     * Retorna estatísticas agregadas
     *
     * @return array
     */
    public function getStatistics(): array;

    /**
     * Busca histórico de alocações de um profissional
     *
     * @param int $professionalId
     * @return array
     */
    public function getAllocationHistory(int $professionalId): array;

    /**
     * Busca histórico de score de um profissional
     *
     * @param int $professionalId
     * @param int $limit
     * @return array
     */
    public function getScoreHistory(int $professionalId, int $limit = 50): array;

    /**
     * Find consecutive poor feedbacks for professional
     *
     * @param int $professionalId Professional ID
     * @param float $threshold Rating threshold (default 3.0)
     * @param int $count Minimum consecutive poor feedbacks
     * @param int $days Within last N days
     * @return array Feedback records
     */
    public function findConsecutivePoorFeedbacks(
        int $professionalId,
        float $threshold = 3.0,
        int $count = 3,
        int $days = 30
    ): array;
}
