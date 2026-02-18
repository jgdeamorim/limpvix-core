<?php
declare(strict_types=1);

/**
 * PayoutRepositoryInterface - Domain Repository Interface
 *
 * RESPONSABILIDADE:
 * - Definir contrato para persistência de Payouts
 * - Garantir Dependency Inversion Principle
 * - Permitir testes unitários com mocks
 *
 * PRINCÍPIOS:
 * - Interface Segregation (métodos coesos)
 * - Dependency Inversion (Application depende de Domain interface)
 * - Repository Pattern (abstração de persistência)
 *
 * CRITICAL FIX (Finance Core Stabilization Sprint):
 * - Interface criada para corrigir violação de DIP
 * - ExecutePayout agora depende de interface (não classe concreta)
 * - WpPayoutRepository implementa esta interface
 * - Permite testes unitários com mocks
 *
 * @package LimpVix\Domain\Finance
 * @since Sprint: Finance Core Stabilization (2026-02-12)
 */

namespace LimpVix\Domain\Finance;

defined('ABSPATH') || exit;

interface PayoutRepositoryInterface
{
    /**
     * Criar novo payout
     *
     * @param array $data Dados do payout
     * @return int ID do payout criado
     */
    public function create(array $data): int;

    /**
     * Buscar payout por ID
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array;

    /**
     * Buscar payouts de uma order
     *
     * @param int $order_id
     * @return array
     */
    public function getByOrder(int $order_id): array;

    /**
     * Buscar payouts de um profissional
     *
     * @param int $professional_id
     * @param string|null $status Filtrar por status
     * @return array
     */
    public function getByProfessional(int $professional_id, ?string $status = null): array;

    /**
     * Buscar payouts por status
     *
     * @param string $status
     * @param int $limit
     * @return array
     */
    public function getByStatus(string $status, int $limit = 100): array;

    /**
     * Buscar payouts pendentes de processamento
     *
     * @return array
     */
    public function getPendingPayouts(): array;

    /**
     * Buscar payouts que falharam e podem ser retentados
     *
     * @return array
     */
    public function getRetriablePayouts(): array;

    /**
     * Atualizar status do payout
     *
     * @param int $id
     * @param string $new_status
     * @param string|null $gateway_response
     * @return bool
     */
    public function updateStatus(int $id, string $new_status, ?string $gateway_response = null): bool;

    /**
     * Registrar falha de payout
     *
     * @param int $id
     * @param string $failure_reason
     * @return bool
     */
    public function registerFailure(int $id, string $failure_reason): bool;

    /**
     * Atualizar gateway_transfer_id após sucesso
     *
     * @param int $id
     * @param string $transfer_id
     * @return bool
     */
    public function setTransferId(int $id, string $transfer_id): bool;

    /**
     * Buscar por gateway_transfer_id
     *
     * @param string $transfer_id
     * @return array|null
     */
    public function getByTransferId(string $transfer_id): ?array;

    /**
     * Calcular total de payouts por profissional
     *
     * @param int $professional_id
     * @param string|null $status
     * @return float
     */
    public function getTotalByProfessional(int $professional_id, ?string $status = null): float;

    /**
     * Estatísticas de payouts
     *
     * @return array{
     *     total_pending: int,
     *     total_approved: int,
     *     total_processing: int,
     *     total_completed: int,
     *     total_failed: int,
     *     amount_pending: float,
     *     amount_completed: float
     * }
     */
    public function getStats(): array;

    /**
     * Atualizar dados do destinatário do payout (chave PIX, nome)
     *
     * Usado por ExecutePayout para popular automaticamente os dados
     * do profissional antes de enviar para o provider EFI Bank.
     *
     * @param int    $id            ID do payout
     * @param string $recipientKey  Chave PIX do profissional
     * @param string $recipientType Tipo da chave: pix | bank_account
     * @param string $recipientName Nome completo do profissional
     * @return bool
     */
    public function setRecipientInfo(
        int $id,
        string $recipientKey,
        string $recipientType,
        string $recipientName
    ): bool;

    /**
     * Deletar payout (uso interno apenas)
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;
}
