<?php
/**
 * BriefingOperationResult - Result Object para operações de Briefing
 *
 * Encapsula o resultado de Use Cases de Briefing.
 * Padrão: Result Pattern (Success/Failure)
 *
 * @package LimpVix\Application\Results
 * @since 0.2.0
 */

namespace LimpVix\Application\Results;

use LimpVix\Domain\Briefing\Briefing;

defined('ABSPATH') || exit;

class BriefingOperationResult
{
    /**
     * @var bool Sucesso da operação
     */
    private $success;

    /**
     * @var Briefing|null Briefing resultante (se sucesso)
     */
    private $briefing;

    /**
     * @var string|null Mensagem de erro (se falha)
     */
    private $errorMessage;

    /**
     * @var array Dados adicionais
     */
    private $data;

    /**
     * Construtor privado
     *
     * @param bool $success
     * @param Briefing|null $briefing
     * @param string|null $errorMessage
     * @param array $data
     */
    private function __construct(
        bool $success,
        ?Briefing $briefing = null,
        ?string $errorMessage = null,
        array $data = []
    ) {
        $this->success = $success;
        $this->briefing = $briefing;
        $this->errorMessage = $errorMessage;
        $this->data = $data;
    }

    /**
     * Factory: Sucesso
     *
     * @param Briefing $briefing
     * @param array $data Dados adicionais
     * @return self
     */
    public static function success(Briefing $briefing, array $data = []): self
    {
        return new self(true, $briefing, null, $data);
    }

    /**
     * Factory: Falha
     *
     * @param string $errorMessage
     * @param array $data Dados adicionais
     * @return self
     */
    public static function failure(string $errorMessage, array $data = []): self
    {
        return new self(false, null, $errorMessage, $data);
    }

    /**
     * Verificar se foi sucesso
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Verificar se foi falha
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }

    /**
     * Obter Briefing (se sucesso)
     *
     * @return Briefing|null
     */
    public function getBriefing(): ?Briefing
    {
        return $this->briefing;
    }

    /**
     * Obter mensagem de erro (se falha)
     *
     * @return string|null
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Obter dados adicionais
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Obter valor específico dos dados
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'briefing' => $this->briefing ? $this->briefing->toArray() : null,
            'error_message' => $this->errorMessage,
            'data' => $this->data
        ];
    }
}
