<?php
/**
 * AdminBootstrap - Inicialização do Módulo Admin
 * VERSÃO CORRIGIDA - SEM DUPLICAÇÃO DE MENUS
 */

namespace LimpVix\Admin\Bootstrap;

use LimpVix\Admin\Capabilities\FinanceCapabilities;
use LimpVix\Admin\Controllers\OrdersListController;
use LimpVix\Admin\Controllers\OrderDetailController;
use LimpVix\Admin\Controllers\AdminActionsController;
use LimpVix\Admin\Settings\MercadoPagoSettings;
use LimpVix\Admin\Settings\MercadoPagoDetector;
use LimpVix\Admin\Settings\GoogleBusinessSettings;
use LimpVix\Admin\Settings\TwilioSettings;
use LimpVix\Admin\Settings\DialogSettings;
use LimpVix\Admin\Settings\FirebaseSettings;
use LimpVix\Admin\Settings\TestVendorsManager;
use LimpVix\Infrastructure\Admin\Pages\PayoutsPage;
use LimpVix\Infrastructure\Admin\Pages\CommunicationCenterPage;
use LimpVix\Infrastructure\Admin\Pages\MessageFlowsAdminPage;
use LimpVix\Infrastructure\Admin\Pages\MessageTemplatesAdminPage;
use LimpVix\Infrastructure\Admin\Pages\FeedbackManagementPage;

defined("ABSPATH") || exit;

class AdminBootstrap
{
    private const MENU_SLUG = "limpvix-finance";
    private $capabilities;
    private $controllers = [];

    public function __construct()
    {
        $this->capabilities = new FinanceCapabilities();
    }

    public function boot(): void
    {
        add_action("admin_init", [$this, "registerCapabilities"]);
        add_action("admin_menu", [$this, "registerMenu"]);
        add_action("admin_enqueue_scripts", [$this, "registerAssets"]);

        MercadoPagoSettings::registerHooks();
        GoogleBusinessSettings::registerHooks();
        TwilioSettings::registerHooks();
        DialogSettings::registerHooks();
        FirebaseSettings::registerHooks();
        // TestVendorsManager::registerHooks(); // DESABILITADO;
        MercadoPagoDetector::registerSyncHooks();

        $this->initializeControllers();

        // Registrar páginas de payouts
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\PayoutsPage')) {
            PayoutsPage::register();
        }

