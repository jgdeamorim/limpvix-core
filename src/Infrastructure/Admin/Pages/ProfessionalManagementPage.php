<?php
/**
 * ProfessionalManagementPage - Gerenciamento de Profissionais
 *
 * RESPONSABILIDADE:
 * - Dashboard com estatísticas de profissionais
 * - Listagem com filtros (ativo, verificado, score, região)
 * - Registro de novos profissionais
 * - Ações: verificar, suspender, editar, ver detalhes
 * - Gestão de ofertas e alocações
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;
use LimpVix\Application\UseCase\Professional\RegisterProfessional;
use LimpVix\Application\UseCase\Professional\UpdateProfessionalScore;
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;

defined('ABSPATH') || exit;

class ProfessionalManagementPage
{
    private const PAGE_SLUG = 'limpvix-professionals';
    private const NONCE_ACTION = 'limpvix_professional_action';

    private $wpdb;
    private $repository;
    private $useCases;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->repository = new WpMarketplaceProfessionalRepository();

        // REFATORADO (FASE 2): Dependency Injection via globals (Bootstrap)
        $this->useCases = $GLOBALS['limpvix_professional_use_cases'] ?? [];
    }

    public function register(): void
    {
        // Call addMenu() directly since we're already inside admin_menu hook
        // (called from ProfessionalBootstrap::registerAdminPages() which is hooked to admin_menu)
        $this->addMenu();

        // Register other hooks normally
        add_action('admin_init', [$this, 'handleFormSubmission']);
        add_action('admin_init', [$this, 'handleQuickActions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        
        // Register KYC AJAX handlers
        $this->registerAjaxHandlers();
    }

    public function addMenu(): void
    {
        error_log('=== ProfessionalManagementPage::addMenu() CALLED ===');
        error_log('Adding submenu to parent: limpvix-finance');
        error_log('Page slug: ' . self::PAGE_SLUG);

        $result = add_submenu_page(
            'limpvix-finance',
            'Profissionais',
            'Profissionais',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );

        error_log('add_submenu_page result: ' . var_export($result, true));

        global $submenu;
        error_log('$submenu[limpvix-finance] exists: ' . (isset($submenu['limpvix-finance']) ? 'YES' : 'NO'));
        error_log('global $menu: ' . (isset($GLOBALS['menu']) ? 'YES' : 'NO'));
    }

    public function enqueueAssets($hook): void
    {
        // Check if we're on the professionals page
        $on_professionals_page = (
            strpos($hook, self::PAGE_SLUG) !== false ||
            (isset($_GET['page']) && $_GET['page'] === self::PAGE_SLUG)
        );

        if (!$on_professionals_page) {
            return;
        }

        // Styles (optional - will 404 but won't break anything)
        if (file_exists(dirname(LIMPVIX_PLUGIN_FILE) . '/assets/css/professionals.css')) {
            wp_enqueue_style(
                'limpvix-professionals',
                plugins_url('assets/css/professionals.css', LIMPVIX_PLUGIN_FILE),
                [],
                LIMPVIX_VERSION
            );
        }

        // Scripts
        wp_enqueue_script('jquery');

        // Only enqueue if file exists (optional - will 404 but won't break anything)
        if (file_exists(dirname(LIMPVIX_PLUGIN_FILE) . '/assets/js/professionals.js')) {
            wp_enqueue_script(
                'limpvix-professionals',
                plugins_url('assets/js/professionals.js', LIMPVIX_PLUGIN_FILE),
                ['jquery'],
                LIMPVIX_VERSION,
                true
            );
        }

        // Localize
        wp_localize_script('jquery', 'limpvixProfessionals', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('limpvix_professionals_ajax'),
        ]);
    }

    /**
     * Handles GET-based quick actions from the Professional_List_Table row actions.
     * Actions: verify, unsuspend, ban, unban, activate, deactivate.
     * Each action URL is nonce-protected via wp_nonce_url().
     */
    public function handleQuickActions(): void
    {
        if (
            !isset($_GET['page'], $_GET['quick_action'], $_GET['id']) ||
            $_GET['page'] !== self::PAGE_SLUG
        ) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $quickAction = sanitize_text_field($_GET['quick_action']);
        $id = (int) $_GET['id'];

        if ($id <= 0) {
            return;
        }

        $nonceKey = "limpvix_quick_action_{$quickAction}_{$id}";

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], $nonceKey)) {
            wp_die('Nonce inválido');
        }

        $professional = $this->repository->findById($id);

        if (!$professional) {
            add_settings_error('limpvix_professionals', 'not_found', 'Profissional não encontrado.', 'error');
            set_transient('limpvix_professional_action_result', get_settings_errors('limpvix_professionals'), 30);
            wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
            exit;
        }

        switch ($quickAction) {
            case 'verify':
                $professional->verify(get_current_user_id());
                $this->repository->save($professional);
                add_settings_error('limpvix_professionals', 'success', 'Profissional verificado com sucesso!', 'success');
                break;

            case 'unsuspend':
                $professional->removeSuspension();
                $this->repository->save($professional);
                add_settings_error('limpvix_professionals', 'success', 'Suspensão removida com sucesso!', 'success');
                break;

            case 'ban':
                $professional->banPermanently('Banimento manual pelo admin (user #' . get_current_user_id() . ')');
                $this->repository->save($professional);
                add_settings_error('limpvix_professionals', 'success', 'Profissional banido permanentemente.', 'success');
                break;

            case 'unban':
                $professional->liftBan();
                $this->repository->save($professional);
                add_settings_error('limpvix_professionals', 'success', 'Banimento removido. Profissional reativado.', 'success');
                break;

            case 'activate':
                $professional->activate();
                $this->repository->save($professional);
                add_settings_error('limpvix_professionals', 'success', 'Profissional ativado.', 'success');
                break;

            case 'deactivate':
                $professional->deactivate();
                $this->repository->save($professional);
                add_settings_error('limpvix_professionals', 'success', 'Profissional desativado.', 'success');
                break;

            default:
                return;
        }

        set_transient('limpvix_professional_action_result', get_settings_errors('limpvix_professionals'), 30);
        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    public function handleFormSubmission(): void
    {
        // Address Autofill Module (CEP)
        wp_enqueue_script('limpvix-address-autofill', LIMPVIX_PLUGIN_URL . 'assets/js/modules/address-autofill.js', [], LIMPVIX_VERSION, true);
        if (isset($_GET['action']) && in_array($_GET['action'], ['create', 'edit'])) {
            wp_add_inline_script('limpvix-address-autofill', 'document.addEventListener("DOMContentLoaded",function(){if(window.LimpVix&&window.LimpVix.AddressAutofill){new window.LimpVix.AddressAutofill("#zipcode",{debounceMs:500,showLoading:true,overwriteFields:false});console.log("[LimpVix] CEP autofill ready");}});');
        }
        if (!isset($_POST['limpvix_professional_action_type'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $action = sanitize_text_field($_POST['limpvix_professional_action_type']);
        $nonceField = 'limpvix_professional_nonce_' . $action;

        if (!isset($_POST[$nonceField]) || !wp_verify_nonce($_POST[$nonceField], self::NONCE_ACTION . '_' . $action)) {
            wp_die('Nonce inválido');
        }

        switch ($action) {
            case 'register':
                $this->handleRegister();
                break;
            case 'verify':
                $this->handleVerify();
                break;
            case 'suspend':
                $this->handleSuspend();
                break;
            case 'unsuspend':
                $this->handleUnsuspend();
                break;
            case 'update_score':
                $this->handleUpdateScore();
                break;
            case 'deactivate':
                $this->handleDeactivate();
                break;
        }
    }

    private function handleRegister(): void
    {
        $data = [
            'full_name' => sanitize_text_field($_POST['full_name'] ?? ''),
            'cpf' => sanitize_text_field($_POST['cpf'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'address' => [
                'street' => sanitize_text_field($_POST['street'] ?? ''),
                'number' => sanitize_text_field($_POST['number'] ?? ''),
                'complement' => sanitize_text_field($_POST['complement'] ?? ''),
                'neighborhood' => sanitize_text_field($_POST['neighborhood'] ?? ''),
                'city' => sanitize_text_field($_POST['city'] ?? 'Vitória'),
                'state' => sanitize_text_field($_POST['state'] ?? 'ES'),
                'zipcode' => sanitize_text_field($_POST['zipcode'] ?? ''),
                'latitude' => !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null,
                'longitude' => !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null,
            ],
            'skills' => isset($_POST['skills']) ? array_map('sanitize_text_field', $_POST['skills']) : [],
            'certifications' => isset($_POST['certifications']) ? array_map('sanitize_text_field', $_POST['certifications']) : [],
            'physical_limitations' => isset($_POST['physical_limitations']) ? array_map('sanitize_text_field', $_POST['physical_limitations']) : [],
            'service_radius_km' => !empty($_POST['service_radius_km']) ? (int)$_POST['service_radius_km'] : 20,
            'weekly_availability' => $this->parseWeeklyAvailability($_POST),
        ];

        $useCase = new RegisterProfessional($this->repository);
        $result = $useCase->execute($data);

        if (is_wp_error($result)) {
            add_settings_error(
                'limpvix_professionals',
                $result->get_error_code(),
                $result->get_error_message(),
                'error'
            );
        } else {
            // Salvar skills do profissional
            $professionalId = $result['professional_id'];
            
            if (!empty($_POST['services'])) {
                foreach ($_POST['services'] as $serviceId) {
                    $serviceId = (int)$serviceId;
                    $hasNR06 = isset($_POST['service_nr06'][$serviceId]) ? 1 : 0;

                    $this->wpdb->insert(
                        $this->wpdb->prefix . 'limpvix_professional_skills',
                        [
                            'professional_id' => $professionalId,
                            'service_id' => $serviceId,
                            'has_certification' => $hasNR06,
                            'certification_number' => $hasNR06 ? 'NR-06' : null,
                            'certification_issuer' => $hasNR06 ? 'Treinamento NR-06 (Uso de EPI)' : null,
                            'years_of_experience' => 0,
                            'is_active' => 1,
                            'created_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql'),
                        ]
                    );
                }
            }
            
            add_settings_error(
                'limpvix_professionals',
                'success',
                'Profissional registrado com sucesso!',
                'success'
            );
        }

        set_transient('limpvix_professional_action_result', get_settings_errors('limpvix_professionals'), 30);
        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private function handleVerify(): void
    {
        $professionalId = (int)($_POST['professional_id'] ?? 0);

        if ($professionalId <= 0) {
            add_settings_error('limpvix_professionals', 'invalid_id', 'ID inválido', 'error');
            return;
        }

        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            add_settings_error('limpvix_professionals', 'not_found', 'Profissional não encontrado', 'error');
            return;
        }

        $professional->verify(get_current_user_id());
        $this->repository->save($professional);

        add_settings_error('limpvix_professionals', 'success', 'Profissional verificado!', 'success');
        set_transient('limpvix_professional_action_result', get_settings_errors('limpvix_professionals'), 30);

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private function handleSuspend(): void
    {
        $professionalId = (int)($_POST['professional_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['suspension_reason'] ?? '');
        $days = (int)($_POST['suspension_days'] ?? 7);

        if ($professionalId <= 0 || empty($reason)) {
            add_settings_error('limpvix_professionals', 'invalid_data', 'Dados inválidos', 'error');
            return;
        }

        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            add_settings_error('limpvix_professionals', 'not_found', 'Profissional não encontrado', 'error');
            return;
        }

        $until = new \DateTimeImmutable("+{$days} days");
        $professional->suspend($until, $reason);
        $this->repository->save($professional);

        add_settings_error('limpvix_professionals', 'success', "Profissional suspenso por {$days} dias", 'success');
        set_transient('limpvix_professional_action_result', get_settings_errors('limpvix_professionals'), 30);

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private function handleUnsuspend(): void
    {
        $professionalId = (int)($_POST['professional_id'] ?? 0);

        if ($professionalId <= 0) {
            add_settings_error('limpvix_professionals', 'invalid_id', 'ID inválido', 'error');
            return;
        }

        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            add_settings_error('limpvix_professionals', 'not_found', 'Profissional não encontrado', 'error');
            return;
        }

        $professional->removeSuspension();
        $this->repository->save($professional);

        add_settings_error('limpvix_professionals', 'success', 'Suspensão removida!', 'success');
        set_transient('limpvix_professional_action_result', get_settings_errors('limpvix_professionals'), 30);

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private function handleUpdateScore(): void
    {
        $professionalId = (int)($_POST['professional_id'] ?? 0);
        $reason = sanitize_text_field($_POST['score_reason'] ?? '');
        $rating = isset($_POST['rating']) ? (float)$_POST['rating'] : null;

        if ($professionalId <= 0 || empty($reason)) {
            add_settings_error('limpvix_professionals', 'invalid_data', 'Dados inválidos', 'error');
            return;
        }

        $details = [];
        if ($rating !== null) {
            $details['rating'] = $rating;
        }
        $details['changed_by'] = 'admin';
        $details['admin_user_id'] = get_current_user_id();

        $useCase = new UpdateProfessionalScore($this->repository);
        $result = $useCase->execute([
            'professional_id' => $professionalId,
            'reason' => $reason,
            'details' => $details,
        ]);

        if (is_wp_error($result)) {
            add_settings_error('limpvix_professionals', 'error', $result->get_error_message(), 'error');
        } else {
            add_settings_error('limpvix_professionals', 'success', $result['message'], 'success');
        }

        set_transient('limpvix_professional_action_result', get_settings_errors('limpvix_professionals'), 30);
        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private function handleDeactivate(): void
    {
        $professionalId = (int)($_POST['professional_id'] ?? 0);

        if ($professionalId <= 0) {
            add_settings_error('limpvix_professionals', 'invalid_id', 'ID inválido', 'error');
            return;
        }

        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            add_settings_error('limpvix_professionals', 'not_found', 'Profissional não encontrado', 'error');
            return;
        }

        $professional->deactivate();
        $this->repository->save($professional);

        add_settings_error('limpvix_professionals', 'success', 'Profissional desativado!', 'success');
        set_transient('limpvix_professional_action_result', get_settings_errors('limpvix_professionals'), 30);

        wp_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('admin.php')));
        exit;
    }

    private function parseWeeklyAvailability(array $post): array
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $availability = [];

        foreach ($days as $day) {
            $enabled = isset($post["available_{$day}"]);
            if ($enabled) {
                $start = sanitize_text_field($post["{$day}_start"] ?? '08:00');
                $end = sanitize_text_field($post["{$day}_end"] ?? '18:00');
                $availability[$day] = [['start' => $start, 'end' => $end]];
            }
        }

        return $availability;
    }

    public function render(): void
    {
        $action = $_GET['action'] ?? 'list';
        $tab = $_GET['tab'] ?? 'professionals';

        $professionalId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        // DEBUG: Log para confirmar que este método está sendo chamado
        error_log('[DEBUG ProfessionalManagementPage] render() called - action: ' . $action . ' - tab: ' . $tab . ' - PAGE_SLUG: ' . self::PAGE_SLUG);

        ?>
        <div class="wrap">

            <?php settings_errors('limpvix_professionals'); ?>

            <!-- Tab Navigation -->
            <?php if ($action === 'list'): ?>
                <nav class="nav-tab-wrapper wp-clearfix" style="margin: 20px 0;">
                    <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=professionals" 
                       class="nav-tab <?php echo $tab === 'professionals' ? 'nav-tab-active' : ''; ?>">
                        👥 Listagem
                    </a>
                    <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=kyc"
                       class="nav-tab <?php echo $tab === 'kyc' ? 'nav-tab-active' : ''; ?>">
                        🔐 KYC Biométrico
                    </a>
                    <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=risk_score"
                       class="nav-tab <?php echo $tab === 'risk_score' ? 'nav-tab-active' : ''; ?>">
                        🛡️ Risk Score
                    </a>
                    <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=payouts"
                       class="nav-tab <?php echo $tab === 'payouts' ? 'nav-tab-active' : ''; ?>">
                        💰 Payouts
                    </a>
                </nav>
            <?php endif; ?>

            <?php
            // Handle KYC details view
            if ($tab === 'kyc' && isset($_GET['kyc_action']) && $_GET['kyc_action'] === 'view' && $professionalId) {
                $this->renderKycDetails($professionalId);
                echo '</div>';
                return;
            }

            // Handle tabs
            if ($action === 'list') {
                if ($tab === 'kyc') {
                    $this->renderKycTab();
                } elseif ($tab === 'risk_score') {
                    $this->renderRiskScoreTab();
                } elseif ($tab === 'payouts') {
                    $this->renderPayoutsTab();
                } else {
                    // Default: professionals tab
                    $this->renderStatistics($this->repository->getStatistics());
                    $this->renderProfessionalsTable();
                }
            } else {
                // Edit/Create forms
                if ($action === 'edit' || $action === 'create') {
                    echo '<a href="?page=' . self::PAGE_SLUG . '" class="page-title-action">← Voltar</a>';
                }
                
                switch ($action) {
                    case 'create':
                        $this->renderProfessionalForm();
                        break;
                    case 'edit':
                        $this->renderProfessionalForm($professionalId);
                        break;
                }
            }
            ?>
        </div>
        <?php
    }

    private function renderMessages($messages): void
    {
        if (!$messages) return;

        foreach ($messages as $message) {
            $type = $message['type'] === 'error' ? 'error' : 'updated';
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>';
            echo esc_html($message['message']);
            echo '</p></div>';
        }
    }

    private function renderProfessionalForm(int $professionalId = 0): void
    {
        // Buscar serviços do catálogo
        $services = $this->wpdb->get_results(
            "SELECT id, service_code, category, service_type, display_name 
             FROM {$this->wpdb->prefix}limpvix_service_catalog 
             WHERE is_active = 1 
             ORDER BY category, service_type",
            ARRAY_A
        );

        // Agrupar por categoria
        $servicesByCategory = ['commercial' => [], 'residential' => []];
        foreach ($services as $service) {
            $servicesByCategory[$service['category']][] = $service;
        }

        $isEdit = $professionalId > 0;
        $professional = $isEdit ? $this->repository->findById($professionalId) : null;

        // Se editing, buscar skills do profissional
        $professionalSkills = [];
        if ($isEdit) {
            $skills = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT service_id, has_certification, years_of_experience 
                     FROM {$this->wpdb->prefix}limpvix_professional_skills 
                     WHERE professional_id = %d AND is_active = 1",
                    $professionalId
                ),
                ARRAY_A
            );
            foreach ($skills as $skill) {
                $professionalSkills[$skill['service_id']] = [
                    'has_certification' => (bool)$skill['has_certification'],
                    'years_of_experience' => (int)$skill['years_of_experience']
                ];
            }
        }

        ?>
        <div class="limpvix-professional-form" style="background: #fff; padding: 20px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2><?php echo $isEdit ? 'Editar Profissional' : 'Registrar Novo Profissional'; ?></h2>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION . '_register', 'limpvix_professional_nonce_register'); ?>
                <input type="hidden" name="limpvix_professional_action_type" value="register">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="professional_id" value="<?php echo $professionalId; ?>">
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th colspan="2"><h3>Dados Pessoais</h3></th>
                    </tr>
                    <tr>
                        <th><label for="full_name">Nome Completo *</label></th>
                        <td><input type="text" name="full_name" id="full_name" class="regular-text" required value="<?php echo $isEdit && $professional ? esc_attr($professional->getFullName()) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="cpf">CPF *</label></th>
                        <td><input type="text" name="cpf" id="cpf" class="regular-text" required placeholder="000.000.000-00" value="<?php echo $isEdit && $professional ? esc_attr($professional->getCpf()) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="email">Email *</label></th>
                        <td><input type="email" name="email" id="email" class="regular-text" required value="<?php echo $isEdit && $professional ? esc_attr($professional->getEmail()) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="phone">Telefone *</label></th>
                        <td><input type="text" name="phone" id="phone" class="regular-text" required placeholder="(27) 99999-9999" value="<?php echo $isEdit && $professional ? esc_attr($professional->getPhone()) : ''; ?>"></td>
                    </tr>

                    <tr>
                        <th colspan="2"><h3>Endereço</h3></th>
                    </tr>
                    <tr>
                        <th><label for="zipcode">CEP *</label></th>
                        <td>
                            <input type="text" name="zipcode" id="zipcode" class="regular-text" placeholder="00000-000" required>
                            <p class="description">Digite o CEP para preencher automaticamente o endereço</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="street">Rua *</label></th>
                        <td><input type="text" name="street" id="street" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="number">Número *</label></th>
                        <td><input type="text" name="number" id="number" class="small-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="complement">Complemento</label></th>
                        <td><input type="text" name="complement" id="complement" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="neighborhood">Bairro *</label></th>
                        <td><input type="text" name="neighborhood" id="neighborhood" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="city">Cidade *</label></th>
                        <td><input type="text" name="city" id="city" class="regular-text" value="Vitória" required></td>
                    </tr>
                    <tr>
                        <th><label for="state">Estado *</label></th>
                        <td><input type="text" name="state" id="state" class="small-text" value="ES" required></td>
                    </tr>

                    <tr>
                        <th colspan="2"><h3>Região de Atuação</h3></th>
                    </tr>
                    <tr>
                        <th><label for="service_radius_km">Raio de Atendimento (km) *</label></th>
                        <td>
                            <input type="number" name="service_radius_km" id="service_radius_km"
                                   class="small-text" value="20" min="1" max="100" required>
                            <p class="description">Raio em km a partir do endereço cadastrado</p>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2"><h3>Skills e Certificações</h3></th>
                    </tr>
                    <?php foreach (['commercial' => 'Serviços Comerciais', 'residential' => 'Serviços Residenciais'] as $category => $categoryLabel): ?>
                        <?php if (!empty($servicesByCategory[$category])): ?>
                            <tr>
                                <th colspan="2">
                                    <h4 style="margin: 15px 0 10px; color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 5px;">
                                        <?php echo esc_html($categoryLabel); ?>
                                    </h4>
                                </th>
                            </tr>

                            <?php foreach ($servicesByCategory[$category] as $service): ?>
                                <?php
                                $serviceId = $service['id'];
                                $isChecked = isset($professionalSkills[$serviceId]);
                                $hasNR06 = $isChecked && $professionalSkills[$serviceId]['has_certification'];
                                
                                // NR-06 apenas para Comercial Pós-Obra
                                $requiresNR06 = ($service['category'] === 'commercial' && $service['service_type'] === 'post_construction');
                                ?>
                                <tr>
                                    <th style="padding-left: 30px; width: <?php echo $requiresNR06 ? '40%' : '100%'; ?>;">
                                        <label>
                                            <input type="checkbox"
                                                   name="services[]"
                                                   value="<?php echo esc_attr($serviceId); ?>"
                                                   <?php checked($isChecked); ?>>
                                            <strong><?php echo esc_html($service['display_name']); ?></strong>
                                        </label>
                                    </th>
                                    <?php if ($requiresNR06): ?>
                                        <td>
                                            <label style="display: inline-flex; align-items: center; gap: 8px;">
                                                <input type="checkbox"
                                                       name="service_nr06[<?php echo esc_attr($serviceId); ?>]"
                                                       value="1"
                                                       <?php checked($hasNR06); ?>>
                                                <span style="color: #d63638; font-weight: 600;">Possui Treinamento NR-06 (Uso de EPI)</span>
                                            </label>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <tr>
                        <td colspan="2">
                            <div style="margin-top: 15px; padding: 12px; background: #ffebee; border-left: 4px solid #d63638;">
                                <strong style="color: #d63638;">⚠️ NR-06 - Equipamento de Proteção Individual:</strong>
                                <p style="margin: 10px 0 0 0; line-height: 1.6;">
                                    <strong>Limpeza Comercial Pós-Obra:</strong> Treinamento NR-06 é <strong>OBRIGATÓRIO</strong> devido ao alto risco 
                                    (poeira, resíduos cortantes, produtos químicos).
                                </p>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2"><h3>Disponibilidade</h3></th>
                    </tr>
                    <?php
                    $days = [
                        'monday' => 'Segunda-feira',
                        'tuesday' => 'Terça-feira',
                        'wednesday' => 'Quarta-feira',
                        'thursday' => 'Quinta-feira',
                        'friday' => 'Sexta-feira',
                        'saturday' => 'Sábado',
                        'sunday' => 'Domingo',
                    ];
                    foreach ($days as $key => $label):
                        $checked = in_array($key, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']) ? 'checked' : '';
                    ?>
                    <tr>
                        <th><label><?php echo $label; ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="available_<?php echo $key; ?>" <?php echo $checked; ?>>
                                Disponível
                            </label>
                            <input type="time" name="<?php echo $key; ?>_start" value="08:00" class="small-text">
                            até
                            <input type="time" name="<?php echo $key; ?>_end" value="18:00" class="small-text">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">
                        <?php echo $isEdit ? 'Atualizar Profissional' : 'Registrar Profissional'; ?>
                    </button>
                    <a href="?page=<?php echo self::PAGE_SLUG; ?>" class="button button-large">Cancelar</a>
                </p>
            </form>
        </div>
        <?php
    }

    private function renderStatistics(array $stats): void
    {
        // REFATORADO (FASE 2): Usar GetProfessionalStatistics Use Case
        $statsData = isset($this->useCases['get_statistics'])
            ? $this->useCases['get_statistics']->execute()
            : [
                'total' => 0,
                'active' => 0,
                'verified' => 0,
                'suspended' => 0,
                'average_score' => 0,
            ];

        $active = $statsData['active'];
        $total = $statsData['total'];
        $avgScore = $statsData['average_score'];
        $verified = $statsData['verified'];
        $suspended = $statsData['suspended'];

        // Calcular pending (ainda usa repository por enquanto)
        $pending = count($this->repository->findPendingVerification());

        // Distribuição de score por faixa (query direta — leve, sem aggregate)
        $table = $this->wpdb->prefix . 'limpvix_professionals';
        $scoreDist = $this->wpdb->get_row(
            "SELECT
                COUNT(CASE WHEN score >= 4.5 THEN 1 END) AS excellent,
                COUNT(CASE WHEN score >= 4.0 AND score < 4.5 THEN 1 END) AS good,
                COUNT(CASE WHEN score >= 3.0 AND score < 4.0 THEN 1 END) AS regular,
                COUNT(CASE WHEN score >= 2.0 AND score < 3.0 THEN 1 END) AS poor,
                COUNT(CASE WHEN score < 2.0 THEN 1 END) AS critical
             FROM {$table}",
            ARRAY_A
        ) ?: ['excellent' => 0, 'good' => 0, 'regular' => 0, 'poor' => 0, 'critical' => 0];

        $slug = self::PAGE_SLUG;

        ?>
        <!-- ── Hero Card – Profissionais ────────────────────────────────────────── -->
        <div style="background: linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 55%, #2563eb 100%); border-radius: 12px; padding: 32px; margin-bottom: 24px; box-shadow: 0 8px 32px rgba(30,58,138,0.40); position: relative; overflow: hidden;">

            <!-- Círculos decorativos de fundo -->
            <div style="position:absolute; top:-60px; right:-60px; width:220px; height:220px; background:rgba(255,255,255,0.06); border-radius:50%; pointer-events:none;"></div>
            <div style="position:absolute; bottom:-50px; left:240px; width:170px; height:170px; background:rgba(255,255,255,0.05); border-radius:50%; pointer-events:none;"></div>

            <!-- Cabeçalho: título + CTA -->
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:28px; position:relative; z-index:1; flex-wrap:wrap; gap:12px;">
                <div>
                    <h2 style="color:#fff; margin:0 0 8px 0; font-size:26px; font-weight:700; line-height:1.2;">
                        👥 Gestão de Profissionais
                    </h2>
                    <p style="color:rgba(255,255,255,0.80); margin:0; font-size:14px;">
                        Cadastro, verificação e monitoramento da força de trabalho LimpVix
                    </p>
                </div>
                <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&action=create"
                   style="background:rgba(255,255,255,0.18); color:#fff; text-decoration:none; padding:10px 22px; border-radius:8px; font-size:14px; font-weight:600; border:1px solid rgba(255,255,255,0.35); backdrop-filter:blur(4px); white-space:nowrap; display:inline-flex; align-items:center; gap:6px;">
                    ➕ Novo Profissional
                </a>
            </div>

            <!-- Linha 1: métricas operacionais (6 colunas) -->
            <div style="display:grid; grid-template-columns:repeat(6,1fr); gap:12px; position:relative; z-index:1; margin-bottom:12px;">

                <!-- Total -->
                <div style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 12px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:32px; font-weight:700; color:#fff; line-height:1;"><?php echo esc_html($total); ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Total</div>
                </div>

                <!-- Ativos -->
                <a href="?page=<?php echo esc_attr($slug); ?>&tab=professionals&filter_status=active"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 12px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:32px; font-weight:700; color:#4ade80; line-height:1;"><?php echo esc_html($active); ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Ativos ↗</div>
                </a>

                <!-- Verificados -->
                <a href="?page=<?php echo esc_attr($slug); ?>&tab=professionals&filter_verified=verified"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 12px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:32px; font-weight:700; color:#86efac; line-height:1;"><?php echo esc_html($verified); ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Verificados ↗</div>
                </a>

                <!-- Pendentes (ativos não verificados) -->
                <a href="?page=<?php echo esc_attr($slug); ?>&tab=professionals&filter_status=active&filter_verified=not_verified"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 12px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:32px; font-weight:700; color:#fbbf24; line-height:1;"><?php echo esc_html($pending); ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Pendentes ↗</div>
                </a>

                <!-- Suspensos -->
                <a href="?page=<?php echo esc_attr($slug); ?>&tab=professionals&filter_status=suspended"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 12px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:32px; font-weight:700; color:#f87171; line-height:1;"><?php echo esc_html($suspended); ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Suspensos ↗</div>
                </a>

                <!-- Score Médio (não filtrável diretamente) -->
                <div style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 12px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:28px; font-weight:700; color:#fde68a; line-height:1;"><?php echo number_format($avgScore, 1, ',', '.'); ?>★</div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Score Médio</div>
                </div>

            </div><!-- /linha 1 -->

            <!-- Linha 2: distribuição de score (5 faixas clicáveis) -->
            <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:10px; position:relative; z-index:1;">

                <!-- Excelente ≥4.5 -->
                <a href="?page=<?php echo esc_attr($slug); ?>&tab=professionals&filter_score=4.5"
                   style="background:rgba(134,239,172,0.20); border:1px solid rgba(134,239,172,0.35); border-radius:8px; padding:12px 8px; text-align:center; text-decoration:none; display:block;" onmouseover="this.style.background='rgba(134,239,172,0.35)'" onmouseout="this.style.background='rgba(134,239,172,0.20)'">
                    <div style="font-size:22px; font-weight:700; color:#86efac; line-height:1;"><?php echo esc_html($scoreDist['excellent']); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600;">⭐⭐⭐⭐⭐ Excelente</div>
                    <div style="font-size:9px; color:rgba(255,255,255,0.55); margin-top:2px;">≥ 4.5 ↗</div>
                </a>

                <!-- Bom 4.0–4.49 -->
                <a href="?page=<?php echo esc_attr($slug); ?>&tab=professionals&filter_score=4.0"
                   style="background:rgba(74,222,128,0.15); border:1px solid rgba(74,222,128,0.30); border-radius:8px; padding:12px 8px; text-align:center; text-decoration:none; display:block;" onmouseover="this.style.background='rgba(74,222,128,0.28)'" onmouseout="this.style.background='rgba(74,222,128,0.15)'">
                    <div style="font-size:22px; font-weight:700; color:#4ade80; line-height:1;"><?php echo esc_html($scoreDist['good']); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600;">⭐⭐⭐⭐ Bom</div>
                    <div style="font-size:9px; color:rgba(255,255,255,0.55); margin-top:2px;">4.0 – 4.49 ↗</div>
                </a>

                <!-- Regular 3.0–3.99 -->
                <a href="?page=<?php echo esc_attr($slug); ?>&tab=professionals&filter_score=3.0"
                   style="background:rgba(251,191,36,0.15); border:1px solid rgba(251,191,36,0.30); border-radius:8px; padding:12px 8px; text-align:center; text-decoration:none; display:block;" onmouseover="this.style.background='rgba(251,191,36,0.28)'" onmouseout="this.style.background='rgba(251,191,36,0.15)'">
                    <div style="font-size:22px; font-weight:700; color:#fbbf24; line-height:1;"><?php echo esc_html($scoreDist['regular']); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600;">⭐⭐⭐ Regular</div>
                    <div style="font-size:9px; color:rgba(255,255,255,0.55); margin-top:2px;">3.0 – 3.99 ↗</div>
                </a>

                <!-- Problemas <3.0 -->
                <a href="?page=<?php echo esc_attr($slug); ?>&tab=professionals&filter_score=below3"
                   style="background:rgba(248,113,113,0.15); border:1px solid rgba(248,113,113,0.30); border-radius:8px; padding:12px 8px; text-align:center; text-decoration:none; display:block;" onmouseover="this.style.background='rgba(248,113,113,0.28)'" onmouseout="this.style.background='rgba(248,113,113,0.15)'">
                    <div style="font-size:22px; font-weight:700; color:#f87171; line-height:1;"><?php echo esc_html($scoreDist['poor']); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600;">⭐⭐ Problemas</div>
                    <div style="font-size:9px; color:rgba(255,255,255,0.55); margin-top:2px;">2.0 – 2.99 ↗</div>
                </a>

                <!-- Crítico <2.0 -->
                <a href="?page=<?php echo esc_attr($slug); ?>&tab=professionals&filter_score=below2"
                   style="background:rgba(220,38,38,0.20); border:1px solid rgba(220,38,38,0.40); border-radius:8px; padding:12px 8px; text-align:center; text-decoration:none; display:block;" onmouseover="this.style.background='rgba(220,38,38,0.35)'" onmouseout="this.style.background='rgba(220,38,38,0.20)'">
                    <div style="font-size:22px; font-weight:700; color:#fca5a5; line-height:1;"><?php echo esc_html($scoreDist['critical']); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600;">⭐ Crítico</div>
                    <div style="font-size:9px; color:rgba(255,255,255,0.55); margin-top:2px;">&lt; 2.0 ↗</div>
                </a>

            </div><!-- /linha 2 -->
        </div><!-- /hero card -->
        <?php
    }

    private function renderFilters($filter_status, $filter_verified, $filter_score, $search): void
    {
        ?>
        <div class="tablenav top" style="background: #fff; padding: 15px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <select name="filter_status" style="min-width: 150px;">
                        <option value="all" <?php selected($filter_status, 'all'); ?>>Todos os Status</option>
                        <option value="active" <?php selected($filter_status, 'active'); ?>>Ativos</option>
                        <option value="inactive" <?php selected($filter_status, 'inactive'); ?>>Inativos</option>
                        <option value="suspended" <?php selected($filter_status, 'suspended'); ?>>Suspensos</option>
                    </select>

                    <select name="filter_verified" style="min-width: 150px;">
                        <option value="all" <?php selected($filter_verified, 'all'); ?>>Todos Verificação</option>
                        <option value="verified" <?php selected($filter_verified, 'verified'); ?>>Verificados</option>
                        <option value="not_verified" <?php selected($filter_verified, 'not_verified'); ?>>Não Verificados</option>
                    </select>

                    <label style="display: flex; align-items: center; gap: 5px;">
                        Score mínimo:
                        <input type="number" name="filter_score" value="<?php echo esc_attr($filter_score); ?>"
                               min="0" max="5" step="0.1" style="width: 80px;">
                    </label>

                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                           placeholder="Buscar por nome, CPF ou email..." style="min-width: 300px;">

                    <button type="submit" class="button">Filtrar</button>
                    <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>" class="button">Limpar</a>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Get professionals with filters (REFATORADO - FASE 2)
     *
     * FASE 2.2 - USE CASE PATTERN:
     * - Usa ListProfessionals Use Case
     * - Whitelist validation, prepared statements, esc_like já implementados no Use Case
     * - Código mais limpo e testável
     *
     * @param string $filter_status Status filter ('all', 'active', 'inactive', 'suspended')
     * @param string $filter_verified Verification filter ('all', 'verified', 'not_verified')
     * @param float $filter_score Minimum score filter
     * @param string $search Search term
     * @return array Array of professional records
     */
    private function getProfessionals($filter_status, $filter_verified, $filter_score, $search): array
    {
        // REFATORADO (FASE 2): Usar ListProfessionals Use Case
        $filters = [
            'status' => $filter_status,
            'verified' => $filter_verified,
            'min_score' => $filter_score,
            'search' => $search,
            'limit' => 100, // Por enquanto, hardcoded (FASE 3 implementará paginação)
        ];

        return isset($this->useCases['list'])
            ? $this->useCases['list']->execute($filters)
            : [];
    }

    private function renderProfessionalsTable(): void
    {
        // REFATORADO (FASE 3): Usar WP_List_Table com paginação nativa
        $listTable = new \LimpVix\Infrastructure\Admin\Tables\Professional_List_Table($this->useCases);
        $listTable->prepare_items();
        ?>
        <div style="background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,0.10); overflow:hidden;">

            <!-- Cabeçalho da tabela -->
            <div style="padding:20px 24px 0; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; margin-bottom:16px;">
                <h3 style="margin:0 0 16px 0; font-size:16px; color:#1e3a8a; font-weight:700; display:flex; align-items:center; gap:8px;">
                    📋 <span>Lista de Profissionais</span>
                    <span style="background:#e0e7ff; color:#3730a3; font-size:12px; font-weight:600; padding:2px 8px; border-radius:20px; margin-left:4px;">
                        <?php
                        $count = is_array($listTable->items) ? count($listTable->items) : 0;
                        echo esc_html($count);
                        ?> registros
                    </span>
                </h3>
            </div>

            <!-- Busca + Tabela -->
            <form method="get" style="padding:0 24px 24px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                <input type="hidden" name="tab" value="professionals" />
                <?php
                $listTable->search_box('🔍 Buscar profissional', 'professional-search');
                $listTable->display();
                ?>
            </form>

        </div>
        <?php
    }

    private function renderProfessionalRow(array $prof): void
    {
        $score = (float)$prof['score'];
        $scoreColor = $score >= 4.5 ? '#00a32a' : ($score >= 3.5 ? '#f0b849' : '#d63638');

        $isActive = (bool)$prof['is_active'];
        $isVerified = (bool)$prof['is_verified'];
        $isSuspended = !empty($prof['suspended_until']) && strtotime($prof['suspended_until']) > time();

        $statusBadge = $isActive
            ? ($isSuspended ? '<span class="badge badge-suspended">Suspenso</span>' : '<span class="badge badge-active">Ativo</span>')
            : '<span class="badge badge-inactive">Inativo</span>';

        $verifiedBadge = $isVerified
            ? '<span class="badge badge-verified">✓ Verificado</span>'
            : '<span class="badge badge-pending">Pendente</span>';

        $region = json_decode($prof['service_region'], true);
        $regionText = isset($region['radius_km']) ? $region['radius_km'] . ' km' : '-';

        $skills = json_decode($prof['skills'], true);
        $skillsText = is_array($skills) ? implode(', ', array_slice($skills, 0, 3)) : '-';
        if (is_array($skills) && count($skills) > 3) {
            $skillsText .= '... +' . (count($skills) - 3);
        }

        ?>
        <tr>
            <td><?php echo $prof['id']; ?></td>
            <td><strong><?php echo esc_html($prof['full_name']); ?></strong></td>
            <td><?php echo esc_html($prof['cpf']); ?></td>
            <td>
                <?php echo esc_html($prof['email']); ?><br>
                <small><?php echo esc_html($prof['phone']); ?></small>
            </td>
            <td>
                <strong style="color: <?php echo $scoreColor; ?>; font-size: 16px;">
                    <?php echo number_format($score, 2, ',', '.'); ?>
                </strong>
            </td>
            <td><?php echo $statusBadge; ?></td>
            <td><?php echo $verifiedBadge; ?></td>
            <td><?php echo esc_html($regionText); ?></td>
            <td><small><?php echo esc_html($skillsText); ?></small></td>
            <td>
                <?php $this->renderActions($prof); ?>
            </td>
        </tr>
        <style>
            .badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .badge-active { background: #d4edda; color: #155724; }
            .badge-inactive { background: #f8d7da; color: #721c24; }
            .badge-suspended { background: #fff3cd; color: #856404; }
            .badge-verified { background: #d1ecf1; color: #0c5460; }
            .badge-pending { background: #fff3cd; color: #856404; }
        </style>
        <?php
    }

    private function renderActions(array $prof): void
    {
        $professionalId = $prof['id'];
        $isActive = (bool)$prof['is_active'];
        $isVerified = (bool)$prof['is_verified'];
        $isSuspended = !empty($prof['suspended_until']) && strtotime($prof['suspended_until']) > time();

        ?>
        <div class="row-actions" style="white-space: nowrap;">
            <span class="view">
                <a href="?page=<?php echo self::PAGE_SLUG; ?>-detail&id=<?php echo $professionalId; ?>">Ver Detalhes</a> |
            </span>

            <?php if (!$isVerified && $isActive): ?>
                <span class="verify">
                    <a href="#" class="btn-verify-professional" data-id="<?php echo $professionalId; ?>"
                       style="color: #00a32a;">Verificar</a> |
                </span>
            <?php endif; ?>

            <?php if ($isActive && !$isSuspended): ?>
                <span class="suspend">
                    <a href="#" class="btn-suspend-professional" data-id="<?php echo $professionalId; ?>"
                       style="color: #d63638;">Suspender</a> |
                </span>
            <?php endif; ?>

            <?php if ($isSuspended): ?>
                <span class="unsuspend">
                    <a href="#" class="btn-unsuspend-professional" data-id="<?php echo $professionalId; ?>"
                       style="color: #00a32a;">Remover Suspensão</a> |
                </span>
            <?php endif; ?>

            <span class="score">
                <a href="#" class="btn-update-score" data-id="<?php echo $professionalId; ?>">Atualizar Score</a> |
            </span>

            <?php if ($isActive): ?>
                <span class="deactivate">
                    <a href="#" class="btn-deactivate-professional" data-id="<?php echo $professionalId; ?>"
                       style="color: #d63638;">Desativar</a>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }


    /**
     * Render KYC tab main content
     */
    private function renderKycTab(): void
    {
        $statusFilter = sanitize_key($_GET['kyc_status'] ?? 'all');
        $search       = sanitize_text_field($_GET['kyc_search'] ?? '');

        // ── Hero card ──────────────────────────────────────────────────────────
        $this->renderKycStatistics();

        // ── Filtros ────────────────────────────────────────────────────────────
        $hasFilter = $statusFilter !== 'all' || $search !== '';
        ?>
        <div style="background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,0.10); padding:16px 24px; margin-bottom:20px;">
            <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="kyc">

                <!-- Filtro de status KYC -->
                <select name="kyc_status" style="min-width:180px;">
                    <option value="all"        <?php selected($statusFilter, 'all'); ?>>Todos os Status KYC</option>
                    <option value="not_started"<?php selected($statusFilter, 'not_started'); ?>>— Não Iniciado</option>
                    <option value="pending"    <?php selected($statusFilter, 'pending'); ?>>⏳ Pendente</option>
                    <option value="processing" <?php selected($statusFilter, 'processing'); ?>>🔄 Processando</option>
                    <option value="approved"   <?php selected($statusFilter, 'approved'); ?>>✅ Aprovado</option>
                    <option value="rejected"   <?php selected($statusFilter, 'rejected'); ?>>❌ Rejeitado</option>
                    <option value="expired"    <?php selected($statusFilter, 'expired'); ?>>⏰ Expirado</option>
                </select>

                <!-- Busca por nome / CPF / email -->
                <input type="search" name="kyc_search"
                       value="<?php echo esc_attr($search); ?>"
                       placeholder="🔍 Buscar por nome, CPF ou email…"
                       style="min-width:260px;">

                <button type="submit" class="button button-primary">Filtrar</button>

                <?php if ($hasFilter): ?>
                    <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=kyc" class="button">Limpar</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tabela de profissionais -->
        <?php $this->renderKycProfessionalsTable($statusFilter, $search); ?>
        <?php
    }

    /**
     * Render KYC statistics cards
     */
    private function renderKycStatistics(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_professionals';

        $stats = $wpdb->get_row("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN kyc_status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                SUM(CASE WHEN kyc_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN kyc_status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN kyc_status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN kyc_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN kyc_status = 'expired' THEN 1 ELSE 0 END) as expired
            FROM {$table}
        ", ARRAY_A);

        ?>
        <!-- ── Hero Card – KYC Biométrico ────────────────────────────────────── -->
        <div style="background:linear-gradient(135deg, #4c1d95 0%, #6d28d9 55%, #7c3aed 100%); border-radius:12px; padding:32px; margin-bottom:20px; box-shadow:0 8px 32px rgba(76,29,149,0.40); position:relative; overflow:hidden;">

            <!-- Círculos decorativos -->
            <div style="position:absolute; top:-60px; right:-60px; width:220px; height:220px; background:rgba(255,255,255,0.06); border-radius:50%; pointer-events:none;"></div>
            <div style="position:absolute; bottom:-50px; left:260px; width:170px; height:170px; background:rgba(255,255,255,0.05); border-radius:50%; pointer-events:none;"></div>

            <!-- Título -->
            <div style="margin-bottom:28px; position:relative; z-index:1;">
                <h2 style="color:#fff; margin:0 0 8px 0; font-size:26px; font-weight:700; line-height:1.2;">
                    🔐 KYC Biométrico
                </h2>
                <p style="color:rgba(255,255,255,0.80); margin:0; font-size:14px;">
                    OCR de documentos · Liveness Detection · Face Match — pipeline de verificação de identidade
                </p>
            </div>

            <!-- Grid 7 colunas — todos clicáveis exceto Total -->
            <?php $kycSlug = self::PAGE_SLUG; ?>
            <div style="display:grid; grid-template-columns:repeat(7,1fr); gap:10px; position:relative; z-index:1;">

                <!-- Total (estático) -->
                <div style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 10px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:34px; font-weight:700; color:#fff; line-height:1;"><?php echo (int) $stats['total']; ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Total</div>
                </div>

                <!-- Não Iniciado -->
                <a href="?page=<?php echo esc_attr($kycSlug); ?>&tab=kyc&kyc_status=not_started"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 10px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:34px; font-weight:700; color:#d1d5db; line-height:1;"><?php echo (int) $stats['not_started']; ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Não Iniciado ↗</div>
                </a>

                <!-- Pendente -->
                <a href="?page=<?php echo esc_attr($kycSlug); ?>&tab=kyc&kyc_status=pending"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 10px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:34px; font-weight:700; color:#fbbf24; line-height:1;"><?php echo (int) $stats['pending']; ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Pendente ↗</div>
                </a>

                <!-- Processando -->
                <a href="?page=<?php echo esc_attr($kycSlug); ?>&tab=kyc&kyc_status=processing"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 10px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:34px; font-weight:700; color:#67e8f9; line-height:1;"><?php echo (int) $stats['processing']; ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Processando ↗</div>
                </a>

                <!-- Aprovado -->
                <a href="?page=<?php echo esc_attr($kycSlug); ?>&tab=kyc&kyc_status=approved"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 10px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:34px; font-weight:700; color:#4ade80; line-height:1;"><?php echo (int) $stats['approved']; ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Aprovado ↗</div>
                </a>

                <!-- Rejeitado -->
                <a href="?page=<?php echo esc_attr($kycSlug); ?>&tab=kyc&kyc_status=rejected"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 10px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:34px; font-weight:700; color:#f87171; line-height:1;"><?php echo (int) $stats['rejected']; ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Rejeitado ↗</div>
                </a>

                <!-- Expirado -->
                <a href="?page=<?php echo esc_attr($kycSlug); ?>&tab=kyc&kyc_status=expired"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 10px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:34px; font-weight:700; color:#fde68a; line-height:1;"><?php echo (int) $stats['expired']; ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Expirado ↗</div>
                </a>

            </div><!-- /grid -->
        </div><!-- /hero card -->
        <?php
    }

    /**
     * Render KYC professionals table
     */
    private function renderKycProfessionalsTable(string $statusFilter, string $search = ''): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_professionals';

        // ── Build WHERE ────────────────────────────────────────────────────────
        $whereParts = ['1=1'];
        $params     = [];

        if ($statusFilter !== 'all') {
            $whereParts[] = 'kyc_status = %s';
            $params[]     = $statusFilter;
        }

        if ($search !== '') {
            $like         = '%' . $wpdb->esc_like($search) . '%';
            $whereParts[] = '(full_name LIKE %s OR cpf LIKE %s OR email LIKE %s OR phone LIKE %s)';
            $params       = array_merge($params, [$like, $like, $like, $like]);
        }

        $whereStr = 'WHERE ' . implode(' AND ', $whereParts);

        // ── Paginação ──────────────────────────────────────────────────────────
        $perPage     = 20;
        $currentPage = max(1, (int) ($_GET['kyc_paged'] ?? 1));
        $offset      = ($currentPage - 1) * $perPage;

        $orderSql = "ORDER BY
                CASE kyc_status
                    WHEN 'pending'     THEN 1
                    WHEN 'processing'  THEN 2
                    WHEN 'rejected'    THEN 3
                    WHEN 'not_started' THEN 4
                    WHEN 'approved'    THEN 5
                    WHEN 'expired'     THEN 6
                END,
                kyc_submitted_at DESC";

        // Total (para paginação)
        $countSql = empty($params)
            ? "SELECT COUNT(*) FROM {$table} {$whereStr}"
            : $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$whereStr}", ...$params);

        $total     = (int) $wpdb->get_var($countSql);
        $totalPages = max(1, (int) ceil($total / $perPage));

        // Dados da página
        $dataSql = empty($params)
            ? $wpdb->prepare(
                "SELECT id, full_name, cpf, email, phone, kyc_status,
                        kyc_started_at, kyc_submitted_at, kyc_approved_at,
                        kyc_rejected_at, kyc_retry_count, kyc_rejection_reason
                 FROM {$table} {$whereStr} {$orderSql} LIMIT %d OFFSET %d",
                $perPage, $offset
            )
            : $wpdb->prepare(
                "SELECT id, full_name, cpf, email, phone, kyc_status,
                        kyc_started_at, kyc_submitted_at, kyc_approved_at,
                        kyc_rejected_at, kyc_retry_count, kyc_rejection_reason
                 FROM {$table} {$whereStr} {$orderSql} LIMIT %d OFFSET %d",
                ...[...$params, $perPage, $offset]
            );

        $professionals = $wpdb->get_results($dataSql, ARRAY_A) ?? [];
        $count         = count($professionals);

        // URL base para paginação (preserva filtros)
        $paginationBase = add_query_arg([
            'page'       => self::PAGE_SLUG,
            'tab'        => 'kyc',
            'kyc_status' => $statusFilter !== 'all' ? $statusFilter : false,
            'kyc_search' => $search !== '' ? $search : false,
        ], admin_url('admin.php'));
        ?>
        <div style="background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,0.10); overflow:hidden;">

            <!-- Cabeçalho -->
            <div style="padding:20px 24px 16px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                <h3 style="margin:0; font-size:16px; color:#4c1d95; font-weight:700; display:flex; align-items:center; gap:8px;">
                    📋 <span>Verificações KYC</span>
                    <span style="background:#ede9fe; color:#6d28d9; font-size:12px; font-weight:600; padding:2px 8px; border-radius:20px; margin-left:4px;">
                        <?php echo esc_html($total); ?> registro<?php echo $total !== 1 ? 's' : ''; ?>
                    </span>
                    <?php if ($search !== ''): ?>
                        <span style="background:#fef3c7; color:#92400e; font-size:11px; font-weight:600; padding:2px 8px; border-radius:20px;">
                            🔍 "<?php echo esc_html($search); ?>"
                        </span>
                    <?php endif; ?>
                </h3>
                <?php if ($totalPages > 1): ?>
                    <span style="font-size:13px; color:#6b7280;">
                        Página <?php echo $currentPage; ?> de <?php echo $totalPages; ?>
                        (<?php echo $perPage; ?> por página)
                    </span>
                <?php endif; ?>
            </div>

            <!-- Tabela -->
            <div style="padding:0 24px 24px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th>Profissional</th>
                            <th style="width:120px;">CPF</th>
                            <th>Contato</th>
                            <th style="width:160px;">Status KYC</th>
                            <th style="width:140px;">Data Submissão</th>
                            <th style="width:100px;">Tentativas</th>
                            <th style="width:130px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($professionals)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:48px; color:#6b7280;">
                                    <div style="font-size:40px; margin-bottom:12px;">🔍</div>
                                    <div style="font-weight:600;">Nenhum profissional encontrado.</div>
                                    <div style="font-size:13px; margin-top:6px;">
                                        <?php if ($search !== ''): ?>
                                            Nenhum resultado para "<strong><?php echo esc_html($search); ?></strong>". Tente outro termo ou limpe o filtro.
                                        <?php else: ?>
                                            Tente ajustar o filtro de status KYC.
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($professionals as $prof): ?>
                                <tr>
                                    <td><strong style="color:#6d28d9;">#<?php echo esc_html($prof['id']); ?></strong></td>
                                    <td><strong><?php echo esc_html($prof['full_name']); ?></strong></td>
                                    <td style="font-size:12px; color:#4b5563; font-family:monospace;"><?php echo esc_html($prof['cpf'] ?? '—'); ?></td>
                                    <td>
                                        <span style="font-size:13px;"><?php echo esc_html($prof['email']); ?></span><br>
                                        <small style="color:#6b7280;"><?php echo esc_html($prof['phone']); ?></small>
                                    </td>
                                    <td><?php echo $this->renderKycStatusBadge($prof['kyc_status']); ?></td>
                                    <td style="font-size:13px;">
                                        <?php echo $prof['kyc_submitted_at']
                                            ? esc_html(date('d/m/Y H:i', strtotime($prof['kyc_submitted_at'])))
                                            : '<span style="color:#9ca3af;">—</span>'; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $retries = (int) $prof['kyc_retry_count'];
                                        if ($retries > 0) {
                                            echo "<span style='background:#fee2e2; color:#dc2626; padding:2px 8px; border-radius:12px; font-size:12px; font-weight:600;'>{$retries}×</span>";
                                        } else {
                                            echo '<span style="color:#9ca3af;">—</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=kyc&kyc_action=view&id=<?php echo esc_attr($prof['id']); ?>"
                                           class="button button-small"
                                           style="font-size:12px;">
                                            👁️ Ver Detalhes
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Paginação -->
                <?php if ($totalPages > 1): ?>
                    <div style="display:flex; align-items:center; justify-content:center; gap:6px; margin-top:20px; flex-wrap:wrap;">
                        <?php if ($currentPage > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('kyc_paged', $currentPage - 1, $paginationBase)); ?>"
                               class="button">&laquo; Anterior</a>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $currentPage - 2);
                        $end   = min($totalPages, $currentPage + 2);
                        if ($start > 1) echo '<span style="color:#9ca3af; padding:4px 2px;">…</span>';
                        for ($p = $start; $p <= $end; $p++):
                            $isActive = $p === $currentPage;
                        ?>
                            <a href="<?php echo esc_url(add_query_arg('kyc_paged', $p, $paginationBase)); ?>"
                               class="button"
                               style="<?php echo $isActive ? 'background:#6d28d9; color:#fff; border-color:#6d28d9; font-weight:700;' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor;
                        if ($end < $totalPages) echo '<span style="color:#9ca3af; padding:4px 2px;">…</span>';
                        ?>

                        <?php if ($currentPage < $totalPages): ?>
                            <a href="<?php echo esc_url(add_query_arg('kyc_paged', $currentPage + 1, $paginationBase)); ?>"
                               class="button">Próxima &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /card -->
        <?php
    }

    /**
     * Render KYC status badge
     */
    private function renderKycStatusBadge(string $status): string
    {
        $badges = [
            'not_started' => '<span class="limpvix-badge limpvix-badge-default">❌ Não Iniciado</span>',
            'pending' => '<span class="limpvix-badge limpvix-badge-warning">⏳ Pendente</span>',
            'processing' => '<span class="limpvix-badge limpvix-badge-info">🔄 Processando</span>',
            'approved' => '<span class="limpvix-badge limpvix-badge-success">✅ Aprovado</span>',
            'rejected' => '<span class="limpvix-badge limpvix-badge-error">❌ Rejeitado</span>',
            'expired' => '<span class="limpvix-badge limpvix-badge-warning">⏰ Expirado</span>',
        ];

        return $badges[$status] ?? $status;
    }
    private function renderKycDetails(int $professionalId): void
    {
        $professional = $this->repository->findById($professionalId);

        if (!$professional) {
            echo '<div class="notice notice-error"><p>Profissional não encontrado.</p></div>';
            return;
        }

        $kycStatus = $professional->getKycStatus();
        $statusLabels = [
            'not_started' => '⚪ Não Iniciado',
            'started' => '🔵 Iniciado',
            'documents_submitted' => '🟡 Documentos Enviados',
            'processing' => '🟠 Processando',
            'approved' => '✅ Aprovado',
            'rejected' => '❌ Rejeitado',
        ];

        ?>
        <div class="wrap">
            <h1>
                KYC Biométrico - <?php echo esc_html($professional->getFullName()); ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=kyc" class="page-title-action">← Voltar</a>
            </h1>

            <!-- Status Badge -->
            <div style="margin: 20px 0;">
                <span class="limpvix-badge limpvix-badge-<?php echo $this->getKycStatusColor($kycStatus); ?> limpvix-badge-large">
                    <?php echo $statusLabels[$kycStatus] ?? $kycStatus; ?>
                </span>

                <?php if ($professional->getKycRetryCount() > 0): ?>
                    <span class="limpvix-badge limpvix-badge-warning" style="margin-left: 10px;">
                        🔄 Tentativas: <?php echo $professional->getKycRetryCount(); ?>/3
                    </span>
                <?php endif; ?>
            </div>

            <!-- Professional Info -->
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header">
                    <h3>👤 Informações do Profissional</h3>
                </div>
                <div class="limpvix-card-body">
                    <table class="widefat">
                        <tr>
                            <td><strong>Nome Completo:</strong></td>
                            <td><?php echo esc_html($professional->getFullName()); ?></td>
                        </tr>
                        <tr>
                            <td><strong>CPF:</strong></td>
                            <td><?php echo esc_html($professional->getCpf()); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Email:</strong></td>
                            <td><?php echo esc_html($professional->getEmail()); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Telefone:</strong></td>
                            <td><?php echo esc_html($professional->getPhone()); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- KYC Timeline -->
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header">
                    <h3>📅 Timeline KYC</h3>
                </div>
                <div class="limpvix-card-body">
                    <table class="widefat">
                        <?php if ($professional->getKycStartedAt()): ?>
                            <tr>
                                <td><strong>Iniciado em:</strong></td>
                                <td><?php echo $professional->getKycStartedAt()->format('d/m/Y H:i:s'); ?></td>
                            </tr>
                        <?php endif; ?>

                        <?php if ($professional->getKycSubmittedAt()): ?>
                            <tr>
                                <td><strong>Documentos Enviados em:</strong></td>
                                <td><?php echo $professional->getKycSubmittedAt()->format('d/m/Y H:i:s'); ?></td>
                            </tr>
                        <?php endif; ?>

                        <?php if ($professional->getKycApprovedAt()): ?>
                            <tr>
                                <td><strong>Aprovado em:</strong></td>
                                <td><?php echo $professional->getKycApprovedAt()->format('d/m/Y H:i:s'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Aprovado por:</strong></td>
                                <td><?php echo esc_html($professional->getKycApprovedBy() ?: 'Sistema Automático'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Válido até:</strong></td>
                                <td><?php echo $professional->getKycExpiresAt() ? $professional->getKycExpiresAt()->format('d/m/Y') : 'N/A'; ?></td>
                            </tr>
                        <?php endif; ?>

                        <?php if ($professional->getKycRejectedAt()): ?>
                            <tr>
                                <td><strong>Rejeitado em:</strong></td>
                                <td><?php echo $professional->getKycRejectedAt()->format('d/m/Y H:i:s'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Rejeitado por:</strong></td>
                                <td><?php echo esc_html($professional->getKycRejectedBy() ?: 'Sistema Automático'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Motivo da Rejeição:</strong></td>
                                <td style="color: #dc2626; font-weight: 600;">
                                    <?php echo esc_html($professional->getKycRejectionReason()); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Document Images -->
            <?php if ($professional->getKycDocumentUrl() || $professional->getKycSelfieUrl()): ?>
                <div class="limpvix-card" style="margin-bottom: 20px;">
                    <div class="limpvix-card-header">
                        <h3>📄 Documentos Enviados</h3>
                    </div>
                    <div class="limpvix-card-body">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <?php if ($professional->getKycDocumentUrl()): ?>
                                <div>
                                    <h4>Documento (<?php echo strtoupper($professional->getKycDocumentType() ?: 'RG'); ?>)</h4>
                                    <img src="<?php echo esc_url($professional->getKycDocumentUrl()); ?>"
                                         style="max-width: 100%; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                            <?php endif; ?>

                            <?php if ($professional->getKycSelfieUrl()): ?>
                                <div>
                                    <h4>Selfie</h4>
                                    <img src="<?php echo esc_url($professional->getKycSelfieUrl()); ?>"
                                         style="max-width: 100%; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- OCR Data -->
            <?php if ($professional->getKycOcrData()): ?>
                <?php $this->renderKycOcrData($professional->getKycOcrData()); ?>
            <?php endif; ?>

            <!-- Liveness Data -->
            <?php if ($professional->getKycLivenessData()): ?>
                <?php $this->renderKycLivenessData($professional->getKycLivenessData()); ?>
            <?php endif; ?>

            <!-- Face Match Data -->
            <?php if ($professional->getKycFaceMatchData()): ?>
                <?php $this->renderKycFaceMatchData($professional->getKycFaceMatchData()); ?>
            <?php endif; ?>

            <!-- Admin Notes -->
            <?php if ($professional->getKycAdminNotes()): ?>
                <div class="limpvix-card" style="margin-bottom: 20px;">
                    <div class="limpvix-card-header">
                        <h3>📝 Notas Administrativas</h3>
                    </div>
                    <div class="limpvix-card-body">
                        <p><?php echo nl2br(esc_html($professional->getKycAdminNotes())); ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Actions -->
            <?php if (in_array($kycStatus, ['documents_submitted', 'processing', 'rejected'])): ?>
                <div class="limpvix-card">
                    <div class="limpvix-card-header">
                        <h3>⚡ Ações</h3>
                    </div>
                    <div class="limpvix-card-body">
                        <button type="button"
                                class="button button-primary button-large"
                                onclick="approveKyc(<?php echo $professionalId; ?>)"
                                style="margin-right: 10px;">
                            ✅ Aprovar Manualmente
                        </button>

                        <button type="button"
                                class="button button-large"
                                onclick="openRejectModal(<?php echo $professionalId; ?>)">
                            ❌ Rejeitar
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Rejection Modal -->
        <div id="limpvix-reject-modal" style="display:none;">
            <h2>Rejeitar Verificação KYC</h2>
            <form id="limpvix-reject-form">
                <input type="hidden" name="professional_id" id="reject_professional_id">

                <p>
                    <label><strong>Motivo da Rejeição:</strong></label>
                    <textarea name="rejection_reason"
                              rows="5"
                              style="width:100%;"
                              required
                              placeholder="Descreva o motivo da rejeição (ex: documento ilegível, selfie de baixa qualidade, dados não conferem...)"></textarea>
                </p>

                <p>
                    <button type="submit" class="button button-primary">Confirmar Rejeição</button>
                    <button type="button" class="button" onclick="closeRejectModal();">Cancelar</button>
                </p>
            </form>
        </div>

        <script>
        function approveKyc(professionalId) {
            if (!confirm('Tem certeza que deseja aprovar esta verificação KYC?')) {
                return;
            }

            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'limpvix_approve_kyc',
                    nonce: '<?php echo wp_create_nonce("limpvix_kyc_action"); ?>',
                    professional_id: professionalId
                },
                success: function(response) {
                    if (response.success) {
                        alert('✅ KYC aprovado com sucesso!');
                        location.reload();
                    } else {
                        alert('❌ Erro: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('❌ Erro de comunicação com o servidor.');
                }
            });
        }

        function openRejectModal(professionalId) {
            jQuery('#reject_professional_id').val(professionalId);
            tb_show('Rejeitar KYC', '#TB_inline?inlineId=limpvix-reject-modal&width=500&height=350');
        }

        function closeRejectModal() {
            tb_remove();
        }

        jQuery(document).ready(function($) {
            $('#limpvix-reject-form').on('submit', function(e) {
                e.preventDefault();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'limpvix_reject_kyc',
                        nonce: '<?php echo wp_create_nonce("limpvix_kyc_action"); ?>',
                        professional_id: $('#reject_professional_id').val(),
                        rejection_reason: $('[name="rejection_reason"]').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            tb_remove();
                            alert('❌ KYC rejeitado.');
                            location.reload();
                        } else {
                            alert('❌ Erro: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('❌ Erro de comunicação com o servidor.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render OCR data
     */
    private function renderKycOcrData(array $data): void
    {
        ?>
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header">
                <h3>🔍 Dados OCR - Documento</h3>
            </div>
            <div class="limpvix-card-body">
                <table class="widefat">
                    <tr>
                        <td><strong>Confiança do OCR:</strong></td>
                        <td>
                            <span style="font-size: 18px; font-weight: 600; color: <?php echo ($data['confidence'] ?? 0) >= 85 ? '#10b981' : '#ef4444'; ?>">
                                <?php echo number_format($data['confidence'] ?? 0, 2); ?>%
                            </span>
                        </td>
                    </tr>

                    <?php if (isset($data['extracted_data'])): ?>
                        <?php foreach ($data['extracted_data'] as $field => $value): ?>
                            <tr>
                                <td><strong><?php echo esc_html(ucfirst(str_replace('_', ' ', $field))); ?>:</strong></td>
                                <td><?php echo esc_html($value); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>

                <?php if (isset($data['raw_response'])): ?>
                    <details style="margin-top: 15px;">
                        <summary style="cursor: pointer; color: #3b82f6;">Ver Resposta Completa da API</summary>
                        <pre style="background: #f3f4f6; padding: 15px; border-radius: 4px; overflow-x: auto; margin-top: 10px;"><?php echo esc_html(json_encode($data['raw_response'], JSON_PRETTY_PRINT)); ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Liveness data
     */
    private function renderKycLivenessData(array $data): void
    {
        ?>
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header">
                <h3>👤 Liveness Detection - Prova de Vida</h3>
            </div>
            <div class="limpvix-card-body">
                <table class="widefat">
                    <tr>
                        <td><strong>Score de Liveness:</strong></td>
                        <td>
                            <span style="font-size: 18px; font-weight: 600; color: <?php echo ($data['liveness_score'] ?? 0) >= 80 ? '#10b981' : '#ef4444'; ?>">
                                <?php echo number_format($data['liveness_score'] ?? 0, 2); ?>%
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td><strong>É Pessoa Real:</strong></td>
                        <td>
                            <?php if (($data['liveness_score'] ?? 0) >= 80): ?>
                                <span style="color: #10b981; font-weight: 600;">✅ Sim</span>
                            <?php else: ?>
                                <span style="color: #ef4444; font-weight: 600;">❌ Não (possível foto de foto)</span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if (isset($data['quality_score'])): ?>
                        <tr>
                            <td><strong>Qualidade da Imagem:</strong></td>
                            <td><?php echo number_format($data['quality_score'], 2); ?>%</td>
                        </tr>
                    <?php endif; ?>
                </table>

                <?php if (isset($data['raw_response'])): ?>
                    <details style="margin-top: 15px;">
                        <summary style="cursor: pointer; color: #3b82f6;">Ver Resposta Completa da API</summary>
                        <pre style="background: #f3f4f6; padding: 15px; border-radius: 4px; overflow-x: auto; margin-top: 10px;"><?php echo esc_html(json_encode($data['raw_response'], JSON_PRETTY_PRINT)); ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Face Match data
     */
    private function renderKycFaceMatchData(array $data): void
    {
        ?>
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header">
                <h3>🎭 Face Match - Comparação Facial</h3>
            </div>
            <div class="limpvix-card-body">
                <table class="widefat">
                    <tr>
                        <td><strong>Similaridade Facial:</strong></td>
                        <td>
                            <span style="font-size: 18px; font-weight: 600; color: <?php echo ($data['similarity_score'] ?? 0) >= 85 ? '#10b981' : '#ef4444'; ?>">
                                <?php echo number_format($data['similarity_score'] ?? 0, 2); ?>%
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td><strong>Faces Correspondem:</strong></td>
                        <td>
                            <?php if (($data['similarity_score'] ?? 0) >= 85): ?>
                                <span style="color: #10b981; font-weight: 600;">✅ Sim - Mesma pessoa</span>
                            <?php else: ?>
                                <span style="color: #ef4444; font-weight: 600;">❌ Não - Pessoas diferentes</span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if (isset($data['confidence'])): ?>
                        <tr>
                            <td><strong>Confiança da Análise:</strong></td>
                            <td><?php echo number_format($data['confidence'], 2); ?>%</td>
                        </tr>
                    <?php endif; ?>
                </table>

                <?php if (isset($data['raw_response'])): ?>
                    <details style="margin-top: 15px;">
                        <summary style="cursor: pointer; color: #3b82f6;">Ver Resposta Completa da API</summary>
                        <pre style="background: #f3f4f6; padding: 15px; border-radius: 4px; overflow-x: auto; margin-top: 10px;"><?php echo esc_html(json_encode($data['raw_response'], JSON_PRETTY_PRINT)); ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Get status color for badge
     */

    /**
     * Register AJAX handlers
     */
    public function registerAjaxHandlers(): void
    {
        add_action('wp_ajax_limpvix_approve_kyc', [$this, 'handleApproveKyc']);
        add_action('wp_ajax_limpvix_reject_kyc', [$this, 'handleRejectKyc']);
    }

    /**
     * Handle AJAX approve KYC
     */
    public function handleApproveKyc(): void
    {
        check_ajax_referer('limpvix_kyc_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada.']);
            return;
        }

        $professionalId = (int) ($_POST['professional_id'] ?? 0);

        if (!$professionalId) {
            wp_send_json_error(['message' => 'ID do profissional inválido.']);
            return;
        }

        try {
            $professional = $this->repository->findById($professionalId);

            if (!$professional) {
                wp_send_json_error(['message' => 'Profissional não encontrado.']);
                return;
            }

            // Get current user
            $currentUser = wp_get_current_user();
            $adminName = $currentUser->display_name ?: $currentUser->user_login;

            // Approve KYC (24 months validity)
            $professional->approveKyc($adminName, 24);

            // Save
            $this->repository->save($professional);

            wp_send_json_success([
                'message' => 'KYC aprovado com sucesso!',
                'professional_id' => $professionalId,
            ]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Erro ao aprovar KYC: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle AJAX reject KYC
     */
    public function handleRejectKyc(): void
    {
        check_ajax_referer('limpvix_kyc_action', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada.']);
            return;
        }

        $professionalId = (int) ($_POST['professional_id'] ?? 0);
        $rejectionReason = sanitize_textarea_field($_POST['rejection_reason'] ?? '');

        if (!$professionalId) {
            wp_send_json_error(['message' => 'ID do profissional inválido.']);
            return;
        }

        if (empty($rejectionReason)) {
            wp_send_json_error(['message' => 'Motivo da rejeição é obrigatório.']);
            return;
        }

        try {
            $professional = $this->repository->findById($professionalId);

            if (!$professional) {
                wp_send_json_error(['message' => 'Profissional não encontrado.']);
                return;
            }

            // Get current user
            $currentUser = wp_get_current_user();
            $adminName = $currentUser->display_name ?: $currentUser->user_login;

            // Reject KYC
            $professional->rejectKyc($rejectionReason, $adminName);

            // Save
            $this->repository->save($professional);

            wp_send_json_success([
                'message' => 'KYC rejeitado.',
                'professional_id' => $professionalId,
            ]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Erro ao rejeitar KYC: ' . $e->getMessage()]);
        }
    }
    private function getKycStatusColor(string $status): string
    {
        return match($status) {
            'approved' => 'success',
            'rejected' => 'error',
            'processing', 'documents_submitted' => 'warning',
            'started' => 'info',
            default => 'default',
        };
    }

    // ─── Risk Score Tab ───────────────────────────────────────────────────────

    private function renderRiskScoreTab(): void
    {
        $statusFilter = sanitize_key($_GET['risk_status'] ?? 'all');
        $search       = sanitize_text_field($_GET['risk_search'] ?? '');

        // ── Hero card com stats + provider status ──────────────────────────────
        $this->renderRiskScoreStatistics();

        // ── Filtros ────────────────────────────────────────────────────────────
        $hasFilter = $statusFilter !== 'all' || $search !== '';
        ?>
        <div style="background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,0.10); padding:16px 24px; margin-bottom:20px;">
            <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="risk_score">

                <!-- Filtro de status final -->
                <select name="risk_status" style="min-width:220px;">
                    <option value="all"                 <?php selected($statusFilter, 'all'); ?>>Todos os Status</option>
                    <optgroup label="── Status final ──">
                        <option value="PENDING_VERIFICATION"<?php selected($statusFilter, 'PENDING_VERIFICATION'); ?>>⏳ Aguardando Verificação</option>
                        <option value="ACTIVE"              <?php selected($statusFilter, 'ACTIVE'); ?>>✅ Ativo</option>
                        <option value="ACTIVE_MONITORED"    <?php selected($statusFilter, 'ACTIVE_MONITORED'); ?>>👁️ Ativo (Monitorado)</option>
                        <option value="UNDER_REVIEW"        <?php selected($statusFilter, 'UNDER_REVIEW'); ?>>🔍 Em Revisão</option>
                        <option value="NOT_ELIGIBLE"        <?php selected($statusFilter, 'NOT_ELIGIBLE'); ?>>🚫 Não Elegível</option>
                        <option value="SUSPENDED"           <?php selected($statusFilter, 'SUSPENDED'); ?>>⛔ Suspenso</option>
                    </optgroup>
                    <optgroup label="── Situações especiais ──">
                        <option value="bg_expired"          <?php selected($statusFilter, 'bg_expired'); ?>>⏰ BG Expirado</option>
                        <option value="high_risk"           <?php selected($statusFilter, 'high_risk'); ?>>🔴 Alto Risco</option>
                    </optgroup>
                </select>

                <!-- Busca por nome / email / telefone -->
                <input type="search" name="risk_search"
                       value="<?php echo esc_attr($search); ?>"
                       placeholder="🔍 Buscar por nome, email ou telefone…"
                       style="min-width:280px;">

                <button type="submit" class="button button-primary">Filtrar</button>

                <?php if ($hasFilter): ?>
                    <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=risk_score" class="button">Limpar</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tabela de verificações -->
        <?php $this->renderRiskScoreTable($statusFilter, $search); ?>
        <?php
    }

    private function renderRiskScoreStatistics(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_professional_verification';

        // Verifica se a tabela existe
        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$tableExists) {
            echo '<div class="notice notice-warning"><p>Tabela de verificação não encontrada. Execute a migration 026.</p></div>';
            return;
        }

        $stats = $wpdb->get_row("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN final_status = 'PENDING_VERIFICATION' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN final_status = 'ACTIVE' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN final_status = 'ACTIVE_MONITORED' THEN 1 ELSE 0 END) as monitored,
                SUM(CASE WHEN final_status = 'UNDER_REVIEW' THEN 1 ELSE 0 END) as review,
                SUM(CASE WHEN final_status = 'NOT_ELIGIBLE' THEN 1 ELSE 0 END) as not_eligible,
                SUM(CASE WHEN final_status = 'SUSPENDED' THEN 1 ELSE 0 END) as suspended,
                SUM(CASE WHEN background_expires_at IS NOT NULL AND background_expires_at < NOW() AND final_status IN ('ACTIVE','ACTIVE_MONITORED') THEN 1 ELSE 0 END) as bg_expired,
                SUM(CASE WHEN risk_level = 'HIGH' THEN 1 ELSE 0 END) as high_risk,
                SUM(CASE WHEN otp_verified = 1 THEN 1 ELSE 0 END) as otp_verified
            FROM {$table}
        ", ARRAY_A);

        if (!$stats) {
            $stats = array_fill_keys(['total','pending','active','monitored','review','not_eligible','suspended','bg_expired','high_risk','otp_verified'], 0);
        }

        // Provider status para integrar no hero
        $providerStatus = \LimpVix\Infrastructure\Verification\Providers\VerificationProviderFactory::connectionStatus();
        $kycOk  = $providerStatus['kyc']['connected']  ?? false;
        $bgOk   = $providerStatus['background']['connected'] ?? false;
        ?>
        <!-- ── Hero Card – Risk Score Pipeline ─────────────────────────────────── -->
        <div style="background:linear-gradient(135deg, #0f172a 0%, #1e293b 55%, #334155 100%); border-radius:12px; padding:32px; margin-bottom:20px; box-shadow:0 8px 32px rgba(15,23,42,0.50); position:relative; overflow:hidden;">

            <!-- Círculos decorativos -->
            <div style="position:absolute; top:-60px; right:-60px; width:220px; height:220px; background:rgba(255,255,255,0.04); border-radius:50%; pointer-events:none;"></div>
            <div style="position:absolute; bottom:-50px; left:260px; width:170px; height:170px; background:rgba(255,255,255,0.04); border-radius:50%; pointer-events:none;"></div>

            <!-- Cabeçalho: título + provider badges + link settings -->
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:28px; position:relative; z-index:1; flex-wrap:wrap; gap:12px;">
                <div>
                    <h2 style="color:#fff; margin:0 0 8px 0; font-size:26px; font-weight:700; line-height:1.2;">
                        🛡️ Risk Score Pipeline
                    </h2>
                    <p style="color:rgba(255,255,255,0.70); margin:0 0 12px; font-size:13px;">
                        OTP → KYC → Background Check → Risk Engine → Status Final
                    </p>
                    <!-- Provider status pills -->
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <?php if ($kycOk): ?>
                            <span style="background:rgba(74,222,128,0.20); border:1px solid rgba(74,222,128,0.40); color:#4ade80; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;">✅ PPID Conectado</span>
                        <?php else: ?>
                            <span style="background:rgba(251,191,36,0.20); border:1px solid rgba(251,191,36,0.40); color:#fbbf24; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;">⚠️ PPID — Mock</span>
                        <?php endif; ?>
                        <?php if ($bgOk): ?>
                            <span style="background:rgba(74,222,128,0.20); border:1px solid rgba(74,222,128,0.40); color:#4ade80; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;">✅ Exato Digital Conectado</span>
                        <?php else: ?>
                            <span style="background:rgba(251,191,36,0.20); border:1px solid rgba(251,191,36,0.40); color:#fbbf24; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;">⚠️ Exato Digital — Mock</span>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-settings&tab=risk')); ?>"
                   style="background:rgba(255,255,255,0.12); color:rgba(255,255,255,0.85); text-decoration:none; padding:10px 18px; border-radius:8px; font-size:13px; font-weight:600; border:1px solid rgba(255,255,255,0.25); backdrop-filter:blur(4px); white-space:nowrap; display:inline-flex; align-items:center; gap:6px;">
                    ⚙️ Configurar Credenciais
                </a>
            </div>

            <!-- Grid 8 colunas -->
            <?php $rSlug = self::PAGE_SLUG; ?>
            <div style="display:grid; grid-template-columns:repeat(8,1fr); gap:10px; position:relative; z-index:1;">

                <!-- Total Pipeline (estático) -->
                <div style="background:rgba(255,255,255,0.12); border-radius:10px; padding:14px 8px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:30px; font-weight:700; color:#fff; line-height:1;"><?php echo (int) $stats['total']; ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Total</div>
                </div>

                <!-- Ativos -->
                <a href="?page=<?php echo esc_attr($rSlug); ?>&tab=risk_score&risk_status=ACTIVE"
                   style="background:rgba(255,255,255,0.12); border-radius:10px; padding:14px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                    <div style="font-size:30px; font-weight:700; color:#4ade80; line-height:1;"><?php echo (int) $stats['active']; ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Ativos ↗</div>
                </a>

                <!-- Monitorados -->
                <a href="?page=<?php echo esc_attr($rSlug); ?>&tab=risk_score&risk_status=ACTIVE_MONITORED"
                   style="background:rgba(255,255,255,0.12); border-radius:10px; padding:14px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                    <div style="font-size:30px; font-weight:700; color:#fbbf24; line-height:1;"><?php echo (int) $stats['monitored']; ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Monitorados ↗</div>
                </a>

                <!-- Em Revisão -->
                <a href="?page=<?php echo esc_attr($rSlug); ?>&tab=risk_score&risk_status=UNDER_REVIEW"
                   style="background:rgba(255,255,255,0.12); border-radius:10px; padding:14px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                    <div style="font-size:30px; font-weight:700; color:#67e8f9; line-height:1;"><?php echo (int) $stats['review']; ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Em Revisão ↗</div>
                </a>

                <!-- Não Elegíveis -->
                <a href="?page=<?php echo esc_attr($rSlug); ?>&tab=risk_score&risk_status=NOT_ELIGIBLE"
                   style="background:rgba(255,255,255,0.12); border-radius:10px; padding:14px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                    <div style="font-size:30px; font-weight:700; color:#f87171; line-height:1;"><?php echo (int) $stats['not_eligible']; ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">N. Elegíveis ↗</div>
                </a>

                <!-- Suspensos -->
                <a href="?page=<?php echo esc_attr($rSlug); ?>&tab=risk_score&risk_status=SUSPENDED"
                   style="background:rgba(255,255,255,0.12); border-radius:10px; padding:14px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                    <div style="font-size:30px; font-weight:700; color:#d1d5db; line-height:1;"><?php echo (int) $stats['suspended']; ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Suspensos ↗</div>
                </a>

                <!-- BG Expirado -->
                <a href="?page=<?php echo esc_attr($rSlug); ?>&tab=risk_score&risk_status=bg_expired"
                   style="background:rgba(255,255,255,0.12); border-radius:10px; padding:14px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                    <div style="font-size:30px; font-weight:700; color:#fde68a; line-height:1;"><?php echo (int) $stats['bg_expired']; ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">BG Expirado ↗</div>
                </a>

                <!-- Alto Risco -->
                <a href="?page=<?php echo esc_attr($rSlug); ?>&tab=risk_score&risk_status=high_risk"
                   style="background:rgba(248,113,113,0.20); border:1px solid rgba(248,113,113,0.30); border-radius:10px; padding:14px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(248,113,113,0.35)'" onmouseout="this.style.background='rgba(248,113,113,0.20)'">
                    <div style="font-size:30px; font-weight:700; color:#f87171; line-height:1;"><?php echo (int) $stats['high_risk']; ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.80); margin-top:5px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Alto Risco ↗</div>
                </a>

            </div><!-- /grid -->
        </div><!-- /hero card -->
        <?php
    }

    private function renderRiskScoreTable(string $statusFilter, string $search = ''): void
    {
        global $wpdb;
        $vtable = $wpdb->prefix . 'limpvix_professional_verification';
        $ptable = $wpdb->prefix . 'limpvix_professionals';

        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$vtable}'");
        if (!$tableExists) {
            return;
        }

        // ── Build WHERE ────────────────────────────────────────────────────────
        $whereParts = ['1=1'];
        $params     = [];

        // Filtros especiais (virtuais) ou status padrão
        if ($statusFilter === 'bg_expired') {
            $whereParts[] = 'v.background_expires_at IS NOT NULL AND v.background_expires_at < NOW() AND v.final_status IN (\'ACTIVE\',\'ACTIVE_MONITORED\')';
        } elseif ($statusFilter === 'high_risk') {
            $whereParts[] = 'v.risk_level = \'HIGH\'';
        } elseif ($statusFilter !== 'all') {
            $whereParts[] = 'v.final_status = %s';
            $params[]     = $statusFilter;
        }

        if ($search !== '') {
            $like         = '%' . $wpdb->esc_like($search) . '%';
            $whereParts[] = '(p.full_name LIKE %s OR p.email LIKE %s OR p.phone LIKE %s)';
            $params       = array_merge($params, [$like, $like, $like]);
        }

        $whereStr = 'WHERE ' . implode(' AND ', $whereParts);

        $orderSql = "ORDER BY
                CASE v.final_status
                    WHEN 'UNDER_REVIEW'         THEN 1
                    WHEN 'NOT_ELIGIBLE'         THEN 2
                    WHEN 'SUSPENDED'            THEN 3
                    WHEN 'ACTIVE_MONITORED'     THEN 4
                    WHEN 'PENDING_VERIFICATION' THEN 5
                    WHEN 'ACTIVE'               THEN 6
                END,
                v.updated_at DESC";

        // ── Paginação ──────────────────────────────────────────────────────────
        $perPage     = 20;
        $currentPage = max(1, (int) ($_GET['risk_paged'] ?? 1));
        $offset      = ($currentPage - 1) * $perPage;

        $joinSql = "FROM {$vtable} v LEFT JOIN {$ptable} p ON p.user_id = v.user_id";

        // Total
        $countSql = empty($params)
            ? "SELECT COUNT(*) {$joinSql} {$whereStr}"
            : $wpdb->prepare("SELECT COUNT(*) {$joinSql} {$whereStr}", ...$params);

        $total      = (int) $wpdb->get_var($countSql);
        $totalPages = max(1, (int) ceil($total / $perPage));

        // Dados da página
        $selectSql = "SELECT
                v.id AS v_id, v.user_id, v.otp_verified, v.kyc_status,
                v.background_status, v.risk_level, v.final_status,
                v.background_expires_at, v.kyc_provider, v.background_provider,
                v.updated_at, p.full_name, p.email, p.phone";

        $dataSql = empty($params)
            ? $wpdb->prepare(
                "{$selectSql} {$joinSql} {$whereStr} {$orderSql} LIMIT %d OFFSET %d",
                $perPage, $offset
            )
            : $wpdb->prepare(
                "{$selectSql} {$joinSql} {$whereStr} {$orderSql} LIMIT %d OFFSET %d",
                ...[...$params, $perPage, $offset]
            );

        $rows  = $wpdb->get_results($dataSql, ARRAY_A) ?? [];
        $count = count($rows);

        // URL base para paginação (preserva filtros)
        $paginationBase = add_query_arg([
            'page'        => self::PAGE_SLUG,
            'tab'         => 'risk_score',
            'risk_status' => $statusFilter !== 'all' ? $statusFilter : false,
            'risk_search' => $search !== '' ? $search : false,
        ], admin_url('admin.php'));
        ?>
        <div style="background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,0.10); overflow:hidden;">

            <!-- Cabeçalho -->
            <div style="padding:20px 24px 16px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                <h3 style="margin:0; font-size:16px; color:#0f172a; font-weight:700; display:flex; align-items:center; gap:8px;">
                    📋 <span>Pipeline de Verificação</span>
                    <span style="background:#f1f5f9; color:#334155; font-size:12px; font-weight:600; padding:2px 8px; border-radius:20px; margin-left:4px;">
                        <?php echo esc_html($total); ?> registro<?php echo $total !== 1 ? 's' : ''; ?>
                    </span>
                    <?php if ($search !== ''): ?>
                        <span style="background:#fef3c7; color:#92400e; font-size:11px; font-weight:600; padding:2px 8px; border-radius:20px;">
                            🔍 "<?php echo esc_html($search); ?>"
                        </span>
                    <?php endif; ?>
                </h3>
                <?php if ($totalPages > 1): ?>
                    <span style="font-size:13px; color:#6b7280;">
                        Página <?php echo $currentPage; ?> de <?php echo $totalPages; ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Tabela -->
            <div style="padding:0 24px 24px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:180px;">Profissional</th>
                            <th style="width:55px; text-align:center;">OTP</th>
                            <th style="width:110px;">KYC</th>
                            <th style="width:125px;">Background</th>
                            <th style="width:85px;">Risco</th>
                            <th style="width:145px;">Status Final</th>
                            <th style="width:105px;">BG Expira</th>
                            <th style="width:85px;">Provedores</th>
                            <th style="width:95px;">Atualizado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="9" style="text-align:center; padding:48px; color:#6b7280;">
                                    <div style="font-size:40px; margin-bottom:12px;">🛡️</div>
                                    <?php if ($search !== ''): ?>
                                        <div style="font-weight:600;">Nenhum resultado para "<?php echo esc_html($search); ?>".</div>
                                        <div style="font-size:13px; margin-top:6px; color:#9ca3af;">Tente outro termo ou limpe a busca.</div>
                                    <?php elseif ($statusFilter !== 'all'): ?>
                                        <div style="font-weight:600;">Nenhum profissional com este status no pipeline.</div>
                                        <div style="font-size:13px; margin-top:6px; color:#9ca3af;">Tente outro filtro de status.</div>
                                    <?php else: ?>
                                        <div style="font-weight:600;">Nenhum profissional no pipeline ainda.</div>
                                        <div style="font-size:13px; margin-top:6px; color:#9ca3af;">
                                            Inicie via <code>RunVerificationPipeline</code> ou pelo app mobile.
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php $bgExpired = !empty($r['background_expires_at']) && strtotime($r['background_expires_at']) < time(); ?>
                                <tr <?php echo $bgExpired ? 'style="background:#fff8f1;"' : ''; ?>>
                                    <td>
                                        <strong><?php echo esc_html($r['full_name'] ?? '(sem cadastro)'); ?></strong><br>
                                        <small style="color:#6b7280; font-size:12px;"><?php echo esc_html($r['email'] ?? 'user_id #' . $r['user_id']); ?></small>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php echo $r['otp_verified']
                                            ? '<span style="color:#16a34a; font-weight:bold; font-size:16px;">✅</span>'
                                            : '<span style="color:#9ca3af;">—</span>'; ?>
                                    </td>
                                    <td><?php echo $this->renderRiskStatusBadge('kyc', $r['kyc_status']); ?></td>
                                    <td><?php echo $this->renderRiskStatusBadge('background', $r['background_status']); ?></td>
                                    <td><?php echo $this->renderRiskLevelBadge($r['risk_level']); ?></td>
                                    <td><?php echo $this->renderFinalStatusBadge($r['final_status']); ?></td>
                                    <td style="font-size:13px;">
                                        <?php if (!empty($r['background_expires_at'])): ?>
                                            <span style="color:<?php echo $bgExpired ? '#ef4444' : '#374151'; ?>;">
                                                <?php echo esc_html(date('d/m/Y', strtotime($r['background_expires_at']))); ?>
                                                <?php if ($bgExpired): ?>
                                                    <br><small style="color:#ef4444; font-size:11px;">⏰ Expirado</small>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:11px; color:#6b7280; line-height:1.6;">
                                        KYC: <?php echo esc_html($r['kyc_provider'] ?? '—'); ?><br>
                                        BG: <?php echo esc_html($r['background_provider'] ?? '—'); ?>
                                    </td>
                                    <td style="font-size:12px; color:#6b7280;">
                                        <?php echo $r['updated_at']
                                            ? esc_html(date('d/m/Y H:i', strtotime($r['updated_at'])))
                                            : '—'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Paginação -->
                <?php if ($totalPages > 1): ?>
                    <div style="display:flex; align-items:center; justify-content:center; gap:6px; margin-top:20px; flex-wrap:wrap;">
                        <?php if ($currentPage > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('risk_paged', $currentPage - 1, $paginationBase)); ?>"
                               class="button">&laquo; Anterior</a>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $currentPage - 2);
                        $end   = min($totalPages, $currentPage + 2);
                        if ($start > 1) echo '<span style="color:#9ca3af; padding:4px 2px;">…</span>';
                        for ($p = $start; $p <= $end; $p++):
                            $isActive = $p === $currentPage;
                        ?>
                            <a href="<?php echo esc_url(add_query_arg('risk_paged', $p, $paginationBase)); ?>"
                               class="button"
                               style="<?php echo $isActive ? 'background:#0f172a; color:#fff; border-color:#0f172a; font-weight:700;' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor;
                        if ($end < $totalPages) echo '<span style="color:#9ca3af; padding:4px 2px;">…</span>';
                        ?>

                        <?php if ($currentPage < $totalPages): ?>
                            <a href="<?php echo esc_url(add_query_arg('risk_paged', $currentPage + 1, $paginationBase)); ?>"
                               class="button">Próxima &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /card -->
        <?php
    }

    private function renderRiskStatusBadge(string $layer, string $status): string
    {
        if ($layer === 'kyc') {
            return match($status) {
                'APPROVED' => '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">✅ Aprovado</span>',
                'REJECTED' => '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">❌ Rejeitado</span>',
                default    => '<span style="background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:4px;font-size:11px;">⏳ Pendente</span>',
            };
        }

        // background layer
        return match($status) {
            'APPROVED'     => '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">✅ Aprovado</span>',
            'RESTRICTED'   => '<span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">⚠️ Restrito</span>',
            'NOT_ELIGIBLE' => '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">🚫 Não Elegível</span>',
            default        => '<span style="background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:4px;font-size:11px;">⏳ Pendente</span>',
        };
    }

    private function renderRiskLevelBadge(?string $level): string
    {
        return match($level) {
            'LOW'    => '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">🟢 Baixo</span>',
            'MEDIUM' => '<span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">🟡 Médio</span>',
            'HIGH'   => '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">🔴 Alto</span>',
            default  => '<span style="color:#9ca3af;font-size:11px;">—</span>',
        };
    }

    private function renderFinalStatusBadge(string $status): string
    {
        return match($status) {
            'ACTIVE'               => '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:700;">✅ Ativo</span>',
            'ACTIVE_MONITORED'     => '<span style="background:#fef9c3;color:#854d0e;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:700;">👁️ Monitorado</span>',
            'UNDER_REVIEW'         => '<span style="background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:700;">🔍 Em Revisão</span>',
            'NOT_ELIGIBLE'         => '<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:700;">🚫 Não Elegível</span>',
            'SUSPENDED'            => '<span style="background:#f3f4f6;color:#374151;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:700;">⛔ Suspenso</span>',
            'PENDING_VERIFICATION' => '<span style="background:#ede9fe;color:#5b21b6;padding:3px 10px;border-radius:4px;font-size:12px;font-weight:700;">⏳ Aguardando</span>',
            default                => '<span style="color:#9ca3af;font-size:12px;">' . esc_html($status) . '</span>',
        };
    }

    // ─── Payouts Tab ──────────────────────────────────────────────────────────

    private function renderPayoutsTab(): void
    {
        global $wpdb;

        $payoutRepo   = new WpPayoutRepository();
        $statusFilter = sanitize_key($_GET['payout_status'] ?? 'all');
        $search       = sanitize_text_field($_GET['payout_search'] ?? '');

        // ── Distribuição de métodos de payout entre profissionais ───────────────
        // Arquitetura: payout automático via EFI Bank PIX se profissional tem chave PIX.
        // Sem chave PIX → payout é processado manualmente pelo admin.
        $profTable = $wpdb->prefix . 'limpvix_professionals';
        $mpStats = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(pix_key IS NOT NULL AND pix_key != '') AS mp_connected,
                SUM(pix_key IS NULL OR pix_key = '') AS pix_only
             FROM {$profTable}
             WHERE is_active = 1 AND is_permanently_banned = 0",
            ARRAY_A
        ) ?? ['total' => 0, 'mp_connected' => 0, 'pix_only' => 0];

        // PIX com payout pendente (aprovados mas method = pix_manual) + valor total
        $payoutTable = $wpdb->prefix . 'limpvix_payouts';
        $pixPending      = 0;
        $pixPendingTotal = 0.0;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$payoutTable}'") === $payoutTable) {
            $pixRow = $wpdb->get_row(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(net_amount), 0) AS total
                 FROM {$payoutTable}
                 WHERE status = 'approved'
                   AND (payout_method = 'pix_manual' OR payout_method IS NULL)",
                ARRAY_A
            );
            $pixPending      = (int)   ($pixRow['cnt']   ?? 0);
            $pixPendingTotal = (float)  ($pixRow['total'] ?? 0);
        }

        // ── Stats gerais de payouts ─────────────────────────────────────────────
        $stats = $payoutRepo->getStats();
        $pSlug = self::PAGE_SLUG;

        // ── Notice: PIX manual pendente (ação necessária) ───────────────────────
        if ($pixPending > 0): ?>
            <div class="notice notice-warning" style="margin:0 0 16px; border-left-color:#d97706;">
                <p style="margin:8px 0;">
                    <strong>🏦 <?php echo $pixPending; ?> payout(s) PIX manual aguardando processamento</strong>
                    — Total: <strong>R$ <?php echo number_format($pixPendingTotal, 2, ',', '.'); ?></strong>
                </p>
                <p style="margin:4px 0 8px; color:#6b7280; font-size:13px;">
                    Profissionais sem chave PIX cadastrada recebem via processamento manual.
                    Use o botão <strong>"🏦 Pagar PIX"</strong> em cada linha para ver os dados de pagamento e registrar o comprovante.
                    <?php if (!empty($_GET['payout_status']) && $_GET['payout_status'] === 'approved'): ?>
                        <span style="color:#059669;">✓ Exibindo payouts aprovados abaixo.</span>
                    <?php else: ?>
                        <a href="?page=<?php echo esc_attr($pSlug); ?>&tab=payouts&payout_status=approved" style="color:#d97706;">Ver todos pendentes →</a>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Hero Card – Payouts -->
        <div style="background:linear-gradient(135deg,#065f46 0%,#047857 55%,#059669 100%); border-radius:12px; padding:32px; margin-bottom:20px; box-shadow:0 8px 32px rgba(6,95,70,0.45); position:relative; overflow:hidden;">
            <div style="position:absolute; top:-60px; right:-60px; width:220px; height:220px; background:rgba(255,255,255,0.05); border-radius:50%; pointer-events:none;"></div>
            <div style="position:absolute; bottom:-50px; left:260px; width:170px; height:170px; background:rgba(255,255,255,0.04); border-radius:50%; pointer-events:none;"></div>

            <!-- Título + badge de métodos ativos -->
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:28px; position:relative; z-index:1; flex-wrap:wrap; gap:12px;">
                <div>
                    <h2 style="color:#fff; margin:0 0 8px; font-size:26px; font-weight:700; line-height:1.2;">💰 Payouts — Repasses Financeiros</h2>
                    <p style="color:rgba(255,255,255,0.80); margin:0; font-size:14px;">
                        Gestão de repasses para profissionais · EFI Bank PIX automático + PIX Manual (admin)
                    </p>
                </div>
                <!-- Distribuição de métodos -->
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <div style="background:rgba(255,255,255,0.15); border-radius:8px; padding:10px 14px; text-align:center; min-width:90px;">
                        <div style="font-size:20px; font-weight:700; color:#6ee7b7;"><?php echo (int)($mpStats['mp_connected'] ?? 0); ?></div>
                        <div style="font-size:10px; color:rgba(255,255,255,0.85); font-weight:600;">Com PIX ⚡</div>
                    </div>
                    <div style="background:rgba(255,255,255,0.15); border-radius:8px; padding:10px 14px; text-align:center; min-width:90px;">
                        <div style="font-size:20px; font-weight:700; color:#fbbf24;"><?php echo (int)($mpStats['pix_only'] ?? 0); ?></div>
                        <div style="font-size:10px; color:rgba(255,255,255,0.85); font-weight:600;">Sem PIX 🏦</div>
                    </div>
                </div>
            </div>

            <!-- Grid de cards clicáveis -->
            <div style="display:grid; grid-template-columns:repeat(7,1fr); gap:10px; position:relative; z-index:1;">

                <!-- Total (estático) -->
                <?php $total = ($stats['total_pending'] ?? 0) + ($stats['total_approved'] ?? 0) + ($stats['total_processing'] ?? 0) + ($stats['total_completed'] ?? 0) + ($stats['total_failed'] ?? 0) + ($stats['total_cancelled'] ?? 0) + ($stats['total_on_hold'] ?? 0); ?>
                <div style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 8px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:30px; font-weight:700; color:#fff; line-height:1;"><?php echo $total; ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Total</div>
                </div>

                <!-- Pendentes -->
                <a href="?page=<?php echo esc_attr($pSlug); ?>&tab=payouts&payout_status=pending"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:30px; font-weight:700; color:#fbbf24; line-height:1;"><?php echo (int) ($stats['total_pending'] ?? 0); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Pendentes ↗</div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.65); margin-top:2px;">R$ <?php echo number_format($stats['amount_pending'] ?? 0, 2, ',', '.'); ?></div>
                </a>

                <!-- Aprovados -->
                <a href="?page=<?php echo esc_attr($pSlug); ?>&tab=payouts&payout_status=approved"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:30px; font-weight:700; color:#67e8f9; line-height:1;"><?php echo (int) ($stats['total_approved'] ?? 0); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Aprovados ↗</div>
                </a>

                <!-- Processando -->
                <a href="?page=<?php echo esc_attr($pSlug); ?>&tab=payouts&payout_status=processing"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:30px; font-weight:700; color:#c4b5fd; line-height:1;"><?php echo (int) ($stats['total_processing'] ?? 0); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Processando ↗</div>
                </a>

                <!-- Concluídos -->
                <a href="?page=<?php echo esc_attr($pSlug); ?>&tab=payouts&payout_status=completed"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:30px; font-weight:700; color:#4ade80; line-height:1;"><?php echo (int) ($stats['total_completed'] ?? 0); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Concluídos ↗</div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.65); margin-top:2px;">R$ <?php echo number_format($stats['amount_completed'] ?? 0, 2, ',', '.'); ?></div>
                </a>

                <!-- Falhas -->
                <a href="?page=<?php echo esc_attr($pSlug); ?>&tab=payouts&payout_status=failed"
                   style="background:rgba(248,113,113,0.20); border:1px solid rgba(248,113,113,0.30); border-radius:10px; padding:16px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(248,113,113,0.35)'" onmouseout="this.style.background='rgba(248,113,113,0.20)'">
                    <div style="font-size:30px; font-weight:700; color:#f87171; line-height:1;"><?php echo (int) ($stats['total_failed'] ?? 0); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Falhas ↗</div>
                </a>

                <!-- Retidos (on_hold) -->
                <a href="?page=<?php echo esc_attr($pSlug); ?>&tab=payouts&payout_status=on_hold"
                   style="background:rgba(255,255,255,0.15); border-radius:10px; padding:16px 8px; text-align:center; backdrop-filter:blur(4px); text-decoration:none; display:block;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <div style="font-size:30px; font-weight:700; color:#fde68a; line-height:1;"><?php echo (int) ($stats['total_on_hold'] ?? 0); ?></div>
                    <div style="font-size:10px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Retidos ↗</div>
                </a>

            </div><!-- /grid -->
        </div><!-- /hero card -->

        <!-- ── Filtros ──────────────────────────────────────────────────────────── -->
        <?php
        $hasFilter = $statusFilter !== 'all' || $search !== '';
        ?>
        <div style="background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,0.10); padding:16px 24px; margin-bottom:20px;">
            <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="payouts">

                <select name="payout_status" style="min-width:200px;">
                    <option value="all"        <?php selected($statusFilter, 'all'); ?>>Todos os Status</option>
                    <option value="pending"    <?php selected($statusFilter, 'pending'); ?>>⏳ Pendentes</option>
                    <option value="approved"   <?php selected($statusFilter, 'approved'); ?>>✓ Aprovados</option>
                    <option value="processing" <?php selected($statusFilter, 'processing'); ?>>⚙️ Processando</option>
                    <option value="completed"  <?php selected($statusFilter, 'completed'); ?>>✅ Concluídos</option>
                    <option value="failed"     <?php selected($statusFilter, 'failed'); ?>>✗ Falhas</option>
                    <option value="cancelled"  <?php selected($statusFilter, 'cancelled'); ?>>⊘ Cancelados</option>
                    <option value="on_hold"    <?php selected($statusFilter, 'on_hold'); ?>>⏸ Retidos</option>
                </select>

                <input type="search" name="payout_search"
                       value="<?php echo esc_attr($search); ?>"
                       placeholder="🔍 Buscar por profissional ou ID do pedido…"
                       style="min-width:280px;">

                <button type="submit" class="button button-primary">Filtrar</button>

                <?php if ($hasFilter): ?>
                    <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=payouts" class="button">Limpar</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ── Tabela ────────────────────────────────────────────────────────────── -->
        <?php $this->renderPayoutsTable($payoutRepo, $statusFilter, $search); ?>
        <?php
    }

    private function renderPayoutsTable(
        WpPayoutRepository $repo,
        string $statusFilter,
        string $search
    ): void {
        global $wpdb;
        $ptable   = $wpdb->prefix . 'limpvix_payouts';
        $prftable = $wpdb->prefix . 'limpvix_professionals';
        $fbtable  = $wpdb->prefix . 'limpvix_feedback';

        // ── WHERE ──────────────────────────────────────────────────────────────
        $whereParts = ['1=1'];
        $params     = [];

        if ($statusFilter !== 'all') {
            $whereParts[] = 'py.status = %s';
            $params[]     = $statusFilter;
        }

        if ($search !== '') {
            if (is_numeric($search)) {
                $whereParts[] = '(py.order_id = %d OR py.id = %d)';
                $params       = array_merge($params, [(int) $search, (int) $search]);
            } else {
                $like         = '%' . $wpdb->esc_like($search) . '%';
                $whereParts[] = '(py.recipient_name LIKE %s OR pr.full_name LIKE %s OR pr.cpf LIKE %s)';
                $params       = array_merge($params, [$like, $like, $like]);
            }
        }

        $whereStr = 'WHERE ' . implode(' AND ', $whereParts);

        // LEFT JOIN com feedback bloqueante (rating ≤ 2, ainda pendente de aprovação)
        $joinSql = "FROM {$ptable} py
                    LEFT JOIN {$prftable} pr ON pr.id = py.professional_id
                    LEFT JOIN {$fbtable} fb
                        ON fb.order_id = py.order_id
                       AND fb.validation_status = 'pending'
                       AND fb.rating <= 2";

        $orderSql = 'ORDER BY py.created_at DESC';

        // Paginação
        $perPage     = 20;
        $currentPage = max(1, (int) ($_GET['payouts_paged'] ?? 1));
        $offset      = ($currentPage - 1) * $perPage;

        $countSql = empty($params)
            ? "SELECT COUNT(*) {$joinSql} {$whereStr}"
            : $wpdb->prepare("SELECT COUNT(*) {$joinSql} {$whereStr}", ...$params);

        $total      = (int) $wpdb->get_var($countSql);
        $totalPages = max(1, (int) ceil($total / $perPage));

        $selectSql = 'SELECT py.*, pr.full_name AS professional_name, pr.cpf AS professional_cpf, pr.pix_key AS prof_pix_key, pr.pix_key_type AS prof_pix_key_type, fb.id AS blocking_feedback_id, fb.rating AS blocking_feedback_rating, fb.comment AS blocking_feedback_comment';

        $dataSql = empty($params)
            ? $wpdb->prepare(
                "{$selectSql} {$joinSql} {$whereStr} {$orderSql} LIMIT %d OFFSET %d",
                $perPage, $offset
            )
            : $wpdb->prepare(
                "{$selectSql} {$joinSql} {$whereStr} {$orderSql} LIMIT %d OFFSET %d",
                ...[...$params, $perPage, $offset]
            );

        $payouts = $wpdb->get_results($dataSql, ARRAY_A) ?? [];

        $paginationBase = add_query_arg([
            'page'          => self::PAGE_SLUG,
            'tab'           => 'payouts',
            'payout_status' => $statusFilter !== 'all' ? $statusFilter : false,
            'payout_search' => $search !== '' ? $search : false,
        ], admin_url('admin.php'));
        ?>
        <div style="background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,0.10); overflow:hidden;">

            <!-- Cabeçalho -->
            <div style="padding:20px 24px 16px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                <h3 style="margin:0; font-size:16px; color:#065f46; font-weight:700; display:flex; align-items:center; gap:8px;">
                    💳 <span>Repasses</span>
                    <span style="background:#d1fae5; color:#065f46; font-size:12px; font-weight:600; padding:2px 8px; border-radius:20px; margin-left:4px;">
                        <?php echo $total; ?> registro<?php echo $total !== 1 ? 's' : ''; ?>
                    </span>
                    <?php if ($search !== ''): ?>
                        <span style="background:#fef3c7; color:#92400e; font-size:11px; font-weight:600; padding:2px 8px; border-radius:20px;">
                            🔍 "<?php echo esc_html($search); ?>"
                        </span>
                    <?php endif; ?>
                </h3>
                <?php if ($totalPages > 1): ?>
                    <span style="font-size:13px; color:#6b7280;">Página <?php echo $currentPage; ?> de <?php echo $totalPages; ?></span>
                <?php endif; ?>
            </div>

            <!-- Tabela -->
            <div style="padding:0 24px 24px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:55px;">ID</th>
                            <th style="width:90px;">Pedido</th>
                            <th>Profissional</th>
                            <th style="width:95px;">Bruto</th>
                            <th style="width:75px;">Taxa</th>
                            <th style="width:95px;">Líquido</th>
                            <th>Destinatário</th>
                            <th style="width:130px;">Status</th>
                            <th style="width:110px;">Gateway</th>
                            <th style="width:110px;">Criado em</th>
                            <th style="width:120px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payouts)): ?>
                            <tr>
                                <td colspan="11" style="text-align:center; padding:48px; color:#6b7280;">
                                    <div style="font-size:40px; margin-bottom:12px;">💰</div>
                                    <?php if ($search !== ''): ?>
                                        <div style="font-weight:600;">Nenhum resultado para "<?php echo esc_html($search); ?>".</div>
                                        <div style="font-size:13px; margin-top:6px; color:#9ca3af;">Tente outro termo ou limpe o filtro.</div>
                                    <?php elseif ($statusFilter !== 'all'): ?>
                                        <div style="font-weight:600;">Nenhum payout com este status.</div>
                                    <?php else: ?>
                                        <div style="font-weight:600;">Nenhum payout registrado ainda.</div>
                                        <div style="font-size:13px; margin-top:6px; color:#9ca3af;">Payouts são criados automaticamente após execuções validadas.</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payouts as $py): ?>
                                <tr>
                                    <td><strong style="color:#065f46;">#<?php echo esc_html($py['id']); ?></strong></td>
                                    <td>
                                        <?php if (!empty($py['order_id'])): ?>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-orders&order_id=' . (int) $py['order_id'])); ?>">
                                                #<?php echo esc_html($py['order_id']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong style="font-size:13px;"><?php echo esc_html($py['professional_name'] ?? 'Prof. #' . $py['professional_id']); ?></strong>
                                        <?php if (!empty($py['professional_cpf'])): ?>
                                            <br><small style="color:#9ca3af; font-family:monospace;"><?php echo esc_html($py['professional_cpf']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:13px;">R$ <?php echo number_format((float) ($py['gross_amount'] ?? 0), 2, ',', '.'); ?></td>
                                    <td style="font-size:13px; color:#6b7280;">R$ <?php echo number_format((float) ($py['platform_fee'] ?? 0), 2, ',', '.'); ?></td>
                                    <td><strong style="color:#065f46;">R$ <?php echo number_format((float) ($py['net_amount'] ?? 0), 2, ',', '.'); ?></strong></td>
                                    <td style="font-size:12px;">
                                        <?php if (!empty($py['recipient_name'])): ?>
                                            <div><strong><?php echo esc_html($py['recipient_name']); ?></strong></div>
                                        <?php endif; ?>
                                        <?php if (!empty($py['recipient_type'])): ?>
                                            <div style="color:#6b7280;"><?php echo match($py['recipient_type']) {
                                                'pix'          => '💳 PIX',
                                                'bank_account' => '🏦 Conta Bancária',
                                                default        => esc_html($py['recipient_type']),
                                            }; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $this->renderPayoutStatusBadge($py['status']); ?></td>
                                    <td style="font-size:11px; color:#6b7280; line-height:1.6;">
                                        <?php echo esc_html($py['gateway'] ?? '—'); ?>
                                        <?php if (!empty($py['gateway_transfer_id'])): ?>
                                            <br><span style="font-family:monospace;"><?php echo esc_html(substr($py['gateway_transfer_id'], 0, 10)); ?>…</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:12px; color:#6b7280;">
                                        <?php echo !empty($py['created_at'])
                                            ? esc_html(date('d/m/Y H:i', strtotime($py['created_at'])))
                                            : '—'; ?>
                                    </td>
                                    <td>
                                        <?php
                                        // Determinar método deste payout:
                                        // profissional com chave PIX → EFI Bank processa automaticamente
                                        // sem chave PIX → admin processa manualmente via PIX
                                        $profPixKey  = $py['prof_pix_key'] ?? $py['recipient_key'] ?? '';
                                        $isPixManual = empty($profPixKey);
                                        ?>
                                        <?php if ($py['status'] === 'approved' && !$isPixManual): ?>
                                            <!-- Payout automático via EFI Bank PIX -->
                                            <?php
                                            $fbId      = (int)    ($py['blocking_feedback_id']      ?? 0);
                                            $fbRating  = (int)    ($py['blocking_feedback_rating']  ?? 0);
                                            $fbComment = (string) ($py['blocking_feedback_comment'] ?? '');
                                            $fbNonce   = $fbId > 0 ? wp_create_nonce('limpvix_resolve_feedback_' . $fbId) : '';
                                            $execNonce = wp_create_nonce('limpvix_process_payout_' . $py['id']);
                                            ?>
                                            <button type="button" class="button button-primary button-small lmpx-process-pix-btn"
                                                    data-payout-id="<?php echo (int) $py['id']; ?>"
                                                    data-feedback-id="<?php echo $fbId; ?>"
                                                    data-feedback-rating="<?php echo $fbRating; ?>"
                                                    data-feedback-comment="<?php echo esc_attr($fbComment); ?>"
                                                    data-feedback-nonce="<?php echo esc_attr($fbNonce); ?>"
                                                    data-exec-nonce="<?php echo esc_attr($execNonce); ?>"
                                                    data-mode="execute">
                                                ⚡ Processar PIX
                                            </button>
                                        <?php elseif ($py['status'] === 'approved' && $isPixManual): ?>
                                            <!-- Payout manual PIX — verifica feedback, depois abre modal de pagamento -->
                                            <?php
                                            $pixKey     = $py['prof_pix_key']      ?? $py['recipient_key'] ?? '';
                                            $pixKeyType = $py['prof_pix_key_type']  ?? $py['recipient_type'] ?? 'pix';
                                            $pixName    = $py['professional_name']  ?? $py['recipient_name'] ?? '';
                                            $pixAmount  = (float) ($py['net_amount'] ?? 0);
                                            $nonce      = wp_create_nonce('limpvix_mark_pix_paid_' . $py['id']);
                                            $pixData    = json_encode([
                                                'id'      => (int) $py['id'],
                                                'name'    => $pixName,
                                                'key'     => $pixKey,
                                                'keyType' => $pixKeyType,
                                                'amount'  => $pixAmount,
                                                'nonce'   => $nonce,
                                            ]);
                                            $fbId      = (int)    ($py['blocking_feedback_id']      ?? 0);
                                            $fbRating  = (int)    ($py['blocking_feedback_rating']  ?? 0);
                                            $fbComment = (string) ($py['blocking_feedback_comment'] ?? '');
                                            $fbNonce   = $fbId > 0 ? wp_create_nonce('limpvix_resolve_feedback_' . $fbId) : '';
                                            ?>
                                            <button type="button" class="button button-primary button-small limpvix-pix-pay-btn"
                                                    data-payout='<?php echo esc_attr($pixData); ?>'
                                                    data-feedback-id="<?php echo $fbId; ?>"
                                                    data-feedback-rating="<?php echo $fbRating; ?>"
                                                    data-feedback-comment="<?php echo esc_attr($fbComment); ?>"
                                                    data-feedback-nonce="<?php echo esc_attr($fbNonce); ?>"
                                                    data-mode="resolve_only"
                                                    style="background:#2563eb; border-color:#1d4ed8; color:#fff;">
                                                🏦 Pagar PIX
                                            </button>
                                        <?php elseif ($py['status'] === 'on_hold' && !empty($py['blocking_feedback_id'])): ?>
                                            <!-- Payout retido por feedback bloqueante — exige resolução antes do repasse -->
                                            <?php
                                            $fbId      = (int)    $py['blocking_feedback_id'];
                                            $fbRating  = (int)    ($py['blocking_feedback_rating']  ?? 0);
                                            $fbComment = (string) ($py['blocking_feedback_comment'] ?? '');
                                            $fbNonce   = wp_create_nonce('limpvix_resolve_feedback_' . $fbId);
                                            $execNonce = wp_create_nonce('limpvix_process_payout_' . $py['id']);
                                            ?>
                                            <button type="button" class="button button-small lmpx-process-pix-btn"
                                                    style="background:#d97706; border-color:#b45309; color:#fff;"
                                                    data-payout-id="<?php echo (int) $py['id']; ?>"
                                                    data-feedback-id="<?php echo $fbId; ?>"
                                                    data-feedback-rating="<?php echo $fbRating; ?>"
                                                    data-feedback-comment="<?php echo esc_attr($fbComment); ?>"
                                                    data-feedback-nonce="<?php echo esc_attr($fbNonce); ?>"
                                                    data-exec-nonce="<?php echo esc_attr($execNonce); ?>"
                                                    data-mode="execute">
                                                🔍 Resolver Feedback
                                            </button>
                                        <?php elseif ($py['status'] === 'failed' && (int) ($py['retry_count'] ?? 0) < (int) ($py['max_retries'] ?? 3)): ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                                <input type="hidden" name="action" value="limpvix_process_payout">
                                                <input type="hidden" name="payout_id" value="<?php echo (int) $py['id']; ?>">
                                                <?php wp_nonce_field('limpvix_process_payout_' . $py['id']); ?>
                                                <button type="submit" class="button button-secondary button-small">
                                                    🔄 Retry (<?php echo (int) ($py['retry_count'] ?? 0); ?>/<?php echo (int) ($py['max_retries'] ?? 3); ?>)
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#9ca3af; font-size:12px;">—</span>
                                        <?php endif; ?>

                                        <?php if (!empty($py['failure_reason'])): ?>
                                            <button type="button" class="button button-small" style="margin-top:4px;"
                                                    onclick="alert(<?php echo json_encode($py['failure_reason']); ?>);">
                                                ⚠️ Erro
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Paginação -->
                <?php if ($totalPages > 1): ?>
                    <div style="display:flex; align-items:center; justify-content:center; gap:6px; margin-top:20px; flex-wrap:wrap;">
                        <?php if ($currentPage > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('payouts_paged', $currentPage - 1, $paginationBase)); ?>" class="button">&laquo; Anterior</a>
                        <?php endif; ?>
                        <?php
                        $start = max(1, $currentPage - 2);
                        $end   = min($totalPages, $currentPage + 2);
                        if ($start > 1) echo '<span style="color:#9ca3af; padding:4px 2px;">…</span>';
                        for ($p = $start; $p <= $end; $p++):
                            $isActive = $p === $currentPage;
                        ?>
                            <a href="<?php echo esc_url(add_query_arg('payouts_paged', $p, $paginationBase)); ?>"
                               class="button"
                               style="<?php echo $isActive ? 'background:#065f46; color:#fff; border-color:#065f46; font-weight:700;' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor;
                        if ($end < $totalPages) echo '<span style="color:#9ca3af; padding:4px 2px;">…</span>';
                        ?>
                        <?php if ($currentPage < $totalPages): ?>
                            <a href="<?php echo esc_url(add_query_arg('payouts_paged', $currentPage + 1, $paginationBase)); ?>" class="button">Próxima &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /card -->

        <!-- ═══════════════════════════════════════════════════════════════════
             MODAL: PIX PAYMENT — Dados de pagamento + QR Code + Confirmação
             ═══════════════════════════════════════════════════════════════════ -->
        <div id="lmpx-pix-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:100000; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:16px; width:480px; max-width:95vw; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3); position:relative;">

                <!-- Header -->
                <div style="background:linear-gradient(135deg,#1e40af,#2563eb); border-radius:16px 16px 0 0; padding:20px 24px; display:flex; align-items:center; justify-content:space-between;">
                    <div>
                        <h2 style="color:#fff; margin:0; font-size:18px; font-weight:700;">🏦 Pagamento PIX Manual</h2>
                        <p style="color:rgba(255,255,255,0.80); margin:4px 0 0; font-size:13px;">Payout #<span id="lmpx-pix-id">—</span></p>
                    </div>
                    <button type="button" onclick="lmpxClosePix()" style="background:rgba(255,255,255,0.2); border:none; border-radius:8px; color:#fff; font-size:18px; cursor:pointer; padding:6px 10px; line-height:1;">✕</button>
                </div>

                <div style="padding:24px;">

                    <!-- Profissional + Valor -->
                    <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:16px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-size:12px; color:#6b7280; margin-bottom:2px;">Profissional</div>
                            <div style="font-size:15px; font-weight:700; color:#065f46;" id="lmpx-pix-name">—</div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:12px; color:#6b7280; margin-bottom:2px;">Valor a pagar</div>
                            <div style="font-size:22px; font-weight:800; color:#065f46;" id="lmpx-pix-amount">R$ —</div>
                        </div>
                    </div>

                    <!-- Chave PIX -->
                    <div style="margin-bottom:20px;">
                        <div style="font-size:12px; color:#6b7280; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:.5px;">Chave PIX</div>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <div style="flex:1; background:#f8fafc; border:2px solid #e2e8f0; border-radius:8px; padding:12px 14px; font-family:monospace; font-size:15px; font-weight:600; color:#1e293b; word-break:break-all;" id="lmpx-pix-key">—</div>
                            <button type="button" onclick="lmpxCopyPix()" style="background:#2563eb; border:none; border-radius:8px; color:#fff; padding:12px 14px; cursor:pointer; font-size:13px; font-weight:600; white-space:nowrap; flex-shrink:0;" title="Copiar chave PIX">
                                📋 Copiar
                            </button>
                        </div>
                        <div style="margin-top:6px; font-size:12px; color:#6b7280;">
                            Tipo: <strong id="lmpx-pix-type">—</strong>
                        </div>
                    </div>

                    <!-- QR Code -->
                    <div style="text-align:center; margin-bottom:20px;">
                        <div style="font-size:12px; color:#6b7280; font-weight:600; margin-bottom:10px; text-transform:uppercase; letter-spacing:.5px;">QR Code PIX</div>
                        <div id="lmpx-qr-container" style="display:inline-block; padding:12px; background:#fff; border:2px solid #e2e8f0; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                            <canvas id="lmpx-qr-canvas" width="180" height="180"></canvas>
                        </div>
                        <div style="margin-top:8px; font-size:12px; color:#6b7280;">Escaneie com qualquer app bancário</div>
                        <div id="lmpx-qr-nokey" style="display:none; padding:20px; color:#9ca3af; font-size:13px;">
                            ⚠️ Profissional sem chave PIX cadastrada.<br>Copie a chave manualmente ou entre em contato.
                        </div>
                    </div>

                    <!-- Instruções -->
                    <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 14px; margin-bottom:20px; font-size:13px; color:#1e40af; line-height:1.6;">
                        <strong>Como pagar:</strong><br>
                        1. Copie a chave PIX ou escaneie o QR Code<br>
                        2. Faça o pagamento no seu app bancário<br>
                        3. Cole o comprovante abaixo e confirme
                    </div>

                    <!-- Comprovante -->
                    <div style="margin-bottom:20px;">
                        <label style="display:block; font-size:12px; color:#374151; font-weight:600; margin-bottom:6px;">
                            Comprovante / Observação <span style="color:#9ca3af; font-weight:400;">(opcional)</span>
                        </label>
                        <textarea id="lmpx-pix-proof" rows="3"
                                  style="width:100%; box-sizing:border-box; border:1px solid #d1d5db; border-radius:8px; padding:10px; font-size:13px; resize:vertical;"
                                  placeholder="Ex: Transferência realizada às 14:30 — ID: E00000000..."></textarea>
                    </div>

                    <!-- Botões de ação -->
                    <div style="display:flex; gap:10px;">
                        <button type="button" onclick="lmpxClosePix()"
                                style="flex:1; padding:12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; color:#475569; font-size:14px; font-weight:600; cursor:pointer;">
                            Cancelar
                        </button>
                        <button type="button" onclick="lmpxConfirmPix()"
                                id="lmpx-confirm-btn"
                                style="flex:2; padding:12px; background:#059669; border:none; border-radius:8px; color:#fff; font-size:14px; font-weight:700; cursor:pointer;">
                            ✅ Confirmar Pagamento PIX
                        </button>
                    </div>

                    <!-- Status de feedback -->
                    <div id="lmpx-pix-feedback" style="display:none; margin-top:12px; padding:10px 14px; border-radius:8px; font-size:13px; text-align:center;"></div>

                </div><!-- /body -->
            </div><!-- /modal inner -->
        </div><!-- /modal overlay PIX -->

        <!-- ═══════════════════════════════════════════════════════════════════
             MODAL: RESOLUÇÃO DE FEEDBACK — Registrar resolução antes do payout
             ═══════════════════════════════════════════════════════════════════ -->
        <div id="lmpx-resolution-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.60); z-index:100001; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:16px; width:520px; max-width:95vw; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.35); position:relative;">

                <!-- Header laranja -->
                <div style="background:linear-gradient(135deg,#d97706,#f59e0b); border-radius:16px 16px 0 0; padding:20px 24px; display:flex; align-items:center; justify-content:space-between;">
                    <div>
                        <h2 style="color:#fff; margin:0; font-size:17px; font-weight:700;">🔍 Resolução de Feedback</h2>
                        <p style="color:rgba(255,255,255,0.85); margin:4px 0 0; font-size:13px;">Payout #<span id="lmpx-res-payout-id">—</span></p>
                    </div>
                    <button type="button" onclick="lmpxCloseResolution()" style="background:rgba(255,255,255,0.25); border:none; border-radius:8px; color:#fff; font-size:18px; cursor:pointer; padding:6px 10px; line-height:1;">✕</button>
                </div>

                <div style="padding:24px;">

                    <!-- Card: Feedback do cliente -->
                    <div id="lmpx-res-feedback-card" style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:16px; margin-bottom:20px;">
                        <div style="font-size:12px; color:#991b1b; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px;">⭐ Feedback do Cliente</div>
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                            <span id="lmpx-res-stars" style="font-size:22px; color:#f59e0b;">★★</span>
                            <span id="lmpx-res-rating-text" style="font-size:15px; font-weight:700; color:#991b1b;"></span>
                        </div>
                        <div id="lmpx-res-comment" style="font-size:13px; color:#7f1d1d; line-height:1.5; font-style:italic;"></div>
                    </div>

                    <!-- Campo: Responsável -->
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; color:#374151; font-weight:600; margin-bottom:6px;">
                            Responsável pela resolução
                        </label>
                        <input type="text" id="lmpx-res-name"
                               style="width:100%; box-sizing:border-box; border:1px solid #d1d5db; border-radius:8px; padding:10px 12px; font-size:13px;"
                               placeholder="Nome do gerente / responsável">
                    </div>

                    <!-- Campo: Descrição -->
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; color:#374151; font-weight:600; margin-bottom:6px;">
                            O que foi feito para resolver? <span style="color:#ef4444;">*</span>
                        </label>
                        <textarea id="lmpx-res-text" rows="4"
                                  style="width:100%; box-sizing:border-box; border:1px solid #d1d5db; border-radius:8px; padding:10px 12px; font-size:13px; resize:vertical;"
                                  placeholder="Ex: Gerente ligou para o cliente e explicou que o profissional chegou 10min atrasado por trânsito…"></textarea>
                    </div>

                    <!-- Campo: Gravidade -->
                    <div style="margin-bottom:20px;">
                        <label style="display:block; font-size:12px; color:#374151; font-weight:600; margin-bottom:10px;">
                            Gravidade da ocorrência <span style="color:#ef4444;">*</span>
                        </label>
                        <div style="display:flex; flex-direction:column; gap:8px;">

                            <label id="lmpx-sev-grave-lbl" style="display:flex; align-items:flex-start; gap:10px; padding:12px 14px; border:2px solid #e5e7eb; border-radius:10px; cursor:pointer; transition:all .15s;">
                                <input type="radio" name="lmpx_severity" value="grave" style="margin-top:2px; accent-color:#ef4444;">
                                <div>
                                    <div style="font-weight:700; color:#991b1b; font-size:13px;">🔴 Grave <span style="color:#6b7280; font-weight:400; font-size:12px;">(penalidade −1.50 no rating)</span></div>
                                    <div style="color:#6b7280; font-size:12px; margin-top:2px;">Falha grave — impacto significativo no score do profissional</div>
                                </div>
                            </label>

                            <label id="lmpx-sev-medio-lbl" style="display:flex; align-items:flex-start; gap:10px; padding:12px 14px; border:2px solid #e5e7eb; border-radius:10px; cursor:pointer; transition:all .15s;">
                                <input type="radio" name="lmpx_severity" value="medio" style="margin-top:2px; accent-color:#f59e0b;">
                                <div>
                                    <div style="font-weight:700; color:#92400e; font-size:13px;">🟡 Médio <span style="color:#6b7280; font-weight:400; font-size:12px;">(penalidade −0.75 no rating)</span></div>
                                    <div style="color:#6b7280; font-size:12px; margin-top:2px;">Falha moderada — impacto médio no score</div>
                                </div>
                            </label>

                            <label id="lmpx-sev-leve-lbl" style="display:flex; align-items:flex-start; gap:10px; padding:12px 14px; border:2px solid #e5e7eb; border-radius:10px; cursor:pointer; transition:all .15s;">
                                <input type="radio" name="lmpx_severity" value="leve" style="margin-top:2px; accent-color:#10b981;">
                                <div>
                                    <div style="font-weight:700; color:#065f46; font-size:13px;">🟢 Leve <span style="color:#6b7280; font-weight:400; font-size:12px;">(sem penalidade)</span></div>
                                    <div style="color:#6b7280; font-size:12px; margin-top:2px;">Falha menor ou mal-entendido — não impacta o score</div>
                                </div>
                            </label>

                        </div>
                    </div>

                    <!-- Botões -->
                    <div style="display:flex; gap:10px;">
                        <button type="button" onclick="lmpxCloseResolution()"
                                style="flex:1; padding:12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; color:#475569; font-size:14px; font-weight:600; cursor:pointer;">
                            Cancelar
                        </button>
                        <button type="button" onclick="lmpxConfirmResolution()"
                                id="lmpx-res-confirm-btn"
                                disabled
                                style="flex:2; padding:12px; background:#d97706; border:none; border-radius:8px; color:#fff; font-size:14px; font-weight:700; cursor:pointer; opacity:.5;">
                            ✅ Confirmar Resolução e Processar
                        </button>
                    </div>

                    <!-- Feedback de status -->
                    <div id="lmpx-res-feedback" style="display:none; margin-top:12px; padding:10px 14px; border-radius:8px; font-size:13px; text-align:center;"></div>

                </div><!-- /body -->
            </div><!-- /modal inner -->
        </div><!-- /resolution modal overlay -->

        <script>
        (function($) {
            'use strict';

            var currentPayout   = null;  // dados do modal PIX (🏦)
            var currentResData  = null;  // dados do modal de resolução

            // ════════════════════════════════════════════════════════════════════
            // MODAL PIX MANUAL (🏦 Pagar PIX) — com verificação de feedback
            // ════════════════════════════════════════════════════════════════════
            $(document).on('click', '.limpvix-pix-pay-btn', function() {
                var btn    = $(this);
                var pyData = btn.data('payout');
                if (!pyData) return;

                var fbId     = parseInt(btn.data('feedback-id')   || '0', 10);
                var fbRating = parseInt(btn.data('feedback-rating') || '0', 10);
                var fbNonce  = btn.data('feedback-nonce') || '';

                if (fbId > 0) {
                    // Há feedback bloqueante: resolve primeiro, depois abre PIX modal
                    currentResData = {
                        payoutId   : pyData.id,
                        feedbackId : fbId,
                        rating     : fbRating,
                        comment    : btn.data('feedback-comment') || '',
                        fbNonce    : fbNonce,
                        mode       : 'resolve_only',
                        pixData    : pyData,   // guarda para abrir modal PIX depois
                    };
                    lmpxOpenResolution(currentResData);
                } else {
                    // Sem feedback bloqueante: abre direto
                    currentPayout = pyData;
                    lmpxOpenPix(pyData);
                }
            });

            // ════════════════════════════════════════════════════════════════════
            // BOTÕES PROCESSAR PIX EFI / RESOLVER FEEDBACK (⚡ / 🔍)
            // ════════════════════════════════════════════════════════════════════
            $(document).on('click', '.lmpx-process-pix-btn', function() {
                var btn      = $(this);
                var payoutId = parseInt(btn.data('payout-id') || '0', 10);
                var fbId     = parseInt(btn.data('feedback-id')   || '0', 10);
                var fbRating = parseInt(btn.data('feedback-rating') || '0', 10);
                var fbNonce  = btn.data('feedback-nonce') || '';
                var execNonce = btn.data('exec-nonce') || '';
                var mode     = btn.data('mode') || 'execute';

                if (fbId > 0) {
                    // Há feedback bloqueante: abrir modal de resolução
                    currentResData = {
                        payoutId   : payoutId,
                        feedbackId : fbId,
                        rating     : fbRating,
                        comment    : btn.data('feedback-comment') || '',
                        fbNonce    : fbNonce,
                        execNonce  : execNonce,
                        mode       : mode,
                        pixData    : null,
                    };
                    lmpxOpenResolution(currentResData);
                } else {
                    // Sem feedback bloqueante: confirmar e processar direto
                    if (!confirm('Processar repasse EFI Bank PIX #' + payoutId + '?')) return;
                    lmpxExecutePayoutDirect(payoutId, execNonce, btn);
                }
            });

            // ── Executar payout direto (sem feedback bloqueante) ─────────────
            function lmpxExecutePayoutDirect(payoutId, execNonce, btn) {
                if (btn) btn.prop('disabled', true).text('Processando…');

                $.post(ajaxurl, {
                    action   : 'limpvix_execute_payout',
                    payout_id: payoutId,
                    nonce    : execNonce
                }, function(resp) {
                    if (resp && resp.success) {
                        alert('✅ Payout #' + payoutId + ' processado com sucesso!');
                        location.reload();
                    } else {
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Erro desconhecido.';
                        alert('❌ Erro: ' + msg);
                        if (btn) btn.prop('disabled', false).text('⚡ Processar PIX');
                    }
                }).fail(function() {
                    alert('❌ Falha na comunicação com o servidor.');
                    if (btn) btn.prop('disabled', false).text('⚡ Processar PIX');
                });
            }

            // ════════════════════════════════════════════════════════════════════
            // MODAL DE RESOLUÇÃO DE FEEDBACK
            // ════════════════════════════════════════════════════════════════════
            window.lmpxOpenResolution = function(data) {
                currentResData = data;

                // Preencher dados
                $('#lmpx-res-payout-id').text(data.payoutId);
                var stars = '★'.repeat(data.rating) + '☆'.repeat(5 - data.rating);
                $('#lmpx-res-stars').text(stars);
                $('#lmpx-res-rating-text').text(data.rating + ' de 5 estrelas');
                $('#lmpx-res-comment').text(data.comment || '(sem comentário do cliente)');

                // Pré-preencher nome com usuário atual
                if (!$('#lmpx-res-name').val()) {
                    $('#lmpx-res-name').val(<?php echo json_encode(wp_get_current_user()->display_name); ?>);
                }

                // Reset formulário
                $('input[name="lmpx_severity"]').prop('checked', false);
                $('#lmpx-res-text').val('');
                $('#lmpx-res-feedback').hide();
                $('#lmpx-res-confirm-btn').prop('disabled', true).css('opacity', '.5');
                lmpxUpdateSeverityLabels();

                // Label do botão conforme modo
                if (data.mode === 'resolve_only') {
                    $('#lmpx-res-confirm-btn').text('✅ Confirmar Resolução e Prosseguir com PIX');
                } else {
                    $('#lmpx-res-confirm-btn').text('✅ Confirmar Resolução e Processar Repasse');
                }

                $('#lmpx-resolution-modal').css('display', 'flex');
            };

            window.lmpxCloseResolution = function() {
                $('#lmpx-resolution-modal').hide();
                currentResData = null;
            };

            // Habilitar botão quando campos obrigatórios estiverem preenchidos
            $('#lmpx-res-text, input[name="lmpx_severity"]').on('input change', function() {
                var text     = $('#lmpx-res-text').val().trim();
                var severity = $('input[name="lmpx_severity"]:checked').val();
                var ok       = text.length > 0 && !!severity;
                $('#lmpx-res-confirm-btn').prop('disabled', !ok).css('opacity', ok ? '1' : '.5');
            });

            // Highlight visual do card de gravidade selecionado
            $(document).on('change', 'input[name="lmpx_severity"]', function() {
                lmpxUpdateSeverityLabels();
            });

            function lmpxUpdateSeverityLabels() {
                var selected = $('input[name="lmpx_severity"]:checked').val();
                var borders  = {grave:'#ef4444', medio:'#f59e0b', leve:'#10b981'};
                ['grave','medio','leve'].forEach(function(s) {
                    var lbl = $('#lmpx-sev-' + s + '-lbl');
                    lbl.css('border-color', selected === s ? (borders[s] || '#e5e7eb') : '#e5e7eb');
                    lbl.css('background',   selected === s ? (s === 'grave' ? '#fef2f2' : s === 'medio' ? '#fffbeb' : '#f0fdf4') : '#fff');
                });
            }

            window.lmpxConfirmResolution = function() {
                if (!currentResData) return;

                var text     = $('#lmpx-res-text').val().trim();
                var severity = $('input[name="lmpx_severity"]:checked').val();
                var name     = $('#lmpx-res-name').val().trim();
                var btn      = $('#lmpx-res-confirm-btn');

                if (!text || !severity) {
                    lmpxResShowFeedback('error', '❌ Preencha a descrição e selecione a gravidade.');
                    return;
                }

                btn.prop('disabled', true).text('Processando…');

                $.post(ajaxurl, {
                    action         : 'limpvix_resolve_feedback_and_payout',
                    feedback_id    : currentResData.feedbackId,
                    payout_id      : currentResData.payoutId,
                    resolution_text: text,
                    severity       : severity,
                    resolved_by_name: name,
                    mode           : currentResData.mode,
                    _wpnonce       : currentResData.fbNonce
                }, function(resp) {
                    if (resp && resp.success) {
                        if (currentResData.mode === 'resolve_only' && currentResData.pixData) {
                            // Fecha modal resolução e abre modal PIX manual
                            lmpxCloseResolution();
                            currentPayout = currentResData.pixData;
                            lmpxOpenPix(currentResData.pixData);
                        } else {
                            lmpxResShowFeedback('success', '✅ ' + resp.data.message + ' Recarregando…');
                            setTimeout(function() { location.reload(); }, 1800);
                        }
                    } else {
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Erro desconhecido.';
                        lmpxResShowFeedback('error', '❌ ' + msg);
                        btn.prop('disabled', false);
                        if (currentResData.mode === 'resolve_only') {
                            btn.text('✅ Confirmar Resolução e Prosseguir com PIX');
                        } else {
                            btn.text('✅ Confirmar Resolução e Processar Repasse');
                        }
                    }
                }).fail(function() {
                    lmpxResShowFeedback('error', '❌ Falha na comunicação com o servidor.');
                    btn.prop('disabled', false);
                });
            };

            function lmpxResShowFeedback(type, msg) {
                var colors = {
                    success: {bg:'#d1fae5', color:'#065f46', border:'#6ee7b7'},
                    error:   {bg:'#fee2e2', color:'#991b1b', border:'#fca5a5'},
                };
                var c = colors[type] || colors.error;
                $('#lmpx-res-feedback')
                    .css({background:c.bg, color:c.color, border:'1px solid '+c.border, 'border-radius':'8px'})
                    .text(msg)
                    .show();
            }

            // ════════════════════════════════════════════════════════════════════
            // MODAL PIX (🏦) — lógica original preservada
            // ════════════════════════════════════════════════════════════════════

            // ── Abrir modal ──────────────────────────────────────────────────────
            /* NOTA: o evento click do .limpvix-pix-pay-btn foi movido para cima
               para verificar feedback bloqueante primeiro. */

            window.lmpxOpenPix = function(data) {
                $('#lmpx-pix-id').text('#' + data.id);
                $('#lmpx-pix-name').text(data.name || '—');
                $('#lmpx-pix-amount').text('R$ ' + parseFloat(data.amount || 0).toFixed(2).replace('.', ','));
                $('#lmpx-pix-key').text(data.key || '(sem chave PIX)');
                $('#lmpx-pix-type').text(lmpxPixTypeName(data.keyType));
                $('#lmpx-pix-proof').val('');
                $('#lmpx-pix-feedback').hide();
                $('#lmpx-confirm-btn').prop('disabled', false).text('✅ Confirmar Pagamento PIX');

                if (data.key) {
                    $('#lmpx-qr-container').show();
                    $('#lmpx-qr-nokey').hide();
                    lmpxGenerateQR(data.key, data.amount, data.name);
                } else {
                    $('#lmpx-qr-container').hide();
                    $('#lmpx-qr-nokey').show();
                }

                $('#lmpx-pix-modal').css('display', 'flex');
            };

            window.lmpxClosePix = function() {
                $('#lmpx-pix-modal').hide();
                currentPayout = null;
            };

            window.lmpxCopyPix = function() {
                var key = $('#lmpx-pix-key').text().trim();
                if (!key || key === '(sem chave PIX)') return;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(key).then(function() {
                        lmpxShowFeedback('info', '📋 Chave PIX copiada!');
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = key;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    lmpxShowFeedback('info', '📋 Chave PIX copiada!');
                }
            };

            window.lmpxConfirmPix = function() {
                if (!currentPayout) return;
                var proof = $('#lmpx-pix-proof').val().trim();
                var btn = $('#lmpx-confirm-btn');
                btn.prop('disabled', true).text('Processando…');

                $.post(ajaxurl, {
                    action: 'limpvix_mark_pix_paid',
                    payout_id: currentPayout.id,
                    payment_proof: proof,
                    _wpnonce: currentPayout.nonce
                }, function(resp) {
                    if (resp && resp.success) {
                        lmpxShowFeedback('success', '✅ Pagamento PIX confirmado! Recarregando…');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Erro desconhecido.';
                        lmpxShowFeedback('error', '❌ Erro: ' + msg);
                        btn.prop('disabled', false).text('✅ Confirmar Pagamento PIX');
                    }
                }).fail(function() {
                    lmpxShowFeedback('error', '❌ Falha na comunicação com o servidor.');
                    btn.prop('disabled', false).text('✅ Confirmar Pagamento PIX');
                });
            };

            function lmpxShowFeedback(type, msg) {
                var colors = {
                    success: {bg:'#d1fae5', color:'#065f46', border:'#6ee7b7'},
                    error:   {bg:'#fee2e2', color:'#991b1b', border:'#fca5a5'},
                    info:    {bg:'#dbeafe', color:'#1e40af', border:'#93c5fd'},
                };
                var c = colors[type] || colors.info;
                $('#lmpx-pix-feedback')
                    .css({background:c.bg, color:c.color, border:'1px solid '+c.border, 'border-radius':'8px'})
                    .text(msg)
                    .show();
            }

            function lmpxPixTypeName(type) {
                var names = {cpf:'CPF', cnpj:'CNPJ', email:'E-mail', phone:'Telefone', random:'Chave Aleatória', pix:'PIX'};
                return names[type] || type || '—';
            }

            // ── Gerador de QR Code PIX (BR Code EMV) ────────────────────────────
            // Formato padrão PIX Bacen - gera o payload EMV para QR estático
            function lmpxBuildPixPayload(pixKey, amount, name) {
                function tlv(tag, value) {
                    var l = value.length.toString().padStart(2, '0');
                    return tag + l + value;
                }
                var merchantName = (name || 'LimpVix').substring(0, 25).replace(/[^a-zA-Z0-9 ]/g, ' ').trim();
                var city = 'SAO PAULO';
                var pixKeyPayload = tlv('00', 'BR.GOV.BCB.PIX') + tlv('01', pixKey);
                var merchantAccount = tlv('26', pixKeyPayload);
                var amtStr = amount > 0 ? parseFloat(amount).toFixed(2) : '';
                var txAmount = amtStr ? tlv('54', amtStr) : '';
                var addData = tlv('62', tlv('05', '***'));

                var payload =
                    tlv('00', '01') +       // Payload Format
                    merchantAccount +        // Merchant Account (PIX)
                    tlv('52', '0000') +      // MCC
                    tlv('53', '986') +       // BRL
                    txAmount +               // Amount (opcional)
                    tlv('58', 'BR') +        // Country
                    tlv('59', merchantName) + // Name
                    tlv('60', city) +        // City
                    addData;                 // Additional data

                // CRC-16 CCITT
                payload += '6304';
                var crc = 0xFFFF;
                for (var i = 0; i < payload.length; i++) {
                    crc ^= payload.charCodeAt(i) << 8;
                    for (var j = 0; j < 8; j++) {
                        crc = (crc & 0x8000) ? (crc << 1) ^ 0x1021 : crc << 1;
                    }
                }
                return payload + (crc & 0xFFFF).toString(16).toUpperCase().padStart(4, '0');
            }

            function lmpxGenerateQR(pixKey, amount, name) {
                var canvas = document.getElementById('lmpx-qr-canvas');
                if (!canvas) return;
                var ctx = canvas.getContext('2d');
                var payload = lmpxBuildPixPayload(pixKey, amount, name);

                // Usar QRCode.js se disponível (WP admin não tem por padrão)
                // Fallback: mostrar o payload como texto monospace
                if (typeof QRCode !== 'undefined') {
                    canvas.style.display = 'block';
                    var qr = new QRCode(canvas, {
                        text: payload,
                        width: 180, height: 180,
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } else {
                    // Fallback: desenhar o payload como texto no canvas (scannable com app de câmera)
                    // e adicionar link de download
                    ctx.clearRect(0, 0, 180, 180);
                    ctx.fillStyle = '#f8fafc';
                    ctx.fillRect(0, 0, 180, 180);
                    ctx.fillStyle = '#64748b';
                    ctx.font = '11px monospace';
                    ctx.textAlign = 'center';
                    ctx.fillText('QR Code PIX', 90, 20);
                    ctx.font = '9px monospace';
                    ctx.fillStyle = '#94a3b8';
                    ctx.fillText('(Copie a chave abaixo)', 90, 40);

                    // Tenta usar qrcode library via CDN dynamicamente
                    lmpxLoadQRLib(function() {
                        if (typeof QRCode !== 'undefined') {
                            ctx.clearRect(0, 0, 180, 180);
                            new QRCode(canvas, {text: payload, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M});
                        }
                    });
                }
            }

            function lmpxLoadQRLib(callback) {
                if (document.getElementById('lmpx-qrjs')) { callback(); return; }
                var s = document.createElement('script');
                s.id = 'lmpx-qrjs';
                s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
                s.onload = callback;
                document.head.appendChild(s);
            }

            // Fechar modal ao clicar fora
            $('#lmpx-pix-modal').on('click', function(e) {
                if (e.target === this) lmpxClosePix();
            });

        })(jQuery);
        </script>
        <?php
    }

    private function renderPayoutStatusBadge(string $status): string
    {
        return match($status) {
            'pending'    => '<span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;">⏳ Pendente</span>',
            'approved'   => '<span style="background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;">✓ Aprovado</span>',
            'processing' => '<span style="background:#ede9fe;color:#5b21b6;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;">⚙️ Processando</span>',
            'completed'  => '<span style="background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;">✅ Concluído</span>',
            'failed'     => '<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;">✗ Falhou</span>',
            'cancelled'  => '<span style="background:#f3f4f6;color:#374151;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;">⊘ Cancelado</span>',
            'on_hold'    => '<span style="background:#fef9c3;color:#854d0e;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;">⏸ Retido</span>',
            default      => '<span style="color:#9ca3af;font-size:11px;">' . esc_html($status) . '</span>',
        };
    }
}
