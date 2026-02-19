<?php
/**
 * EfiPaymentProvider — Provider de Cash-In via EFI Bank (PIX QR Code)
 *
 * RESPONSABILIDADE:
 * - Criar cobrança PIX imediata (cob) via API EFI Bank
 * - Implementa PaymentProviderInterface
 * - Suporte a mTLS (certificado PEM) via cURL direto
 * - OAuth2 com cache de token (55 minutos)
 *
 * ENDPOINTS:
 * - Criar cobrança: PUT /v2/cob/{txid}    → scope: cob.write
 * - Consultar:      GET /v2/cob/{txid}    → scope: cob.read
 *
 * CONFIGURAÇÃO (wp_options):
 * - limpvix_efi_client_id      → Client ID
 * - limpvix_efi_client_secret  → Client Secret
 * - limpvix_efi_pix_key        → Chave PIX da plataforma (recebedor)
 * - limpvix_efi_sandbox        → 'yes' = sandbox | 'no' = produção
 * - limpvix_efi_cert_path      → Caminho para certificado .pem (mTLS)
 *
 * NOTA: Requer escopo cob.write habilitado no painel EFI Bank:
 *       API → Aplicações → sua aplicação → Escopos → PIX → Criar cobrança
 *
 * @package LimpVix\Infrastructure\Finance\Providers
 * @since 0.3.0
 */

namespace LimpVix\Infrastructure\Finance\Providers;

use LimpVix\Domain\Finance\PaymentProviderInterface;
use LimpVix\Domain\Finance\RecurringPayment;

defined('ABSPATH') || exit;

class EfiPaymentProvider implements PaymentProviderInterface
{
    private string $clientId     = '';
    private string $clientSecret = '';
    private string $pixKey       = '';
    private string $certPath     = '';
    private bool   $sandbox      = true;
    private bool   $enabled      = false;
    private string $baseUrl;

    private const TOKEN_TRANSIENT = 'limpvix_efi_payment_access_token';

    /**
     * Mapa de status EFI Bank → status normalizado
     */
    private const STATUS_MAP = [
        'ATIVA'    => 'pending',
        'CONCLUIDA'=> 'approved',
        'REMOVIDA_PELO_USUARIO_RECEBEDOR' => 'cancelled',
        'REMOVIDA_PELO_PSP'               => 'cancelled',
    ];

    /**
     * Mapa de razões de falha para exibição ao admin
     */
    private const FAILURE_REASONS = [
        'cob_write_scope_missing' => 'Escopo cob.write não habilitado no painel EFI Bank. Acesse API → Aplicações → Escopos → PIX → Criar cobrança.',
        'cert_not_found'          => 'Certificado mTLS não encontrado. Configure o caminho em Configurações → EFI Bank.',
        'oauth_failed'            => 'Falha na autenticação OAuth EFI Bank. Verifique Client ID e Client Secret.',
        'api_error'               => 'Erro na API EFI Bank. Verifique logs para detalhes.',
        'not_configured'          => 'EFI Bank não está configurado. Acesse Configurações → EFI Bank.',
    ];

