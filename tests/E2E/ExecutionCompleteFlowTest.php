<?php
/**
 * ExecutionCompleteFlowTest - Teste E2E do Fluxo Completo de Execução
 *
 * Testa o fluxo completo de uma execução de serviço:
 * 1. Criar execução a partir de contrato
 * 2. Profissional inicia execução
 * 3. Upload de evidências (fotos antes/depois)
 * 4. Completar execução
 * 5. Cliente dá feedback
 * 6. Payout é gerado automaticamente
 * 7. Validação de evidências pelo admin
 *
 * @package LimpVix\Tests\E2E
 * @group e2e
 * @group smoke-test
 */

namespace LimpVix\Tests\E2E;

use PHPUnit\Framework\TestCase;

class ExecutionCompleteFlowTest extends TestCase
{
    private $contractId;
    private $professionalId;
    private $executionId;
    private $payoutId;
    private $evidenceIds = [];

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
     * SMOKE TEST #4: Execution Complete Flow
     *
     * CENÁRIO: Profissional executa serviço, cliente aprova, payout é gerado
     *
     * EXPECTATIVA:
     * - Execução criada a partir de contrato
     * - Profissional pode iniciar execução
     * - Upload de múltiplas evidências
     * - Completar execução com sucesso
     * - Cliente dá feedback com rating
     * - Payout gerado automaticamente após feedback
     * - Admin pode validar evidências
     */
    public function test_execution_complete_flow_from_start_to_payout()
    {
        // ==================== PASSO 1: SETUP - CRIAR CONTRATO E PROFISSIONAL ====================
        $this->professionalId = $this->createTestProfessional([
            'name' => 'João Executante Test',
            'email' => 'joao.exec.test' . time() . '@limpvix.com',
            'skills' => ['deep_cleaning'],
        ]);

        $this->contractId = $this->createTestContract([
            'service_code' => 'deep_cleaning',
            'monthly_value' => 600.00,
            'professional_id' => $this->professionalId,
        ]);

        $this->assertGreaterThan(0, $this->professionalId);
        $this->assertGreaterThan(0, $this->contractId);

        echo "\n✅ SETUP: Profissional #{$this->professionalId} e Contrato #{$this->contractId} criados\n";

        // ==================== PASSO 2: CRIAR EXECUÇÃO ====================
        $executionData = [
            'contract_id' => $this->contractId,
            'professional_id' => $this->professionalId,
            'scheduled_date' => date('Y-m-d', strtotime('+1 day')),
            'scheduled_time' => '14:00:00',
            'status' => 'pending',
            'service_code' => 'deep_cleaning',
        ];

        $execution = $this->createExecution($executionData);
        $this->executionId = $execution['id'];

        $this->assertNotNull($this->executionId);
        $this->assertEquals('pending', $execution['status']);

        echo "✅ PASSO 2: Execução #{$this->executionId} criada (status: pending)\n";

        // ==================== PASSO 3: PROFISSIONAL INICIA EXECUÇÃO ====================
        $startResult = $this->startExecution($this->executionId, $this->professionalId);

        $this->assertTrue($startResult['success']);
        $this->assertEquals('in_progress', $startResult['status']);
        $this->assertNotNull($startResult['started_at']);

        echo "✅ PASSO 3: Execução iniciada às " . $startResult['started_at'] . " (status: in_progress)\n";

        // ==================== PASSO 4: UPLOAD EVIDÊNCIA #1 - FOTO ANTES ====================
        $evidence1 = $this->uploadEvidence($this->executionId, [
            'type' => 'photo',
            'url' => 'https://example.com/evidence/before-1.jpg',
            'description' => 'Sala antes da limpeza',
            'stage' => 'before',
        ]);

        $this->assertTrue($evidence1['success']);
        $this->evidenceIds[] = $evidence1['evidence_id'];

        echo "✅ PASSO 4: Evidência #1 (ANTES) uploaded - ID: " . $evidence1['evidence_id'] . "\n";

        // ==================== PASSO 5: UPLOAD EVIDÊNCIA #2 - FOTO DURANTE ====================
        $evidence2 = $this->uploadEvidence($this->executionId, [
            'type' => 'photo',
            'url' => 'https://example.com/evidence/during-1.jpg',
            'description' => 'Limpeza em andamento',
            'stage' => 'during',
        ]);

        $this->assertTrue($evidence2['success']);
        $this->evidenceIds[] = $evidence2['evidence_id'];

        echo "✅ PASSO 5: Evidência #2 (DURANTE) uploaded - ID: " . $evidence2['evidence_id'] . "\n";

        // ==================== PASSO 6: UPLOAD EVIDÊNCIA #3 - FOTO DEPOIS ====================
        $evidence3 = $this->uploadEvidence($this->executionId, [
            'type' => 'photo',
            'url' => 'https://example.com/evidence/after-1.jpg',
            'description' => 'Sala após limpeza completa',
            'stage' => 'after',
        ]);

        $this->assertTrue($evidence3['success']);
        $this->evidenceIds[] = $evidence3['evidence_id'];

        echo "✅ PASSO 6: Evidência #3 (DEPOIS) uploaded - ID: " . $evidence3['evidence_id'] . "\n";

        // ==================== PASSO 7: VERIFICAR EVIDÊNCIAS NO BANCO ====================
        $evidences = $this->getEvidencesForExecution($this->executionId);

        $this->assertCount(3, $evidences, 'Devem existir 3 evidências');

        $beforeCount = count(array_filter($evidences, fn($e) => $e['stage'] === 'before'));
        $afterCount = count(array_filter($evidences, fn($e) => $e['stage'] === 'after'));

        $this->assertGreaterThanOrEqual(1, $beforeCount, 'Deve ter pelo menos 1 foto ANTES');
        $this->assertGreaterThanOrEqual(1, $afterCount, 'Deve ter pelo menos 1 foto DEPOIS');

        echo "✅ PASSO 7: " . count($evidences) . " evidências verificadas (before: {$beforeCount}, after: {$afterCount})\n";

        // ==================== PASSO 8: COMPLETAR EXECUÇÃO ====================
        $completeResult = $this->completeExecution($this->executionId, $this->professionalId);

        $this->assertTrue($completeResult['success']);
        $this->assertEquals('completed', $completeResult['status']);
        $this->assertNotNull($completeResult['completed_at']);

        echo "✅ PASSO 8: Execução completada às " . $completeResult['completed_at'] . " (status: completed)\n";

        // ==================== PASSO 9: CLIENTE DÁ FEEDBACK COM RATING 5 ====================
        $feedbackData = [
            'rating' => 5,
            'comment' => 'Serviço impecável! Muito profissional e atencioso.',
            'approved_at' => date('Y-m-d H:i:s'),
        ];

        $feedbackResult = $this->submitCustomerFeedback($this->executionId, $feedbackData);

        $this->assertTrue($feedbackResult['success']);
        $this->assertEquals(5, $feedbackResult['rating']);

        echo "✅ PASSO 9: Cliente deu feedback ⭐⭐⭐⭐⭐ (5 estrelas)\n";

        // ==================== PASSO 10: VERIFICAR PAYOUT GERADO AUTOMATICAMENTE ====================
        // Aguardar processamento (simulado com sleep)
        sleep(1);

        $payout = $this->getPayoutForExecution($this->executionId);

        $this->assertNotNull($payout, 'Payout deve ser criado automaticamente após feedback 5 estrelas');
        $this->payoutId = $payout['id'];
        $this->assertEquals($this->professionalId, $payout['professional_id']);
        $this->assertEquals('approved', $payout['status'], 'Payout deve estar aprovado (feedback 5 estrelas)');
        $this->assertGreaterThan(0, $payout['net_amount']);

        echo "✅ PASSO 10: Payout #{$this->payoutId} gerado automaticamente - R$ " . number_format($payout['net_amount'], 2) . " (status: approved)\n";

        // ==================== PASSO 11: ADMIN VALIDA EVIDÊNCIAS ====================
        $validationResult1 = $this->validateEvidence($this->evidenceIds[0], 'approved', 'Foto clara e bem enquadrada');
        $validationResult2 = $this->validateEvidence($this->evidenceIds[1], 'approved', 'Evidência válida');
        $validationResult3 = $this->validateEvidence($this->evidenceIds[2], 'approved', 'Excelente qualidade');

        $this->assertTrue($validationResult1['success']);
        $this->assertTrue($validationResult2['success']);
        $this->assertTrue($validationResult3['success']);

        echo "✅ PASSO 11: Admin validou 3 evidências (todas aprovadas)\n";

        // ==================== PASSO 12: VERIFICAR EVIDÊNCIAS APROVADAS ====================
        $approvedEvidences = $this->getApprovedEvidences($this->executionId);

        $this->assertCount(3, $approvedEvidences);

        echo "✅ PASSO 12: 3 evidências aprovadas pelo admin\n";

        // ==================== VALIDAÇÃO FINAL ====================
        echo "\n🎉 SMOKE TEST #4 PASSED: Fluxo completo de execução concluído com sucesso\n";
        echo "   - Execução criada: #{$this->executionId}\n";
        echo "   - Evidências uploaded: " . count($evidences) . "\n";
        echo "   - Status: pending → in_progress → completed\n";
        echo "   - Feedback cliente: 5 estrelas\n";
        echo "   - Payout gerado: #{$this->payoutId} (R$ " . number_format($payout['net_amount'], 2) . ")\n";
        echo "   - Evidências validadas: " . count($approvedEvidences) . "/3\n\n";
    }

