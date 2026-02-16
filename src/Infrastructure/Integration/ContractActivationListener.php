<?php
/**
 * ContractActivationListener - Listener para Ativação de Contratos
 *
 * RESPONSABILIDADE:
 * - Escutar evento: limpvix_briefing_locked
 * - Verificar se briefing requires_contract = true
 * - Criar contrato automaticamente (placeholder - módulo Contrato futuro)
 * - Notificar usuário sobre contrato gerado
 *
 * HOOK ESCUTADO:
 * - limpvix_briefing_locked (prioridade 10)
 *
 * AÇÕES EXECUTADAS:
 * - Criar contrato (futuro: via CreateContract use case)
 * - Enviar notificação ao cliente
 * - Enviar notificação ao admin
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Domain\Briefing\BriefingRepositoryInterface;

defined('ABSPATH') || exit;

class ContractActivationListener
{
    /**
     * @var BriefingRepositoryInterface
     */
    private $briefingRepository;

    /**
     * Construtor
     *
     * @param BriefingRepositoryInterface $briefingRepository
     */
    public function __construct(BriefingRepositoryInterface $briefingRepository)
    {
        $this->briefingRepository = $briefingRepository;
    }

    /**
     * Registrar listener
     *
     * @return void
     */
    public function register(): void
    {
        add_action('limpvix_briefing_locked', [$this, 'onBriefingLocked'], 10, 1);
    }

    /**
     * Handler: limpvix_briefing_locked
     *
     * @param array $eventData ['briefing_uuid', 'order_id', 'requires_contract']
     * @return void
     */
    public function onBriefingLocked(array $eventData): void
    {
        try {
            // 1. Verificar se requer contrato
            if (!isset($eventData['requires_contract']) || !$eventData['requires_contract']) {
                return; // Não requer contrato, ignorar
            }

            // 2. Buscar Briefing
            $briefingUuid = $eventData['briefing_uuid'] ?? '';
            $briefing = $this->briefingRepository->findByUuid($briefingUuid);

            if (!$briefing) {
                $this->logError("Briefing não encontrado: {$briefingUuid}");
                return;
            }

            // 3. Criar contrato (placeholder - módulo Contrato futuro)
            $contractId = $this->createContract($briefing);

            // 4. Enviar notificações
            $this->sendNotifications($briefing, $contractId);

            // 5. Log
            $this->logInfo("Contrato criado automaticamente: Contract ID={$contractId}, Briefing UUID={$briefingUuid}");

        } catch (\Exception $e) {
            $this->logError('Erro ao processar ativação de contrato: ' . $e->getMessage());
        }
    }

    /**
     * Criar contrato (placeholder)
     *
     * TODO: Implementar integração com módulo Contrato quando disponível.
     *
     * @param \LimpVix\Domain\Briefing\Briefing $briefing
     * @return int Contract ID (mock)
     */
    private function createContract($briefing): int
    {
        // Placeholder: por enquanto, apenas criar um post de contrato simulado

        $contractData = [
            'post_title' => sprintf('Contrato - Briefing %s', substr($briefing->getUuid(), 0, 8)),
            'post_content' => $this->generateContractContent($briefing),
            'post_status' => 'publish',
            'post_type' => 'limpvix_contract', // CPT futuro
            'post_author' => $briefing->getUserId()
        ];

        // TODO: Usar CreateContract use case quando módulo existir
        $contractId = wp_insert_post($contractData);

        // Vincular meta: briefing_uuid
        if ($contractId) {
            update_post_meta($contractId, '_limpvix_briefing_uuid', $briefing->getUuid());
            update_post_meta($contractId, '_limpvix_contract_status', 'pending_signature');
            update_post_meta($contractId, '_limpvix_frequency_type', $briefing->getFrequency() ? $briefing->getFrequency()->getType() : '');
        }

        return $contractId;
    }

    /**
     * Gerar conteúdo do contrato (placeholder)
     *
     * @param \LimpVix\Domain\Briefing\Briefing $briefing
     * @return string HTML do contrato
     */
    private function generateContractContent($briefing): string
    {
        $user = get_userdata($briefing->getUserId());
        $userName = $user ? $user->display_name : 'Cliente';

        $html = '<h1>Contrato de Prestação de Serviços de Limpeza</h1>';
        $html .= '<p><strong>Cliente:</strong> ' . esc_html($userName) . '</p>';
        $html .= '<p><strong>Tipo de Propriedade:</strong> ' . esc_html($briefing->getPropertyType()->getValue()) . '</p>';

        if ($briefing->getFrequency()) {
            $html .= '<p><strong>Frequência:</strong> ' . esc_html($briefing->getFrequency()->getType()) . '</p>';
            if (!$briefing->getFrequency()->isAvulso()) {
                $html .= '<p><strong>Execuções por período:</strong> ' . esc_html($briefing->getFrequency()->getExecutionsPerPeriod()) . 'x</p>';
            }
        }

        if ($briefing->getMetrics()) {
            $html .= '<p><strong>Área estimada:</strong> ' . esc_html(number_format($briefing->getMetrics()->getM2(), 2)) . ' m²</p>';
            $html .= '<p><strong>Duração estimada:</strong> ' . esc_html($briefing->getMetrics()->getDurationMinutes()) . ' minutos</p>';
        }

        $html .= '<hr>';
        $html .= '<p><em>Contrato gerado automaticamente em ' . date('d/m/Y H:i') . '</em></p>';
        $html .= '<p><strong>Status:</strong> Aguardando assinatura digital</p>';

        return $html;
    }

    /**
     * Enviar notificações
     *
     * @param \LimpVix\Domain\Briefing\Briefing $briefing
     * @param int $contractId
     * @return void
     */
    private function sendNotifications($briefing, int $contractId): void
    {
        // TODO: Integrar com módulo de Notificações/Email

        // Notificar cliente
        $user = get_userdata($briefing->getUserId());
        if ($user && $user->user_email) {
            wp_mail(
                $user->user_email,
                'LimpVix - Contrato Gerado',
                sprintf(
                    "Olá %s,\n\nSeu contrato de serviço foi gerado e está disponível para assinatura.\n\nAcesse: %s\n\nObrigado,\nEquipe LimpVix",
                    $user->display_name,
                    home_url('/contrato/' . $contractId)
                )
            );
        }

        // Notificar admin
        $adminEmail = get_option('admin_email');
        if ($adminEmail) {
            wp_mail(
                $adminEmail,
                '[LimpVix] Novo Contrato Gerado',
                sprintf(
                    "Novo contrato gerado automaticamente:\n\nBriefing UUID: %s\nCliente: %s\nContrato ID: %d",
                    $briefing->getUuid(),
                    $user ? $user->display_name : 'Desconhecido',
                    $contractId
                )
            );
        }
    }

    /**
     * Log de informação
     *
     * @param string $message
     * @return void
     */
    private function logInfo(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix][ContractActivation][INFO] ' . $message);
        }
    }

    /**
     * Log de erro
     *
     * @param string $message
     * @return void
     */
    private function logError(string $message): void
    {
        error_log('[LimpVix][ContractActivation][ERROR] ' . $message);
    }
}