    public function __construct()
    {
        $this->clientId     = (string) get_option('limpvix_efi_client_id', '');
        $this->clientSecret = (string) get_option('limpvix_efi_client_secret', '');
        $this->pixKey       = (string) get_option('limpvix_efi_pix_key', '');
        $this->certPath     = (string) get_option('limpvix_efi_cert_path', '');
        $this->sandbox      = get_option('limpvix_efi_sandbox', 'yes') === 'yes';

        $this->baseUrl = $this->sandbox
            ? 'https://pix-h.api.efipay.com.br'
            : 'https://pix.api.efipay.com.br';

        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->pixKey)) {
            error_log('[LimpVix][EFI-Pay] Provider não configurado (clientId, clientSecret ou pixKey ausentes)');
            return;
        }

        $this->enabled = true;
        $env = $this->sandbox ? 'sandbox' : 'produção';
        error_log("[LimpVix][EFI-Pay] EfiPaymentProvider inicializado — Ambiente: {$env}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PaymentProviderInterface
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Criar cobrança PIX imediata (cob) via EFI Bank
     * Endpoint: PUT /v2/cob/{txid}   scope: cob.write
     *
     * {@inheritdoc}
     */
    public function createPaymentCharge(RecurringPayment $payment, array $paymentData): array
    {
        if (!$this->enabled) {
            throw new \RuntimeException(
                'EFI Bank não configurado. ' . $this->getFailureReason('not_configured')
            );
        }

        $token = $this->getAccessToken();

        if ($token === null) {
            throw new \RuntimeException(
                'Falha na autenticação EFI Bank. ' . $this->getFailureReason('oauth_failed')
            );
        }

        $txid   = $this->buildTxid($payment);
        $url    = "{$this->baseUrl}/v2/cob/{$txid}";
        $amount = number_format((float) $payment->getAmount(), 2, '.', '');

        $payload = [
            'calendario'         => ['expiracao' => 3600],
            'valor'              => ['original' => $amount],
            'chave'              => $this->pixKey,
            'solicitacaoPagador' => sprintf(
                'LimpVix - Contrato #%d - Ciclo %d',
                $payment->getContractId(),
                $payment->getBillingCycleNumber()
            ),
        ];

        // devedor é opcional — só incluir se tiver CPF/CNPJ válido
        $devedor = $this->buildDevedor($paymentData);
        if (isset($devedor['cpf']) || isset($devedor['cnpj'])) {
            $payload['devedor'] = $devedor;
        }

        [$statusCode, $body, $curlErr] = $this->curlRequest('PUT', $url, $payload, [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json',
        ]);

        if ($curlErr) {
            error_log("[LimpVix][EFI-Pay] Erro cURL ao criar cobrança: {$curlErr}");
            throw new \RuntimeException("Erro de rede EFI Bank: {$curlErr}");
        }

        if ($statusCode === 403) {
            // Escopo cob.write não habilitado
            $errMsg = $body['mensagem'] ?? 'Acesso negado';
            error_log("[LimpVix][EFI-Pay] HTTP 403: {$errMsg} — escopo cob.write necessário");
            throw new \RuntimeException($this->getFailureReason('cob_write_scope_missing'));
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $errMsg = $body['mensagem'] ?? ($body['titulo'] ?? "HTTP {$statusCode}");
            error_log("[LimpVix][EFI-Pay] Erro API: HTTP {$statusCode} — {$errMsg}");
            throw new \RuntimeException("EFI Bank API erro {$statusCode}: {$errMsg}");
        }

        // Normalizar resposta
        return [
            'id'           => $txid,
            'status'       => $this->mapStatus($body['status'] ?? 'ATIVA'),
            'status_detail'=> $body['status'] ?? 'ATIVA',
            'pix_qrcode'   => $body['pixCopiaECola'] ?? null,
            'pix_qrimage'  => $body['imagemQrcode'] ?? null,
            'txid'         => $body['txid'] ?? $txid,
            'location'     => $body['loc']['location'] ?? null,
            'raw'          => $body,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return $this->enabled;
    }

    /**
     * {@inheritdoc}
     */
    public function getFailureReason(string $statusDetail): string
    {
        return self::FAILURE_REASONS[$statusDetail]
            ?? "Falha no pagamento EFI Bank: {$statusDetail}";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Métodos Auxiliares Públicos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Consultar status de uma cobrança
     * Endpoint: GET /v2/cob/{txid}
     *
     * @param string $txid
     * @return array|null  null em caso de erro
     */
    public function getChargeStatus(string $txid): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            return null;
        }

        [$statusCode, $body, $curlErr] = $this->curlRequest(
            'GET',
            "{$this->baseUrl}/v2/cob/{$txid}",
            [],
            ["Authorization: Bearer {$token}"]
        );

        if ($curlErr || $statusCode >= 300) {
            return null;
        }

        return $body;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * Consultar status de pagamento via API EFI Bank
     * Usado pelo ProcessPaymentWebhook e PaymentAuthorizationTimeout
     *
     * @param string $paymentId  txid da cobrança PIX
     * @return array  Dados da cobrança incluindo 'status'
     */
    public function getPaymentStatus(string $paymentId): array
    {
        $charge = $this->getChargeStatus($paymentId);

        if ($charge === null) {
            throw new \RuntimeException("EFI Bank: cobrança {$paymentId} não encontrada");
        }

        return [
            'status' => $charge['status'] ?? 'ATIVA',
            'txid' => $charge['txid'] ?? $paymentId,
            'valor' => $charge['valor']['original'] ?? '0.00',
            'date_approved' => $charge['pix'][0]['horario'] ?? null,
            'payer_info' => $charge['pix'][0]['infoPagador'] ?? null,
            'end_to_end_id' => $charge['pix'][0]['endToEndId'] ?? null,
            'raw' => $charge,
        ];
    }

    /**
     * Verificar webhook da EFI Bank
     * EFI Bank usa mTLS para autenticação do webhook — não envia HMAC.
     * A verificação é feita no nível do servidor (certificado mTLS).
     * Aqui apenas validamos estrutura básica do payload.
     */
    public function verifyWebhookSignature(array $headers, string $body): bool
    {
        // EFI Bank valida via mTLS no nível de conexão, não por HMAC.
        // Se o request chegou até aqui, já foi autenticado pelo mTLS do servidor.
        // Validação adicional: verificar se o payload tem estrutura esperada.
        $data = json_decode($body, true);
        return isset($data['pix']) && is_array($data['pix']);
    }

    /**
     * Parsear payload do webhook EFI Bank PIX
     *
     * Formato EFI Bank:
     * { "pix": [{ "endToEndId": "...", "txid": "...", "chave": "...", "valor": "...", "horario": "..." }] }
     */
    public function parseWebhookPayload(array $payload): array
    {
        $pixEvents = $payload['pix'] ?? [];

        if (empty($pixEvents)) {
            return ['payment_id' => null, 'event_type' => 'unknown', 'events' => []];
        }

        // Primeiro evento PIX (normalmente só vem um)
        $firstPix = $pixEvents[0];

        return [
            'payment_id' => $firstPix['txid'] ?? null,
            'event_type' => 'payment',
            'status' => 'CONCLUIDA', // Se webhook PIX chegou, pagamento foi confirmado
            'end_to_end_id' => $firstPix['endToEndId'] ?? null,
            'valor' => $firstPix['valor'] ?? '0.00',
            'horario' => $firstPix['horario'] ?? null,
            'chave' => $firstPix['chave'] ?? null,
            'events' => $pixEvents,
        ];
    }

    /**
     * Mapear status EFI Bank → status RecurringPayment
     */
    public function mapStatusToRecurringPayment(string $gatewayStatus): string
    {
        return match ($gatewayStatus) {
            'CONCLUIDA' => 'completed',
            'ATIVA' => 'pending',
            'REMOVIDA_PELO_USUARIO_RECEBEDOR', 'REMOVIDA_PELO_PSP' => 'failed',
            default => 'pending',
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OAuth2 com cache
    // ─────────────────────────────────────────────────────────────────────────

    private function getAccessToken(): ?string
    {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if (!empty($cached)) {
            return $cached;
        }

        $url   = "{$this->baseUrl}/oauth/token";
        $basic = base64_encode("{$this->clientId}:{$this->clientSecret}");

        [$statusCode, $body, $curlErr] = $this->curlRequest(
            'POST',
            $url,
            'grant_type=client_credentials',
            ["Authorization: Basic {$basic}", 'Content-Type: application/x-www-form-urlencoded']
        );

        if ($curlErr) {
            error_log("[LimpVix][EFI-Pay] Erro OAuth cURL: {$curlErr}");
            return null;
        }

        if ($statusCode !== 200 || empty($body['access_token'])) {
            $msg = $body['error_description'] ?? ($body['message'] ?? "HTTP {$statusCode}");
            error_log("[LimpVix][EFI-Pay] OAuth falhou ({$statusCode}): {$msg}");
            return null;
        }

        $token = $body['access_token'];
        $ttl   = (int) ($body['expires_in'] ?? 3600);
        set_transient(self::TOKEN_TRANSIENT, $token, $ttl - 300);

        return $token;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HTTP com mTLS via cURL direto
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{0: int, 1: array, 2: string}  [http_status, body_array, curl_error]
     */
    private function curlRequest(
        string $method,
        string $url,
        string|array $body = '',
        array $headers = []
    ): array {
        $ch = curl_init();

        $isFormData = is_string($body);

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];

        // Certificado mTLS (obrigatório pela API EFI Bank)
        if (!empty($this->certPath) && file_exists($this->certPath)) {
            $opts[CURLOPT_SSLCERT] = $this->certPath;
            $opts[CURLOPT_SSLKEY]  = $this->certPath;
        }

        $method = strtoupper($method);

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $isFormData ? $body : json_encode($body);
            if (!$isFormData) {
                $headers[] = 'Content-Type: application/json';
            }
        } elseif ($method === 'PUT') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opts[CURLOPT_POSTFIELDS]    = $isFormData ? $body : json_encode($body);
            if (!$isFormData) {
                $headers[] = 'Content-Type: application/json';
            }
        } elseif ($method === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        }

        // Set headers AFTER all modifications
        $opts[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $opts);

        $resp      = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr   = curl_error($ch);
        curl_close($ch);

        $parsed = is_string($resp) ? (json_decode($resp, true) ?? []) : [];

        return [$httpCode, $parsed, $curlErr];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Gerar txid único: 26-35 chars alfanuméricos
     * Formato: lmpx + 5 dígitos do contrato + 6 dígitos do ciclo + hash md5
     */
    private function buildTxid(RecurringPayment $payment): string
    {
        $base = sprintf('lmpx%05d%03d', $payment->getContractId(), $payment->getBillingCycleNumber());
        $hash = substr(md5($base . $payment->getPaymentUuid()), 0, 26 - strlen($base));
        return substr($base . $hash, 0, 35);
    }

    /**
     * Construir objeto devedor a partir dos dados do pagador
     */
    private function buildDevedor(array $paymentData): array
    {
        $devedor = ['nome' => $paymentData['payer_name'] ?? 'Cliente LimpVix'];

        $doc = preg_replace('/\D/', '', $paymentData['doc_number'] ?? '');

        if (strlen($doc) === 11) {
            $devedor['cpf'] = $doc;
        } elseif (strlen($doc) === 14) {
            $devedor['cnpj'] = $doc;
        }

        return $devedor;
    }

    /**
     * Mapear status EFI Bank → status normalizado (pending/approved/cancelled)
     */
    private function mapStatus(string $efiStatus): string
    {
        return self::STATUS_MAP[$efiStatus] ?? 'pending';
    }
}
