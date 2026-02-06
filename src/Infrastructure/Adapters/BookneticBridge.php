<?php
/**
 * BookneticBridge - Ponte entre Booknetic e LimpVix
 *
 * RESPONSABILIDADE:
 * - Traduzir hooks nativos do Booknetic para hooks LimpVix
 * - Mapear status do Booknetic para eventos LimpVix
 * - Garantir compatibilidade entre versões do Booknetic
 *
 * PRINCÍPIOS:
 * - Zero modificação no código do Booknetic
 * - Adapter Pattern puro
 * - Fail-safe (não quebra se Booknetic mudar)
 *
 * HOOKS NATIVOS BOOKNETIC 4.8.5:
 * - bkntc_appointment_created
 * - bkntc_appointment_status_changed
 * - bkntc_appointment_deleted
 *
 * HOOKS CUSTOMIZADOS LIMPVIX:
 * - limpvix_booknetic_appointment_completed
 *
 * MAPEAMENTO DE STATUS:
 * - Booknetic "completed" → LimpVix "appointment_completed"
 *
 * @package LimpVix\Infrastructure\Adapters
 */

namespace LimpVix\Infrastructure\Adapters;

defined('ABSPATH') || exit;

class BookneticBridge
{
    /**
     * Status do Booknetic que representa "serviço completado"
     */
    private const COMPLETED_STATUS = 'completed';

    /**
     * Registrar hooks do Booknetic
     *
     * @return void
     */
    public function register(): void
    {
        // Hook NATIVO do Booknetic: quando status muda
        add_action(
            'bkntc_appointment_status_changed',
            [$this, 'onStatusChanged'],
            10,
            3
        );
    }

    /**
     * Handler: Status do appointment mudou
     *
     * TRADUÇÃO:
     * - Booknetic "bkntc_appointment_status_changed" (nativo)
     * - → LimpVix "limpvix_booknetic_appointment_completed" (customizado)
     *
     * @param int $appointmentId ID do appointment no Booknetic
     * @param string $oldStatus Status anterior
     * @param string $newStatus Novo status
     * @return void
     */
    public function onStatusChanged(int $appointmentId, string $oldStatus, string $newStatus): void
    {
        // Verificar se mudou para "completed"
        if ($newStatus !== self::COMPLETED_STATUS) {
            return; // Ignora outras mudanças
        }

        // Garantir que é uma transição válida (não foi completed antes)
        if ($oldStatus === self::COMPLETED_STATUS) {
            return; // Já estava completed, ignora
        }

        try {
            // Disparar hook customizado LimpVix
            do_action('limpvix_booknetic_appointment_completed', [
                'appointment_id' => $appointmentId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'timestamp' => current_time('mysql'),
                'bridge_version' => '1.0.0', // Versionamento para debug
            ]);

            // Log de auditoria (se WP_DEBUG ativo)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[LimpVix Bridge] Appointment #%d completed (%s → %s)',
                    $appointmentId,
                    $oldStatus,
                    $newStatus
                ));
            }

        } catch (\Exception $e) {
            // NUNCA quebrar o fluxo do Booknetic
            // Apenas logar erro
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[LimpVix Bridge ERROR] Failed to dispatch completed event: %s',
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Verificar se bridge está funcionando (health check)
     *
     * @return array Status do bridge
     */
    public function healthCheck(): array
    {
        return [
            'bridge_active' => true,
            'booknetic_detected' => $this->isBookneticActive(),
            'hook_registered' => has_action('bkntc_appointment_status_changed'),
            'completed_status' => self::COMPLETED_STATUS,
            'version' => '1.0.0',
        ];
    }

    /**
     * Verificar se Booknetic está ativo
     *
     * @return bool
     */
    private function isBookneticActive(): bool
    {
        return is_plugin_active('booknetic/init.php');
    }

    /**
     * Obter status possíveis do Booknetic (debug)
     *
     * NOTA: Booknetic 4.8.5 usa estes status:
     * - pending: Aguardando confirmação
     * - approved: Confirmado
     * - completed: Serviço concluído ✅
     * - canceled: Cancelado pelo cliente
     * - rejected: Rejeitado pelo admin
     *
     * @return array
     */
    public function getBookneticStatuses(): array
    {
        return [
            'pending',
            'approved',
            'completed',
            'canceled',
            'rejected',
        ];
    }
}
