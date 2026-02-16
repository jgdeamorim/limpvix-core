<?php
/**
 * CreateContractFromBriefing Use Case
 *
 * Cria contrato recorrente automaticamente quando briefing é locked
 * e possui campos de recorrência preenchidos.
 *
 * TRIGGER: Evento limpvix_briefing_locked
 * CONDIÇÃO: briefing.is_recurrent = true
 * RESULTADO: Novo contrato criado em wp_limpvix_contracts
 *
 * @package LimpVix\Application\UseCases\Contract
 * @since 0.2.0
 */

namespace LimpVix\Application\UseCases\Contract;

defined('ABSPATH') || exit;

class CreateContractFromBriefing
{
    /**
     * Executa criação de contrato a partir de briefing
     *
     * @param array $briefingData Dados do briefing
     * @return array|WP_Error Contrato criado ou erro
     */
    public function execute(array $briefingData)
    {
        // Validar se briefing tem recorrência
        if (empty($briefingData['is_recurrent']) || !$briefingData['is_recurrent']) {
            return new \WP_Error(
                'not_recurrent',
                'Briefing não é recorrente',
                ['status' => 400]
            );
        }

        // Validar campos obrigatórios de recorrência
        $requiredFields = ['recurrence_type', 'recurrence_duration', 'recurrence_start_date'];
        foreach ($requiredFields as $field) {
            if (empty($briefingData[$field])) {
                return new \WP_Error(
                    'missing_field',
                    "Campo obrigatório ausente: $field",
                    ['status' => 400]
                );
            }
        }

        // Validar tipo de recorrência
        $validTypes = ['weekly', 'monthly', 'biweekly'];
        if (!in_array($briefingData['recurrence_type'], $validTypes, true)) {
            return new \WP_Error(
                'invalid_recurrence_type',
                'Tipo de recorrência inválido',
                ['status' => 400]
            );
        }

        // Calcular data de fim baseado na duração
        $endDate = $this->calculateEndDate(
            $briefingData['recurrence_start_date'],
            $briefingData['recurrence_duration']
        );

        // Preparar dados do contrato
        $contractData = [
            'client_user_id' => $briefingData['user_id'] ?? get_current_user_id(),
            'contract_type' => $briefingData['recurrence_type'],
            'recurrence_day' => $briefingData['recurrence_day'] ?? $this->getDefaultRecurrenceDay($briefingData['recurrence_type']),
            'recurrence_weeks' => $briefingData['recurrence_weeks'] ?? 1,
            'service_code' => $briefingData['service_type'] ?? 'residential_standard',
            'property_type' => $briefingData['property_type'] ?? 'residential',
            'estimated_m2' => $briefingData['total_m2'] ?? 0,
            'monthly_value' => $this->calculateMonthlyValue($briefingData),
            'payment_method' => 'pending',
            'payment_day' => 5, // Default: dia 5 do mês
            'start_date' => $briefingData['recurrence_start_date'],
            'end_date' => $endDate,
            'auto_renew' => $briefingData['recurrence_duration'] === 'indefinite',
            'service_address' => $this->formatServiceAddress($briefingData),
            'notes' => $this->generateContractNotes($briefingData),
        ];

        // Criar contrato
        $contractId = $this->createContract($contractData);

        if (is_wp_error($contractId)) {
            return $contractId;
        }

        // Associar briefing ao contrato (opcional, para rastreabilidade)
        $this->linkBriefingToContract($briefingData['id'], $contractId);

        // Disparar evento
        do_action('limpvix_contract_created_from_briefing', [
            'contract_id' => $contractId,
            'briefing_id' => $briefingData['id'],
            'briefing_uuid' => $briefingData['uuid'],
            'contract_data' => $contractData,
        ]);

        return [
            'success' => true,
            'contract_id' => $contractId,
            'message' => 'Contrato recorrente criado com sucesso',
        ];
    }

