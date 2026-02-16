<?php
/**
 * OrderRepositoryInterface - Port para Acesso a Orders
 *
 * RESPONSABILIDADE:
 * - Contrato para acesso a orders
 * - Operações mínimas necessárias para gestão financeira
 * - Abstração sobre persistência (WooCommerce, custom, etc)
 *
 * PRINCÍPIOS:
 * - Port (Hexagonal Architecture)
 * - Interface Segregation (apenas o necessário)
 * - Agnóstico de implementação
 *
 * OPERAÇÕES:
 * - findByUuid(): Buscar order por UUID
 * - getCurrentFinancialStatus(): Obter status financeiro atual
 * - updateFinancialStatus(): Atualizar status financeiro (cache)
 *
 * IMPORTANTE:
 * - Status da order é CACHE
 * - Ledger é fonte da verdade
 * - Update de status é otimização de leitura
 *
 * PASSO 5.3 - Use Cases de Decisão
 *
 * @package LimpVix\Domain\Order
 */

namespace LimpVix\Domain\Order;

use LimpVix\Domain\Finance\FinancialStatus;

defined('ABSPATH') || exit;

interface OrderRepositoryInterface
{
    /**
     * Buscar order por UUID
     *
     * @param string $uuid UUID da order
     * @return Order|null Order ou null se não encontrada
     */
    public function findByUuid(string $uuid): ?Order;

    /**
     * Obter status financeiro atual da order
     *
     * Este é o status CACHED (pode estar dessinc com ledger)
     *
     * @param string $uuid UUID da order
     * @return FinancialStatus|null Status ou null se order não existe
     */
    public function getCurrentFinancialStatus(string $uuid): ?FinancialStatus;

    /**
     * Atualizar status financeiro da order
     *
     * Esta operação atualiza o CACHE de status
     * A fonte da verdade continua sendo o Ledger
     *
     * @param string $uuid UUID da order
     * @param FinancialStatus $status Novo status
     * @return void
     * @throws \RuntimeException Se order não existe ou falha de persistência
     */
    public function updateFinancialStatus(string $uuid, FinancialStatus $status): void;

    /**
     * Verificar se order existe
     *
     * @param string $uuid UUID da order
     * @return bool
     */
    public function exists(string $uuid): bool;
}
