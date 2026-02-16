<?php
/**
 * RegisterBriefingAcceptance - Use Case de Registro de Aceite de Briefing
 *
 * RESPONSABILIDADE:
 * - Registrar aceite jurídico do cliente aos termos
 * - Gravar no ledger imutável (prova legal)
 * - Criar contexto financeiro inicial
 * - Vincular appointment → order → briefing
 *
 * PRINCÍPIOS:
 * - Imutabilidade (ledger)
 * - Auditabilidade (timestamp, IP, user agent)
 * - Compliance (LGPD, CDC)
 * - Idempotência (não duplicar aceites)
 *
 * @package LimpVix\Application\UseCases\Briefing
 */

namespace LimpVix\Application\UseCases\Briefing;

use LimpVix\Infrastructure\Persistence\WpFinancialLedgerRepository;

defined('ABSPATH') || exit;

final class RegisterBriefingAcceptance
{
    /**
     * Financial Ledger Repository
     *
     * @var WpFinancialLedgerRepository
     */
    private $ledgerRepository;

    /**
     * Construtor
     *
     * @param WpFinancialLedgerRepository|null $ledgerRepository
     */
    public function __construct(?WpFinancialLedgerRepository $ledgerRepository = null)
    {
        $this->ledgerRepository = $ledgerRepository ?? new WpFinancialLedgerRepository();
    }

    /**
     * Executar registro de aceite
     *
     * @param array $data
     * @return array ['success' => bool, 'message' => string]
     */
    public function execute(array $data): array
    {
        try {
            // Validar dados obrigatórios
            $this->validateInput($data);

            // Verificar se já existe aceite (idempotência)
            if ($this->hasExistingAcceptance($data['order_uuid'])) {
                return [
                    'success' => true,
                    'message' => 'Aceite já registrado anteriormente',
                ];
            }

            // Registrar aceite no ledger
            $ledgerId = $this->recordAcceptance($data);

            // Criar contexto financeiro inicial
            $this->createFinancialContext($data);

            // Notificar eventos
            do_action('limpvix_briefing_accepted', $data['order_uuid']);

            return [
                'success' => true,
                'message' => 'Briefing aceito com sucesso',
                'ledger_id' => $ledgerId,
            ];
        } catch (\Exception $e) {
            error_log('[LimpVix] Erro ao registrar briefing: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Validar entrada
     *
     * @param array $data
     * @throws \InvalidArgumentException
     */
    private function validateInput(array $data): void
    {
        $required = ['order_uuid', 'appointment_id', 'customer_id', 'accepted_terms'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Campo obrigatório ausente: {$field}");
            }
        }

        // Validar aceite explícito
        if ($data['accepted_terms'] !== true && $data['accepted_terms'] !== '1') {
            throw new \InvalidArgumentException("Termos não aceitos");
        }
    }

    /**
     * Verificar se já existe aceite
     *
     * @param string $orderUuid
     * @return bool
     */
    private function hasExistingAcceptance(string $orderUuid): bool
    {
        return $this->ledgerRepository->hasEvent($orderUuid, 'briefing_accepted');
    }

    /**
     * Registrar aceite no ledger imutável
     *
     * @param array $data
     * @return int ledger_id
     */
    private function recordAcceptance(array $data): int
    {
        return $this->ledgerRepository->append([
            'order_uuid' => $data['order_uuid'],
            'event_type' => 'briefing_accepted',
            'customer_id' => $data['customer_id'],
            'appointment_id' => $data['appointment_id'],
            'event_data' => [
                'accepted_terms' => true,
                'ip_address' => $this->getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'timestamp' => time(),
                'version_terms' => '1.0', // Versão dos termos aceitos
            ],
        ]);
    }

    /**
     * Criar contexto financeiro inicial
     *
     * @param array $data
     */
    private function createFinancialContext(array $data): void
    {
        global $wpdb;

        // Verificar se já existe contexto
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_financial_context WHERE order_uuid = %s",
            $data['order_uuid']
        ));

        if ($exists > 0) {
            return;
        }

        // Criar contexto inicial
        $wpdb->insert(
            $wpdb->prefix . 'limpvix_financial_context',
            [
                'order_uuid' => $data['order_uuid'],
                'customer_id' => $data['customer_id'],
                'professional_id' => $data['professional_id'] ?? null,
                'appointment_id' => $data['appointment_id'],
                'briefing_completed' => 1,
                'briefing_data' => json_encode($data['briefing_data'] ?? []),
                'service_complexity' => $data['service_complexity'] ?? 'medium',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Obter IP do cliente
     *
     * @return string
     */
    private function getClientIP(): string
    {
        $ipKeys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];

        foreach ($ipKeys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }

        return 'unknown';
    }

    /**
     * Obter dados do briefing registrado
     *
     * @param string $orderUuid
     * @return array|null
     */
    public function getBriefingData(string $orderUuid): ?array
    {
        $ledgerEntry = $this->ledgerRepository->findLatestEvent($orderUuid, 'briefing_accepted');

        if (!$ledgerEntry) {
            return null;
        }

        return [
            'ledger_id' => $ledgerEntry['id'],
            'order_uuid' => $ledgerEntry['order_uuid'],
            'customer_id' => $ledgerEntry['customer_id'],
            'event_data' => $ledgerEntry['event_data'], // Já decodificado pelo repository
            'created_at' => $ledgerEntry['created_at'],
        ];
    }
}
