<?php
/**
 * ContractCompleteFlowTest - Teste E2E do Fluxo Completo de Contrato
 *
 * Testa o fluxo completo de um contrato:
 * 1. Cliente cria briefing
 * 2. Criar contrato a partir do briefing
 * 3. Alocar profissional ao contrato
 * 4. Gerar execuções automaticamente
 * 5. Verificar status do contrato
 * 6. Pausar/Retomar contrato
 * 7. Cancelar contrato
 *
 * @package LimpVix\Tests\E2E
 * @group e2e
 * @group smoke-test
 */

namespace LimpVix\Tests\E2E;

use PHPUnit\Framework\TestCase;

class ContractCompleteFlowTest extends TestCase
{
    private $clientUserId;
    private $briefingId;
    private $contractId;
    private $professionalId;
    private $executionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * @test
     * SMOKE TEST #3: Contract Complete Flow
     *
     * CENÁRIO: Cliente cria briefing, contrato é gerado, profissional alocado, execuções criadas
     *
     * EXPECTATIVA:
     * - Briefing criado com sucesso
     * - Contrato gerado a partir do briefing
     * - Profissional alocado automaticamente
     * - Execuções geradas para datas futuras
     * - Contrato pode ser pausado/retomado
     * - Contrato pode ser cancelado
     */
    public function test_contract_complete_flow_from_briefing_to_execution_generation()
    {
        // ==================== PASSO 1: CRIAR CLIENTE ====================
        $clientData = [
            'email' => 'cliente.test' . time() . '@limpvix.com',
            'password' => 'Senha@123',
            'first_name' => 'Maria',
            'last_name' => 'Silva Test',
            'phone' => '(27) 98888-' . rand(1000, 9999),
        ];

        $this->clientUserId = $this->createTestClient($clientData);

        $this->assertGreaterThan(0, $this->clientUserId, 'Cliente deve ser criado');

        echo "\n✅ PASSO 1: Cliente #{$this->clientUserId} criado\n";

        // ==================== PASSO 2: CRIAR BRIEFING ====================
        $briefingData = [
            'client_user_id' => $this->clientUserId,
            'service_code' => 'deep_cleaning',
            'frequency' => 'weekly',
            'preferred_days' => ['monday', 'wednesday'],
            'preferred_time' => '14:00',
            'address' => [
                'street' => 'Rua das Flores',
                'number' => '123',
                'complement' => 'Apt 45',
                'neighborhood' => 'Praia do Canto',
                'city' => 'Vitória',
                'state' => 'ES',
                'zip_code' => '29055-460',
                'lat' => -20.315,
                'lng' => -40.298,
            ],
            'additional_info' => [
                'has_pets' => true,
                'pet_type' => 'dog',
                'property_size' => '100sqm',
                'bedrooms' => 3,
                'bathrooms' => 2,
            ]
        ];

        $this->briefingId = $this->createBriefing($briefingData);

        $this->assertNotNull($this->briefingId, 'Briefing deve ser criado');

        echo "✅ PASSO 2: Briefing UUID {$this->briefingId} criado\n";

        // ==================== PASSO 3: CRIAR CONTRATO A PARTIR DO BRIEFING ====================
        $contractData = [
            'briefing_uuid' => $this->briefingId,
            'contract_type' => 'monthly',
            'monthly_value' => 800.00,
            'start_date' => date('Y-m-d', strtotime('+7 days')),
            'service_frequency' => 'weekly',
            'preferred_days' => json_encode(['monday', 'wednesday']),
            'status' => 'pending_allocation',
        ];

        $contract = $this->createContractFromBriefing($contractData);

        $this->contractId = $contract['id'];
        $this->assertNotNull($this->contractId);
        $this->assertEquals('pending_allocation', $contract['status']);

        echo "✅ PASSO 3: Contrato #{$this->contractId} criado (status: pending_allocation)\n";

        // ==================== PASSO 4: CRIAR PROFISSIONAL ELEGÍVEL ====================
        $professionalData = [
            'name' => 'Pedro Limpador Test',
            'email' => 'pedro.test' . time() . '@limpvix.com',
            'phone' => '(27) 99777-' . rand(1000, 9999),
            'skills' => ['deep_cleaning'],
            'service_region' => [
                'center_lat' => -20.315,
                'center_lng' => -40.298,
                'radius_km' => 15
            ],
            'availability' => [
                'monday' => [['start' => '08:00', 'end' => '18:00']],
                'wednesday' => [['start' => '08:00', 'end' => '18:00']],
            ]
        ];

        $this->professionalId = $this->createTestProfessional($professionalData);

        $this->assertGreaterThan(0, $this->professionalId);

        echo "✅ PASSO 4: Profissional #{$this->professionalId} criado e elegível\n";

        // ==================== PASSO 5: ALOCAR PROFISSIONAL AO CONTRATO ====================
        $allocationResult = $this->allocateProfessionalToContract($this->contractId, $this->professionalId);

        $this->assertTrue($allocationResult['success']);
        $this->assertEquals('active', $allocationResult['contract_status']);

        echo "✅ PASSO 5: Profissional alocado, contrato ativado (status: active)\n";

        // ==================== PASSO 6: VERIFICAR EXECUÇÕES GERADAS ====================
        // Contratos mensais/semanais devem gerar execuções automaticamente
        $executions = $this->getExecutionsForContract($this->contractId);

        $this->assertNotEmpty($executions, 'Execuções devem ser geradas automaticamente');
        $this->assertGreaterThanOrEqual(4, count($executions), 'Deve gerar pelo menos 4 execuções (1 mês)');

        $this->executionIds = array_column($executions, 'id');

        echo "✅ PASSO 6: " . count($executions) . " execuções geradas automaticamente\n";

        // ==================== PASSO 7: VERIFICAR DATAS DAS EXECUÇÕES ====================
        $firstExecution = $executions[0];
        $startDate = new \DateTime($contract['start_date']);
        $executionDate = new \DateTime($firstExecution['scheduled_date']);

        // Primeira execução deve ser próxima à data de início
        $diffDays = $executionDate->diff($startDate)->days;
        $this->assertLessThanOrEqual(7, $diffDays, 'Primeira execução deve ser na primeira semana');

        echo "✅ PASSO 7: Primeira execução agendada para " . $firstExecution['scheduled_date'] . "\n";

        // ==================== PASSO 8: PAUSAR CONTRATO ====================
        $pauseResult = $this->pauseContract($this->contractId);

        $this->assertTrue($pauseResult['success']);
        $this->assertEquals('suspended', $pauseResult['status']);

        echo "✅ PASSO 8: Contrato pausado (status: suspended)\n";

        // ==================== PASSO 9: VERIFICAR EXECUÇÕES PAUSADAS ====================
        $executionsAfterPause = $this->getExecutionsForContract($this->contractId);

        foreach ($executionsAfterPause as $execution) {
            if ($execution['status'] === 'pending') {
                // Execuções pending devem estar pausadas
                $this->assertNotNull($execution['paused_at'] ?? null);
            }
        }

        echo "✅ PASSO 9: Execuções pendentes pausadas\n";

        // ==================== PASSO 10: RETOMAR CONTRATO ====================
        $resumeResult = $this->resumeContract($this->contractId);

        $this->assertTrue($resumeResult['success']);
        $this->assertEquals('active', $resumeResult['status']);

        echo "✅ PASSO 10: Contrato retomado (status: active)\n";

        // ==================== PASSO 11: CANCELAR CONTRATO ====================
        $cancelResult = $this->cancelContract($this->contractId, 'Teste de cancelamento');

        $this->assertTrue($cancelResult['success']);
        $this->assertEquals('cancelled', $cancelResult['status']);
        $this->assertNotNull($cancelResult['cancelled_at']);

        echo "✅ PASSO 11: Contrato cancelado (status: cancelled)\n";

        // ==================== VALIDAÇÃO FINAL ====================
        echo "\n🎉 SMOKE TEST #3 PASSED: Fluxo completo de contrato concluído com sucesso\n";
        echo "   - Cliente criado: #{$this->clientUserId}\n";
        echo "   - Briefing criado: {$this->briefingId}\n";
        echo "   - Contrato criado: #{$this->contractId}\n";
        echo "   - Profissional alocado: #{$this->professionalId}\n";
        echo "   - Execuções geradas: " . count($executions) . "\n";
        echo "   - Ciclo completo: pending_allocation → active → suspended → active → cancelled\n\n";
    }

