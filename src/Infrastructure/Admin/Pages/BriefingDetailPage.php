<?php
/**
 * BriefingDetailPage - Página de Detalhes do Briefing + Scheduling
 *
 * RESPONSABILIDADE:
 * - Visualização completa de um Briefing específico
 * - Dados por step (estrutura, frequência, localização, etc)
 * - Métricas calculadas (m², tempo, buffer)
 * - **NOVO: Integração com Scheduling (agendamento de profissionais)**
 * - Timeline de eventos (via ledger)
 * - Links para Order e Contrato (se existentes)
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Domain\Briefing\BriefingRepositoryInterface;
use LimpVix\Infrastructure\Persistence\WpScheduleRepository;
use LimpVix\Infrastructure\Persistence\WpProfessionalRepository;
use LimpVix\Application\UseCases\Scheduling\CreateSchedule;
use LimpVix\Application\UseCases\Scheduling\AllocateProfessional;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;
use LimpVix\Domain\Scheduling\ValueObjects\GeoCoordinates;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceComplexity;

defined('ABSPATH') || exit;

class BriefingDetailPage
{
    private $briefingRepository;
    private $scheduleRepository;
    private $professionalRepository;
    private const PAGE_SLUG = 'limpvix-briefing-detail';

    public function __construct(BriefingRepositoryInterface $briefingRepository)
    {
        $this->briefingRepository = $briefingRepository;
        $this->scheduleRepository = new WpScheduleRepository();
        $this->professionalRepository = new WpProfessionalRepository();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_limpvix_schedule_briefing', [$this, 'handleSchedulingAction']);
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

        // Buscar schedule existente (se houver)
        $existingSchedule = $this->getExistingSchedule($uuid);

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

            <!-- NOVO: Seção de Agendamento -->
            <?php if ($briefing->isLocked()): ?>
                <?php $this->renderSchedulingSection($briefing, $existingSchedule); ?>
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

    /**
     * NOVO: Renderiza seção de agendamento
     */
    private function renderSchedulingSection($briefing, $existingSchedule): void
    {
        ?>
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>📅 Agendamento de Profissionais</h2>

            <?php if ($existingSchedule): ?>
                <!-- Schedule já existe - mostrar status -->
                <?php $this->renderExistingSchedule($existingSchedule); ?>
            <?php else: ?>
                <!-- Nenhum schedule - mostrar formulário -->
                <?php $this->renderSchedulingForm($briefing); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * NOVO: Renderiza formulário de agendamento
     */
    private function renderSchedulingForm($briefing): void
    {
        ?>
        <p>O briefing está finalizado. Agende os profissionais escolhendo data e horário desejados.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:20px">
            <input type="hidden" name="action" value="limpvix_schedule_briefing">
            <input type="hidden" name="briefing_uuid" value="<?php echo esc_attr($briefing->getUuid()); ?>">
            <?php wp_nonce_field('schedule_briefing'); ?>

            <table class="form-table">
                <tr>
                    <th><label for="requested_date">Data Desejada:</label></th>
                    <td>
                        <input type="date"
                               id="requested_date"
                               name="requested_date"
                               min="<?php echo date('Y-m-d'); ?>"
                               required
                               style="width:200px">
                    </td>
                </tr>
                <tr>
                    <th><label for="requested_time">Horário Desejado:</label></th>
                    <td>
                        <input type="time"
                               id="requested_time"
                               name="requested_time"
                               required
                               style="width:150px">
                        <p class="description">O sistema criará uma janela válida de ±1h (ex: 09:00 → janela 08:00-10:00)</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary button-large">
                    🎯 Alocar Profissionais Automaticamente
                </button>
            </p>
        </form>

        <div style="background:#f0f6fc;border-left:4px solid #0969da;padding:15px;margin-top:20px">
            <strong>ℹ️ Como funciona:</strong>
            <ul style="margin:10px 0 0 20px">
                <li>Sistema calcula quantos profissionais são necessários (baseado na duração)</li>
                <li>Busca profissionais disponíveis na data/hora escolhida</li>
                <li>Calcula score de cada profissional (proximidade 40% + disponibilidade 30% + rating 20% + carga 10%)</li>
                <li>Aloca automaticamente os melhores profissionais</li>
                <li>Cria appointments no Booknetic</li>
            </ul>
        </div>
        <?php
    }

    /**
     * NOVO: Renderiza schedule existente
     */
    private function renderExistingSchedule($schedule): void
    {
        $statusLabels = [
            'draft' => ['label' => 'Rascunho', 'color' => '#6c757d'],
            'allocated' => ['label' => 'Alocado', 'color' => '#0969da'],
            'in_progress' => ['label' => 'Em Progresso', 'color' => '#bf8700'],
            'completed' => ['label' => 'Concluído', 'color' => '#1a7f37'],
            'cancelled' => ['label' => 'Cancelado', 'color' => '#d1242f'],
        ];

        $status = $statusLabels[$schedule['status']] ?? ['label' => $schedule['status'], 'color' => '#6c757d'];

        ?>
        <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin-bottom:20px">
            <strong>✅ Agendamento criado!</strong>
        </div>

        <table class="form-table">
            <tr>
                <th>Schedule UUID:</th>
                <td><code><?php echo esc_html($schedule['uuid']); ?></code></td>
            </tr>
            <tr>
                <th>Status:</th>
                <td>
                    <span style="background:<?php echo esc_attr($status['color']); ?>;color:#fff;padding:4px 12px;border-radius:3px;font-weight:600">
                        <?php echo esc_html($status['label']); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Horário Solicitado:</th>
                <td><strong><?php echo esc_html(date('d/m/Y H:i', strtotime($schedule['requested_time']))); ?></strong></td>
            </tr>
            <tr>
                <th>Janela Válida:</th>
                <td>
                    <?php echo esc_html(date('H:i', strtotime($schedule['window_start']))); ?> -
                    <?php echo esc_html(date('H:i', strtotime($schedule['window_end']))); ?>
                    <small>(±1h de tolerância)</small>
                </td>
            </tr>
            <tr>
                <th>Duração Estimada:</th>
                <td><?php echo esc_html($schedule['estimated_duration_minutes']); ?> minutos</td>
            </tr>
            <tr>
                <th>Profissionais Necessários:</th>
                <td><?php echo esc_html($schedule['required_professionals']); ?></td>
            </tr>
        </table>

        <!-- Profissionais Alocados -->
        <h3 style="margin-top:30px">👥 Profissionais Alocados</h3>
        <?php
        $allocations = $this->getProfessionalAllocations($schedule['uuid']);

        if (empty($allocations)): ?>
            <p><em>Nenhum profissional alocado ainda.</em></p>
        <?php else: ?>
            <table class="wp-list-table widefat striped" style="margin-top:10px">
                <thead>
                    <tr>
                        <th>Profissional</th>
                        <th>Score</th>
                        <th>Horário Alocado</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allocations as $allocation): ?>
                    <tr>
                        <td><strong><?php echo esc_html($allocation['professional_name']); ?></strong></td>
                        <td>
                            <?php if ($allocation['allocation_score']): ?>
                                <span style="background:#0969da;color:#fff;padding:2px 8px;border-radius:3px;font-weight:600">
                                    <?php echo esc_html(number_format($allocation['allocation_score'], 1)); ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html(date('H:i', strtotime($allocation['allocated_start']))); ?> -
                            <?php echo esc_html(date('H:i', strtotime($allocation['allocated_end']))); ?>
                        </td>
                        <td><?php echo esc_html($allocation['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- SLA Violation -->
        <?php if ($schedule['sla_violation']): ?>
            <div style="background:#f8d7da;border-left:4px solid #d32f2f;padding:15px;margin-top:20px">
                <strong>⚠️ Violação de SLA Detectada</strong>
                <pre><?php echo esc_html(print_r(json_decode($schedule['sla_violation'], true), true)); ?></pre>
            </div>
        <?php endif; ?>

        <p style="margin-top:20px">
            <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-schedules&schedule=' . urlencode($schedule['uuid']))); ?>" class="button">
                Ver Detalhes Completos do Schedule
            </a>
        </p>
        <?php
    }

    /**
     * NOVO: Handler para ação de agendamento
     */
    public function handleSchedulingAction(): void
    {
        check_admin_referer('schedule_briefing');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }

        $briefingUuid = isset($_POST['briefing_uuid']) ? sanitize_text_field($_POST['briefing_uuid']) : '';
        $requestedDate = isset($_POST['requested_date']) ? sanitize_text_field($_POST['requested_date']) : '';
        $requestedTime = isset($_POST['requested_time']) ? sanitize_text_field($_POST['requested_time']) : '';

        if (empty($briefingUuid) || empty($requestedDate) || empty($requestedTime)) {
            wp_die('Dados inválidos.');
        }

        $briefing = $this->briefingRepository->findByUuid($briefingUuid);

        if (!$briefing) {
            wp_die('Briefing não encontrado.');
        }

        try {
            // Criar ServiceLocation a partir do briefing
            $location = $this->createServiceLocationFromBriefing($briefing);

            // Criar timestamp do horário solicitado
            $requestedDateTime = new \DateTimeImmutable($requestedDate . ' ' . $requestedTime);

            // Criar Schedule via Use Case
            $createScheduleUseCase = new CreateSchedule($this->scheduleRepository);

            $result = $createScheduleUseCase->execute(
                'order_' . $briefing->getUuid(), // orderUuid (temporário - TODO: usar order real)
                $briefing->getId(),
                $requestedDateTime,
                $location
            );

            if (!$result['success']) {
                wp_die('Erro ao criar schedule: ' . ($result['error'] ?? 'Erro desconhecido'));
            }

            $scheduleUuid = $result['schedule_uuid'];

            // Calcular complexidade
            $complexity = ServiceComplexity::fromMetrics(
                $briefing->getMetrics()->getM2(),
                $briefing->getMetrics()->getTotalMinutes(),
                [] // TODO: passar packages reais
            );

            // Alocar profissionais via Use Case
            $allocateUseCase = new AllocateProfessional(
                $this->scheduleRepository,
                $this->professionalRepository,
                new \LimpVix\Application\Services\Scheduling\AllocationEngine(
                    $this->professionalRepository
                )
            );

            $allocationResult = $allocateUseCase->execute($scheduleUuid, $complexity);

            if (!$allocationResult['success']) {
                wp_die('Erro ao alocar profissionais: ' . ($allocationResult['error'] ?? 'Erro desconhecido'));
            }

            // Sucesso - redirecionar de volta
            wp_redirect(add_query_arg([
                'page' => 'limpvix-briefing-detail',
                'uuid' => $briefingUuid,
                'scheduled' => '1'
            ], admin_url('admin.php')));
            exit;

        } catch (\Exception $e) {
            wp_die('Erro ao agendar: ' . $e->getMessage());
        }
    }

    /**
     * NOVO: Busca schedule existente para um briefing
     */
    private function getExistingSchedule(string $briefingUuid): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_schedules';

        $schedule = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE briefing_id = (SELECT id FROM {$wpdb->prefix}limpvix_briefings WHERE uuid = %s) ORDER BY created_at DESC LIMIT 1",
                $briefingUuid
            ),
            ARRAY_A
        );

        return $schedule ?: null;
    }

    /**
     * NOVO: Busca alocações de profissionais
     */
    private function getProfessionalAllocations(string $scheduleUuid): array
    {
        global $wpdb;
        $allocationsTable = $wpdb->prefix . 'limpvix_professional_allocations';
        $staffTable = $wpdb->prefix . 'bkntc_staff';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*, s.name as professional_name
                FROM {$allocationsTable} a
                LEFT JOIN {$staffTable} s ON a.professional_id = s.id
                WHERE a.schedule_uuid = %s
                ORDER BY a.allocation_score DESC",
                $scheduleUuid
            ),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * NOVO: Cria ServiceLocation a partir do Briefing
     */
    private function createServiceLocationFromBriefing($briefing): ServiceLocation
    {
        // Coordenadas padrão (Vitória/ES)
        $coordinates = GeoCoordinates::fromLatLong(-20.3155, -40.3128);

        // TODO: Obter dados reais de localização do briefing
        return ServiceLocation::create(
            'Rua Exemplo',
            '123',
            'Centro',
            'Vitória',
            'ES',
            '29000-000',
            $coordinates
        );
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
