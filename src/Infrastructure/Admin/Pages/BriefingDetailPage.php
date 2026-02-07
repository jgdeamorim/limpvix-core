<?php
/**
 * BriefingDetailPage - Página de Detalhes do Briefing
 *
 * RESPONSABILIDADE:
 * - Visualização completa de um Briefing específico
 * - Dados por step (estrutura, frequência, localização, etc)
 * - Métricas calculadas (m², tempo, buffer)
 * - Timeline de eventos (via ledger)
 * - Links para Order e Contrato (se existentes)
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Domain\Briefing\BriefingRepositoryInterface;

defined('ABSPATH') || exit;

class BriefingDetailPage
{
    private $briefingRepository;
    private const PAGE_SLUG = 'limpvix-briefing-detail';

    public function __construct(BriefingRepositoryInterface $briefingRepository)
    {
        $this->briefingRepository = $briefingRepository;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            null, // Página oculta (sem menu)
            'Detalhes do Briefing',
            'Detalhes do Briefing',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        $uuid = isset($_GET['uuid']) ? sanitize_text_field($_GET['uuid']) : '';

        if (empty($uuid)) {
            echo '<div class="wrap"><h1>Erro</h1><p>UUID não fornecido.</p></div>';
            return;
        }

        $briefing = $this->briefingRepository->findByUuid($uuid);

        if (!$briefing) {
            echo '<div class="wrap"><h1>Erro</h1><p>Briefing não encontrado.</p></div>';
            return;
        }

        ?>
        <div class="wrap">
            <h1>Detalhes do Briefing</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-briefings')); ?>" class="page-title-action">← Voltar</a>

            <!-- Informações Principais -->
            <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
                <h2>Informações Principais</h2>
                <table class="form-table">
                    <tr>
                        <th>UUID:</th>
                        <td><code><?php echo esc_html($briefing->getUuid()); ?></code></td>
                    </tr>
                    <tr>
                        <th>Usuário:</th>
                        <td>
                            <?php
                            $user = get_userdata($briefing->getUserId());
                            echo $user ? esc_html($user->display_name) . ' (ID: ' . $briefing->getUserId() . ')' : 'ID: ' . $briefing->getUserId();
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><strong><?php echo esc_html($briefing->getStatus()->getValue()); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Tipo de Propriedade:</th>
                        <td><?php echo esc_html($briefing->getPropertyType()->getValue() === 'residential' ? 'Residencial' : 'Comercial'); ?></td>
                    </tr>
                    <tr>
                        <th>Telefone Verificado:</th>
                        <td><?php echo $briefing->isPhoneVerified() ? '✅ Sim' : '❌ Não'; ?></td>
                    </tr>
                    <tr>
                        <th>Requer Contrato:</th>
                        <td><?php echo $briefing->requiresContract() ? '✅ Sim' : '❌ Não'; ?></td>
                    </tr>
                    <tr>
                        <th>Locked:</th>
                        <td><?php echo $briefing->isLocked() ? '🔒 Sim' : '🔓 Não'; ?></td>
                    </tr>
                    <?php if ($briefing->getOrderId()): ?>
                    <tr>
                        <th>Order ID:</th>
                        <td>
                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $briefing->getOrderId() . '&action=edit')); ?>" target="_blank">
                                #<?php echo esc_html($briefing->getOrderId()); ?> (WooCommerce)
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Criado:</th>
                        <td><?php echo esc_html($briefing->getCreatedAt()->format('d/m/Y H:i:s')); ?></td>
                    </tr>
                    <tr>
                        <th>Atualizado:</th>
                        <td><?php echo esc_html($briefing->getUpdatedAt()->format('d/m/Y H:i:s')); ?></td>
                    </tr>
                    <?php if ($briefing->getLockedAt()): ?>
                    <tr>
                        <th>Locked em:</th>
                        <td><?php echo esc_html($briefing->getLockedAt()->format('d/m/Y H:i:s')); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Métricas -->
            <?php if ($briefing->getMetrics()): ?>
            <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
                <h2>Métricas Calculadas</h2>
                <table class="form-table">
                    <tr>
                        <th>Área Estimada:</th>
                        <td><strong><?php echo esc_html(number_format($briefing->getMetrics()->getM2(), 2)); ?> m²</strong></td>
                    </tr>
                    <tr>
                        <th>Duração Estimada:</th>
                        <td><?php echo esc_html($briefing->getMetrics()->getDurationMinutes()); ?> minutos</td>
                    </tr>
                    <tr>
                        <th>Buffer Operacional:</th>
                        <td><?php echo esc_html($briefing->getMetrics()->getBufferMinutes()); ?> minutos</td>
                    </tr>
                    <tr>
                        <th>Tempo Total:</th>
                        <td><strong><?php echo esc_html($briefing->getMetrics()->getTotalMinutes()); ?> minutos</strong></td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <!-- Estrutura do Imóvel -->
            <?php if ($briefing->getStructure()): ?>
            <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
                <h2>Estrutura do Imóvel</h2>
                <table class="form-table">
                    <?php if ($briefing->getStructure()->getBedrooms() > 0): ?>
                    <tr>
                        <th>Quartos:</th>
                        <td><?php echo esc_html($briefing->getStructure()->getBedrooms()); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Banheiros:</th>
                        <td><?php echo esc_html($briefing->getStructure()->getBathrooms()); ?></td>
                    </tr>
                    <tr>
                        <th>Sala:</th>
                        <td><?php echo $briefing->getStructure()->hasLivingRoom() ? '✅ Sim' : '❌ Não'; ?></td>
                    </tr>
                    <tr>
                        <th>Cozinha:</th>
                        <td><?php echo $briefing->getStructure()->hasKitchen() ? '✅ Sim' : '❌ Não'; ?></td>
                    </tr>
                    <tr>
                        <th>Escritório:</th>
                        <td><?php echo $briefing->getStructure()->hasOffice() ? '✅ Sim' : '❌ Não'; ?></td>
                    </tr>
                    <tr>
                        <th>Área Externa:</th>
                        <td><?php echo $briefing->getStructure()->hasExternalArea() ? '✅ Sim' : '❌ Não'; ?></td>
                    </tr>
                </table>
            </div>
            <?php endif; ?>

            <!-- Frequência -->
            <?php if ($briefing->getFrequency()): ?>
            <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
                <h2>Frequência do Serviço</h2>
                <table class="form-table">
                    <tr>
                        <th>Tipo:</th>
                        <td>
                            <?php
                            $typeLabels = ['avulso' => 'Serviço Único (Avulso)', 'weekly' => 'Semanal', 'monthly' => 'Mensal'];
                            echo esc_html($typeLabels[$briefing->getFrequency()->getType()] ?? $briefing->getFrequency()->getType());
                            ?>
                        </td>
                    </tr>
                    <?php if (!$briefing->getFrequency()->isAvulso()): ?>
                    <tr>
                        <th>Execuções por Período:</th>
                        <td><?php echo esc_html($briefing->getFrequency()->getExecutionsPerPeriod()); ?>x</td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>

            <!-- Timeline de Eventos -->
            <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
                <h2>Timeline de Eventos</h2>
                <?php $this->renderEventTimeline($uuid); ?>
            </div>
        </div>
        <?php
    }

    private function renderEventTimeline(string $uuid): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_briefing_ledger';

        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE briefing_uuid = %s ORDER BY occurred_at DESC",
            $uuid
        ), ARRAY_A);

        if (empty($events)) {
            echo '<p><em>Nenhum evento registrado.</em></p>';
            return;
        }

        echo '<ul style="list-style:none;padding:0">';
        foreach ($events as $event) {
            $occurred = new \DateTimeImmutable($event['occurred_at']);
            echo '<li style="margin-bottom:15px;padding:10px;background:#f9f9f9;border-left:3px solid #2271b1">';
            echo '<strong>' . esc_html($event['event_type']) . '</strong><br>';
            if ($event['from_status']) {
                echo '<small>Transição: ' . esc_html($event['from_status']) . ' → ' . esc_html($event['to_status']) . '</small><br>';
            }
            echo '<small>Ator: ' . esc_html($event['actor']) . ' | ' . esc_html($occurred->format('d/m/Y H:i:s')) . '</small>';
            echo '</li>';
        }
        echo '</ul>';
    }
}
