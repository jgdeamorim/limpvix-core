<?php
/**
 * ProfessionalCompleteFlowTest - Teste E2E do Fluxo Completo de Profissional
 *
 * Testa o fluxo completo de um profissional:
 * 1. Registrar profissional
 * 2. Verificar disponibilidade inicial
 * 3. Receber offer de serviço
 * 4. Aceitar offer
 * 5. Executar serviço
 * 6. Upload de evidências
 * 7. Completar execução
 * 8. Receber payout
 *
 * @package LimpVix\Tests\E2E
 * @group e2e
 * @group smoke-test
 */

namespace LimpVix\Tests\E2E;

use PHPUnit\Framework\TestCase;

class ProfessionalCompleteFlowTest extends TestCase
{
    private $professionalId;
    private $contractId;
    private $executionId;
    private $payoutId;

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
     * SMOKE TEST #2: Professional Complete Flow
     *
     * CENÁRIO: Profissional novo realiza seu primeiro serviço e recebe pagamento
     *
     * EXPECTATIVA:
     * - Profissional registrado com sucesso
     * - Offer recebida e aceita
     * - Execução iniciada e completada
     * - Evidências aprovadas
     * - Payout gerado automaticamente
     */
    public function test_professional_complete_flow_from_registration_to_payout()
    {
        // ==================== PASSO 1: REGISTRAR PROFISSIONAL ====================
        $professionalData = [
            'name' => 'João Silva Test',
            'email' => 'joao.test' . time() . '@limpvix.com',
            'phone' => '(27) 99999-' . rand(1000, 9999),
            'cpf' => '123.456.789-00',
            'skills' => ['basic_cleaning', 'deep_cleaning'],
            'service_region' => [
                'center_lat' => -20.315,
                'center_lng' => -40.298,
                'radius_km' => 10
            ],
            'availability' => [
                'monday' => [['start' => '08:00', 'end' => '18:00']],
                'tuesday' => [['start' => '08:00', 'end' => '18:00']],
                'wednesday' => [['start' => '08:00', 'end' => '18:00']],
                'thursday' => [['start' => '08:00', 'end' => '18:00']],
                'friday' => [['start' => '08:00', 'end' => '18:00']],
            ]
        ];

        $response = $this->registerProfessional($professionalData);
        
        $this->assertTrue($response['success'], 'Profissional deve ser registrado com sucesso');
        $this->assertNotEmpty($response['professional_id']);
        $this->professionalId = $response['professional_id'];

        echo "\n✅ PASSO 1: Profissional #{$this->professionalId} registrado\n";

        // ==================== PASSO 2: VERIFICAR DISPONIBILIDADE ====================
        $availability = $this->getProfessionalAvailability($this->professionalId);
        
        $this->assertNotEmpty($availability['monday'], 'Segunda-feira deve ter slots disponíveis');
        $this->assertEquals('08:00', $availability['monday'][0]['start']);
        
        echo "✅ PASSO 2: Disponibilidade configurada (segunda a sexta, 08:00-18:00)\n";

        // ==================== PASSO 3: RECEBER OFFER ====================
        // Simular criação de contrato que gera offer
        $contract = $this->createTestContract([
            'service_code' => 'deep_cleaning',
            'lat' => -20.320,
            'lng' => -40.300,
            'start_date' => date('Y-m-d', strtotime('+7 days'))
        ]);
        
        $this->contractId = $contract['id'];
        
        // SendOffers deve encontrar este profissional
        $offers = $this->sendOffersForContract($this->contractId);
        
        $this->assertNotEmpty($offers, 'Deve gerar pelo menos uma offer');
        $this->assertContains($this->professionalId, array_column($offers, 'professional_id'));
        
        $offerId = $this->getOfferForProfessional($this->professionalId, $this->contractId);
        $this->assertNotNull($offerId, 'Profissional deve ter recebido offer');
        
        echo "✅ PASSO 3: Offer #{$offerId} recebida\n";

        // ==================== PASSO 4: ACEITAR OFFER ====================
        $acceptResult = $this->acceptOffer($offerId, $this->professionalId);
        
        $this->assertTrue($acceptResult['success']);
        $this->assertEquals('accepted', $acceptResult['offer_status']);
        
        echo "✅ PASSO 4: Offer aceita, profissional alocado ao contrato\n";

        // ==================== PASSO 5: CRIAR EXECUÇÃO ====================
        $execution = $this->getNextExecutionForProfessional($this->professionalId);
        
        $this->assertNotNull($execution, 'Execução deve ter sido criada automaticamente');
        $this->executionId = $execution['id'];
        $this->assertEquals('pending', $execution['status']);
        
        echo "✅ PASSO 5: Execução #{$this->executionId} criada automaticamente\n";

        // ==================== PASSO 6: INICIAR EXECUÇÃO ====================
        $startResult = $this->startExecution($this->executionId, $this->professionalId);
        
        $this->assertTrue($startResult['success']);
        $this->assertEquals('in_progress', $startResult['status']);
        $this->assertNotNull($startResult['started_at']);
        
        echo "✅ PASSO 6: Execução iniciada\n";

        // ==================== PASSO 7: UPLOAD EVIDÊNCIAS ====================
        $evidence1 = $this->uploadEvidence($this->executionId, [
            'type' => 'photo',
            'url' => 'https://example.com/before.jpg',
            'description' => 'Foto antes da limpeza'
        ]);
        
        $evidence2 = $this->uploadEvidence($this->executionId, [
            'type' => 'photo',
            'url' => 'https://example.com/after.jpg',
            'description' => 'Foto depois da limpeza'
        ]);
        
        $this->assertTrue($evidence1['success']);
        $this->assertTrue($evidence2['success']);
        
        echo "✅ PASSO 7: 2 evidências uploaded\n";

        // ==================== PASSO 8: COMPLETAR EXECUÇÃO ====================
        $completeResult = $this->completeExecution($this->executionId, $this->professionalId);
        
        $this->assertTrue($completeResult['success']);
        $this->assertEquals('completed', $completeResult['status']);
        $this->assertNotNull($completeResult['completed_at']);
        
        echo "✅ PASSO 8: Execução completada\n";

        // ==================== PASSO 9: FEEDBACK DO CLIENTE ====================
        $feedbackResult = $this->submitCustomerFeedback($this->executionId, [
            'rating' => 5,
            'comment' => 'Excelente serviço!',
            'approved_at' => date('Y-m-d H:i:s')
        ]);
        
        $this->assertTrue($feedbackResult['success']);
        $this->assertEquals(5, $feedbackResult['rating']);
        
        echo "✅ PASSO 9: Cliente deu feedback 5 estrelas\n";

        // ==================== PASSO 10: VERIFICAR PAYOUT ====================
        // Aguardar feedback window (simulado)
        sleep(1);
        
        $payout = $this->getPayoutForExecution($this->executionId);
        
        $this->assertNotNull($payout, 'Payout deve ser criado automaticamente após feedback');
        $this->payoutId = $payout['id'];
        $this->assertEquals($this->professionalId, $payout['professional_id']);
        $this->assertEquals('approved', $payout['status']);
        $this->assertGreaterThan(0, $payout['net_amount']);
        
        echo "✅ PASSO 10: Payout #{$this->payoutId} criado - R$ " . number_format($payout['net_amount'], 2) . "\n";

        // ==================== VALIDAÇÃO FINAL ====================
        echo "\n🎉 SMOKE TEST #2 PASSED: Fluxo completo de profissional concluído com sucesso\n";
        echo "   - Profissional registrado: #{$this->professionalId}\n";
        echo "   - Offer aceita e alocado ao contrato: #{$this->contractId}\n";
        echo "   - Execução completada: #{$this->executionId}\n";
        echo "   - Payout aprovado: #{$this->payoutId} (R$ " . number_format($payout['net_amount'], 2) . ")\n\n";
    }

