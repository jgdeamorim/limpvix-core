<?php
/**
 * LimpVixSettingsPage - Configurações Centrais do LimpVix Core
 *
 * RESPONSABILIDADE:
 * - Menu principal: LimpVix (position 3, abaixo de Dashboard)
 * - Sistema de abas: Conexões | Briefing | Scheduling
 * - Aba Conexões: Firebase, Google Meu Negócio, NVoip OTP, 360Dialog
 * - Aba Briefing: Gerenciador Profissional Completo
 * - Aba Scheduling: Configurações de agendamento e profissionais
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Infrastructure\Persistence\WpBriefingRepository;
use LimpVix\Infrastructure\Persistence\WpScheduleRepository;
use LimpVix\Domain\Briefing\BriefingStatus;

defined('ABSPATH') || exit;

class LimpVixSettingsPage
{
    private const PAGE_SLUG = 'limpvix';
    private const OPTION_GROUP_CONNECTIONS = 'limpvix_connections';
    private const OPTION_GROUP_BRIEFING = 'limpvix_briefing_settings';
    private const OPTION_GROUP_SCHEDULING = 'limpvix_scheduling_settings';

    private $briefingRepository;
    private $scheduleRepository;

    public function __construct()
    {
        $this->briefingRepository = new WpBriefingRepository();
        $this->scheduleRepository = new WpScheduleRepository();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_limpvix_get_briefing_details', [$this, 'ajaxGetBriefingDetails']);
    }

    public function addMenu(): void
    {
        // Menu principal LimpVix (abaixo de Dashboard)
        add_menu_page(
            'LimpVix Core',
            'LimpVix',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-admin-generic',
            3
        );

        // Submenu Configurações (mesmo slug do menu principal)
        add_submenu_page(
            self::PAGE_SLUG,
            'Configurações LimpVix',
            'Configurações',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function registerSettings(): void
    {
        // Aba Conexões
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_firebase_project_id');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_firebase_api_key');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_firebase_auth_domain');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_google_mybusiness_api_key');
        // Sprint 9: NVoip OTP (substitui Twilio)
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_nvoip_api_key');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_nvoip_user_token');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_nvoip_default_number');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_nvoip_enable_otp');

        // Aba Briefing
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_m2_table');
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_time_factors');
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_buffer_minutes');
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_price_per_m2');

        // Aba Scheduling
        register_setting(self::OPTION_GROUP_SCHEDULING, 'limpvix_scheduling_geofence_radius');
        register_setting(self::OPTION_GROUP_SCHEDULING, 'limpvix_scheduling_time_tolerance');

        // Aba Verificação
        register_setting('limpvix_verificacao', 'limpvix_ppid_api_key');
        register_setting('limpvix_verificacao', 'limpvix_ppid_api_secret');
        register_setting('limpvix_verificacao', 'limpvix_ppid_endpoint');
        register_setting('limpvix_verificacao', 'limpvix_exato_api_key');
        register_setting('limpvix_verificacao', 'limpvix_exato_token');
        register_setting('limpvix_verificacao', 'limpvix_exato_endpoint');
        register_setting('limpvix_verificacao', 'limpvix_policy_review_categories');
    }

    public function enqueueAssets($hook): void
    {
        if ($hook !== 'toplevel_page_limpvix') {
            return;
        }

        // Enqueue Thickbox para modais
        add_thickbox();

        wp_enqueue_style('limpvix-settings', LIMPVIX_PLUGIN_URL . 'assets/css/admin-settings.css', [], LIMPVIX_VERSION);
        wp_enqueue_script('limpvix-settings', LIMPVIX_PLUGIN_URL . 'assets/js/admin-settings.js', ['jquery'], LIMPVIX_VERSION, true);

        wp_localize_script('limpvix-settings', 'limpvixSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('limpvix_settings'),
        ]);
    }

    public function render(): void
    {
        // Determinar aba ativa
        $activeTab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'connections';

        // Processar ações
        if (isset($_POST['action'])) {
            $this->handleActions($activeTab);
        }

        // Processar salvamento
        if (isset($_POST['submit']) && check_admin_referer('limpvix_settings_' . $activeTab)) {
            $this->handleSave($activeTab);
        }

        ?>
        <div class="wrap limpvix-settings-wrap">
            <h1>⚙️ Configurações LimpVix Core</h1>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ Configurações salvas com sucesso!</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ Briefing deletado com sucesso!</p>
                </div>
            <?php endif; ?>

            <!-- Sistema de Abas -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=connections"
                   class="nav-tab <?php echo $activeTab === 'connections' ? 'nav-tab-active' : ''; ?>">
                    🔌 Conexões
                </a>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=briefing"
                   class="nav-tab <?php echo $activeTab === 'briefing' ? 'nav-tab-active' : ''; ?>">
                    📋 Briefing Manager
                </a>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=scheduling"
                   class="nav-tab <?php echo $activeTab === 'scheduling' ? 'nav-tab-active' : ''; ?>">
                    📅 Scheduling
                </a>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=fluxos-operacionais"
                   class="nav-tab <?php echo $activeTab === 'fluxos-operacionais' ? 'nav-tab-active' : ''; ?>">
                    🔄 Fluxos Operacionais
                </a>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=verificacao"
                   class="nav-tab <?php echo $activeTab === 'verificacao' ? 'nav-tab-active' : ''; ?>">
                    🛡️ Verificação
                </a>
            </h2>

            <?php if ($activeTab === 'connections'): ?>
                <form method="post" action="">
                    <?php wp_nonce_field('limpvix_settings_' . $activeTab); ?>
                    <?php $this->renderConnectionsTab(); ?>
                    <p class="submit">
                        <button type="submit" name="submit" class="button button-primary button-large">
                            💾 Salvar Configurações
                        </button>
                    </p>
                </form>

            <?php elseif ($activeTab === 'briefing'): ?>
                <?php $this->renderBriefingManagerTab(); ?>

            <?php elseif ($activeTab === 'scheduling'): ?>
                <form method="post" action="">
                    <?php wp_nonce_field('limpvix_settings_' . $activeTab); ?>
                    <?php $this->renderSchedulingTab(); ?>
                    <p class="submit">
                        <button type="submit" name="submit" class="button button-primary button-large">
                            💾 Salvar Configurações
                        </button>
                    </p>
                </form>

            <?php elseif ($activeTab === 'fluxos-operacionais'): ?>
                <?php $this->renderFluxosOperacionaisTab(); ?>

            <?php elseif ($activeTab === 'verificacao'): ?>
                <form method="post" action="">
                    <?php wp_nonce_field('limpvix_settings_' . $activeTab); ?>
                    <?php $this->renderVerificacaoTab(); ?>
                    <p class="submit">
                        <button type="submit" name="submit" class="button button-primary button-large">
                            💾 Salvar Credenciais
                        </button>
                    </p>
                </form>
            <?php endif; ?>
        </div>

        <style>
            .limpvix-settings-wrap .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .limpvix-settings-wrap .stat-card {
                background: #fff;
                border-left: 4px solid #2271b1;
                padding: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .limpvix-settings-wrap .stat-card.draft { border-left-color: #d63638; }
            .limpvix-settings-wrap .stat-card.pending { border-left-color: #dba617; }
            .limpvix-settings-wrap .stat-card.locked { border-left-color: #00a32a; }
            .limpvix-settings-wrap .stat-card.scheduled { border-left-color: #2271b1; }
            .limpvix-settings-wrap .stat-value {
                font-size: 36px;
                font-weight: 700;
                line-height: 1;
                margin-bottom: 8px;
            }
            .limpvix-settings-wrap .stat-label {
                font-size: 13px;
                color: #646970;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .limpvix-settings-wrap .briefing-table-container {
                background: #fff;
                border: 1px solid #ccd0d4;
                margin: 20px 0;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .limpvix-settings-wrap .briefing-filters {
                padding: 15px;
                background: #f6f7f7;
                border-bottom: 1px solid #ccd0d4;
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                align-items: center;
            }
            .limpvix-settings-wrap .status-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .limpvix-settings-wrap .status-badge.draft { background: #f0f0f1; color: #50575e; }
            .limpvix-settings-wrap .status-badge.pending_validation { background: #fcf9e8; color: #94660c; }
            .limpvix-settings-wrap .status-badge.locked { background: #d5f4e6; color: #1e4620; }
            .limpvix-settings-wrap .status-badge.scheduled { background: #eaf3ff; color: #0a4b78; }
            .limpvix-settings-wrap .status-badge.completed { background: #d5f4e6; color: #1e4620; }
        </style>
        <?php
    }

    private function renderBriefingManagerTab(): void
    {
        // Obter filtros
        $filterStatus = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
        $filterPropertyType = isset($_GET['filter_property_type']) ? sanitize_text_field($_GET['filter_property_type']) : '';
        $searchTerm = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // Buscar briefings
        $briefings = $this->briefingRepository->findAll();

        // Aplicar filtros
        if ($filterStatus) {
            $briefings = array_filter($briefings, function($b) use ($filterStatus) {
                return $b->getStatus()->getValue() === $filterStatus;
            });
        }
        if ($filterPropertyType) {
            $briefings = array_filter($briefings, function($b) use ($filterPropertyType) {
                return $b->getPropertyType() === $filterPropertyType;
            });
        }
        if ($searchTerm) {
            $briefings = array_filter($briefings, function($b) use ($searchTerm) {
                return stripos($b->getUuid(), $searchTerm) !== false ||
                       stripos($b->getUserId(), $searchTerm) !== false;
            });
        }

        // Calcular estatísticas
        $stats = [
            'total' => count($this->briefingRepository->findAll()),
            'draft' => count(array_filter($this->briefingRepository->findAll(), fn($b) => $b->getStatus()->getValue() === 'draft')),
            'pending' => count(array_filter($this->briefingRepository->findAll(), fn($b) => $b->getStatus()->getValue() === 'pending_validation')),
            'locked' => count(array_filter($this->briefingRepository->findAll(), fn($b) => $b->getStatus()->getValue() === 'locked')),
            'scheduled' => count($this->scheduleRepository->findAll()),
        ];

        ?>
        <!-- Dashboard de Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total de Briefings</div>
            </div>
            <div class="stat-card draft">
                <div class="stat-value"><?php echo $stats['draft']; ?></div>
                <div class="stat-label">Em Rascunho</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value"><?php echo $stats['pending']; ?></div>
                <div class="stat-label">Pendentes</div>
            </div>
            <div class="stat-card locked">
                <div class="stat-value"><?php echo $stats['locked']; ?></div>
                <div class="stat-label">Travados (Prontos)</div>
            </div>
            <div class="stat-card scheduled">
                <div class="stat-value"><?php echo $stats['scheduled']; ?></div>
                <div class="stat-label">Agendados</div>
            </div>
        </div>

        <!-- Tabela de Briefings -->
        <div class="briefing-table-container">
            <!-- Filtros -->
            <div class="briefing-filters">
                <form method="get" style="display: flex; gap: 10px; flex-wrap: wrap; flex: 1;">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                    <input type="hidden" name="tab" value="briefing">

                    <input type="search"
                           name="s"
                           value="<?php echo esc_attr($searchTerm); ?>"
                           placeholder="Buscar por UUID ou Usuário..."
                           style="min-width: 250px;">

                    <select name="filter_status">
                        <option value="">Todos os Status</option>
                        <option value="draft" <?php selected($filterStatus, 'draft'); ?>>Rascunho</option>
                        <option value="pending_validation" <?php selected($filterStatus, 'pending_validation'); ?>>Pendente</option>
                        <option value="locked" <?php selected($filterStatus, 'locked'); ?>>Travado</option>
                        <option value="completed" <?php selected($filterStatus, 'completed'); ?>>Concluído</option>
                    </select>

                    <select name="filter_property_type">
                        <option value="">Todos os Tipos</option>
                        <option value="residential" <?php selected($filterPropertyType, 'residential'); ?>>Residencial</option>
                        <option value="commercial" <?php selected($filterPropertyType, 'commercial'); ?>>Comercial</option>
                    </select>

                    <button type="submit" class="button">🔍 Filtrar</button>
                    <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=briefing" class="button">🔄 Limpar</a>
                </form>

                <a href="<?php echo home_url('/briefing'); ?>" class="button button-primary" target="_blank">
                    ➕ Novo Briefing
                </a>
            </div>

            <!-- Tabela -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 10%;">UUID</th>
                        <th style="width: 10%;">Usuário</th>
                        <th style="width: 10%;">Tipo</th>
                        <th style="width: 10%;">Status</th>
                        <th style="width: 8%;">M²</th>
                        <th style="width: 8%;">Duração</th>
                        <th style="width: 10%;">Criado</th>
                        <th style="width: 12%;">Schedule</th>
                        <th style="width: 22%;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($briefings)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                <p style="margin: 0; color: #646970;">Nenhum briefing encontrado.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($briefings as $briefing): ?>
                            <?php
                            $uuid = $briefing->getUuid();
                            $schedule = $this->scheduleRepository->findByOrderUuid($uuid);
                            $hasSchedule = $schedule !== null;
                            ?>
                            <tr>
                                <td>
                                    <code title="<?php echo esc_attr($uuid); ?>">
                                        <?php echo esc_html(substr($uuid, 0, 8)); ?>...
                                    </code>
                                </td>
                                <td><?php echo esc_html($briefing->getUserId()); ?></td>
                                <td><?php echo $briefing->getPropertyType() === 'residential' ? '🏠 Res.' : '🏢 Com.'; ?></td>
                                <td>
                                    <?php
                                    $status = $briefing->getStatus()->getValue();
                                    $statusLabels = [
                                        'draft' => 'Rascunho',
                                        'pending_validation' => 'Pendente',
                                        'locked' => 'Travado',
                                        'completed' => 'Concluído',
                                    ];
                                    ?>
                                    <span class="status-badge <?php echo esc_attr($status); ?>">
                                        <?php echo esc_html($statusLabels[$status] ?? $status); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($briefing->getEstimatedM2(), 0); ?> m²</td>
                                <td><?php echo round($briefing->getEstimatedDuration() / 60, 1); ?>h</td>
                                <td><?php echo $briefing->getCreatedAt()->format('d/m/Y H:i'); ?></td>
                                <td>
                                    <?php if ($hasSchedule): ?>
                                        <?php
                                        $scheduleStatus = $schedule['status'] ?? 'unknown';
                                        $scheduleLabels = [
                                            'draft' => '📝 Draft',
                                            'allocated' => '✅ Alocado',
                                            'in_progress' => '⏳ Em Andamento',
                                            'completed' => '✔️ Concluído',
                                        ];
                                        ?>
                                        <span title="Schedule UUID: <?php echo esc_attr($schedule['uuid'] ?? ''); ?>">
                                            <?php echo esc_html($scheduleLabels[$scheduleStatus] ?? $scheduleStatus); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #646970;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button"
                                            class="button button-small limpvix-view-briefing"
                                            data-briefing-uuid="<?php echo esc_attr($uuid); ?>"
                                            data-briefing-id="<?php echo esc_attr($briefing->getId()); ?>">
                                        👁️ Ver
                                    </button>

                                    <?php if ($briefing->getStatus()->getValue() === 'locked' && !$hasSchedule): ?>
                                        <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=briefing&action=create_schedule&uuid=<?php echo esc_attr($uuid); ?>"
                                           class="button button-small button-primary">
                                            📅 Agendar
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($briefing->getStatus()->getValue() === 'draft'): ?>
                                        <form method="post" style="display: inline-block;" onsubmit="return confirm('Deletar este briefing?');">
                                            <?php wp_nonce_field('limpvix_delete_briefing_' . $uuid); ?>
                                            <input type="hidden" name="action" value="delete_briefing">
                                            <input type="hidden" name="uuid" value="<?php echo esc_attr($uuid); ?>">
                                            <button type="submit" class="button button-small button-link-delete">
                                                🗑️ Deletar
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Configurações de Briefing -->
        <div style="margin-top: 40px;">
            <h2 style="border-top: 1px solid #ccd0d4; padding-top: 20px;">⚙️ Configurações de Cálculo</h2>
            <form method="post" action="">
                <?php wp_nonce_field('limpvix_settings_briefing'); ?>
                <?php $this->renderBriefingConfigSection(); ?>
                <p class="submit">
                    <button type="submit" name="submit" class="button button-primary button-large">
                        💾 Salvar Configurações
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    private function renderBriefingConfigSection(): void
    {
        $m2Table = $this->getM2Table();
        $timeFactors = $this->getTimeFactors();
        $bufferMinutes = get_option('limpvix_briefing_buffer_minutes', 30);
        $pricePerM2 = get_option('limpvix_briefing_price_per_m2', 15.00);
        ?>

        <!-- Tabela m² -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h3>📏 Tabela de m² por Cômodo</h3>
            <p>Valores usados para cálculo de área estimada do briefing:</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Quarto:</th>
                    <td><input type="number" name="m2_bedroom" value="<?php echo esc_attr($m2Table['bedroom']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
                <tr>
                    <th scope="row">Banheiro:</th>
                    <td><input type="number" name="m2_bathroom" value="<?php echo esc_attr($m2Table['bathroom']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
                <tr>
                    <th scope="row">Sala:</th>
                    <td><input type="number" name="m2_living_room" value="<?php echo esc_attr($m2Table['living_room']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
                <tr>
                    <th scope="row">Cozinha:</th>
                    <td><input type="number" name="m2_kitchen" value="<?php echo esc_attr($m2Table['kitchen']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
                <tr>
                    <th scope="row">Escritório:</th>
                    <td><input type="number" name="m2_office" value="<?php echo esc_attr($m2Table['office']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
                <tr>
                    <th scope="row">Área Externa:</th>
                    <td><input type="number" name="m2_external_area" value="<?php echo esc_attr($m2Table['external_area']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
            </table>
        </div>

        <!-- Fatores de Tempo -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h3>⏱️ Fatores de Tempo por Tipo de Limpeza</h3>
            <p>Multiplicadores aplicados ao tempo base (ex: 0.40 = +40% de tempo):</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Limpeza Pesada:</th>
                    <td>
                        <input type="number" name="time_factor_limpeza_pesada" value="<?php echo esc_attr($timeFactors['limpeza_pesada']); ?>" step="0.01" class="small-text">
                        <span class="description">(+40% padrão)</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Pós-Obra:</th>
                    <td>
                        <input type="number" name="time_factor_pos_obra" value="<?php echo esc_attr($timeFactors['pos_obra']); ?>" step="0.01" class="small-text">
                        <span class="description">(+70% padrão)</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Pré-Mudança:</th>
                    <td>
                        <input type="number" name="time_factor_pre_mudanca" value="<?php echo esc_attr($timeFactors['pre_mudanca']); ?>" step="0.01" class="small-text">
                        <span class="description">(+30% padrão)</span>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Outros Parâmetros -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h3>⚙️ Outros Parâmetros</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Buffer Operacional:</th>
                    <td>
                        <input type="number" name="buffer_minutes" value="<?php echo esc_attr($bufferMinutes); ?>" class="small-text"> minutos
                        <p class="description">Tempo adicional para deslocamento e preparação</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Preço por m²:</th>
                    <td>
                        R$ <input type="number" name="price_per_m2" value="<?php echo esc_attr($pricePerM2); ?>" step="0.01" class="small-text">
                        <p class="description">Valor base para cálculo de preço</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Modal para Ver Briefing -->
        <div id="limpvix-briefing-modal" style="display:none;">
            <div id="limpvix-briefing-modal-content">
                <div class="limpvix-modal-loading">Carregando briefing...</div>
            </div>
        </div>

        <style>
            #TB_ajaxContent {
                width: 95% !important;
                height: 90% !important;
                padding: 20px;
                overflow-y: auto;
            }
            .limpvix-modal-loading {
                text-align: center;
                padding: 40px;
                font-size: 16px;
                color: #646970;
            }
            .limpvix-modal-error {
                background: #f8d7da;
                border: 1px solid #f5c2c7;
                color: #842029;
                padding: 15px;
                border-radius: 4px;
                margin: 20px 0;
            }
        </style>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Interceptar clique no botão "Ver"
            $('.limpvix-view-briefing').on('click', function(e) {
                e.preventDefault();

                var uuid = $(this).data('briefing-uuid');
                var briefingId = $(this).data('briefing-id');

                // Abrir modal do WordPress (ThickBox)
                var modalUrl = '#TB_inline?width=900&height=600&inlineId=limpvix-briefing-modal';
                tb_show('Detalhes do Briefing #' + briefingId, modalUrl);

                // Resetar conteúdo do modal
                $('#limpvix-briefing-modal-content').html('<div class="limpvix-modal-loading">Carregando briefing...</div>');

                // Carregar conteúdo via AJAX
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'limpvix_get_briefing_details',
                        uuid: uuid,
                        nonce: '<?php echo wp_create_nonce('limpvix_view_briefing'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#limpvix-briefing-modal-content').html(response.data.html);
                        } else {
                            $('#limpvix-briefing-modal-content').html(
                                '<div class="limpvix-modal-error">Erro ao carregar briefing: ' +
                                (response.data.message || 'Erro desconhecido') +
                                '</div>'
                            );
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#limpvix-briefing-modal-content').html(
                            '<div class="limpvix-modal-error">Erro de comunicação: ' + error + '</div>'
                        );
                    }
                });
            });
        });
        </script>
        <?php
    }

    private function renderConnectionsTab(): void
    {
        $firebaseProjectId = get_option('limpvix_firebase_project_id', '');
        $firebaseApiKey = get_option('limpvix_firebase_api_key', '');
        $firebaseAuthDomain = get_option('limpvix_firebase_auth_domain', '');
        $googleApiKey = get_option('limpvix_google_mybusiness_api_key', '');
        $nvoipApiKey = get_option('limpvix_nvoip_api_key', '');
        $nvoipUserToken = get_option('limpvix_nvoip_user_token', '');
        $nvoipDefaultNumber = get_option('limpvix_nvoip_default_number', '+552720183484');
        $nvoipEnableOtp = get_option('limpvix_nvoip_enable_otp', '1');
        ?>

        <!-- Firebase Authentication -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>🔥 Firebase Authentication (SMS OTP)</h2>
            <p>Configure as credenciais do Firebase para verificação de telefone via SMS:</p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="firebase_project_id">Project ID *</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_project_id"
                               name="firebase_project_id"
                               value="<?php echo esc_attr($firebaseProjectId); ?>"
                               class="regular-text"
                               placeholder="my-project-123456">
                        <p class="description">
                            ID do projeto Firebase (encontrado no Console Firebase)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="firebase_api_key">API Key</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_api_key"
                               name="firebase_api_key"
                               value="<?php echo esc_attr($firebaseApiKey); ?>"
                               class="regular-text"
                               placeholder="AIzaSy...">
                        <p class="description">
                            Web API Key (encontrado em Project Settings → General)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="firebase_auth_domain">Auth Domain</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_auth_domain"
                               name="firebase_auth_domain"
                               value="<?php echo esc_attr($firebaseAuthDomain); ?>"
                               class="regular-text"
                               placeholder="my-project-123456.firebaseapp.com">
                        <p class="description">
                            Domínio de autenticação (geralmente: {projectId}.firebaseapp.com)
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($firebaseProjectId)): ?>
                <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin-top:15px">
                    ✅ <strong>Firebase configurado!</strong> Project ID: <code><?php echo esc_html($firebaseProjectId); ?></code>
                </div>
            <?php else: ?>
                <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin-top:15px">
                    ⚠️ <strong>Firebase não configurado.</strong> O módulo Briefing funcionará, mas a verificação de telefone não estará disponível.
                </div>
            <?php endif; ?>
        </div>

        <!-- Google Meu Negócio -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>🏢 Google Meu Negócio</h2>
            <p>Integração com Google My Business para gestão de avaliações e postagens:</p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="google_mybusiness_api_key">API Key</label>
                    </th>
                    <td>
                        <input type="text"
                               id="google_mybusiness_api_key"
                               name="google_mybusiness_api_key"
                               value="<?php echo esc_attr($googleApiKey); ?>"
                               class="regular-text"
                               placeholder="AIza...">
                        <p class="description">
                            Google Cloud API Key com Google My Business API ativada
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($googleApiKey)): ?>
                <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin-top:15px">
                    ✅ <strong>Google Meu Negócio configurado!</strong>
                </div>
            <?php else: ?>
                <div style="background:#f8f9fa;border-left:4px solid #6c757d;padding:15px;margin-top:15px">
                    ℹ️ <strong>Opcional.</strong> Configure quando precisar da integração.
                </div>
            <?php endif; ?>
        </div>

        <!-- NVoip OTP (Sprint 9) -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>📱 NVoip OTP - Verificação de Telefone (Sprint 9)</h2>
            <p style="background:#e7f3ff;border-left:4px solid #2196F3;padding:12px;margin:10px 0">
                <strong>🔐 OBRIGATÓRIO:</strong> Verificação de telefone via OTP (WhatsApp → SMS fallback) é REQUERIDA para:
                <ul style="margin:8px 0 0 20px">
                    <li>✅ Profissionais aceitarem offers</li>
                    <li>✅ Clientes criarem contratos e briefings</li>
                    <li>✅ Envio de offers para profissionais</li>
                </ul>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="nvoip_api_key">API Key *</label>
                    </th>
                    <td>
                        <input type="text"
                               id="nvoip_api_key"
                               name="nvoip_api_key"
                               value="<?php echo esc_attr($nvoipApiKey); ?>"
                               class="regular-text"
                               placeholder="sua-api-key"
                               required>
                        <p class="description">
                            API Key fornecida pela NVoip (<a href="https://nvoip.com.br" target="_blank">Obter credenciais</a>)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="nvoip_user_token">User Token *</label>
                    </th>
                    <td>
                        <input type="password"
                               id="nvoip_user_token"
                               name="nvoip_user_token"
                               value="<?php echo esc_attr($nvoipUserToken); ?>"
                               class="regular-text"
                               placeholder="seu-token"
                               required>
                        <p class="description">
                            Token de autenticação (mantenha secreto)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="nvoip_default_number">Número Remetente</label>
                    </th>
                    <td>
                        <input type="text"
                               id="nvoip_default_number"
                               name="nvoip_default_number"
                               value="<?php echo esc_attr($nvoipDefaultNumber); ?>"
                               class="regular-text"
                               placeholder="+552720183484">
                        <p class="description">
                            Número registrado na NVoip (formato: +55DDXXXXXXXXX)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="nvoip_enable_otp">Ativar OTP</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   id="nvoip_enable_otp"
                                   name="nvoip_enable_otp"
                                   value="1"
                                   <?php checked($nvoipEnableOtp, '1'); ?>>
                            <strong>Habilitar verificação de telefone via OTP</strong>
                        </label>
                        <p class="description">
                            <strong style="color:#d32f2f">⚠️ IMPORTANTE:</strong> Desabilitar OTP bloqueia ações críticas da plataforma!
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($nvoipApiKey) && !empty($nvoipUserToken)): ?>
                <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin-top:15px">
                    ✅ <strong>NVoip configurado!</strong> OTP via WhatsApp/SMS está <?php echo $nvoipEnableOtp === '1' ? '<strong style="color:#28a745">ATIVO</strong>' : '<strong style="color:#dc3545">INATIVO</strong>'; ?>
                    <br>
                    📍 <strong>Endpoints:</strong>
                    <ul style="margin:8px 0 0 0">
                        <li><code>POST /limpvix/v1/auth/otp/send</code> - Enviar código</li>
                        <li><code>POST /limpvix/v1/auth/otp/verify</code> - Verificar código</li>
                    </ul>
                </div>
            <?php else: ?>
                <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin-top:15px">
                    ⚠️ <strong>NVoip NÃO configurado.</strong>
                    <br>
                    🔒 <strong>Impacto:</strong> Usuários NÃO poderão realizar ações críticas (aceitar offers, criar contratos, criar briefings).
                    <br>
                    📚 <strong>Docs:</strong> <a href="https://nvoip.docs.apiary.io" target="_blank">NVoip API Documentation</a>
                </div>
            <?php endif; ?>
        </div>



        <!-- System Info -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>ℹ️ Informações do Sistema</h2>
            <table class="form-table">
                <tr>
                    <th>Plugin Version:</th>
                    <td><code><?php echo LIMPVIX_VERSION; ?></code></td>
                </tr>
                <tr>
                    <th>WordPress Version:</th>
                    <td><code><?php echo get_bloginfo('version'); ?></code></td>
                </tr>
                <tr>
                    <th>PHP Version:</th>
                    <td><code><?php echo PHP_VERSION; ?></code></td>
                </tr>
                <tr>
                    <th>Feature Flags:</th>
                    <td>
                        <?php $this->renderFeatureFlags(); ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    private function renderSchedulingTab(): void
    {
        $geofenceRadius = get_option('limpvix_scheduling_geofence_radius', 150);
        $timeTolerance = get_option('limpvix_scheduling_time_tolerance', 60);
        ?>

        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>📍 Configurações de Geolocalização</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Raio de Geofence:</th>
                    <td>
                        <input type="number" name="geofence_radius" value="<?php echo esc_attr($geofenceRadius); ?>" class="small-text"> metros
                        <p class="description">Distância máxima permitida do endereço para check-in (padrão: 150m)</p>
                    </td>
                </tr>
            </table>
        </div>

        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>⏰ Configurações de Tempo</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Tolerância de Horário:</th>
                    <td>
                        <input type="number" name="time_tolerance" value="<?php echo esc_attr($timeTolerance); ?>" class="small-text"> minutos
                        <p class="description">Janela de tempo antes/depois do horário solicitado (padrão: 60min = ±1h)</p>
                    </td>
                </tr>
            </table>
        </div>

        <div style="background:#fff3cd;border-left:4px solid #dba617;padding:20px;margin:20px 0">
            <p style="margin:0">
                <strong>📊 Algoritmo de Alocação:</strong><br>
                As configurações de peso do algoritmo de alocação (proximidade 40%, disponibilidade 30%, rating 20%, carga 10%)
                estão definidas em <code>src/Infrastructure/Admin/Settings/SchedulingSettings.php</code>
            </p>
        </div>
        <?php
    }

    private function handleActions(string $tab): void
    {
        if ($tab !== 'briefing') {
            return;
        }

        $action = sanitize_text_field($_POST['action'] ?? '');

        // Deletar Briefing
        if ($action === 'delete_briefing' && isset($_POST['uuid'])) {
            $uuid = sanitize_text_field($_POST['uuid']);

            if (!check_admin_referer('limpvix_delete_briefing_' . $uuid)) {
                wp_die('Nonce inválido');
            }

            $briefing = $this->briefingRepository->findByUuid($uuid);
            if ($briefing && $briefing->getStatus()->getValue() === 'draft') {
                $this->briefingRepository->delete($uuid);
                wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=briefing&deleted=1'));
                exit;
            }
        }
    }

    private function handleSave(string $tab): void
    {
        if ($tab === 'connections') {
            update_option('limpvix_firebase_project_id', sanitize_text_field($_POST['firebase_project_id'] ?? ''));
            update_option('limpvix_firebase_api_key', sanitize_text_field($_POST['firebase_api_key'] ?? ''));
            update_option('limpvix_firebase_auth_domain', sanitize_text_field($_POST['firebase_auth_domain'] ?? ''));
            update_option('limpvix_google_mybusiness_api_key', sanitize_text_field($_POST['google_mybusiness_api_key'] ?? ''));
            update_option('limpvix_twilio_account_sid', sanitize_text_field($_POST['twilio_account_sid'] ?? ''));
            update_option('limpvix_twilio_auth_token', sanitize_text_field($_POST['twilio_auth_token'] ?? ''));
        } elseif ($tab === 'briefing') {
            // Salvar tabela m²
            $m2Table = [
                'bedroom' => (float) ($_POST['m2_bedroom'] ?? 12),
                'bathroom' => (float) ($_POST['m2_bathroom'] ?? 4),
                'living_room' => (float) ($_POST['m2_living_room'] ?? 20),
                'kitchen' => (float) ($_POST['m2_kitchen'] ?? 10),
                'office' => (float) ($_POST['m2_office'] ?? 10),
                'external_area' => (float) ($_POST['m2_external_area'] ?? 25)
            ];
            update_option('limpvix_briefing_m2_table', $m2Table);

            // Salvar fatores de tempo
            $timeFactors = [
                'limpeza_pesada' => (float) ($_POST['time_factor_limpeza_pesada'] ?? 0.40),
                'pos_obra' => (float) ($_POST['time_factor_pos_obra'] ?? 0.70),
                'pre_mudanca' => (float) ($_POST['time_factor_pre_mudanca'] ?? 0.30)
            ];
            update_option('limpvix_briefing_time_factors', $timeFactors);

            // Salvar buffer
            update_option('limpvix_briefing_buffer_minutes', (int) ($_POST['buffer_minutes'] ?? 30));

            // Salvar preço por m²
            update_option('limpvix_briefing_price_per_m2', (float) ($_POST['price_per_m2'] ?? 15.00));
        } elseif ($tab === 'scheduling') {
            update_option('limpvix_scheduling_geofence_radius', (int) ($_POST['geofence_radius'] ?? 150));
            update_option('limpvix_scheduling_time_tolerance', (int) ($_POST['time_tolerance'] ?? 60));
        } elseif ($tab === 'verificacao') {
            // PPID KYC Provider
            update_option('limpvix_ppid_api_key',      sanitize_text_field($_POST['ppid_api_key']      ?? ''));
            update_option('limpvix_ppid_api_secret',   sanitize_text_field($_POST['ppid_api_secret']   ?? ''));
            update_option('limpvix_ppid_endpoint',     esc_url_raw($_POST['ppid_endpoint']              ?? 'https://api.ppid.com.br/v1'));

            // Exato Digital Background Check Provider
            update_option('limpvix_exato_api_key',     sanitize_text_field($_POST['exato_api_key']     ?? ''));
            update_option('limpvix_exato_token',       sanitize_text_field($_POST['exato_token']       ?? ''));
            update_option('limpvix_exato_endpoint',    esc_url_raw($_POST['exato_endpoint']             ?? 'https://api.exatodigital.com.br/v1'));

            // Policy Engine
            $reviewCategories = array_map('sanitize_text_field', (array) ($_POST['policy_review_categories'] ?? []));
            update_option('limpvix_policy_review_categories', $reviewCategories);
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab . '&updated=1'));
        exit;
    }

    private function renderVerificacaoTab(): void
    {
        // Estado de conexão dos provedores
        $ppidConnected  = !empty(get_option('limpvix_ppid_api_key')) && !empty(get_option('limpvix_ppid_api_secret'));
        $exatoConnected = !empty(get_option('limpvix_exato_api_key')) && !empty(get_option('limpvix_exato_token'));

        $policyCategories = (array) get_option('limpvix_policy_review_categories', []);

        $allReviewCategories = [
            'FRAUD_RELEVANT'   => 'Fraude / Estelionato',
            'PROPERTY_CRIME'   => 'Crime contra patrimônio (furto, roubo)',
            'DRUG_OFFENSE'     => 'Tráfico / Uso de entorpecentes',
            'PUBLIC_DISORDER'  => 'Perturbação da ordem pública',
        ];

        ?>
        <div style="max-width:900px;">

            <!-- Status dos Provedores -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:28px;">
                <!-- PPID -->
                <div style="padding:16px 20px;background:<?php echo $ppidConnected ? '#f0fdf4' : '#fef9f1'; ?>;border:1px solid <?php echo $ppidConnected ? '#bbf7d0' : '#fed7aa'; ?>;border-radius:8px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                        <span style="font-size:20px;"><?php echo $ppidConnected ? '✅' : '🔴'; ?></span>
                        <strong style="font-size:15px;">PPID – KYC Provider</strong>
                    </div>
                    <p style="margin:0;font-size:13px;color:<?php echo $ppidConnected ? '#15803d' : '#c2410c'; ?>;">
                        <?php echo $ppidConnected ? 'Conectado — usando provider real' : 'Desconectado — usando MockKycProvider (modo teste)'; ?>
                    </p>
                </div>
                <!-- Exato -->
                <div style="padding:16px 20px;background:<?php echo $exatoConnected ? '#f0fdf4' : '#fef9f1'; ?>;border:1px solid <?php echo $exatoConnected ? '#bbf7d0' : '#fed7aa'; ?>;border-radius:8px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                        <span style="font-size:20px;"><?php echo $exatoConnected ? '✅' : '🔴'; ?></span>
                        <strong style="font-size:15px;">Exato Digital – Background Check</strong>
                    </div>
                    <p style="margin:0;font-size:13px;color:<?php echo $exatoConnected ? '#15803d' : '#c2410c'; ?>;">
                        <?php echo $exatoConnected ? 'Conectado — usando provider real' : 'Desconectado — usando MockBackgroundProvider (modo teste)'; ?>
                    </p>
                </div>
            </div>

            <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:12px 16px;margin-bottom:24px;font-size:13px;">
                <strong>ℹ️ Modo Teste:</strong> Enquanto as credenciais não estiverem configuradas, o sistema usa
                providers mock que aprovam automaticamente — perfeito para desenvolvimento e testes.
                Ao preencher as credenciais abaixo, o sistema ativa os providers reais sem nenhuma mudança de código.
            </div>

            <!-- Seção PPID -->
            <h2 style="margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
                🎯 PPID — KYC &amp; Verificação de Identidade
            </h2>
            <p style="color:#64748b;margin-bottom:16px;font-size:13px;">
                O PPID (Provedor de Prova de Identidade Digital) realiza verificação biométrica via documento + selfie.
                Ativado automaticamente ao preencher API Key e Secret abaixo.
            </p>
            <table class="form-table" role="presentation" style="margin-bottom:24px;">
                <tr>
                    <th scope="row"><label for="ppid_api_key">API Key</label></th>
                    <td>
                        <input type="password" id="ppid_api_key" name="ppid_api_key"
                               value="<?php echo esc_attr(get_option('limpvix_ppid_api_key', '')); ?>"
                               class="regular-text" autocomplete="off">
                        <p class="description">Chave de API fornecida pelo PPID (obrigatório para ativar)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppid_api_secret">API Secret</label></th>
                    <td>
                        <input type="password" id="ppid_api_secret" name="ppid_api_secret"
                               value="<?php echo esc_attr(get_option('limpvix_ppid_api_secret', '')); ?>"
                               class="regular-text" autocomplete="off">
                        <p class="description">Secret de API fornecido pelo PPID (obrigatório para ativar)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppid_endpoint">Endpoint</label></th>
                    <td>
                        <input type="url" id="ppid_endpoint" name="ppid_endpoint"
                               value="<?php echo esc_attr(get_option('limpvix_ppid_endpoint', 'https://api.ppid.com.br/v1')); ?>"
                               class="regular-text">
                        <p class="description">URL base da API PPID (não alterar sem orientação do suporte)</p>
                    </td>
                </tr>
            </table>

            <!-- Seção Exato Digital -->
            <h2 style="margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
                🔍 Exato Digital — Background Check
            </h2>
            <p style="color:#64748b;margin-bottom:16px;font-size:13px;">
                A Exato Digital realiza consultas de antecedentes criminais, processos e restrições.
                Ativado automaticamente ao preencher API Key e Token abaixo.
                Requer consentimento LGPD separado do profissional antes de cada consulta.
            </p>
            <table class="form-table" role="presentation" style="margin-bottom:24px;">
                <tr>
                    <th scope="row"><label for="exato_api_key">API Key</label></th>
                    <td>
                        <input type="password" id="exato_api_key" name="exato_api_key"
                               value="<?php echo esc_attr(get_option('limpvix_exato_api_key', '')); ?>"
                               class="regular-text" autocomplete="off">
                        <p class="description">API Key da Exato Digital (obrigatório para ativar)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="exato_token">Token</label></th>
                    <td>
                        <input type="password" id="exato_token" name="exato_token"
                               value="<?php echo esc_attr(get_option('limpvix_exato_token', '')); ?>"
                               class="regular-text" autocomplete="off">
                        <p class="description">Token de autenticação da Exato Digital (obrigatório para ativar)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="exato_endpoint">Endpoint</label></th>
                    <td>
                        <input type="url" id="exato_endpoint" name="exato_endpoint"
                               value="<?php echo esc_attr(get_option('limpvix_exato_endpoint', 'https://api.exatodigital.com.br/v1')); ?>"
                               class="regular-text">
                        <p class="description">URL base da API Exato Digital</p>
                    </td>
                </tr>
            </table>

            <!-- Seção Policy Engine -->
            <h2 style="margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
                ⚙️ Policy Engine — Regras de Elegibilidade
            </h2>
            <p style="color:#64748b;margin-bottom:16px;font-size:13px;">
                Configure quais categorias de antecedentes geram <strong>revisão manual</strong> (UNDER_REVIEW)
                ao invés de bloqueio automático. Crimes violentos e sexuais são sempre bloqueadores imutáveis
                independente desta configuração.
            </p>

            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;margin-bottom:16px;">
                <p style="margin:0 0 10px;font-weight:600;font-size:13px;color:#374151;">
                    🔴 Bloqueadores Imutáveis (sempre NOT_ELIGIBLE — não configurável):
                </p>
                <ul style="margin:0 0 16px;padding-left:20px;color:#6b7280;font-size:13px;">
                    <li>Crimes sexuais (SEXUAL_CRIME)</li>
                    <li>Crimes violentos com vítima (VIOLENT_CRIME)</li>
                </ul>

                <p style="margin:0 0 10px;font-weight:600;font-size:13px;color:#374151;">
                    🟡 Categorias configuráveis (marque as que devem gerar UNDER_REVIEW):
                </p>
                <?php foreach ($allReviewCategories as $value => $label): ?>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:13px;cursor:pointer;">
                        <input type="checkbox"
                               name="policy_review_categories[]"
                               value="<?php echo esc_attr($value); ?>"
                               <?php checked(in_array($value, $policyCategories, true)); ?>>
                        <strong><?php echo esc_html($value); ?></strong> — <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
                <p style="margin:8px 0 0;font-size:12px;color:#9ca3af;">
                    Categorias não marcadas → status APPROVED (aprovado com monitoramento).
                </p>
            </div>
        </div>
        <?php
    }

    private function renderFluxosOperacionaisTab(): void
    {
        // Dashboard de Status dos Fluxos Operacionais (Check-in, Check-out, Evidências, EPI)
        $fluxos = [
            [
                'name' => 'Check-in Básico',
                'status' => 'completo',
                'completude' => 100,
                'description' => 'Validação de geofence (150m) e time window (±60min)',
                'gaps' => []
            ],
            [
                'name' => 'Check-in com EPI',
                'status' => 'implementado',
                'completude' => 100,
                'description' => 'Validação de EPI video selfie obrigatório',
                'gaps' => []
            ],
            [
                'name' => 'Check-out Básico',
                'status' => 'completo',
                'completude' => 100,
                'description' => 'Check-out com validação de evidências obrigatórias',
                'gaps' => []
            ],
            [
                'name' => 'Evidências no Check-out',
                'status' => 'completo',
                'completude' => 100,
                'description' => 'Professional adiciona fotos/vídeos ao finalizar',
                'gaps' => []
            ],
            [
                'name' => 'Evidências Durante Execução',
                'status' => 'completo',
                'completude' => 100,
                'description' => 'Professional adiciona evidências durante o serviço',
                'gaps' => []
            ],
            [
                'name' => 'Evidence Categorization',
                'status' => 'implementado',
                'completude' => 100,
                'description' => 'Sistema de categorização (EPI, location, issue)',
                'gaps' => []
            ],
            [
                'name' => 'Cliente Adiciona Evidências',
                'status' => 'completo',
                'completude' => 100,
                'description' => 'Cliente pode adicionar fotos via API REST',
                'gaps' => []
            ],
            [
                'name' => 'Notificação ao Cliente (Check-in)',
                'status' => 'pendente',
                'completude' => 0,
                'description' => 'Cliente recebe notificação quando profissional chega',
                'gaps' => ['ExecutionCheckedIn event', 'SMS/Email integration', 'Push notification']
            ],
            [
                'name' => 'Cliente Reporta Problemas',
                'status' => 'pendente',
                'completude' => 0,
                'description' => 'Cliente pode reportar problemas durante execução',
                'gaps' => ['ReportIssue use case', 'Issue entity', 'API endpoint']
            ],
            [
                'name' => 'Professional Reporta Problemas',
                'status' => 'pendente',
                'completude' => 0,
                'description' => 'Professional pode reportar problemas encontrados',
                'gaps' => ['ReportIssue use case', 'Admin UI', 'Notifications']
            ]
        ];

        ?>
        <!-- Dashboard de Status -->
        <div style="margin: 20px 0;">
            <h2 style="margin-bottom: 5px;">📊 Status dos Fluxos Operacionais</h2>
            <p style="color: #646970; margin-top: 5px;">Baseado na auditoria completa realizada em 2026-02-16</p>
        </div>

        <!-- Grid de Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0;">
            <?php foreach ($fluxos as $fluxo): ?>
                <?php
                $statusClass = '';
                $statusIcon = '';
                $statusLabel = '';

                switch ($fluxo['status']) {
                    case 'completo':
                        $statusClass = 'status-completo';
                        $statusIcon = '✅';
                        $statusLabel = 'COMPLETO';
                        break;
                    case 'implementado':
                        $statusClass = 'status-implementado';
                        $statusIcon = '🆕';
                        $statusLabel = 'IMPLEMENTADO';
                        break;
                    case 'parcial':
                        $statusClass = 'status-parcial';
                        $statusIcon = '⚠️';
                        $statusLabel = 'PARCIAL';
                        break;
                    case 'pendente':
                        $statusClass = 'status-pendente';
                        $statusIcon = '❌';
                        $statusLabel = 'PENDENTE';
                        break;
                }
                ?>
                <div class="fluxo-card <?php echo $statusClass; ?>">
                    <div class="fluxo-header">
                        <h3><?php echo esc_html($fluxo['name']); ?></h3>
                        <span class="fluxo-status-badge"><?php echo $statusIcon; ?> <?php echo $statusLabel; ?></span>
                    </div>
                    <div class="fluxo-progress">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $fluxo['completude']; ?>%"></div>
                        </div>
                        <span class="progress-text"><?php echo $fluxo['completude']; ?>%</span>
                    </div>
                    <p class="fluxo-description"><?php echo esc_html($fluxo['description']); ?></p>

                    <?php if (!empty($fluxo['gaps'])): ?>
                        <div class="fluxo-gaps">
                            <strong>Gaps:</strong>
                            <ul>
                                <?php foreach ($fluxo['gaps'] as $gap): ?>
                                    <li><?php echo esc_html($gap); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Resumo Geral -->
        <?php
        $totalFluxos = count($fluxos);
        $completosEImplementados = count(array_filter($fluxos, fn($f) => in_array($f['status'], ['completo', 'implementado'])));
        $percentualGeral = round(($completosEImplementados / $totalFluxos) * 100);
        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 30px 0;">
            <h2>📈 Resumo Geral</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px;">
                <div style="text-align: center;">
                    <div style="font-size: 48px; font-weight: 700; color: #2271b1;"><?php echo $completosEImplementados; ?>/<?php echo $totalFluxos; ?></div>
                    <div style="color: #646970; margin-top: 8px;">Fluxos Operacionais</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 48px; font-weight: 700; color: #00a32a;"><?php echo $percentualGeral; ?>%</div>
                    <div style="color: #646970; margin-top: 8px;">Taxa de Completude</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 48px; font-weight: 700; color: #d63638;"><?php echo ($totalFluxos - $completosEImplementados); ?></div>
                    <div style="color: #646970; margin-top: 8px;">Gaps P1/P2</div>
                </div>
            </div>
        </div>

        <!-- Documentação -->
        <div style="background: #e7f3ff; border-left: 4px solid #2196F3; padding: 20px; margin: 20px 0;">
            <h3 style="margin-top: 0;">📚 Documentação Técnica</h3>
            <p>Análise completa dos fluxos operacionais disponível em:</p>
            <ul style="margin: 10px 0 0 20px;">
                <li><strong>ANALISE-FLUXOS-OPERACIONAIS-COMPLETA.md</strong> - Auditoria linha a linha (2.254 linhas)</li>
                <li><strong>GO-LIVE-100-PERCENT-READY.md</strong> - Status de go-live (544 linhas)</li>
                <li><strong>ENTREGA-FINAL.md</strong> - Consolidação completa (497 linhas)</li>
            </ul>
        </div>

        <!-- Próximos Passos -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0;">
            <h2>🎯 Próximos Passos (P1 - Alta Prioridade)</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 5%;">Prioridade</th>
                        <th style="width: 25%;">Gap</th>
                        <th style="width: 40%;">Descrição</th>
                        <th style="width: 15%;">Estimativa</th>
                        <th style="width: 15%;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="priority-badge p1">P1</span></td>
                        <td><strong>Notificação ao Cliente</strong></td>
                        <td>Cliente recebe SMS/Email/Push quando profissional faz check-in</td>
                        <td>4-6h</td>
                        <td><span class="status-badge pendente">Pendente</span></td>
                    </tr>
                    <tr>
                        <td><span class="priority-badge p1">P1</span></td>
                        <td><strong>Sistema de Issue Reporting</strong></td>
                        <td>Cliente/Professional podem reportar problemas durante execução</td>
                        <td>6-8h</td>
                        <td><span class="status-badge pendente">Pendente</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Sistema de Decisão -->
        <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 20px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #1e4620;">✅ Sistema 100% Pronto para Go-Live</h3>
            <p><strong>Decisão Técnica:</strong> Todos os fluxos críticos (P0) estão implementados e testados:</p>
            <ul style="margin: 10px 0 0 20px;">
                <li>✅ Check-in com validação de geofence e time window</li>
                <li>✅ Check-in com EPI video selfie obrigatório (GAP #1 resolvido)</li>
                <li>✅ Check-out com evidências obrigatórias</li>
                <li>✅ Evidence categorization (EPI vs location vs issue) (GAP #2 resolvido)</li>
                <li>✅ Golden Rule enforcement (execution VALIDATED requer evidência)</li>
                <li>✅ Payout flow completo (feedback + hold + auto-release)</li>
            </ul>
            <p style="margin-top: 15px;"><strong>Gaps P1</strong> (notificações e issue reporting) são <strong>melhorias não-bloqueadoras</strong> e podem ser implementados pós-launch.</p>
        </div>

        <style>
            .fluxo-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .fluxo-card.status-completo {
                border-left: 4px solid #00a32a;
            }
            .fluxo-card.status-implementado {
                border-left: 4px solid #2196F3;
            }
            .fluxo-card.status-parcial {
                border-left: 4px solid #dba617;
            }
            .fluxo-card.status-pendente {
                border-left: 4px solid #d63638;
            }
            .fluxo-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 15px;
            }
            .fluxo-header h3 {
                margin: 0;
                font-size: 16px;
                flex: 1;
            }
            .fluxo-status-badge {
                background: #f0f0f1;
                padding: 4px 10px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                white-space: nowrap;
                margin-left: 10px;
            }
            .status-completo .fluxo-status-badge {
                background: #d5f4e6;
                color: #1e4620;
            }
            .status-implementado .fluxo-status-badge {
                background: #e7f3ff;
                color: #0a4b78;
            }
            .status-parcial .fluxo-status-badge {
                background: #fcf9e8;
                color: #94660c;
            }
            .status-pendente .fluxo-status-badge {
                background: #f8d7da;
                color: #842029;
            }
            .fluxo-progress {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 12px;
            }
            .progress-bar {
                flex: 1;
                height: 8px;
                background: #f0f0f1;
                border-radius: 4px;
                overflow: hidden;
            }
            .progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #2196F3, #00a32a);
                transition: width 0.3s ease;
            }
            .progress-text {
                font-size: 12px;
                font-weight: 600;
                color: #646970;
                min-width: 40px;
                text-align: right;
            }
            .fluxo-description {
                font-size: 13px;
                color: #646970;
                margin: 0 0 12px 0;
                line-height: 1.5;
            }
            .fluxo-gaps {
                background: #fcf9e8;
                border-left: 3px solid #dba617;
                padding: 10px;
                margin-top: 12px;
                font-size: 12px;
            }
            .fluxo-gaps ul {
                margin: 8px 0 0 0;
                padding-left: 20px;
            }
            .fluxo-gaps li {
                margin: 4px 0;
            }
            .priority-badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 700;
                text-align: center;
            }
            .priority-badge.p0 {
                background: #d63638;
                color: #fff;
            }
            .priority-badge.p1 {
                background: #dba617;
                color: #fff;
            }
            .priority-badge.p2 {
                background: #646970;
                color: #fff;
            }
            .status-badge.pendente {
                background: #f8d7da;
                color: #842029;
            }
            .status-badge.em-andamento {
                background: #e7f3ff;
                color: #0a4b78;
            }
        </style>
        <?php
    }

    private function renderFeatureFlags(): void
    {
        $featureFlags = new \LimpVix\Core\FeatureFlags();
        $flags = [
            'core_enabled' => 'Core Enabled',
            'briefing_enabled' => 'Módulo Briefing',
            'financial_workflow' => 'Financial Workflow',
            'admin_interface' => 'Admin Interface'
        ];

        echo '<ul style="margin:0">';
        foreach ($flags as $flag => $label) {
            $enabled = $featureFlags->isEnabled($flag);
            $icon = $enabled ? '✅' : '❌';
            $color = $enabled ? 'green' : 'gray';
            echo sprintf(
                '<li style="color:%s">%s <strong>%s</strong></li>',
                $color,
                $icon,
                esc_html($label)
            );
        }
        echo '</ul>';
    }

    private function getM2Table(): array
    {
        $defaults = [
            'bedroom' => 12,
            'bathroom' => 4,
            'living_room' => 20,
            'kitchen' => 10,
            'office' => 10,
            'external_area' => 25
        ];

        return get_option('limpvix_briefing_m2_table', $defaults);
    }

    private function getTimeFactors(): array
    {
        $defaults = [
            'limpeza_pesada' => 0.40,
            'pos_obra' => 0.70,
            'pre_mudanca' => 0.30
        ];

        return get_option('limpvix_briefing_time_factors', $defaults);
    }

    /**
     * AJAX handler para carregar detalhes do briefing no modal
     */
    public function ajaxGetBriefingDetails(): void
    {
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'limpvix_view_briefing')) {
            wp_send_json_error(['message' => 'Nonce inválido']);
            return;
        }

        // Verificar permissão
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão']);
            return;
        }

        // Obter UUID
        $uuid = sanitize_text_field($_POST['uuid'] ?? '');
        if (empty($uuid)) {
            wp_send_json_error(['message' => 'UUID não fornecido']);
            return;
        }

        // Buscar briefing
        try {
            $briefing = $this->briefingRepository->findByUuid($uuid);
            if (!$briefing) {
                wp_send_json_error(['message' => 'Briefing não encontrado']);
                return;
            }

            // Gerar HTML
            ob_start();
            $this->renderBriefingDetailsForModal($briefing);
            $html = ob_get_clean();

            wp_send_json_success(['html' => $html]);

        } catch (\Exception $e) {
            error_log('[LimpVixSettingsPage] Erro ao carregar briefing: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Erro ao carregar briefing: ' . $e->getMessage()]);
        }
    }

    /**
     * Renderiza o HTML do briefing para o modal
     */
    private function renderBriefingDetailsForModal($briefing): void
    {
        $uuid = $briefing->getUuid();
        $status = $briefing->getStatus()->getValue();
        $statusLabels = [
            'draft' => '🟡 Rascunho',
            'in_progress' => '🔵 Em Progresso',
            'pending_phone_verification' => '⏳ Aguardando Verificação',
            'awaiting_payment' => '💰 Aguardando Pagamento',
            'paid' => '✅ Pago',
            'locked' => '🔒 Travado'
        ];

        global $wpdb;
        $tableData = $wpdb->prefix . 'limpvix_briefing_data';

        // Buscar dados adicionais
        $locationData = $wpdb->get_var($wpdb->prepare(
            "SELECT data_value FROM {$tableData} WHERE briefing_uuid = %s AND data_key = %s",
            $uuid, 'location'
        ));
        $location = $locationData ? json_decode($locationData, true) : null;

        ?>
        <div class="limpvix-briefing-detail-modal">
            <style>
                .limpvix-briefing-detail-modal {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                }
                .limpvix-briefing-detail-modal h2 {
                    margin-top: 0;
                    padding-bottom: 10px;
                    border-bottom: 1px solid #ccd0d4;
                }
                .limpvix-briefing-detail-modal table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 15px 0;
                }
                .limpvix-briefing-detail-modal table th {
                    text-align: left;
                    padding: 8px 12px;
                    background: #f6f7f7;
                    font-weight: 600;
                    border-bottom: 1px solid #ccd0d4;
                }
                .limpvix-briefing-detail-modal table td {
                    padding: 8px 12px;
                    border-bottom: 1px solid #dcdcde;
                }
                .limpvix-briefing-detail-modal .section {
                    margin: 20px 0;
                    padding: 15px;
                    background: #f6f7f7;
                    border-radius: 4px;
                }
                .limpvix-briefing-detail-modal .section h3 {
                    margin-top: 0;
                }
            </style>

            <h2>📋 Briefing #<?php echo esc_html($briefing->getId()); ?></h2>

            <div class="section">
                <h3>ℹ️ Informações Gerais</h3>
                <table>
                    <tr>
                        <th>UUID:</th>
                        <td><code><?php echo esc_html($uuid); ?></code></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td><?php echo esc_html($statusLabels[$status] ?? $status); ?></td>
                    </tr>
                    <tr>
                        <th>Tipo de Propriedade:</th>
                        <td><?php echo esc_html($briefing->getPropertyType()->getValue()); ?></td>
                    </tr>
                    <tr>
                        <th>Criado em:</th>
                        <td><?php echo $briefing->getCreatedAt()->format('d/m/Y H:i'); ?></td>
                    </tr>
                    <?php if ($briefing->getLockedAt()): ?>
                    <tr>
                        <th>Travado em:</th>
                        <td><?php echo $briefing->getLockedAt()->format('d/m/Y H:i'); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <div class="section">
                <h3>📏 Métricas</h3>
                <table>
                    <tr>
                        <th>Área Estimada:</th>
                        <td><strong><?php echo number_format($briefing->getEstimatedM2(), 2); ?> m²</strong></td>
                    </tr>
                    <tr>
                        <th>Duração Estimada:</th>
                        <td><strong><?php echo $this->formatMinutes($briefing->getEstimatedDuration()); ?></strong></td>
                    </tr>
                    <?php if ($briefing->getMetrics()): ?>
                    <tr>
                        <th>Tempo Base:</th>
                        <td><?php echo $this->formatMinutes($briefing->getMetrics()->getBaseMinutes()); ?></td>
                    </tr>
                    <tr>
                        <th>Buffer:</th>
                        <td><?php echo $this->formatMinutes($briefing->getMetrics()->getBufferMinutes()); ?></td>
                    </tr>
                    <tr>
                        <th>Tempo Total:</th>
                        <td><strong><?php echo $this->formatMinutes($briefing->getMetrics()->getTotalMinutes()); ?></strong></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <?php if ($location): ?>
            <div class="section">
                <h3>📍 Localização</h3>
                <table>
                    <tr>
                        <th>Endereço:</th>
                        <td><?php echo esc_html($location['address'] ?? '—'); ?>, <?php echo esc_html($location['number'] ?? '—'); ?></td>
                    </tr>
                    <?php if (!empty($location['complement'])): ?>
                    <tr>
                        <th>Complemento:</th>
                        <td><?php echo esc_html($location['complement']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Bairro:</th>
                        <td><?php echo esc_html($location['neighborhood'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <th>Cidade/Estado:</th>
                        <td><?php echo esc_html($location['city'] ?? '—'); ?> / <?php echo esc_html($location['state'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <th>CEP:</th>
                        <td><?php echo esc_html($location['zip_code'] ?? '—'); ?></td>
                    </tr>
                    <?php if (!empty($location['latitude']) && !empty($location['longitude'])): ?>
                    <tr>
                        <th>Coordenadas:</th>
                        <td>
                            <?php echo esc_html($location['latitude']); ?>, <?php echo esc_html($location['longitude']); ?>
                            <a href="https://www.google.com/maps?q=<?php echo esc_attr($location['latitude']); ?>,<?php echo esc_attr($location['longitude']); ?>" target="_blank" class="button button-small">Ver no Mapa</a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>

            <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ccd0d4; text-align: right;">
                <a href="admin.php?page=limpvix-briefings&action=view&uuid=<?php echo esc_attr($uuid); ?>"
                   class="button button-primary" target="_blank">Ver Página Completa</a>
                <button type="button" class="button" onclick="tb_remove();">Fechar</button>
            </div>
        </div>
        <?php
    }

    /**
     * Formata minutos em formato legível
     */
    private function formatMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' minutos';
        }
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes === 0) {
            return $hours . 'h';
        }

        return $hours . 'h ' . $remainingMinutes . 'min';
    }
}
