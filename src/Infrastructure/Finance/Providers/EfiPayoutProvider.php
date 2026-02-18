<?php
/**
 * EfiPayoutProvider - Provider de Payout via EFI Bank (Gerencianet/Efipay)
 *
 * RESPONSABILIDADE:
 * - Enviar PIX Cash-Out via API EFI Bank (PUT /v3/gn/pix/{idEnvio})
 * - Autenticar via OAuth2 com cache de token (55 minutos)
 * - Suporte a mTLS (certificado PEM) via hook http_api_curl
 * - Implementa PayoutProviderInterface
 *
 * CONFIGURACAO (wp_options):
 * - limpvix_efi_client_id        -> Client ID da aplicacao EFI
 * - limpvix_efi_client_secret    -> Client Secret da aplicacao EFI
 * - limpvix_efi_pix_key          -> Chave PIX da plataforma (pagador)
 * - limpvix_efi_sandbox          -> 'yes' = sandbox | 'no' = producao
 * - limpvix_efi_cert_path        -> Caminho para certificado .pem (mTLS)
 *
 * @package LimpVix\Infrastructure\Finance\Providers
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Finance\Providers;

use LimpVix\Domain\Finance\PayoutProviderInterface;
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;

defined('ABSPATH') || exit;

class EfiPayoutProvider implements PayoutProviderInterface
{
    private string $clientId       = '';
    private string $clientSecret   = '';
    private string $platformPixKey = '';
    private string $certPath       = '';
    private bool   $sandbox        = false;
    private bool   $enabled        = false;
    private string $baseUrl;
    private WpPayoutRepository $payoutRepository;

    private const TOKEN_TRANSIENT = 'limpvix_efi_access_token';

    private const STATUS_MAP = [
        'REALIZADO'     => 'completed',
        'EM_ENVIO'      => 'processing',
        'DEVOLVIDO'     => 'failed',
        'CANCELADO'     => 'cancelled',
        'FALHA'         => 'failed',
        'NAO_REALIZADO' => 'failed',
    ];

    public function __construct()
    {
        $this->payoutRepository = new WpPayoutRepository();

        $this->clientId       = (string) get_option('limpvix_efi_client_id', '');
        $this->clientSecret   = (string) get_option('limpvix_efi_client_secret', '');
        $this->platformPixKey = (string) get_option('limpvix_efi_pix_key', '');
        $this->certPath       = (string) get_option('limpvix_efi_cert_path', '');
        $this->sandbox        = get_option('limpvix_efi_sandbox', 'no') === 'yes';

        $this->baseUrl = $this->sandbox
            ? 'https://pix-h.api.efipay.com.br'
            : 'https://pix.api.efipay.com.br';

        if (empty($this->clientId) || empty($this->clientSecret)) {
            error_log('[LimpVix][EFI] Provider nao configurado (clientId/clientSecret ausentes)');
            return;
        }

        if (empty($this->platformPixKey)) {
            error_log('[LimpVix][EFI] Chave PIX da plataforma nao configurada (limpvix_efi_pix_key)');
            return;
        }

        $this->enabled = true;
        $env = $this->sandbox ? 'sandbox' : 'production';
        error_log("[LimpVix][EFI] EfiPayoutProvider inicializado — Ambiente: {$env}");
    }

    /**
     * Criar payout via EFI Bank (PIX Cash-Out)
     * Endpoint: PUT /v3/gn/pix/{idEnvio}
     */
    public function createPayout(int $payout_id): bool
    {
        if (!$this->enabled) {
            error_log('[LimpVix][EFI] Provider nao configurado — payout nao processado');
            $this->payoutRepository->registerFailure($payout_id, 'EFI Bank nao configurado');
            return false;
        }

        $payout = $this->payoutRepository->getById($payout_id);

        if (!$payout) {
            error_log("[LimpVix][EFI] Payout #{$payout_id} nao encontrado no banco");
            return false;
        }

        if ($payout['status'] !== 'approved') {
            error_log("[LimpVix][EFI] Payout #{$payout_id} nao aprovado (status: {$payout['status']})");
            return false;
        }

        $token = $this->getAccessToken();

        if ($token === null) {
            $this->payoutRepository->registerFailure($payout_id, 'Falha na autenticacao OAuth EFI');
            return false;
        }

        $this->payoutRepository->updateStatus($payout_id, 'processing');

        $idEnvio = $this->generateIdEnvio($payout_id);

        try {
            $payload = $this->buildPayload($payout);
            $url     = "{$this->baseUrl}/v3/gn/pix/{$idEnvio}";

            [$status_code, $body, $curl_err] = $this->makeRequest('PUT', $url, $payload, $token);

            if ($curl_err) {
                error_log("[LimpVix][EFI] Erro de rede ao criar payout: {$curl_err}");
                $this->payoutRepository->registerFailure($payout_id, $curl_err);
                return false;
            }

            if ($status_code >= 200 && $status_code < 300) {
                $efi_status   = $body['status'] ?? 'EM_ENVIO';
                $local_status = $this->mapEfiStatusToLocal($efi_status);

                error_log("[LimpVix][EFI] Payout criado — idEnvio={$idEnvio}, EFI={$efi_status}");

                $this->payoutRepository->setTransferId($payout_id, $idEnvio);
                $this->payoutRepository->updateStatus($payout_id, $local_status, json_encode($body));

                return true;
            }

            $error_msg  = $body['mensagem'] ?? ($body['titulo'] ?? 'Erro desconhecido EFI');
            $error_code = $body['codigo'] ?? $status_code;

            error_log("[LimpVix][EFI] Erro API: Code={$error_code}, Msg={$error_msg}");
            $this->payoutRepository->registerFailure($payout_id, "EFI Error {$error_code}: {$error_msg}");

            return false;

        } catch (\Exception $e) {
            error_log('[LimpVix][EFI] Excecao ao criar payout: ' . $e->getMessage());
            $this->payoutRepository->registerFailure($payout_id, $e->getMessage());
            return false;
        }
    }

    /**
     * Consultar status de payout enviado
     * Endpoint: GET /v3/gn/pix/enviados/{idEnvio}
     */
    public function getPayoutStatus(string $transfer_id): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $token = $this->getAccessToken();
        if ($token === null) {
            return null;
        }

        $url = "{$this->baseUrl}/v3/gn/pix/enviados/{$transfer_id}";

        [$status_code, $body, $curl_err] = $this->makeRequest('GET', $url, [], $token);

        if ($curl_err) {
            error_log("[LimpVix][EFI] Erro ao consultar status: {$curl_err}");
            return null;
        }

        return ($status_code >= 200 && $status_code < 300) ? $body : null;
    }

    /**
     * Sincronizar status de payouts em processing
     */
    public function syncProcessingPayouts(): int
    {
        $processing = $this->payoutRepository->getByStatus('processing', 50);
        $synced     = 0;

        foreach ($processing as $payout) {
            if (empty($payout['gateway_transfer_id'])) {
                continue;
            }

            $efi_data = $this->getPayoutStatus($payout['gateway_transfer_id']);
            if (!$efi_data) {
                continue;
            }

            $efi_status   = $efi_data['status'] ?? 'EM_ENVIO';
            $local_status = $this->mapEfiStatusToLocal($efi_status);

            if ($local_status !== $payout['status']) {
                $this->payoutRepository->updateStatus(
                    $payout['id'],
                    $local_status,
                    json_encode($efi_data)
                );
                $synced++;
            }
        }

        return $synced;
    }

    public function isAvailable(): bool
    {
        return $this->enabled;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    // ── OAuth2 com cache ─────────────────────────────────────

    private function getAccessToken(): ?string
    {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if (!empty($cached)) {
            return $cached;
        }

        $url   = "{$this->baseUrl}/oauth/token";
        $basic = base64_encode("{$this->clientId}:{$this->clientSecret}");

        [$status_code, $body, $curl_err] = $this->curlRequest(
            'POST',
            $url,
            'grant_type=client_credentials',
            ["Authorization: Basic {$basic}", 'Content-Type: application/x-www-form-urlencoded']
        );

        if ($curl_err) {
            error_log("[LimpVix][EFI] Erro OAuth cURL: {$curl_err}");
            return null;
        }

        if ($status_code !== 200 || empty($body['access_token'])) {
            $msg = $body['error_description'] ?? ($body['message'] ?? "HTTP {$status_code}");
            error_log("[LimpVix][EFI] OAuth falhou ({$status_code}): {$msg}");
            return null;
        }

        $token = $body['access_token'];
        $ttl   = (int) ($body['expires_in'] ?? 3600);

        set_transient(self::TOKEN_TRANSIENT, $token, $ttl - 300);

        return $token;
    }

    // ── HTTP com mTLS via cURL direto ────────────────────────

    /**
     * Executar requisição HTTP com suporte completo a mTLS (CURLOPT_SSLCERT + CURLOPT_SSLKEY).
     * wp_remote_request não suporta mTLS nativamente — necessário cURL direto.
     *
     * @return array{0: int, 1: array, 2: string}  [http_status, body_array, curl_error]
     */
    private function curlRequest(
        string $method,
        string $url,
        string|array $body = '',
        array $headers = []
    ): array {
        $ch = curl_init();

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        // Certificado mTLS (obrigatório pela API EFI Bank)
        if (!empty($this->certPath) && file_exists($this->certPath)) {
            $opts[CURLOPT_SSLCERT] = $this->certPath;
            $opts[CURLOPT_SSLKEY]  = $this->certPath;
        }

        $method = strtoupper($method);

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body) : $body;
        } elseif ($method === 'PUT') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opts[CURLOPT_POSTFIELDS]    = is_array($body) ? json_encode($body) : $body;
        } elseif ($method === 'GET') {
            $opts[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $opts);

        $resp      = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        $parsed = is_string($resp) ? (json_decode($resp, true) ?? []) : [];

        return [$http_code, $parsed, $curl_err];
    }

    private function makeRequest(
        string $method,
        string $url,
        array $payload = [],
        ?string $token = null,
        array $extra_headers = []
    ): array {
        $headers = ['Content-Type: application/json'];

        if ($token !== null) {
            $headers[] = "Authorization: Bearer {$token}";
        }

        foreach ($extra_headers as $k => $v) {
            $headers[] = "{$k}: {$v}";
        }

        $is_form = in_array('Content-Type: application/x-www-form-urlencoded', $headers, true);
        $body    = empty($payload) ? '' : ($is_form ? http_build_query($payload) : $payload);

        return $this->curlRequest($method, $url, $body, $headers);
    }

    // ── Helpers ─────────────────────────────────────────────

    private function buildPayload(array $payout): array
    {
        $amount = number_format((float) $payout['net_amount'], 2, '.', '');
        $base   = ['valor' => $amount, 'pagador' => ['chave' => $this->platformPixKey]];

        switch ($payout['recipient_type']) {
            case 'pix':
                return array_merge($base, ['favorecido' => ['chave' => $payout['recipient_key']]]);

            case 'bank_account':
                $parts        = explode('|', $payout['recipient_key']);
                $bank_code    = $parts[0] ?? '';
                $agency       = $parts[1] ?? '';
                $account      = $parts[2] ?? '';
                $account_type = $parts[3] ?? 'checking';
                $doc          = preg_replace('/\D/', '', $payout['recipient_document'] ?? '');
                $is_pj        = strlen($doc) === 14;

                return array_merge($base, [
                    'favorecido' => [
                        'contaBanco' => array_filter([
                            'nome'        => $payout['recipient_name'],
                            'cpf'         => !$is_pj ? $doc : null,
                            'cnpj'        => $is_pj  ? $doc : null,
                            'codigoBanco' => $bank_code,
                            'agencia'     => $agency,
                            'conta'       => $account,
                            'tipoConta'   => $account_type === 'savings' ? 'POUPANCA' : 'CORRENTE',
                        ]),
                    ],
                ]);

            default:
                throw new \Exception("Tipo de destinatario invalido para EFI: {$payout['recipient_type']}");
        }
    }

    private function mapEfiStatusToLocal(string $efi_status): string
    {
        return self::STATUS_MAP[$efi_status] ?? 'processing';
    }

    /**
     * idEnvio: 35 chars, apenas alfanumericos
     * Formato: lmpx + 5 digitos + 26 chars de md5
     */
    private function generateIdEnvio(int $payout_id): string
    {
        $hash = substr(md5('limpvix_efi_payout_' . $payout_id), 0, 26);
        return substr(sprintf('lmpx%05d', $payout_id) . $hash, 0, 35);
    }
}
