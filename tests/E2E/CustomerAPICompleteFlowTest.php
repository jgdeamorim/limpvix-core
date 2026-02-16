<?php
/**
 * CustomerAPICompleteFlowTest - Teste E2E da API de Clientes
 *
 * Testa o fluxo completo da REST API de clientes:
 * 1. Registrar novo cliente via API
 * 2. Login e obter token JWT
 * 3. Buscar perfil do cliente
 * 4. Atualizar dados do perfil
 * 5. Criar briefing via API
 * 6. Listar contratos do cliente
 * 7. Verificar histórico de execuções
 * 8. Logout
 *
 * @package LimpVix\Tests\E2E
 * @group e2e
 * @group smoke-test
 * @group api
 */

namespace LimpVix\Tests\E2E;

use PHPUnit\Framework\TestCase;

class CustomerAPICompleteFlowTest extends TestCase
{
    private $baseUrl;
    private $clientUserId;
    private $authToken;
    private $briefingId;
    private $contractId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = rest_url('limpvix/v1');
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * @test
     * SMOKE TEST #5: Customer REST API Complete Flow
     *
     * CENÁRIO: Cliente se registra, faz login, cria briefing, consulta contratos via API
     *
     * EXPECTATIVA:
     * - Cliente registrado via POST /customers/register
     * - Login bem-sucedido via POST /auth/login
     * - Token JWT válido retornado
     * - Perfil acessível via GET /customers/me
     * - Atualização de perfil via PUT /customers/me
     * - Briefing criado via POST /briefings
     * - Contratos listados via GET /customers/me/contracts
     * - Execuções listadas via GET /customers/me/executions
     */
    public function test_customer_api_complete_flow()
    {
        // ==================== PASSO 1: REGISTRAR CLIENTE VIA API ====================
        $registerPayload = [
            'email' => 'api.cliente.test' . time() . '@limpvix.com',
            'password' => 'SenhaSegura@123',
            'first_name' => 'Ana',
            'last_name' => 'Costa Test',
            'phone' => '(27) 99555-' . rand(1000, 9999),
        ];

        $registerResponse = $this->makeAPIRequest('POST', '/customers/register', $registerPayload);

        $this->assertEquals(201, $registerResponse['status'], 'Register deve retornar 201 Created');
        $this->assertTrue($registerResponse['body']['success'] ?? false);
        $this->assertNotEmpty($registerResponse['body']['user_id']);

        $this->clientUserId = $registerResponse['body']['user_id'];

        echo "\n✅ PASSO 1: Cliente registrado via API - ID: {$this->clientUserId}\n";

        // ==================== PASSO 2: LOGIN VIA API ====================
        $loginPayload = [
            'username' => $registerPayload['email'],
            'password' => $registerPayload['password'],
        ];

        $loginResponse = $this->makeAPIRequest('POST', '/auth/login', $loginPayload);

        $this->assertEquals(200, $loginResponse['status']);
        $this->assertNotEmpty($loginResponse['body']['token'] ?? null, 'Login deve retornar token JWT');
        $this->assertNotEmpty($loginResponse['body']['user']['ID']);
        $this->assertEquals($registerPayload['email'], $loginResponse['body']['user']['user_email']);

        $this->authToken = $loginResponse['body']['token'];

        echo "✅ PASSO 2: Login bem-sucedido, token JWT obtido (expires: " . ($loginResponse['body']['expires'] ?? 'N/A') . ")\n";

        // ==================== PASSO 3: BUSCAR PERFIL DO CLIENTE ====================
        $profileResponse = $this->makeAPIRequest('GET', '/customers/me', [], $this->authToken);

        $this->assertEquals(200, $profileResponse['status']);
        $this->assertEquals($this->clientUserId, $profileResponse['body']['id']);
        $this->assertEquals($registerPayload['first_name'], $profileResponse['body']['first_name']);
        $this->assertEquals($registerPayload['last_name'], $profileResponse['body']['last_name']);
        $this->assertEquals($registerPayload['email'], $profileResponse['body']['email']);

        echo "✅ PASSO 3: Perfil do cliente carregado - {$profileResponse['body']['first_name']} {$profileResponse['body']['last_name']}\n";

        // ==================== PASSO 4: ATUALIZAR PERFIL ====================
        $updatePayload = [
            'phone' => '(27) 99666-7788',
            'address' => [
                'street' => 'Avenida Nossa Senhora da Penha',
                'number' => '456',
                'neighborhood' => 'Praia do Canto',
                'city' => 'Vitória',
                'state' => 'ES',
                'zip_code' => '29055-131',
            ]
        ];

        $updateResponse = $this->makeAPIRequest('PUT', '/customers/me', $updatePayload, $this->authToken);

        $this->assertEquals(200, $updateResponse['status']);
        $this->assertTrue($updateResponse['body']['success'] ?? false);

        echo "✅ PASSO 4: Perfil atualizado (telefone, endereço)\n";

        // ==================== PASSO 5: CRIAR BRIEFING VIA API ====================
        $briefingPayload = [
            'service_code' => 'basic_cleaning',
            'frequency' => 'weekly',
            'preferred_days' => ['tuesday', 'friday'],
            'preferred_time' => '10:00',
            'address' => $updatePayload['address'],
            'additional_info' => [
                'has_pets' => false,
                'property_size' => '80sqm',
                'bedrooms' => 2,
                'bathrooms' => 1,
            ]
        ];

        $briefingResponse = $this->makeAPIRequest('POST', '/briefings', $briefingPayload, $this->authToken);

        $this->assertEquals(201, $briefingResponse['status']);
        $this->assertNotEmpty($briefingResponse['body']['briefing_uuid']);

        $this->briefingId = $briefingResponse['body']['briefing_uuid'];

        echo "✅ PASSO 5: Briefing criado via API - UUID: {$this->briefingId}\n";

        // ==================== PASSO 6: SIMULAR CONTRATO GERADO ====================
        // Na prática, contrato seria criado por admin a partir do briefing
        // Aqui simulamos criação para testar endpoint de listagem
        $this->contractId = $this->createTestContract([
            'client_user_id' => $this->clientUserId,
            'briefing_uuid' => $this->briefingId,
            'monthly_value' => 450.00,
            'status' => 'active',
        ]);

        $this->assertGreaterThan(0, $this->contractId);

        echo "✅ PASSO 6: Contrato #{$this->contractId} criado (simulado pelo admin)\n";

        // ==================== PASSO 7: LISTAR CONTRATOS DO CLIENTE VIA API ====================
        $contractsResponse = $this->makeAPIRequest('GET', '/customers/me/contracts', [], $this->authToken);

        $this->assertEquals(200, $contractsResponse['status']);
        $this->assertIsArray($contractsResponse['body']['data'] ?? null);
        $this->assertGreaterThanOrEqual(1, count($contractsResponse['body']['data']));

        $foundContract = false;
        foreach ($contractsResponse['body']['data'] as $contract) {
            if ($contract['id'] == $this->contractId) {
                $foundContract = true;
                $this->assertEquals('active', $contract['status']);
                $this->assertEquals(450.00, (float) $contract['monthly_value']);
            }
        }

        $this->assertTrue($foundContract, 'Contrato criado deve aparecer na listagem da API');

        echo "✅ PASSO 7: " . count($contractsResponse['body']['data']) . " contrato(s) listado(s) via API\n";

        // ==================== PASSO 8: SIMULAR EXECUÇÃO ====================
        $executionId = $this->createTestExecution([
            'contract_id' => $this->contractId,
            'professional_id' => 1,
            'status' => 'completed',
        ]);

        echo "✅ PASSO 8: Execução #{$executionId} criada (simulada)\n";

        // ==================== PASSO 9: LISTAR EXECUÇÕES DO CLIENTE VIA API ====================
        $executionsResponse = $this->makeAPIRequest('GET', '/customers/me/executions', [], $this->authToken);

        $this->assertEquals(200, $executionsResponse['status']);
        $this->assertIsArray($executionsResponse['body']['data'] ?? null);
        $this->assertGreaterThanOrEqual(1, count($executionsResponse['body']['data']));

        echo "✅ PASSO 9: " . count($executionsResponse['body']['data']) . " execução(ões) listada(s) via API\n";

        // ==================== PASSO 10: BUSCAR BRIEFING ESPECÍFICO ====================
        $getBriefingResponse = $this->makeAPIRequest('GET', '/briefings/' . $this->briefingId, [], $this->authToken);

        $this->assertEquals(200, $getBriefingResponse['status']);
        $this->assertEquals($this->briefingId, $getBriefingResponse['body']['uuid']);
        $this->assertEquals('basic_cleaning', $getBriefingResponse['body']['service_code']);

        echo "✅ PASSO 10: Briefing {$this->briefingId} recuperado via API\n";

        // ==================== PASSO 11: ATUALIZAR STATUS DO BRIEFING ====================
        $updateBriefingPayload = [
            'status' => 'converted',
        ];

        $updateBriefingResponse = $this->makeAPIRequest(
            'PUT',
            '/briefings/' . $this->briefingId,
            $updateBriefingPayload,
            $this->authToken
        );

        $this->assertEquals(200, $updateBriefingResponse['status']);

        echo "✅ PASSO 11: Briefing status atualizado para 'converted'\n";

        // ==================== PASSO 12: LOGOUT ====================
        $logoutResponse = $this->makeAPIRequest('POST', '/auth/logout', [], $this->authToken);

        $this->assertEquals(200, $logoutResponse['status']);
        $this->assertTrue($logoutResponse['body']['success'] ?? false);

        echo "✅ PASSO 12: Logout realizado com sucesso\n";

        // ==================== PASSO 13: VERIFICAR TOKEN INVÁLIDO APÓS LOGOUT ====================
        $unauthorizedResponse = $this->makeAPIRequest('GET', '/customers/me', [], $this->authToken);

        // Após logout, token deve ser inválido (401 ou 403)
        $this->assertContains(
            $unauthorizedResponse['status'],
            [401, 403],
            'Após logout, token deve ser invalidado'
        );

        echo "✅ PASSO 13: Token invalidado após logout (status: {$unauthorizedResponse['status']})\n";

        // ==================== VALIDAÇÃO FINAL ====================
        echo "\n🎉 SMOKE TEST #5 PASSED: Fluxo completo da Customer REST API concluído com sucesso\n";
        echo "   - Cliente registrado: #{$this->clientUserId}\n";
        echo "   - Login/Logout funcionando\n";
        echo "   - JWT Token validado\n";
        echo "   - Perfil acessível e atualizável\n";
        echo "   - Briefing criado: {$this->briefingId}\n";
        echo "   - Contratos listados: " . count($contractsResponse['body']['data']) . "\n";
        echo "   - Execuções listadas: " . count($executionsResponse['body']['data']) . "\n\n";
    }