    /**
     * Cria contrato no banco de dados
     *
     * @param array $data
     * @return int|WP_Error ID do contrato ou erro
     */
    private function createContract(array $data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        // Gerar número único do contrato
        $contractNumber = $this->generateContractNumber();

        // Preparar dados completos
        $insertData = array_merge($data, [
            'contract_number' => $contractNumber,
            'status' => 'active',
            'allocation_status' => 'pending', // Para sistema de ofertas
            'allocation_attempts' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        // Converter service_address para JSON se necessário
        if (is_array($insertData['service_address'])) {
            $insertData['service_address'] = wp_json_encode($insertData['service_address']);
        }

        // Inserir
        $result = $wpdb->insert($table, $insertData);

        if ($result === false) {
            return new \WP_Error(
                'db_error',
                'Erro ao criar contrato: ' . $wpdb->last_error,
                ['status' => 500]
            );
        }

        return $wpdb->insert_id;
    }

    /**
     * Gera número único do contrato (CNT-YYYY-XXXX)
     *
     * @return string
     */
    private function generateContractNumber(): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        $year = date('Y');
        $prefix = "CNT-$year-";

        // Buscar último número do ano
        $lastNumber = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING(contract_number, 10) AS UNSIGNED))
             FROM $table
             WHERE contract_number LIKE %s",
            $prefix . '%'
        ));

        $nextNumber = ($lastNumber ? (int) $lastNumber : 0) + 1;

        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calcula data de fim baseado na duração
     *
     * @param string $startDate Data de início (Y-m-d)
     * @param string $duration '3_months', '6_months', 'indefinite'
     * @return string|null Data de fim (Y-m-d) ou null
     */
    private function calculateEndDate(string $startDate, string $duration): ?string
    {
        if ($duration === 'indefinite') {
            return null;
        }

        $start = new \DateTime($startDate);

        switch ($duration) {
            case '3_months':
                $start->modify('+3 months');
                break;
            case '6_months':
                $start->modify('+6 months');
                break;
            case '12_months':
            case '1_year':
                $start->modify('+12 months');
                break;
            default:
                return null;
        }

        return $start->format('Y-m-d');
    }

    /**
     * Calcula valor mensal baseado no briefing
     *
     * @param array $briefingData
     * @return float
     */
    private function calculateMonthlyValue(array $briefingData): float
    {
        // Se briefing já tem valor calculado, usar
        if (!empty($briefingData['estimated_price'])) {
            return (float) $briefingData['estimated_price'];
        }

        // Calcular baseado em m² e tipo
        $m2 = (float) ($briefingData['total_m2'] ?? 0);
        $pricePerM2 = 15.00; // Default R$ 15/m²

        return round($m2 * $pricePerM2, 2);
    }

    /**
     * Formata endereço do serviço
     *
     * @param array $briefingData
     * @return array
     */
    private function formatServiceAddress(array $briefingData): array
    {
        // Se briefing já tem localização estruturada, usar
        if (!empty($briefingData['location']) && is_array($briefingData['location'])) {
            return $briefingData['location'];
        }

        // Montar estrutura mínima
        return [
            'street' => $briefingData['street'] ?? '',
            'number' => $briefingData['number'] ?? '',
            'complement' => $briefingData['complement'] ?? '',
            'neighborhood' => $briefingData['neighborhood'] ?? '',
            'city' => $briefingData['city'] ?? 'Vitória',
            'state' => $briefingData['state'] ?? 'ES',
            'zipcode' => $briefingData['zipcode'] ?? '',
        ];
    }

    /**
     * Gera notas do contrato baseado no briefing
     *
     * @param array $briefingData
     * @return string
     */
    private function generateContractNotes(array $briefingData): string
    {
        $notes = "Contrato gerado automaticamente do Briefing #{$briefingData['id']} ({$briefingData['uuid']}).\n";
        $notes .= "Tipo de recorrência: {$briefingData['recurrence_type']}.\n";
        $notes .= "Duração: {$briefingData['recurrence_duration']}.\n";

        if (!empty($briefingData['observations'])) {
            $notes .= "\nObservações do cliente:\n{$briefingData['observations']}";
        }

        return $notes;
    }

    /**
     * Obtém dia padrão de recorrência baseado no tipo
     *
     * @param string $type weekly|monthly|biweekly
     * @return int
     */
    private function getDefaultRecurrenceDay(string $type): int
    {
        switch ($type) {
            case 'weekly':
                return 1; // Segunda-feira
            case 'biweekly':
                return 1; // Segunda-feira
            case 'monthly':
                return 5; // Dia 5 do mês
            default:
                return 1;
        }
    }

    /**
     * Associa briefing ao contrato (para rastreabilidade)
     *
     * @param int $briefingId
     * @param int $contractId
     * @return void
     */
    private function linkBriefingToContract(int $briefingId, int $contractId): void
    {
        global $wpdb;

        // Atualizar meta do briefing
        $wpdb->update(
            $wpdb->prefix . 'limpvix_briefings',
            ['contract_id' => $contractId],
            ['id' => $briefingId],
            ['%d'],
            ['%d']
        );

        // Registrar no ledger do briefing
        $wpdb->insert(
            $wpdb->prefix . 'limpvix_briefing_ledger',
            [
                'briefing_id' => $briefingId,
                'event_type' => 'contract_created',
                'from_status' => 'locked',
                'to_status' => 'locked',
                'actor' => 'system',
                'event_data' => wp_json_encode(['contract_id' => $contractId]),
                'occurred_at' => current_time('mysql'),
            ]
        );
    }
}
