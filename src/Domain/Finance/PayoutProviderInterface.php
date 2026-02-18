<?php
/**
 * PayoutProviderInterface - Contrato para Providers de Payout
 *
 * Permite inversao de dependencia em ExecutePayout.
 * Implementado por MercadoPagoPayoutProvider e EfiPayoutProvider.
 *
 * @package LimpVix\Domain\Finance
 * @since 0.2.0
 */

namespace LimpVix\Domain\Finance;

defined('ABSPATH') || exit;

interface PayoutProviderInterface
{
    /**
     * Criar e enviar payout ao profissional no gateway.
     *
     * Contrato:
     * - Buscar payout no banco por $payout_id
     * - Validar que status === 'approved'
     * - Atualizar status para 'processing' antes de chamar API
     * - Chamar API do gateway (irreversivel)
     * - Salvar transfer_id e atualizar status local
     * - Em caso de erro, chamar registerFailure()
     *
     * @param int $payout_id ID do payout no banco local
     * @return bool True se payout criado com sucesso no gateway
     */
    public function createPayout(int $payout_id): bool;

    /**
     * Consultar status de um payout no gateway.
     *
     * @param string $transfer_id ID da transferencia no gateway
     * @return array|null Dados do payout ou null se nao encontrado
     */
    public function getPayoutStatus(string $transfer_id): ?array;

    /**
     * Sincronizar status de payouts em processamento.
     * Busca ate 50 payouts com status 'processing' e reconcilia.
     *
     * @return int Numero de payouts sincronizados
     */
    public function syncProcessingPayouts(): int;

    /**
     * Verificar se o provider esta configurado e disponivel.
     *
     * @return bool True se pronto para operar
     */
    public function isAvailable(): bool;

    /**
     * Verificar se esta em modo sandbox.
     *
     * @return bool True se sandbox
     */
    public function isSandbox(): bool;
}
