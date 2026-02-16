<?php
namespace LimpVix\Infrastructure\KYC;

defined('ABSPATH') || exit;

/**
 * PPID Mock Provider
 *
 * Provider simulado para desenvolvimento e testes sem depender da API real
 *
 * USAR APENAS EM DESENVOLVIMENTO
 * Em produção, use PPIDProvider (API real)
 */
final class PPIDMockProvider
{
    private string $email;
    private string $senha;
    private ?string $jwtToken = null;

    public function __construct()
    {
        $this->email = get_option('limpvix_ppid_email', '');
        $this->senha = get_option('limpvix_ppid_senha', '');

        if (empty($this->email) || empty($this->senha)) {
            throw new \RuntimeException(
                'PPID não configurado (Mock Mode)'
            );
        }
    }

    /**
     * Mock Login - Sempre retorna sucesso com token fake
     */
    public function login(): array
    {
        error_log('[PPID MOCK] Login simulado');
        
        // Simula delay de rede
        usleep(300000); // 300ms

        $this->jwtToken = 'mock_jwt_token_' . time();

        return [
            'token' => $this->jwtToken,
            'expiration' => (new \DateTime('+24 hours'))->format('Y-m-d\TH:i:s\Z'),
            'nome' => 'Usuário Mock',
            'saldo' => 1000, // Saldo simulado
        ];
    }

    /**
     * Mock OCR - Retorna dados simulados de documento
     */
    public function ocr(string $imagemBase64, ?string $mimeType = null): array
    {
        error_log('[PPID MOCK] OCR simulado');
        
        usleep(500000); // 500ms

        return [
            'sucesso' => true,
            'consultaId' => $this->generateUuid(),
            'resultado' => [
                'documentType' => 'CNH',
                'confidence' => 95,
                'fields' => [
                    'fullName' => 'JOÃO DA SILVA MOCK',
                    'cpf' => '123.456.789-00',
                    'rg' => '12.345.678-9',
                    'birthDate' => '1990-01-15',
                    'documentNumber' => '12345678900',
                    'issuedDate' => '2020-05-10',
                    'expirationDate' => '2030-05-10',
                    'category' => 'AB',
                    'mothersName' => 'MARIA DA SILVA',
                    'fathersName' => 'JOSÉ DA SILVA',
                ]
            ],
            'saldoRestante' => 999,
        ];
    }

    /**
     * Mock Liveness - Retorna score alto (aprovado)
     */
    public function liveness(string $selfieBase64): array
    {
        error_log('[PPID MOCK] Liveness simulado');
        
        usleep(600000); // 600ms

        return [
            'sucesso' => true,
            'liveness' => 92, // Score alto = aprovado
            'detalhes' => [
                'singleFaceDetected' => true,
                'photoOfPhotoDetected' => false,
                'maskDetected' => false,
                'lightingQuality' => 'good',
                'facePosition' => 'centered',
                'eyesOpen' => true,
            ],
            'saldoRestante' => 998,
        ];
    }

    /**
     * Mock Face Match - Retorna similaridade alta (aprovado)
     */
    public function faceMatch(string $documentoBase64, string $selfieBase64): array
    {
        error_log('[PPID MOCK] Face Match simulado');
        
        usleep(700000); // 700ms

        return [
            'sucesso' => true,
            'similaridade' => 94, // Alta similaridade = aprovado
            'detalhes' => [
                'facesMatched' => true,
                'confidenceLevel' => 'high',
                'ageConsistency' => true,
                'genderConsistency' => true,
            ],
            'saldoRestante' => 997,
        ];
    }

    /**
     * Mock Classification - Classifica como CNH
     */
    public function classification(string $imagemBase64): array
    {
        error_log('[PPID MOCK] Classification simulado');
        
        usleep(400000); // 400ms

        return [
            'sucesso' => true,
            'tipoDocumento' => 'CNH',
            'confianca' => 96,
            'detalhes' => [
                'category' => 'identification',
                'side' => 'front',
                'isComplete' => true,
                'quality' => 'good',
            ],
            'saldoRestante' => 996,
        ];
    }

    /**
     * Mock Test Connection
     */
    public static function testConnection(string $email, string $senha): array
    {
        error_log('[PPID MOCK] Test connection simulado');
        
        try {
            // Simula delay de rede
            usleep(300000);

            // Sempre retorna sucesso no mock
            return [
                'success' => true,
                'message' => '✅ Conexão simulada bem-sucedida (MOCK MODE)',
                'saldo' => 1000,
                'nome' => 'Usuário Mock Development',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'saldo' => 0,
                'nome' => '',
            ];
        }
    }

    /**
     * Gera UUID v4 simulado
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