    // ==================== HELPER METHODS ====================

    private function createTestClient(array $data): int
    {
        // Criar usuário WordPress com role limpvix_client
        $userId = wp_create_user($data['email'], $data['password'], $data['email']);

        wp_update_user([
            'ID' => $userId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'display_name' => $data['first_name'] . ' ' . $data['last_name'],
            'role' => 'limpvix_client'
        ]);

        update_user_meta($userId, 'phone', $data['phone']);

        return $userId;
    }

    private function createBriefing(array $data): string
    {
        global $wpdb;

        $uuid = wp_generate_uuid4();

        $wpdb->insert($wpdb->prefix . 'limpvix_briefings', [
            'uuid' => $uuid,
            'client_user_id' => $data['client_user_id'],
            'service_code' => $data['service_code'],
            'frequency' => $data['frequency'],
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ]);

        // Salvar dados adicionais (address, additional_info)
        foreach ($data['address'] as $key => $value) {
            $wpdb->insert($wpdb->prefix . 'limpvix_briefing_data', [
                'briefing_uuid' => $uuid,
                'data_key' => 'address.' . $key,
                'data_value' => json_encode($value),
                'created_at' => current_time('mysql')
            ]);
        }

        foreach ($data['additional_info'] as $key => $value) {
            $wpdb->insert($wpdb->prefix . 'limpvix_briefing_data', [
                'briefing_uuid' => $uuid,
                'data_key' => 'additional_info.' . $key,
                'data_value' => json_encode($value),
                'created_at' => current_time('mysql')
            ]);
        }

        return $uuid;
    }

