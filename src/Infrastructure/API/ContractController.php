<?php
/**
 * ContractController - REST API para Contratos Recorrentes
 *
 * Endpoints:
 * - GET  /limpvix/v1/contracts - Listar contratos do cliente
 * - POST /limpvix/v1/contracts - Criar novo contrato
 * - GET  /limpvix/v1/contracts/{id}/executions - Histórico de execuções
 * - POST /limpvix/v1/contracts/{id}/schedule-execution - Agendar próxima execução
 *
 * @package LimpVix
 * @since 0.1.13
 */

namespace LimpVix\Infrastructure\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ContractController
{
    private string $namespace = 'limpvix/v1';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        // GET /limpvix/v1/contracts
        register_rest_route($this->namespace, '/contracts', [
            'methods' => 'GET',
            'callback' => [$this, 'listContracts'],
            'permission_callback' => [$this, 'checkPermissions'],
            'args' => [
                'client_user_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'description' => 'Filtrar por ID do cliente (usuário WordPress)'
                ],
                'status' => [
                    'required' => false,
                    'type' => 'string',
                    'enum' => ['active', 'suspended', 'cancelled', 'expired'],
                    'description' => 'Filtrar por status do contrato'
                ]
            ]
        ]);

        // POST /limpvix/v1/contracts
        register_rest_route($this->namespace, '/contracts', [
            'methods' => 'POST',
            'callback' => [$this, 'createContract'],
            'permission_callback' => [$this, 'checkPermissions'],
            'args' => [
                'client_user_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param) {
                        return get_user_by('id', $param) !== false;
                    }
                ],
                'contract_type' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['monthly', 'weekly', 'biweekly']
                ],
                'recurrence_day' => [
                    'required' => true,
                    'type' => 'integer'
                ],
                'service_code' => [
                    'required' => true,
                    'type' => 'string'
                ],
                'property_type' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['residential', 'commercial']
                ],
                'monthly_value' => [
                    'required' => true,
                    'type' => 'number',
                    'minimum' => 0
                ],
                'start_date' => [
                    'required' => true,
                    'type' => 'string',
                    'format' => 'date'
                ],
                'service_address' => [
                    'required' => true,
                    'type' => 'object'
                ]
            ]
        ]);

        // GET /limpvix/v1/contracts/{id}/executions
        register_rest_route($this->namespace, '/contracts/(?P<id>\d+)/executions', [
            'methods' => 'GET',
            'callback' => [$this, 'getExecutions'],
            'permission_callback' => [$this, 'checkPermissions'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => [$this, 'validateContractId']
                ]
            ]
        ]);

        // POST /limpvix/v1/contracts/{id}/schedule-execution
        register_rest_route($this->namespace, '/contracts/(?P<id>\d+)/schedule-execution', [
            'methods' => 'POST',
            'callback' => [$this, 'scheduleExecution'],
            'permission_callback' => [$this, 'checkPermissions'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => [$this, 'validateContractId']
                ],
                'scheduled_date' => [
                    'required' => true,
                    'type' => 'string',
                    'format' => 'date'
                ]
            ]
        ]);
    }

    /**
     * GET /limpvix/v1/contracts
     * Lista contratos (admin vê todos, cliente vê apenas seus)
     */
    public function listContracts(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        // Determinar filtros
        $clientUserId = $request->get_param('client_user_id');
        $status = $request->get_param('status');
        $currentUserId = get_current_user_id();

        // Se não for admin, só pode ver seus próprios contratos
        if (!current_user_can('manage_options')) {
            $clientUserId = $currentUserId;
        }

        // Build query
        $where = ['1=1'];
        if ($clientUserId) {
            $where[] = $wpdb->prepare('client_user_id = %d', $clientUserId);
        }
        if ($status) {
            $where[] = $wpdb->prepare('status = %s', $status);
        }

        $sql = "SELECT
                    c.*,
                    u.display_name as client_name,
                    u.user_email as client_email
                FROM {$table} c
                LEFT JOIN {$wpdb->users} u ON c.client_user_id = u.ID
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.created_at DESC";

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Format results
        $contracts = array_map(function($row) {
            return [
                'id' => (int) $row['id'],
                'contract_number' => $row['contract_number'],
                'client' => [
                    'user_id' => (int) $row['client_user_id'],
                    'name' => $row['client_name'],
                    'email' => $row['client_email']
                ],
                'contract_type' => $row['contract_type'],
                'contract_type_label' => $this->getContractTypeLabel($row['contract_type']),
                'recurrence_day' => (int) $row['recurrence_day'],
                'service_code' => $row['service_code'],
                'property_type' => $row['property_type'],
                'monthly_value' => (float) $row['monthly_value'],
                'monthly_value_formatted' => 'R$ ' . number_format($row['monthly_value'], 2, ',', '.'),
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'auto_renew' => (bool) $row['auto_renew'],
                'status' => $row['status'],
                'status_label' => $this->getStatusLabel($row['status']),
                'service_address' => json_decode($row['service_address'], true),
                'created_at' => $row['created_at']
            ];
        }, $results);

        return new WP_REST_Response([
            'success' => true,
            'data' => $contracts,
            'total' => count($contracts)
        ], 200);
    }

    /**
     * POST /limpvix/v1/contracts
     * Cria novo contrato
     */
    public function createContract(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        // Gerar número único do contrato
        $contractNumber = $this->generateContractNumber();

        // Preparar dados
        $data = [
            'contract_number' => $contractNumber,
            'client_user_id' => $request->get_param('client_user_id'),
            'contract_type' => $request->get_param('contract_type'),
            'recurrence_day' => $request->get_param('recurrence_day'),
            'recurrence_weeks' => $request->get_param('recurrence_weeks') ?: 1,
            'service_code' => $request->get_param('service_code'),
            'property_type' => $request->get_param('property_type'),
            'estimated_m2' => $request->get_param('estimated_m2'),
            'monthly_value' => $request->get_param('monthly_value'),
            'payment_method' => $request->get_param('payment_method'),
            'payment_day' => $request->get_param('payment_day'),
            'start_date' => $request->get_param('start_date'),
            'end_date' => $request->get_param('end_date'),
            'auto_renew' => $request->get_param('auto_renew') ? 1 : 0,
            'status' => 'active',
            'service_address' => wp_json_encode($request->get_param('service_address')),
            'notes' => $request->get_param('notes'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        // Validar service_code existe
        $serviceExists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_service_catalog WHERE service_code = %s",
            $data['service_code']
        ));

        if (!$serviceExists) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Código de serviço inválido'
            ], 400);
        }

        // Inserir
        $inserted = $wpdb->insert($table, $data);

        if (!$inserted) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Erro ao criar contrato: ' . $wpdb->last_error
            ], 500);
        }

        $contractId = $wpdb->insert_id;

        // Buscar contrato criado
        $contract = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $contractId
        ), ARRAY_A);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Contrato criado com sucesso',
            'data' => [
                'id' => (int) $contract['id'],
                'contract_number' => $contract['contract_number'],
                'client_user_id' => (int) $contract['client_user_id'],
                'contract_type' => $contract['contract_type'],
                'monthly_value' => (float) $contract['monthly_value'],
                'start_date' => $contract['start_date'],
                'status' => $contract['status']
            ]
        ], 201);
    }

    /**
     * GET /limpvix/v1/contracts/{id}/executions
     * Lista histórico de execuções do contrato
     */
    public function getExecutions(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $contractId = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'limpvix_contract_executions';

        // Verificar permissão (se não for admin, verificar se é o cliente do contrato)
        if (!current_user_can('manage_options')) {
            $clientUserId = $wpdb->get_var($wpdb->prepare(
                "SELECT client_user_id FROM {$wpdb->prefix}limpvix_contracts WHERE id = %d",
                $contractId
            ));

            if ($clientUserId != get_current_user_id()) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Acesso negado'
                ], 403);
            }
        }

        // Buscar execuções
        $executions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE contract_id = %d ORDER BY scheduled_date DESC",
            $contractId
        ), ARRAY_A);

        // Formatar
        $formatted = array_map(function($exec) {
            return [
                'id' => (int) $exec['id'],
                'contract_id' => (int) $exec['contract_id'],
                'briefing_uuid' => $exec['briefing_uuid'],
                'schedule_uuid' => $exec['schedule_uuid'],
                'scheduled_date' => $exec['scheduled_date'],
                'executed_date' => $exec['executed_date'],
                'status' => $exec['status'],
                'status_label' => $this->getExecutionStatusLabel($exec['status']),
                'execution_value' => $exec['execution_value'] ? (float) $exec['execution_value'] : null,
                'notes' => $exec['notes'],
                'created_at' => $exec['created_at']
            ];
        }, $executions);

        return new WP_REST_Response([
            'success' => true,
            'data' => $formatted,
            'total' => count($formatted)
        ], 200);
    }

    /**
     * POST /limpvix/v1/contracts/{id}/schedule-execution
     * Agenda uma nova execução do contrato
     */
    public function scheduleExecution(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        $contractId = (int) $request->get_param('id');
        $scheduledDate = $request->get_param('scheduled_date');

        $contractsTable = $wpdb->prefix . 'limpvix_contracts';
        $executionsTable = $wpdb->prefix . 'limpvix_contract_executions';

        // Verificar se contrato existe e está ativo
        $contract = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$contractsTable} WHERE id = %d",
            $contractId
        ), ARRAY_A);

        if (!$contract) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Contrato não encontrado'
            ], 404);
        }

        if ($contract['status'] !== 'active') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Contrato não está ativo'
            ], 400);
        }

        // Verificar se já existe execução para esta data
        $existingExecution = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$executionsTable}
             WHERE contract_id = %d AND scheduled_date = %s AND status != 'cancelled'",
            $contractId,
            $scheduledDate
        ));

        if ($existingExecution > 0) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Já existe uma execução agendada para esta data'
            ], 400);
        }

        // Criar execução
        $data = [
            'contract_id' => $contractId,
            'scheduled_date' => $scheduledDate,
            'status' => 'pending',
            'execution_value' => $contract['monthly_value'],
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $inserted = $wpdb->insert($executionsTable, $data);

        if (!$inserted) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Erro ao agendar execução: ' . $wpdb->last_error
            ], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Execução agendada com sucesso',
            'data' => [
                'execution_id' => $wpdb->insert_id,
                'contract_id' => $contractId,
                'scheduled_date' => $scheduledDate,
                'status' => 'pending'
            ]
        ], 201);
    }

    // ========================================
    // MÉTODOS AUXILIARES
    // ========================================

    public function checkPermissions(): bool
    {
        // Admin pode tudo
        if (current_user_can('manage_options')) {
            return true;
        }

        // Cliente logado pode ver seus próprios contratos
        return is_user_logged_in();
    }

    public function validateContractId($param): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id = %d",
            $param
        ));

        return $exists > 0;
    }

    private function generateContractNumber(): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        $year = date('Y');
        $lastNumber = $wpdb->get_var(
            "SELECT contract_number FROM {$table}
             WHERE contract_number LIKE 'CNT-{$year}-%'
             ORDER BY id DESC LIMIT 1"
        );

        if ($lastNumber) {
            preg_match('/CNT-\d{4}-(\d+)/', $lastNumber, $matches);
            $nextNumber = isset($matches[1]) ? (int)$matches[1] + 1 : 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('CNT-%s-%04d', $year, $nextNumber);
    }

    private function getContractTypeLabel(string $type): string
    {
        $labels = [
            'monthly' => 'Mensal',
            'weekly' => 'Semanal',
            'biweekly' => 'Quinzenal'
        ];
        return $labels[$type] ?? $type;
    }

    private function getStatusLabel(string $status): string
    {
        $labels = [
            'active' => 'Ativo',
            'suspended' => 'Suspenso',
            'cancelled' => 'Cancelado',
            'expired' => 'Expirado'
        ];
        return $labels[$status] ?? $status;
    }

    private function getExecutionStatusLabel(string $status): string
    {
        $labels = [
            'pending' => 'Pendente',
            'scheduled' => 'Agendado',
            'in_progress' => 'Em Andamento',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
            'failed' => 'Falhou'
        ];
        return $labels[$status] ?? $status;
    }
}
