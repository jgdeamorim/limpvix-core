<?php
/**
 * PayoutResult - Resultado de Execução de Payout
 *
 * RESPONSABILIDADE:
 * - Encapsular resultado técnico da transferência
 * - Sucesso: MP Transfer ID
 * - Falha: Código de erro + mensagem
 *
 * PRINCÍPIOS:
 * - Result Object Pattern
 * - Imutável
 * - Type-safe
 * - Agnóstico de provider
 *
 * IMPORTANTE:
 * - NÃO contém lógica de retry
 * - NÃO contém lógica de negócio
 * - Apenas resultado técnico puro
 *
 * PASSO 5.5 - Payout Engine
 *
 * @package LimpVix\Modules\Payouts\MercadoPago
 */

namespace LimpVix\Modules\Payouts\MercadoPago;

defined('ABSPATH') || exit;

final class PayoutResult
{
    /**
     * Sucesso?
     *
     * @var bool
     */
    private $success;

    /**
     * MP Transfer ID (se sucesso)
     *
     * @var string|null
     */
    private $mpTransferId;

    /**
     * Código de erro (se falha)
     *
     * @var string|null
     */
    private $errorCode;

    /**
     * Mensagem de erro (se falha)
     *
     * @var string|null
     */
    private $errorMessage;

    /**
     * HTTP Status Code da resposta
     *
     * @var int|null
     */
    private $httpStatusCode;

    /**
     * Resposta completa do MP (debug)
     *
     * @var array|null
     */
    private $rawResponse;

    /**
     * Factory: Sucesso
     *
     * @param string $mpTransferId MP Transfer ID
     * @param int $httpStatusCode HTTP Status
     * @param array|null $rawResponse Resposta completa
     * @return self
     */
    public static function success(
        string $mpTransferId,
        int $httpStatusCode = 201,
        ?array $rawResponse = null
    ): self {
        $result = new self();
        $result->success = true;
        $result->mpTransferId = $mpTransferId;
        $result->errorCode = null;
        $result->errorMessage = null;
        $result->httpStatusCode = $httpStatusCode;
        $result->rawResponse = $rawResponse;

        return $result;
    }

    /**
     * Factory: Falha
     *
     * @param string $errorCode Código de erro
     * @param string $errorMessage Mensagem de erro
     * @param int|null $httpStatusCode HTTP Status
     * @param array|null $rawResponse Resposta completa
     * @return self
     */
    public static function failure(
        string $errorCode,
        string $errorMessage,
        ?int $httpStatusCode = null,
        ?array $rawResponse = null
    ): self {
        $result = new self();
        $result->success = false;
        $result->mpTransferId = null;
        $result->errorCode = $errorCode;
        $result->errorMessage = $errorMessage;
        $result->httpStatusCode = $httpStatusCode;
        $result->rawResponse = $rawResponse;

        return $result;
    }

    /**
     * Construtor privado (use factories)
     */
    private function __construct()
    {
        // Use factories: success() ou failure()
    }

    /**
     * Verificar sucesso
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Verificar falha
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }

    /**
     * Obter MP Transfer ID
     *
     * @return string|null
     */
    public function getMpTransferId(): ?string
    {
        return $this->mpTransferId;
    }

    /**
     * Obter código de erro
     *
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Obter mensagem de erro
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Obter HTTP status code
     *
     * @return int|null
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /**
     * Obter resposta completa
     *
     * @return array|null
     */
    public function getRawResponse(): ?array
    {
        return $this->rawResponse;
    }

    /**
     * Verificar se é erro recuperável
     *
     * Erros recuperáveis: timeout, 500, 503
     * Erros irrecuperáveis: 400, 401, 403, 404
     *
     * @return bool
     */
    public function isRecoverable(): bool
    {
        if ($this->success) {
            return false;
        }

        $recoverableCodes = [500, 503, 504];

        return in_array($this->httpStatusCode, $recoverableCodes, true);
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        $data = [
            'success' => $this->success,
            'http_status_code' => $this->httpStatusCode
        ];

        if ($this->success) {
            $data['mp_transfer_id'] = $this->mpTransferId;
        } else {
            $data['error_code'] = $this->errorCode;
            $data['error_message'] = $this->errorMessage;
        }

        return $data;
    }

    /**
     * Representação em string
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->success) {
            return sprintf(
                'SUCCESS: MP Transfer ID %s (HTTP %d)',
                $this->mpTransferId,
                $this->httpStatusCode
            );
        }

        return sprintf(
            'FAILURE: %s - %s (HTTP %d)',
            $this->errorCode,
            $this->errorMessage,
            $this->httpStatusCode ?? 0
        );
    }
}