        // Registrar páginas de comunicação (BLOCO E)
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageFlowsAdminPage')) {
            MessageFlowsAdminPage::register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage')) {
            MessageTemplatesAdminPage::register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            FeedbackManagementPage::register();
        }

        // REMOVIDO: registerCommunicationPages() que causava duplicação
        // Os menus de comunicação são adicionados diretamente no registerMenu()
    }

    public function registerCapabilities(): void
    {
        if (get_transient("limpvix_finance_caps_registered")) {
            return;
        }
        $this->capabilities->register();
        set_transient("limpvix_finance_caps_registered", true, WEEK_IN_SECONDS);
    }

    public function registerMenu(): void
    {
        if (!FinanceCapabilities::canView()) {
            return;
        }

        // Menu principal
        add_menu_page(
            "LimpVix",
            "LimpVix",
            "limpvix_finance_view",
            self::MENU_SLUG,
            [$this, "renderDashboardPage"],
            "dashicons-chart-line",
            30
        );

        // Submenu: Dashboard
        add_submenu_page(
            self::MENU_SLUG,
            "Dashboard LimpVix",
            "Dashboard",
            "limpvix_finance_view",
            self::MENU_SLUG,
            [$this, "renderDashboardPage"]
        );

        // Submenu: Orders
        add_submenu_page(
            self::MENU_SLUG,
            "Orders Financeiras",
            "Orders",
            "limpvix_finance_view",
            "limpvix-orders",
            [$this, "renderOrdersPage"]
        );

        // Submenu: Payouts
        add_submenu_page(
            self::MENU_SLUG,
            "Histórico de Payouts",
            "Payouts",
            "limpvix_finance_view",
            "limpvix-payouts",
            [$this, "renderPayoutsPage"]
        );

        // Submenu: Briefings
        add_submenu_page(
            self::MENU_SLUG,
            "Gerenciar Briefings",
            "Briefings",
            "manage_options",
            "limpvix-briefings",
            [$this, "renderBriefingsPage"]
        );

        // Submenu: Feedback Negativo (página 4 - a ser implementada)
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            add_submenu_page(
                self::MENU_SLUG,
                "Feedback Negativo (C2)",
                "Feedback C2",
                "manage_options",
                "limpvix-feedback-management",
                [$this, 'renderFeedbackManagementPage']
            );
        }

        // Submenu: Configurações
        add_submenu_page(
            self::MENU_SLUG,
            "Configurações LimpVix",
            "Configurações",
            "limpvix_finance_manage",
            "limpvix-settings",
            [$this, "renderSettingsPage"]
        );
    }

    public function registerAssets(string $hook): void
    {
        // Carregar assets em todas as páginas do LimpVix
        if (strpos($hook, 'limpvix') === false) {
            return;
        }

        // CSS Moderno
        wp_enqueue_style(
            "limpvix-admin-modern",
            plugins_url("assets/css/limpvix-admin-modern.css", dirname(dirname(__DIR__))),
            [],
            "1.1.0"
        );

        // CSS Legacy (compatibilidade)
        wp_enqueue_style(
            "limpvix-admin",
            plugins_url("assets/css/limpvix-admin.css", dirname(dirname(__DIR__))),
            [],
            "1.0.0"
        );

        // JS Templates
        wp_enqueue_script(
            "limpvix-templates",
            plugins_url("assets/js/message-templates.js", dirname(dirname(__DIR__))),
            ["jquery"],
            "1.0.0",
            true
        );

        // Localização
        wp_localize_script("limpvix-templates", "limpvixAdmin", [
            "ajaxUrl" => admin_url("admin-ajax.php"),
            "nonce" => wp_create_nonce("limpvix_admin_actions"),
            "capabilities" => [
                "canView" => FinanceCapabilities::canView(),
                "canManage" => FinanceCapabilities::canManage(),
                "canPayout" => FinanceCapabilities::canPayout()
            ]
        ]);
    }

    private function initializeControllers(): void
    {
        $this->controllers["orders_list"] = null;
        $this->controllers["order_detail"] = null;
        $this->controllers["admin_actions"] = null;
    }

    public function getController(string $name)
    {
        if (!isset($this->controllers[$name])) {
            return null;
        }

        if ($this->controllers[$name] === null) {
            $this->controllers[$name] = $this->instantiateController($name);
        }

        return $this->controllers[$name];
    }

    private function instantiateController(string $name)
    {
        switch ($name) {
            case "orders_list":
                return new OrdersListController();
            case "order_detail":
                return new OrderDetailController();
            case "admin_actions":
                return new AdminActionsController();
            default:
                return null;
        }
    }

    public function renderDashboardPage(): void
    {
        if (!FinanceCapabilities::canView()) {
            wp_die("Acesso negado");
        }
        ?>
        <div class="wrap limpvix-admin">
            <div class="limpvix-page-header">
                <div>
                    <h1>
                        <span class="dashicons dashicons-chart-line"></span>
                        Dashboard LimpVix
                    </h1>
                    <p class="limpvix-page-subtitle">Visão geral do sistema de gestão</p>
                </div>
            </div>

            <!-- Grid de Métricas -->
            <div class="limpvix-grid limpvix-grid-3">
                <div class="limpvix-stat-box">
                    <div class="limpvix-stat-icon limpvix-stat-icon-primary">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <div class="limpvix-stat-content">
                        <div class="limpvix-stat-label">Orders Financeiras</div>
                        <div class="limpvix-stat-value">0</div>
                        <div class="limpvix-stat-change positive">
                            <span class="dashicons dashicons-arrow-up-alt"></span>
                            Em desenvolvimento
                        </div>
                    </div>
                </div>

                <div class="limpvix-stat-box">
                    <div class="limpvix-stat-icon limpvix-stat-icon-success">
                        <span class="dashicons dashicons-megaphone"></span>
                    </div>
                    <div class="limpvix-stat-content">
                        <div class="limpvix-stat-label">Mensagens Enviadas</div>
                        <div class="limpvix-stat-value">0</div>
                        <div class="limpvix-stat-change positive">
                            <span class="dashicons dashicons-arrow-up-alt"></span>
                            Sistema ativo
                        </div>
                    </div>
                </div>

                <div class="limpvix-stat-box">
                    <div class="limpvix-stat-icon limpvix-stat-icon-warning">
                        <span class="dashicons dashicons-admin-users"></span>
                    </div>
                    <div class="limpvix-stat-content">
                        <div class="limpvix-stat-label">Profissionais Ativos</div>
                        <div class="limpvix-stat-value">0</div>
                        <div class="limpvix-stat-change">
                            <span class="dashicons dashicons-minus"></span>
                            Aguardando dados
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alertas -->
            <div class="limpvix-alert limpvix-alert-info">
                <div class="limpvix-alert-icon">
                    <span class="dashicons dashicons-info"></span>
                </div>
                <div class="limpvix-alert-content">
                    <div class="limpvix-alert-title">Dashboard em Desenvolvimento</div>
                    <p>As métricas e funcionalidades do dashboard serão implementadas nas próximas versões.</p>
                </div>
            </div>
        </div>
        <?php
    }

    public function renderSettingsPage(): void
    {
        if (!FinanceCapabilities::canManage()) {
            wp_die("Acesso negado");
        }

        // Determinar aba ativa
        $activeTab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'geral';

        // Processar salvamento da aba Briefing
        if ($activeTab === 'briefing' && isset($_POST['limpvix_save_briefing_settings']) && check_admin_referer('limpvix_briefing_settings')) {
            $this->handleBriefingSave();
        }

        ?>
        <div class="wrap limpvix-admin">
            <div class="limpvix-page-header">
                <div>
                    <h1>
                        <span class="dashicons dashicons-admin-settings"></span>
                        Configurações LimpVix
                    </h1>
                    <p class="limpvix-page-subtitle">Gerenciar configurações gerais do sistema</p>
                </div>
            </div>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ Configurações salvas com sucesso!</p>
                </div>
            <?php endif; ?>

            <!-- Sistema de Abas -->
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=limpvix-settings&tab=geral"
                   class="nav-tab <?php echo $activeTab === 'geral' ? 'nav-tab-active' : ''; ?>">
                    🏠 Geral
                </a>
                <a href="?page=limpvix-settings&tab=conexoes"
                   class="nav-tab <?php echo $activeTab === 'conexoes' ? 'nav-tab-active' : ''; ?>">
                    🔌 Conexões
                </a>
                <a href="?page=limpvix-settings&tab=comunicacao"
                   class="nav-tab <?php echo $activeTab === 'comunicacao' ? 'nav-tab-active' : ''; ?>">
                    📡 Comunicação
                </a>
                <a href="?page=limpvix-settings&tab=briefing"
                   class="nav-tab <?php echo $activeTab === 'briefing' ? 'nav-tab-active' : ''; ?>">
                    📋 Briefing
                </a>
                <a href="?page=limpvix-settings&tab=templates"
                   class="nav-tab <?php echo $activeTab === 'templates' ? 'nav-tab-active' : ''; ?>">
                    📝 Templates
                </a>
                <a href="?page=limpvix-settings&tab=fluxos"
                   class="nav-tab <?php echo $activeTab === 'fluxos' ? 'nav-tab-active' : ''; ?>">
                    🔄 Fluxos
                </a>
                <a href="?page=limpvix-settings&tab=pagamentos"
                   class="nav-tab <?php echo $activeTab === 'pagamentos' ? 'nav-tab-active' : ''; ?>">
                    💳 Pagamentos
                </a>
                <a href="?page=limpvix-settings&tab=dependencias"
                   class="nav-tab <?php echo $activeTab === 'dependencias' ? 'nav-tab-active' : ''; ?>">
                    🔗 Dependências
                </a>
            </h2>

            <?php
            // Renderizar aba ativa
            switch ($activeTab) {
                case 'conexoes':
                    $this->renderConexoesTab();
                    break;
                case 'comunicacao':
                    $this->renderComunicacaoTab();
                    break;
                case 'briefing':
                    $this->renderBriefingTab();
                    break;
                case 'templates':
                    $this->renderTemplatesTab();
                    break;
                case 'fluxos':
                    $this->renderFluxosTab();
                    break;
                case 'pagamentos':
                    $this->renderPagamentosTab();
                    break;
                case 'dependencias':
                    $this->renderDependenciasTab();
                    break;
                case 'geral':
                default:
                    $this->renderGeralTab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    private function renderDependenciasTab(): void
    {
        $isBookneticActive = is_plugin_active('booknetic/init.php');
        ?>

        <!-- Status Geral da Dependência -->
        <div class="limpvix-card <?php echo $isBookneticActive ? 'limpvix-card-success' : 'limpvix-card-danger'; ?>">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-admin-plugins"></span>
                    Status: Plugin Booknetic
                </h3>
                <p>Dependência principal para agendamentos e gestão operacional</p>
            </div>
            <div class="limpvix-card-body">
                <?php if ($isBookneticActive): ?>
                    <div class="notice notice-success inline">
                        <p><strong>✅ Booknetic ATIVO</strong> - Todas as funcionalidades de integração disponíveis</p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-error inline">
                        <p><strong>❌ Booknetic INATIVO</strong> - Módulo de agendamentos não funcionará sem o Booknetic instalado e ativo</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Auditoria Completa -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">

            <!-- O QUE USAMOS -->
            <div class="limpvix-card limpvix-card-info">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-yes"></span>
                        ✅ O Que USAMOS do Booknetic
                    </h3>
                    <p>Funcionalidades e dados que o LimpVix-Core consome</p>
                </div>
                <div class="limpvix-card-body">
                    <h4 style="margin-top: 0;">📡 Hooks Capturados (10)</h4>
                    <table class="limpvix-table">
                        <thead>
                            <tr>
                                <th>Hook</th>
                                <th>Função</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>bkntc_appointment_created</code></td>
                                <td>Criar order no LimpVix</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_appointment_completed</code></td>
                                <td>Disparar fluxo financeiro</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_appointment_canceled</code></td>
                                <td>Cancelar order</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff_updated</code></td>
                                <td>Sincronizar dados staff</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_after_booking_completed</code></td>
                                <td>Redirecionar para Briefing</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff_can_access</code></td>
                                <td>Controle de permissões</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff_can_execute_action</code></td>
                                <td>Controle de ações</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff_panel_header</code></td>
                                <td>Avisos personalizados</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff_panel_footer</code></td>
                                <td>Ocultar abas financeiras</td>
                            </tr>
                            <tr>
                                <td><code>admin_menu</code> (999)</td>
                                <td>Ocultar menus para staff</td>
                            </tr>
                        </tbody>
                    </table>

                    <h4 style="margin-top: 20px;">🗄️ Tabelas Acessadas (4)</h4>
                    <table class="limpvix-table">
                        <thead>
                            <tr>
                                <th>Tabela</th>
                                <th>Tipo Acesso</th>
                                <th>Propósito</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>bkntc_appointments</code></td>
                                <td>READ</td>
                                <td>Mapear appointment → order</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff</code></td>
                                <td>READ</td>
                                <td>Vincular user_id WordPress</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_customers</code></td>
                                <td>READ</td>
                                <td>Dados para Google Reviews</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_services</code></td>
                                <td>READ</td>
                                <td>Nome do serviço executado</td>
                            </tr>
                        </tbody>
                    </table>

                    <h4 style="margin-top: 20px;">📦 Classes/Componentes (6)</h4>
                    <ul style="list-style: none; padding: 0;">
                        <li>✅ <strong>BookneticBridge</strong> - Ponte principal de integração</li>
                        <li>✅ <strong>AppointmentOrderMapper</strong> - Mapeamento 1:1</li>
                        <li>✅ <strong>StaffAccessGuard</strong> - Controle de acesso</li>
                        <li>✅ <strong>StaffActionGuard</strong> - Controle de ações</li>
                        <li>✅ <strong>StaffPanelOverride</strong> - UI customizada</li>
                        <li>✅ <strong>StaffNotices</strong> - Avisos personalizados</li>
                    </ul>
                </div>
            </div>

            <!-- O QUE NÃO USAMOS -->
            <div class="limpvix-card limpvix-card-warning">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-no"></span>
                        ❌ O Que NÃO Usamos do Booknetic
                    </h3>
                    <p>Funcionalidades ignoradas - LimpVix tem implementação própria</p>
                </div>
                <div class="limpvix-card-body">
                    <h4 style="margin-top: 0;">💰 Sistema Financeiro</h4>
                    <ul>
                        <li>❌ <strong>Payments do Booknetic</strong> - LimpVix tem sistema próprio</li>
                        <li>❌ <strong>Invoices do Booknetic</strong> - WooCommerce gerencia</li>
                        <li>❌ <strong>Staff Payouts</strong> - MercadoPago via LimpVix</li>
                        <li>❌ <strong>Comissões</strong> - FinancialPolicy próprio</li>
                    </ul>

                    <h4 style="margin-top: 20px;">📊 Relatórios e Analytics</h4>
                    <ul>
                        <li>❌ <strong>Dashboard Booknetic</strong> - LimpVix tem próprio</li>
                        <li>❌ <strong>Reports do Booknetic</strong> - Não utilizados</li>
                        <li>❌ <strong>Analytics</strong> - Sistema próprio de métricas</li>
                    </ul>

                    <h4 style="margin-top: 20px;">📧 Comunicação</h4>
                    <ul>
                        <li>❌ <strong>Notifications do Booknetic</strong> - LimpVix gerencia fluxos</li>
                        <li>❌ <strong>SMS do Booknetic</strong> - Twilio via LimpVix</li>
                        <li>❌ <strong>WhatsApp do Booknetic</strong> - 360Dialog via LimpVix</li>
                        <li>❌ <strong>Email templates</strong> - MessageTemplates próprios</li>
                    </ul>

                    <h4 style="margin-top: 20px;">⚙️ Configurações</h4>
                    <ul>
                        <li>❌ <strong>Settings do Booknetic</strong> - Não modificamos</li>
                        <li>❌ <strong>Custom Fields</strong> - Não utilizamos</li>
                        <li>❌ <strong>Extras/Add-ons</strong> - Não utilizamos</li>
                    </ul>

                    <h4 style="margin-top: 20px;">🎨 Interface</h4>
                    <ul>
                        <li>❌ <strong>Frontend Booknetic</strong> - Não expomos ao público</li>
                        <li>❌ <strong>Customer Panel</strong> - Briefing próprio</li>
                        <li>❌ <strong>Widgets</strong> - Não utilizamos</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Princípios de Integração -->
        <div class="limpvix-card" style="margin-top: 20px;">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-shield"></span>
                    🛡️ Princípios da Integração
                </h3>
                <p>Como mantemos isolamento e evitamos dependência excessiva</p>
            </div>
            <div class="limpvix-card-body">
                <div class="limpvix-grid limpvix-grid-2">
                    <div>
                        <h4>✅ O QUE FAZEMOS:</h4>
                        <ul>
                            <li>✅ Interceptamos eventos via hooks do WordPress</li>
                            <li>✅ Lemos dados das tabelas Booknetic (READ-ONLY)</li>
                            <li>✅ Mantemos mapeamento 1:1 em tabela própria</li>
                            <li>✅ Sobrescrevemos UI apenas para staff (Guards)</li>
                            <li>✅ Validamos permissões antes de cada ação</li>
                        </ul>
                    </div>
                    <div>
                        <h4>❌ O QUE NÃO FAZEMOS:</h4>
                        <ul>
                            <li>❌ NUNCA modificamos código do Booknetic</li>
                            <li>❌ NUNCA escrevemos em tabelas do Booknetic</li>
                            <li>❌ NUNCA sobrescrevemos classes do Booknetic</li>
                            <li>❌ NUNCA dependemos de métodos internos</li>
                            <li>❌ NUNCA quebramos compatibilidade com updates</li>
                        </ul>
                    </div>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa;">
                    <strong>📌 Arquitetura de Isolamento:</strong>
                    <p style="margin: 10px 0 0 0;">
                        <code>Booknetic (Engine Operacional)</code> →
                        <code>BookneticBridge (Interceptação)</code> →
                        <code>LimpVix (Soberano em Regras/Dinheiro/Compliance)</code>
                    </p>
                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #666;">
                        Esta arquitetura permite que o Booknetic seja substituído no futuro sem quebrar o LimpVix-Core.
                    </p>
                </div>
            </div>
        </div>

        <?php
    }

    private function renderGeralTab(): void
    {
        ?>
        <div class="limpvix-grid limpvix-grid-2">
            <!-- Feature Flags Card -->
            <div class="limpvix-card limpvix-card-primary">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-flag"></span>
                        Feature Flags
                    </h3>
                    <p>Controle das funcionalidades ativas do sistema</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $flags = new \LimpVix\Core\FeatureFlags();
                    $all_flags = $flags->getAll();
                    $important_flags = [
                        "briefing_enabled" => "Módulo Briefing",
                        "financial_workflow" => "Workflow Financeiro",
                        "payout_engine" => "Motor de Payouts",
                        "admin_interface" => "Interface Admin",
                        "audit_logging" => "Logs de Auditoria",
                    ];
                    ?>
                    <table class="limpvix-table">
                        <thead>
                            <tr>
                                <th>Funcionalidade</th>
                                <th style="text-align: center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($important_flags as $flag => $label): ?>
                            <tr>
                                <td><strong><?php echo esc_html($label); ?></strong></td>
                                <td style="text-align: center;">
                                    <?php if (isset($all_flags[$flag]) && $all_flags[$flag]): ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Habilitado</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">Desabilitado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Health Check Card -->
            <div class="limpvix-card limpvix-card-success">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-heart"></span>
                        Health Check
                    </h3>
                    <p>Status e saúde do sistema</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $kernel = \LimpVix\Core\Kernel::getInstance();
                    $health = $kernel->healthCheck();
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td><strong>Versão do Plugin</strong></td>
                                <td>
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($health["version"]); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Kernel LimpVix</strong></td>
                                <td>
                                    <?php if ($health["booted"]): ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Inicializado</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-danger limpvix-badge-dot">Não inicializado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Integração Booknetic</strong></td>
                                <td>
                                    <?php if ($health["booknetic_active"]): ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Ativo</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-danger limpvix-badge-dot">Inativo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Segunda linha: Módulos e Estatísticas -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">
            <!-- Módulos Ativos -->
            <div class="limpvix-card limpvix-card-info">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-plugins"></span>
                        Módulos do Sistema
                    </h3>
                    <p>Componentes carregados e funcionais</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $modules = [
                        'Briefing' => class_exists('LimpVix\\Core\\BriefingBootstrap') && method_exists('LimpVix\\Core\\BriefingBootstrap', 'isInitialized') ? \LimpVix\Core\BriefingBootstrap::isInitialized() : false,
                        'Comunicação' => class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\CommunicationCenterPage'),
                        'Financeiro' => class_exists('LimpVix\\Domain\\Finance\\LedgerEntry'),
                        'Feedback' => class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage'),
                        'Fluxos' => class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageFlowsAdminPage'),
                        'Templates' => class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage'),
                    ];
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <?php foreach ($modules as $name => $active): ?>
                            <tr>
                                <td><strong><?php echo esc_html($name); ?></strong></td>
                                <td style="text-align: right;">
                                    <?php if ($active): ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Ativo</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">Inativo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Estatísticas do Sistema -->
            <div class="limpvix-card limpvix-card-warning">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-chart-bar"></span>
                        Estatísticas Gerais
                    </h3>
                    <p>Resumo de dados do sistema</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    global $wpdb;

                    // Contar briefings
                    $briefings_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_briefings");

                    // Contar mensagens (últimos 30 dias)
                    $messages_count = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_messages
                         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );

                    // Contar pedidos WooCommerce (últimos 30 dias)
                    $orders_count = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}posts
                         WHERE post_type = 'shop_order'
                         AND post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );

                    // Contar Feedbacks Negativos C2 (rating < 4, últimos 30 dias)
                    $feedbacks_c2_count = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_orders
                         WHERE customer_rating IS NOT NULL
                         AND customer_rating < 4
                         AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );

                    // Contar entradas ledger (últimos 7 dias)
                    $ledger_count = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_ledger
                         WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
                    );
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td><strong>Briefings (total)</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($briefings_count ?: 0); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Mensagens (30 dias)</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($messages_count ?: 0); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Pedidos (30 dias)</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($orders_count ?: 0); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Feedbacks C2 (30 dias)</strong></td>
                                <td style="text-align: right;">
                                    <?php if ($feedbacks_c2_count > 0): ?>
                                        <span class="limpvix-badge limpvix-badge-warning"><?php echo number_format($feedbacks_c2_count); ?></span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-success"><?php echo number_format($feedbacks_c2_count ?: 0); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Eventos Ledger (7 dias)</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($ledger_count ?: 0); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Terceira linha: Integrações -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">
            <!-- Status de Integrações -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-cloud"></span>
                        Integrações Externas
                    </h3>
                    <p>Status das conexões com serviços externos</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    // Usar métodos isConnected() de cada Settings
                    $integrations = [
                        'Firebase' => FirebaseSettings::isConfigured(),
                        'Twilio' => TwilioSettings::isConnected(),
                        '360Dialog' => DialogSettings::isConnected(),
                        'Google Business' => GoogleBusinessSettings::isConnected(),
                        'Mercado Pago' => \LimpVix\Admin\Settings\MercadoPagoDetector::isOfficialPluginConnected(),
                        'Booknetic' => is_plugin_active('booknetic/init.php'),
                        'WooCommerce' => class_exists('WooCommerce'),
                    ];
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <?php foreach ($integrations as $name => $configured): ?>
                            <tr>
                                <td><strong><?php echo esc_html($name); ?></strong></td>
                                <td style="text-align: right;">
                                    <?php if ($configured): ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Configurado</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">Não configurado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Informações do Ambiente -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-tools"></span>
                        Ambiente e Performance
                    </h3>
                    <p>Informações técnicas do servidor</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $php_version = PHP_VERSION;
                    $wp_version = get_bloginfo('version');
                    $memory_limit = ini_get('memory_limit');
                    $max_execution_time = ini_get('max_execution_time');
                    $upload_max_filesize = ini_get('upload_max_filesize');
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td><strong>PHP</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($php_version); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>WordPress</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($wp_version); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Memory Limit</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($memory_limit); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Max Execution Time</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($max_execution_time); ?>s</span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Upload Max Size</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($upload_max_filesize); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Debug Mode</strong></td>
                                <td style="text-align: right;">
                                    <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                                        <span class="limpvix-badge limpvix-badge-warning limpvix-badge-dot">Ativo</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Inativo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderConexoesTab(): void
    {
        ?>
        <!-- Authentication Providers Grid -->
        <div class="limpvix-grid limpvix-grid-2">
            <!-- Firebase Authentication -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-network"></span>
                        🔥 Firebase Authentication
                    </h3>
                    <p>SMS OTP para verificação de telefone</p>
                </div>
                <div class="limpvix-card-body">
                    <?php FirebaseSettings::render(); ?>
                </div>
            </div>

            <!-- Google Meu Negócio -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-google"></span>
                        Google Meu Negócio
                    </h3>
                    <p>Integração para convites de avaliação</p>
                </div>
                <div class="limpvix-card-body">
                    <?php GoogleBusinessSettings::render(); ?>
                </div>
            </div>
        </div>

        <!-- Communication Providers Grid -->
        <div class="limpvix-grid limpvix-grid-2">
            <!-- Twilio SMS Settings -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-smartphone"></span>
                        Twilio SMS
                    </h3>
                    <p>Configurações de envio de SMS via Twilio</p>
                </div>
                <div class="limpvix-card-body">
                    <?php TwilioSettings::render(); ?>
                </div>
            </div>

            <!-- 360Dialog WhatsApp Settings -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-whatsapp"></span>
                        360Dialog WhatsApp
                    </h3>
                    <p>Configurações de envio de WhatsApp via 360Dialog</p>
                </div>
                <div class="limpvix-card-body">
                    <?php DialogSettings::render(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderComunicacaoTab(): void
    {
        ?>
        <div class="limpvix-card">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-megaphone"></span>
                    📡 Central de Comunicação
                </h3>
                <p>Hub de gerenciamento de mensagens, fluxos e providers</p>
            </div>
            <div class="limpvix-card-body">
                <?php
                if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\CommunicationCenterPage')) {
                    $page = new \LimpVix\Infrastructure\Admin\Pages\CommunicationCenterPage();
                    $page->render();
                } else {
                    echo '<div class="notice notice-error inline"><p>❌ Central de Comunicação não encontrada. Verifique se a classe CommunicationCenterPage existe.</p></div>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function renderBriefingTab(): void
    {
        $m2Table = $this->getM2Table();
        $timeFactors = $this->getTimeFactors();
        $bufferMinutes = get_option('limpvix_briefing_buffer_minutes', 30);
        $pricePerM2 = get_option('limpvix_briefing_price_per_m2', 15.00);

        ?>
        <form method="post" action="">
            <?php wp_nonce_field('limpvix_briefing_settings'); ?>

            <!-- Tabela m² -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-home"></span>
                        📏 Tabela de m² por Cômodo
                    </h3>
                    <p>Valores usados para cálculo de área estimada do briefing</p>
                </div>
                <div class="limpvix-card-body">
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
            </div>

            <!-- Fatores de Tempo -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-clock"></span>
                        ⏱️ Fatores de Tempo por Tipo de Limpeza
                    </h3>
                    <p>Multiplicadores aplicados ao tempo base (ex: 0.40 = +40% de tempo)</p>
                </div>
                <div class="limpvix-card-body">
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
            </div>

            <!-- Outros Parâmetros -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-generic"></span>
                        ⚙️ Outros Parâmetros
                    </h3>
                </div>
                <div class="limpvix-card-body">
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
            </div>

            <p class="submit">
                <button type="submit" name="limpvix_save_briefing_settings" class="button button-primary button-large">
                    💾 Salvar Configurações do Briefing
                </button>
            </p>
        </form>
        <?php
    }

    private function renderTemplatesTab(): void
    {
        // Renderizar página completa de Templates diretamente na aba
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage')) {
            $page = new \LimpVix\Infrastructure\Admin\Pages\MessageTemplatesAdminPage();
            $page->render();
        } else {
            ?>
            <div class="limpvix-card">
                <div class="limpvix-card-body">
                    <div class="notice notice-error">
                        <p><strong>Erro:</strong> Classe MessageTemplatesAdminPage não encontrada.</p>
                    </div>
                </div>
            </div>
            <?php
        }
    }

    private function renderFluxosTab(): void
    {
        ?>
        <div class="limpvix-card">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-update"></span>
                    🔄 Gerenciar Fluxos Automáticos
                </h3>
                <p>Configurar e monitorar fluxos de comunicação automáticos do sistema</p>
            </div>
            <div class="limpvix-card-body">
                <?php
                if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageFlowsAdminPage')) {
                    $page = new \LimpVix\Infrastructure\Admin\Pages\MessageFlowsAdminPage();
                    $page->render();
                } else {
                    echo '<div class="notice notice-error inline"><p>❌ Página de Fluxos não encontrada. Verifique se a classe MessageFlowsAdminPage existe.</p></div>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function renderPagamentosTab(): void
    {
        ?>
        <!-- Mercado Pago Settings -->
        <div class="limpvix-card">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-admin-generic"></span>
                    Integrações e Pagamentos
                </h3>
                <p>Configurações de integração com Mercado Pago e outros serviços</p>
            </div>
            <div class="limpvix-card-body">
                <?php MercadoPagoSettings::render(); ?>
            </div>
        </div>
        <?php
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

    private function handleBriefingSave(): void
    {
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

        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=briefing&updated=1'));
        exit;
    }

    public function renderOrdersPage(): void {
        echo "<div class='wrap limpvix-admin'><div class='limpvix-page-header'><div><h1><span class='dashicons dashicons-list-view'></span> Orders Financeiras</h1><p class='limpvix-page-subtitle'>Gerenciar orders e fluxo financeiro</p></div></div><div class='limpvix-alert limpvix-alert-info'><div class='limpvix-alert-icon'><span class='dashicons dashicons-info'></span></div><div class='limpvix-alert-content'><div class='limpvix-alert-title'>Página em Desenvolvimento</div><p>A listagem de orders será implementada em breve.</p></div></div></div>";
    }

    public function renderPayoutsPage(): void {
        if (!FinanceCapabilities::canView()) {
            wp_die("Acesso negado");
        }

        $page = new PayoutsPage();
        $page->render();
    }

    /**
     * @deprecated Movido para aba Comunicação em Configurações (renderComunicacaoTab)
     * @see renderComunicacaoTab()
     */
    public function renderCommunicationCenterPage(): void {
        // Redirecionar para nova localização
        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=comunicacao'));
        exit;
    }

    /**
     * @deprecated Movido para aba Fluxos em Configurações (renderFluxosTab)
     * @see renderFluxosTab()
     */
    public function renderMessageFlowsPage(): void {
        // Redirecionar para nova localização
        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=fluxos'));
        exit;
    }

    /**
     * @deprecated Movido para aba Templates em Configurações (renderTemplatesTab)
     * @see renderTemplatesTab()
     */
    public function renderMessageTemplatesPage(): void {
        // Redirecionar para nova localização
        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=templates'));
        exit;
    }

    public function renderFeedbackManagementPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die("Acesso negado");
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            $page = new \LimpVix\Infrastructure\Admin\Pages\FeedbackManagementPage();
            $page->render();
        }
    }

    public function renderBriefingsPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die("Acesso negado");
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\BriefingManagementPage')) {
            $briefingRepository = new \LimpVix\Infrastructure\Persistence\WpBriefingRepository();
            $page = new \LimpVix\Infrastructure\Admin\Pages\BriefingManagementPage($briefingRepository);
            $page->render();
        }
    }

    public function deactivate(): void {
        $this->capabilities->unregister();
        delete_transient("limpvix_finance_caps_registered");
    }
}