    // ==================== HELPER METHODS ====================

    /**
     * Make API request
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint (e.g., '/customers/register')
     * @param array $payload Request body
     * @param string|null $token JWT auth token
     * @return array ['status' => int, 'body' => array]
     */
    private function makeAPIRequest(
        string $method,
        string $endpoint,
        array $payload = [],
        ?string $token = null
    ): array {
        $url = $this->baseUrl . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ];

        // Add JWT token if provided
        if ($token) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        // Add body for POST/PUT
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($payload)) {
            $args['body'] = json_encode($payload);
        }

        // Add query params for GET
        if ($method === 'GET' && !empty($payload)) {
            $url = add_query_arg($payload, $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new \RuntimeException('API request failed: ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $bodyJson = wp_remote_retrieve_body($response);
        $body = json_decode($bodyJson, true);

        return [
            'status' => $statusCode,
            'body' => $body ?? [],
            'raw' => $bodyJson,
        ];
    }

    private function createTestContract(array $data): int
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'limpvix_contracts', [
            'contract_number' => 'API-TEST-' . time(),
            'client_user_id' => $data['client_user_id'],
            'briefing_uuid' => $data['briefing_uuid'],
            'contract_type' => 'monthly',
            'service_code' => 'basic_cleaning',
            'monthly_value' => $data['monthly_value'],
            'start_date' => date('Y-m-d'),
            'status' => $data['status'],
            'created_at' => current_time('mysql')
        ]);

        return $wpdb->insert_id;
    }

    private function createTestExecution(array $data): int
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'limpvix_executions', [
            'contract_id' => $data['contract_id'],
            'professional_id' => $data['professional_id'],
            'scheduled_date' => date('Y-m-d'),
            'scheduled_time' => '10:00:00',
            'status' => $data['status'],
            'service_code' => 'basic_cleaning',
            'started_at' => current_time('mysql'),
            'completed_at' => current_time('mysql'),
            'created_at' => current_time('mysql')
        ]);

        return $wpdb->insert_id;
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

            // Delete related executions
            $wpdb->delete($wpdb->prefix . 'limpvix_executions', ['contract_id' => $this->contractId]);
        }
    }
}