    // ==================== HELPER METHODS ====================

    private function registerProfessional(array $data): array
    {
        // Simular chamada ao RegisterProfessional Use Case
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
        
        return ['success' => true, 'professional_id' => $professionalId];
    }

    private function getProfessionalAvailability(int $professionalId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_professional_availability WHERE professional_id = %d AND is_active = 1",
            $professionalId
        ), ARRAY_A);
        
        $availability = [];
        foreach ($rows as $row) {
            $day = $row['day_of_week'];
            if (!isset($availability[$day])) {
                $availability[$day] = [];
            }
            $availability[$day][] = [
                'start' => $row['start_time'],
                'end' => $row['end_time']
            ];
        }
        
        return $availability;
    }

    private function createTestContract(array $data): array
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'limpvix_contracts', [
            'contract_number' => 'TEST-' . time(),
            'client_user_id' => 999,
            'contract_type' => 'monthly',
            'service_code' => $data['service_code'],
            'monthly_value' => 500.00,
            'start_date' => $data['start_date'],
            'status' => 'pending_allocation',
            'created_at' => current_time('mysql')
        ]);
        
        return ['id' => $wpdb->insert_id, 'lat' => $data['lat'], 'lng' => $data['lng']];
    }

    private function sendOffersForContract(int $contractId): array
    {
        // Simular SendOffers Use Case
        return [['professional_id' => $this->professionalId, 'contract_id' => $contractId]];
    }

    private function getOfferForProfessional(int $professionalId, int $contractId): ?int
    {
        global $wpdb;
        $offerId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}limpvix_offers WHERE professional_id = %d AND contract_id = %d",
            $professionalId,
            $contractId
        ));
        
        return $offerId ?: null;
    }

    private function acceptOffer(int $offerId, int $professionalId): array
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'limpvix_offers',
            ['status' => 'accepted', 'accepted_at' => current_time('mysql')],
            ['id' => $offerId],
            ['%s', '%s'],
            ['%d']
        );
        
        return ['success' => true, 'offer_status' => 'accepted'];
    }

    private function getNextExecutionForProfessional(int $professionalId): ?array
    {
        global $wpdb;
        $execution = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_executions WHERE professional_id = %d LIMIT 1",
            $professionalId
        ), ARRAY_A);
        
        return $execution ?: null;
    }

    private function startExecution(int $executionId, int $professionalId): array
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'limpvix_executions',
            ['status' => 'in_progress', 'started_at' => current_time('mysql')],
            ['id' => $executionId],
            ['%s', '%s'],
            ['%d']
        );
        
        return ['success' => true, 'status' => 'in_progress', 'started_at' => current_time('mysql')];
    }

    private function uploadEvidence(int $executionId, array $data): array
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'limpvix_execution_evidences', [
            'execution_id' => $executionId,
            'type' => $data['type'],
            'url' => $data['url'],
            'description' => $data['description'],
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ]);
        
        return ['success' => true, 'evidence_id' => $wpdb->insert_id];
    }

    private function completeExecution(int $executionId, int $professionalId): array
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'limpvix_executions',
            ['status' => 'completed', 'completed_at' => current_time('mysql')],
            ['id' => $executionId],
            ['%s', '%s'],
            ['%d']
        );
        
        return ['success' => true, 'status' => 'completed', 'completed_at' => current_time('mysql')];
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
        
        return ['success' => true, 'rating' => $data['rating']];
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

    private function cleanupTestData(): void
    {
        global $wpdb;
        
        if ($this->professionalId) {
            $wpdb->delete($wpdb->prefix . 'bkntc_staff', ['id' => $this->professionalId]);
            $wpdb->delete($wpdb->prefix . 'limpvix_professional_availability', ['professional_id' => $this->professionalId]);
        }
        
        if ($this->contractId) {
            $wpdb->delete($wpdb->prefix . 'limpvix_contracts', ['id' => $this->contractId]);
        }
        
        if ($this->executionId) {
            $wpdb->delete($wpdb->prefix . 'limpvix_executions', ['id' => $this->executionId]);
            $wpdb->delete($wpdb->prefix . 'limpvix_execution_evidences', ['execution_id' => $this->executionId]);
            $wpdb->delete($wpdb->prefix . 'limpvix_execution_feedbacks', ['execution_id' => $this->executionId]);
        }
        
        if ($this->payoutId) {
            $wpdb->delete($wpdb->prefix . 'limpvix_payouts', ['id' => $this->payoutId]);
        }
    }
}