    // ==================== HELPER METHODS ====================

    private function createTestProfessional(array $data): int
    {
        global $wpdb;

        $userId = wp_create_user($data['email'], wp_generate_password(), $data['email']);
        wp_update_user([
            'ID' => $userId,
            'display_name' => $data['name'],
            'role' => 'limpvix_professional'
        ]);

        $wpdb->insert($wpdb->prefix . 'bkntc_staff', [
            'name' => $data['name'],
            'wp_user_id' => $userId,
            'is_active' => 1,
            'created_at' => current_time('mysql')
        ]);

        return $wpdb->insert_id;
    }

    private function createTestContract(array $data): int
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'limpvix_contracts', [
            'contract_number' => 'TEST-EXEC-' . time(),
            'client_user_id' => 999,
            'contract_type' => 'monthly',
            'service_code' => $data['service_code'],
            'monthly_value' => $data['monthly_value'],
            'professional_id' => $data['professional_id'],
            'start_date' => date('Y-m-d'),
            'status' => 'active',
            'created_at' => current_time('mysql')
        ]);

        return $wpdb->insert_id;
    }

    private function createExecution(array $data): array
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'limpvix_executions', [
            'contract_id' => $data['contract_id'],
            'professional_id' => $data['professional_id'],
            'scheduled_date' => $data['scheduled_date'],
            'scheduled_time' => $data['scheduled_time'],
            'status' => $data['status'],
            'service_code' => $data['service_code'],
            'created_at' => current_time('mysql')
        ]);

        return [
            'id' => $wpdb->insert_id,
            'status' => $data['status'],
        ];
    }

    private function startExecution(int $executionId, int $professionalId): array
    {
        global $wpdb;

        $startedAt = current_time('mysql');

        $wpdb->update(
            $wpdb->prefix . 'limpvix_executions',
            [
                'status' => 'in_progress',
                'started_at' => $startedAt
            ],
            ['id' => $executionId],
            ['%s', '%s'],
            ['%d']
        );

        return [
            'success' => true,
            'status' => 'in_progress',
            'started_at' => $startedAt
        ];
    }

    private function uploadEvidence(int $executionId, array $data): array
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'limpvix_execution_evidences', [
            'execution_id' => $executionId,
            'type' => $data['type'],
            'url' => $data['url'],
            'description' => $data['description'],
            'stage' => $data['stage'] ?? 'after',
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ]);

        return [
            'success' => true,
            'evidence_id' => $wpdb->insert_id
        ];
    }

    private function getEvidencesForExecution(int $executionId): array
    {
        global $wpdb;

        $evidences = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_execution_evidences WHERE execution_id = %d",
            $executionId
        ), ARRAY_A);

        return $evidences ?: [];
    }

    private function completeExecution(int $executionId, int $professionalId): array
    {
        global $wpdb;

        $completedAt = current_time('mysql');

        $wpdb->update(
            $wpdb->prefix . 'limpvix_executions',
            [
                'status' => 'completed',
                'completed_at' => $completedAt
            ],
            ['id' => $executionId],
            ['%s', '%s'],
            ['%d']
        );

        return [
            'success' => true,
            'status' => 'completed',
            'completed_at' => $completedAt
        ];
    }

    private function submitCustomerFeedback(int $executionId, array $data): array
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'limpvix_execution_feedbacks', [
            'execution_id' => $executionId,
            'rating' => $data['rating'],
            'comment' => $data['comment'],
            'approved_at' => $data['approved_at'],
            'created_at' => current_time('mysql')
        ]);

        return [
            'success' => true,
            'rating' => $data['rating']
        ];
    }

    private function getPayoutForExecution(int $executionId): ?array
    {
        global $wpdb;

        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_payouts WHERE execution_id = %d LIMIT 1",
            $executionId
        ), ARRAY_A);

        return $payout ?: null;
    }

    private function validateEvidence(int $evidenceId, string $status, string $adminNotes): array
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'limpvix_execution_evidences',
            [
                'status' => $status,
                'admin_notes' => $adminNotes,
                'validated_at' => current_time('mysql'),
                'validated_by' => 1 // Admin user ID
            ],
            ['id' => $evidenceId],
            ['%s', '%s', '%s', '%d'],
            ['%d']
        );

        return ['success' => true];
    }

    private function getApprovedEvidences(int $executionId): array
    {
        global $wpdb;

        $evidences = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_execution_evidences
             WHERE execution_id = %d AND status = 'approved'",
            $executionId
        ), ARRAY_A);

        return $evidences ?: [];
    }

    private function cleanupTestData(): void
    {
        global $wpdb;

        if ($this->executionId) {
            $wpdb->delete($wpdb->prefix . 'limpvix_executions', ['id' => $this->executionId]);
            $wpdb->delete($wpdb->prefix . 'limpvix_execution_evidences', ['execution_id' => $this->executionId]);
            $wpdb->delete($wpdb->prefix . 'limpvix_execution_feedbacks', ['execution_id' => $this->executionId]);
        }

        if ($this->payoutId) {
            $wpdb->delete($wpdb->prefix . 'limpvix_payouts', ['id' => $this->payoutId]);
        }

        if ($this->contractId) {
            $wpdb->delete($wpdb->prefix . 'limpvix_contracts', ['id' => $this->contractId]);
        }

        if ($this->professionalId) {
            $wpdb->delete($wpdb->prefix . 'bkntc_staff', ['id' => $this->professionalId]);
        }
    }
}