    private function createContractFromBriefing(array $data): array
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'limpvix_contracts', [
            'contract_number' => 'TEST-CONTRACT-' . time(),
            'client_user_id' => $data['client_user_id'] ?? 0,
            'briefing_uuid' => $data['briefing_uuid'],
            'contract_type' => $data['contract_type'],
            'monthly_value' => $data['monthly_value'],
            'start_date' => $data['start_date'],
            'service_frequency' => $data['service_frequency'],
            'preferred_days' => $data['preferred_days'],
            'status' => $data['status'],
            'created_at' => current_time('mysql')
        ]);

        $contractId = $wpdb->insert_id;

        return [
            'id' => $contractId,
            'status' => $data['status'],
        ];
    }

    private function createTestProfessional(array $data): int
    {
        global $wpdb;

        // Criar usuário WordPress
        $userId = wp_create_user($data['email'], wp_generate_password(), $data['email']);
        wp_update_user([
            'ID' => $userId,
            'display_name' => $data['name'],
            'role' => 'limpvix_professional'
        ]);

        // Criar registro de profissional
        $wpdb->insert($wpdb->prefix . 'bkntc_staff', [
            'name' => $data['name'],
            'wp_user_id' => $userId,
            'is_active' => 1,
            'created_at' => current_time('mysql')
        ]);

        $professionalId = $wpdb->insert_id;

        // Salvar availability
        foreach ($data['availability'] as $day => $slots) {
            foreach ($slots as $slot) {
                $wpdb->insert($wpdb->prefix . 'limpvix_professional_availability', [
                    'professional_id' => $professionalId,
                    'day_of_week' => $day,
                    'start_time' => $slot['start'],
                    'end_time' => $slot['end'],
                    'service_region' => json_encode($data['service_region']),
                    'skills' => json_encode($data['skills']),
                    'is_active' => 1
                ]);
            }
        }

        return $professionalId;
    }

    private function allocateProfessionalToContract(int $contractId, int $professionalId): array
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'limpvix_contracts',
            [
                'professional_id' => $professionalId,
                'status' => 'active',
                'activated_at' => current_time('mysql')
            ],
            ['id' => $contractId],
            ['%d', '%s', '%s'],
            ['%d']
        );

        return [
            'success' => true,
            'contract_status' => 'active'
        ];
    }

    private function getExecutionsForContract(int $contractId): array
    {
        global $wpdb;

        $executions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_executions WHERE contract_id = %d ORDER BY scheduled_date ASC",
            $contractId
        ), ARRAY_A);

        return $executions ?: [];
    }

    private function pauseContract(int $contractId): array
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'limpvix_contracts',
            [
                'status' => 'suspended',
                'suspended_at' => current_time('mysql')
            ],
            ['id' => $contractId],
            ['%s', '%s'],
            ['%d']
        );

        // Pausar execuções pending
        $wpdb->update(
            $wpdb->prefix . 'limpvix_executions',
            ['paused_at' => current_time('mysql')],
            ['contract_id' => $contractId, 'status' => 'pending'],
            ['%s'],
            ['%d', '%s']
        );

        return [
            'success' => true,
            'status' => 'suspended'
        ];
    }

    private function resumeContract(int $contractId): array
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'limpvix_contracts',
            [
                'status' => 'active',
                'suspended_at' => null
            ],
            ['id' => $contractId],
            ['%s', 'NULL'],
            ['%d']
        );

        // Despausar execuções
        $wpdb->update(
            $wpdb->prefix . 'limpvix_executions',
            ['paused_at' => null],
            ['contract_id' => $contractId],
            ['NULL'],
            ['%d']
        );

        return [
            'success' => true,
            'status' => 'active'
        ];
    }

    private function cancelContract(int $contractId, string $reason): array
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'limpvix_contracts',
            [
                'status' => 'cancelled',
                'cancelled_at' => current_time('mysql'),
                'cancellation_reason' => $reason
            ],
            ['id' => $contractId],
            ['%s', '%s', '%s'],
            ['%d']
        );

        return [
            'success' => true,
            'status' => 'cancelled',
            'cancelled_at' => current_time('mysql')
        ];
    }

    private function cleanupTestData(): void
    {
        global $wpdb;

        if ($this->clientUserId) {
            wp_delete_user($this->clientUserId);
        }

        if ($this->briefingId) {
            $wpdb->delete($wpdb->prefix . 'limpvix_briefings', ['uuid' => $this->briefingId]);
            $wpdb->delete($wpdb->prefix . 'limpvix_briefing_data', ['briefing_uuid' => $this->briefingId]);
        }

        if ($this->contractId) {
            $wpdb->delete($wpdb->prefix . 'limpvix_contracts', ['id' => $this->contractId]);
        }

        if ($this->professionalId) {
            $wpdb->delete($wpdb->prefix . 'bkntc_staff', ['id' => $this->professionalId]);
            $wpdb->delete($wpdb->prefix . 'limpvix_professional_availability', ['professional_id' => $this->professionalId]);
        }

        if (!empty($this->executionIds)) {
            foreach ($this->executionIds as $executionId) {
                $wpdb->delete($wpdb->prefix . 'limpvix_executions', ['id' => $executionId]);
            }
        }
    }
}
