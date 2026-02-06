<?php
/**
 * Booknetic485Adapter - Implementação para Booknetic 4.8.5
 *
 * RESPONSABILIDADE:
 * - Implementar BookingEngineInterface para Booknetic 4.8.5
 * - Única classe que conhece internamente o Booknetic
 * - Traduzir entre domínio LimpVix e Booknetic
 * - Lidar com particularidades do Booknetic
 *
 * PRINCÍPIOS:
 * - Isolamento total (resto do sistema não sabe que é Booknetic)
 * - Adaptação de estruturas de dados
 * - Tratamento de erros do Booknetic
 * - Logging de interações
 *
 * ⚠️ ATENÇÃO:
 * - NUNCA modificar código do Booknetic
 * - NUNCA chamar métodos privados do Booknetic
 * - SEMPRE usar APIs públicas
 * - Se Booknetic não expõe algo, PERGUNTAR ao usuário
 *
 * @package LimpVix\Infrastructure\BookingEngine
 */

namespace LimpVix\Infrastructure\BookingEngine;

defined('ABSPATH') || exit;

class Booknetic485Adapter implements BookingEngineInterface
{
    /**
     * Prefixo das tabelas do Booknetic
     *
     * @var string
     */
    private $tablePrefix;

    /**
     * Construtor
     */
    public function __construct()
    {
        global $wpdb;
        $this->tablePrefix = $wpdb->prefix . 'bkntc_';
    }

    /**
     * {@inheritDoc}
     *
     * @throws \LogicException Adapter em modo read-only
     */
    public function createAppointment(array $data): int
    {
        // ⚠️ ADAPTER EM MODO READ-ONLY (PASSO 2)
        //
        // Métodos de escrita DESABILITADOS até:
        // 1. Análise completa da API do Booknetic
        // 2. Validação de que Core controla fluxo
        // 3. Feature Flag específica habilitada
        //
        // NÃO assumir ou inventar API.

        throw new \LogicException(
            'Método não habilitado. Adapter em modo read-only. ' .
            'Escrita no Booknetic será implementada após validação completa.'
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws \LogicException Adapter em modo read-only
     */
    public function updateAppointment(int $appointmentId, array $data): bool
    {
        throw new \LogicException(
            'Método não habilitado. Adapter em modo read-only.'
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws \LogicException Adapter em modo read-only
     */
    public function deleteAppointment(int $appointmentId): bool
    {
        throw new \LogicException(
            'Método não habilitado. Adapter em modo read-only.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getAppointment(int $appointmentId): ?array
    {
        global $wpdb;

        $table = $this->tablePrefix . 'appointments';

        $appointment = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $appointmentId),
            ARRAY_A
        );

        if (!$appointment) {
            return null;
        }

        // Transformar para formato padronizado
        return $this->transformAppointmentData($appointment);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \LogicException Adapter em modo read-only
     */
    public function updateAppointmentStatus(int $appointmentId, string $newStatus): bool
    {
        // ⚠️ ESCRITA DIRETA NO BANCO DESABILITADA
        //
        // Status deve ser alterado VIA HOOKS do Booknetic,
        // não diretamente no banco.
        //
        // Isso garante que:
        // 1. Hooks do Booknetic executam
        // 2. Core intercepta via booknetic_before_status_change
        // 3. Auditoria completa

        throw new \LogicException(
            'Método não habilitado. Adapter em modo read-only. ' .
            'Status deve ser alterado via API do Booknetic, não diretamente no banco.'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getAvailableTimeslots(array $params): array
    {
        // TODO: Implementar
        // Precisa chamar TimeSlotService do Booknetic
        // Ou usar hooks expostos

        throw new \Exception('getAvailableTimeslots: Implementação pendente');
    }

    /**
     * {@inheritDoc}
     */
    public function getServices(array $filters = []): array
    {
        global $wpdb;

        $table = $this->tablePrefix . 'services';

        $where = ['is_active = 1'];
        $whereValues = [];

        if (isset($filters['category_id'])) {
            $where[] = 'category_id = %d';
            $whereValues[] = $filters['category_id'];
        }

        $whereClause = implode(' AND ', $where);

        if (!empty($whereValues)) {
            $query = $wpdb->prepare("SELECT * FROM {$table} WHERE {$whereClause}", ...$whereValues);
        } else {
            $query = "SELECT * FROM {$table} WHERE {$whereClause}";
        }

        $services = $wpdb->get_results($query, ARRAY_A);

        return array_map([$this, 'transformServiceData'], $services);
    }

    /**
     * {@inheritDoc}
     */
    public function getStaff(array $filters = []): array
    {
        global $wpdb;

        $table = $this->tablePrefix . 'staff';

        $where = ['is_active = 1'];
        $whereValues = [];

        $whereClause = implode(' AND ', $where);

        if (!empty($whereValues)) {
            $query = $wpdb->prepare("SELECT * FROM {$table} WHERE {$whereClause}", ...$whereValues);
        } else {
            $query = "SELECT * FROM {$table} WHERE {$whereClause}";
        }

        $staff = $wpdb->get_results($query, ARRAY_A);

        return array_map([$this, 'transformStaffData'], $staff);
    }

    /**
     * {@inheritDoc}
     */
    public function appointmentExists(int $appointmentId): bool
    {
        global $wpdb;

        $table = $this->tablePrefix . 'appointments';

        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id = %d", $appointmentId)
        );

        return (int) $exists > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function validateAppointmentData(array $data): array
    {
        $errors = [];

        $required = ['service_id', 'customer_id', 'date', 'time'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[] = "Campo obrigatório: {$field}";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    // ========================================
    // TRANSFORMERS (Booknetic → LimpVix)
    // ========================================

    /**
     * Transforma dados de appointment do Booknetic para formato padronizado
     *
     * @param array $appointment Dados raw do Booknetic
     * @return array Dados padronizados
     */
    private function transformAppointmentData(array $appointment): array
    {
        return [
            'id' => (int) $appointment['id'],
            'service_id' => (int) $appointment['service_id'],
            'customer_id' => (int) $appointment['customer_id'],
            'staff_id' => isset($appointment['staff_id']) ? (int) $appointment['staff_id'] : null,
            'status' => $appointment['status'],
            'date' => $appointment['start_date'],
            'price' => (float) $appointment['price'],
            'created_at' => $appointment['created_at'] ?? null,
        ];
    }

    /**
     * Transforma dados de serviço
     *
     * @param array $service Dados raw do Booknetic
     * @return array Dados padronizados
     */
    private function transformServiceData(array $service): array
    {
        return [
            'id' => (int) $service['id'],
            'name' => $service['name'],
            'duration' => (int) $service['duration'],
            'price' => (float) $service['price'],
            'category_id' => isset($service['category_id']) ? (int) $service['category_id'] : null,
        ];
    }

    /**
     * Transforma dados de profissional
     *
     * @param array $staff Dados raw do Booknetic
     * @return array Dados padronizados
     */
    private function transformStaffData(array $staff): array
    {
        return [
            'id' => (int) $staff['id'],
            'name' => $staff['name'],
            'email' => $staff['email'] ?? null,
        ];
    }
}
