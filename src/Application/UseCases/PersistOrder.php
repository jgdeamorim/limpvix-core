<?php
/**
 * PersistOrder - Use Case de persistência de Order
 *
 * RESPONSABILIDADE:
 * - Orquestrar persistência de Order via Repository
 * - Decidir entre save() ou update()
 * - Capturar e traduzir exceções de infraestrutura
 * - Retornar resultado estruturado
 *
 * PRINCÍPIOS:
 * - Use Case = orquestração (não lógica de negócio)
 * - Camada fina (Application Layer)
 * - Domain é soberano (Order decide transições)
 * - Infrastructure é burra (Repository só persiste)
 *
 * DECISÕES:
 * - exists() → update()
 * - não exists() → save()
 * - Exceções são capturadas e envelopadas
 * - Resultado explícito (success/failure + motivo)
 *
 * ESTA CLASSE:
 * - Pertence à APPLICATION LAYER
 * - Conhece Order (domínio)
 * - Conhece OrderRepositoryInterface (contrato)
 * - NÃO conhece wpdb
 * - NÃO conhece Booknetic
 * - NÃO conhece Hooks
 * - NÃO conhece FeatureFlags
 *
 * USO:
 * ```php
 * $useCase = new PersistOrder($repository);
 * $result = $useCase->execute($order);
 *
 * if ($result->isSuccess()) {
 *     echo "Order persistida: " . $result->getOrderUuid();
 * } else {
 *     echo "Erro: " . $result->getError();
 * }
 * ```
 *
 * @package LimpVix\Application\UseCases
 */

namespace LimpVix\Application\UseCases;

use LimpVix\Domain\Order\Order;
use LimpVix\Domain\Order\OrderRepositoryInterface;

defined('ABSPATH') || exit;

class PersistOrder
{
    /**
     * Repository de Orders
     *
     * @var OrderRepositoryInterface
     */
    private $repository;

    /**
     * Construtor
     *
     * @param OrderRepositoryInterface $repository Repository injetado
     */
    public function __construct(OrderRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Executar Use Case
     *
     * LÓGICA:
     * - Se Order já existe (por UUID): UPDATE
     * - Se Order não existe: INSERT
     *
     * RESULTADO:
     * - PersistOrderResult com success/failure
     *
     * @param Order $order Order a ser persistida
     * @return PersistOrderResult Resultado da operação
     */
    public function execute(Order $order): PersistOrderResult
    {
        try {
            // Decisão explícita: save ou update?
            if ($this->repository->exists($order->getUuid())) {
                // Já existe → UPDATE
                $this->repository->update($order);

                return PersistOrderResult::success(
                    $order->getUuid(),
                    'updated'
                );
            } else {
                // Não existe → INSERT
                $this->repository->save($order);

                return PersistOrderResult::success(
                    $order->getUuid(),
                    'created'
                );
            }
        } catch (\RuntimeException $e) {
            // Exceção de infraestrutura (Repository falhou)
            return PersistOrderResult::failure(
                $order->getUuid(),
                'persistence_error',
                $e->getMessage()
            );
        } catch (\Exception $e) {
            // Exceção inesperada
            return PersistOrderResult::failure(
                $order->getUuid(),
                'unexpected_error',
                $e->getMessage()
            );
        }
    }
}

/**
 * PersistOrderResult - Resultado do Use Case
 *
 * Value Object que encapsula resultado da persistência
 *
 * IMUTÁVEL - criado via factory methods
 */
class PersistOrderResult
{
    /**
     * Sucesso?
     *
     * @var bool
     */
    private $success;

    /**
     * UUID da Order
     *
     * @var string
     */
    private $orderUuid;

    /**
     * Operação realizada (created, updated)
     *
     * @var string|null
     */
    private $operation;

    /**
     * Código de erro (se falhou)
     *
     * @var string|null
     */
    private $errorCode;

    /**
     * Mensagem de erro (se falhou)
     *
     * @var string|null
     */
    private $errorMessage;

    /**
     * Construtor privado (use factory methods)
     *
     * @param bool $success
     * @param string $orderUuid
     * @param string|null $operation
     * @param string|null $errorCode
     * @param string|null $errorMessage
     */
    private function __construct(
        bool $success,
        string $orderUuid,
        ?string $operation = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ) {
        $this->success = $success;
        $this->orderUuid = $orderUuid;
        $this->operation = $operation;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
    }

    /**
     * Factory: Sucesso
     *
     * @param string $orderUuid UUID da Order
     * @param string $operation Operação (created, updated)
     * @return self
     */
    public static function success(string $orderUuid, string $operation): self
    {
        return new self(true, $orderUuid, $operation);
    }

    /**
     * Factory: Falha
     *
     * @param string $orderUuid UUID da Order
     * @param string $errorCode Código do erro
     * @param string $errorMessage Mensagem do erro
     * @return self
     */
    public static function failure(string $orderUuid, string $errorCode, string $errorMessage): self
    {
        return new self(false, $orderUuid, null, $errorCode, $errorMessage);
    }

    /**
     * Sucesso?
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Falhou?
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }

    /**
     * Obter UUID da Order
     *
     * @return string
     */
    public function getOrderUuid(): string
    {
        return $this->orderUuid;
    }

    /**
     * Obter operação realizada
     *
     * @return string|null "created" ou "updated" se sucesso, null se falha
     */
    public function getOperation(): ?string
    {
        return $this->operation;
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
     * Converter para array (para logs, etc)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'order_uuid' => $this->orderUuid,
            'operation' => $this->operation,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
