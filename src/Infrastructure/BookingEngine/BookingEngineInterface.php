<?php
/**
 * BookingEngineInterface - Contrato de Integração
 *
 * RESPONSABILIDADE:
 * - Definir interface para qualquer sistema de agendamento
 * - Abstrair Booknetic (ou qualquer outro)
 * - Permitir troca de implementação
 *
 * PRINCÍPIOS:
 * - Dependency Inversion (dependemos de abstração)
 * - Adapter Pattern
 * - Contrato claro e estável
 * - Não vazar detalhes de implementação
 *
 * BENEFÍCIOS:
 * - Trocar Booknetic por outro sistema = criar novo adapter
 * - Testar sem Booknetic (mock desta interface)
 * - Migração facilitada
 *
 * @package LimpVix\Infrastructure\BookingEngine
 */

namespace LimpVix\Infrastructure\BookingEngine;

defined('ABSPATH') || exit;

interface BookingEngineInterface
{
    /**
     * Cria um appointment no sistema de agendamento
     *
     * @param array $data Dados do appointment
     * @return int ID do appointment criado
     * @throws \Exception Se criação falhar
     */
    public function createAppointment(array $data): int;

    /**
     * Atualiza um appointment
     *
     * @param int $appointmentId ID do appointment
     * @param array $data Novos dados
     * @return bool Sucesso
     */
    public function updateAppointment(int $appointmentId, array $data): bool;

    /**
     * Deleta (ou cancela) um appointment
     *
     * @param int $appointmentId ID do appointment
     * @return bool Sucesso
     */
    public function deleteAppointment(int $appointmentId): bool;

    /**
     * Busca dados de um appointment
     *
     * @param int $appointmentId ID do appointment
     * @return array|null Dados do appointment ou null se não encontrado
     */
    public function getAppointment(int $appointmentId): ?array;

    /**
     * Atualiza status de um appointment
     *
     * @param int $appointmentId ID do appointment
     * @param string $newStatus Novo status
     * @return bool Sucesso
     */
    public function updateAppointmentStatus(int $appointmentId, string $newStatus): bool;

    /**
     * Busca horários disponíveis
     *
     * @param array $params Parâmetros de busca
     * @return array Lista de horários disponíveis
     */
    public function getAvailableTimeslots(array $params): array;

    /**
     * Busca serviços disponíveis
     *
     * @param array $filters Filtros
     * @return array Lista de serviços
     */
    public function getServices(array $filters = []): array;

    /**
     * Busca profissionais (staff) disponíveis
     *
     * @param array $filters Filtros
     * @return array Lista de profissionais
     */
    public function getStaff(array $filters = []): array;

    /**
     * Verifica se appointment existe
     *
     * @param int $appointmentId ID do appointment
     * @return bool
     */
    public function appointmentExists(int $appointmentId): bool;

    /**
     * Valida se dados são suficientes para criar appointment
     *
     * @param array $data Dados a validar
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateAppointmentData(array $data): array;
}
