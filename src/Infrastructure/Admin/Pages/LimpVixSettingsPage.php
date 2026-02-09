<?php
/**
 * LimpVixSettingsPage - Configurações Centrais do LimpVix Core
 *
 * RESPONSABILIDADE:
 * - Menu principal: LimpVix (position 3, abaixo de Dashboard)
 * - Sistema de abas: Conexões | Briefing | Scheduling
 * - Aba Conexões: Firebase, Google Meu Negócio, Twilio, 360Dialog
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
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_twilio_account_sid');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_twilio_auth_token');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_360dialog_api_key');

        // Aba Briefing
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_m2_table');
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_time_factors');
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_buffer_minutes');
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_price_per_m2');

        // Aba Scheduling
        register_setting(self::OPTION_GROUP_SCHEDULING, 'limpvix_scheduling_geofence_radius');
        register_setting(self::OPTION_GROUP_SCHEDULING, 'limpvix_scheduling_time_tolerance');
    }

    public function enqueueAssets($hook): void
    {
        if ($hook !== 'toplevel_page_limpvix') {
            return;
        }

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
                                    <a href="admin.php?page=limpvix-briefings&action=view&uuid=<?php echo esc_attr($uuid); ?>"
                                       class="button button-small">
                                        👁️ Ver
                                    </a>

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
        <?php
    }

    private function renderConnectionsTab(): void
    {
        $firebaseProjectId = get_option('limpvix_firebase_project_id', '');
        $firebaseApiKey = get_option('limpvix_firebase_api_key', '');
        $firebaseAuthDomain = get_option('limpvix_firebase_auth_domain', '');
        $googleApiKey = get_option('limpvix_google_mybusiness_api_key', '');
        $twilioSid = get_option('limpvix_twilio_account_sid', '');
        $twilioToken = get_option('limpvix_twilio_auth_token', '');
        $dialog360ApiKey = get_option('limpvix_360dialog_api_key', '');
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

        <!-- Twilio -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>📱 Twilio (SMS/WhatsApp)</h2>
            <p>Envio de SMS e mensagens WhatsApp via Twilio:</p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="twilio_account_sid">Account SID</label>
                    </th>
                    <td>
                        <input type="text"
                               id="twilio_account_sid"
                               name="twilio_account_sid"
                               value="<?php echo esc_attr($twilioSid); ?>"
                               class="regular-text"
                               placeholder="AC...">
                        <p class="description">
                            Account SID (encontrado no Console Twilio)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="twilio_auth_token">Auth Token</label>
                    </th>
                    <td>
                        <input type="password"
                               id="twilio_auth_token"
                               name="twilio_auth_token"
                               value="<?php echo esc_attr($twilioToken); ?>"
                               class="regular-text">
                        <p class="description">
                            Auth Token (mantenha secreto)
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($twilioSid)): ?>
                <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin-top:15px">
                    ✅ <strong>Twilio configurado!</strong>
                </div>
            <?php else: ?>
                <div style="background:#f8f9fa;border-left:4px solid #6c757d;padding:15px;margin-top:15px">
                    ℹ️ <strong>Opcional.</strong> Configure quando precisar de SMS/WhatsApp.
                </div>
            <?php endif; ?>
        </div>

        <!-- 360Dialog -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>💬 360Dialog (WhatsApp Business API)</h2>
            <p>WhatsApp Business API oficial via 360Dialog:</p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="360dialog_api_key">API Key</label>
                    </th>
                    <td>
                        <input type="text"
                               id="360dialog_api_key"
                               name="360dialog_api_key"
                               value="<?php echo esc_attr($dialog360ApiKey); ?>"
                               class="regular-text">
                        <p class="description">
                            API Key fornecida pela 360Dialog
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($dialog360ApiKey)): ?>
                <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin-top:15px">
                    ✅ <strong>360Dialog configurado!</strong>
                </div>
            <?php else: ?>
                <div style="background:#f8f9fa;border-left:4px solid #6c757d;padding:15px;margin-top:15px">
                    ℹ️ <strong>Opcional.</strong> Configure quando precisar de WhatsApp Business API.
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
            update_option('limpvix_360dialog_api_key', sanitize_text_field($_POST['360dialog_api_key'] ?? ''));
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
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab . '&updated=1'));
        exit;
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
}
