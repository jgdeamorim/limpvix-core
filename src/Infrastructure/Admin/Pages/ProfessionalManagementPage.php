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

        $professional->verify();
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

        $this->repository->delete($professionalId);

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

            <!-- Grid de métricas (6 colunas) -->
            <div style="display:grid; grid-template-columns:repeat(6,1fr); gap:12px; position:relative; z-index:1;">

                <!-- Total -->
                <div style="background:rgba(255,255,255,0.15); border-radius:10px; padding:18px 12px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:36px; font-weight:700; color:#fff; line-height:1;"><?php echo esc_html($total); ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Total</div>
                </div>

                <!-- Ativos -->
                <div style="background:rgba(255,255,255,0.15); border-radius:10px; padding:18px 12px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:36px; font-weight:700; color:#4ade80; line-height:1;"><?php echo esc_html($active); ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Ativos</div>
                </div>

                <!-- Verificados -->
                <div style="background:rgba(255,255,255,0.15); border-radius:10px; padding:18px 12px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:36px; font-weight:700; color:#86efac; line-height:1;"><?php echo esc_html($verified); ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Verificados</div>
                </div>

                <!-- Pendentes -->
                <div style="background:rgba(255,255,255,0.15); border-radius:10px; padding:18px 12px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:36px; font-weight:700; color:#fbbf24; line-height:1;"><?php echo esc_html($pending); ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Pendentes</div>
                </div>

                <!-- Suspensos -->
                <div style="background:rgba(255,255,255,0.15); border-radius:10px; padding:18px 12px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:36px; font-weight:700; color:#f87171; line-height:1;"><?php echo esc_html($suspended); ?></div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Suspensos</div>
                </div>

                <!-- Score Médio -->
                <div style="background:rgba(255,255,255,0.15); border-radius:10px; padding:18px 12px; text-align:center; backdrop-filter:blur(4px);">
                    <div style="font-size:30px; font-weight:700; color:#fde68a; line-height:1;"><?php echo number_format($avgScore, 1, ',', '.'); ?>★</div>
                    <div style="font-size:11px; color:rgba(255,255,255,0.85); margin-top:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Score Médio</div>
                </div>

            </div><!-- /grid -->
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
        $statusFilter = $_GET['kyc_status'] ?? 'all';

        ?>
        <p class="description">
            Gerencie as verificações biométricas (OCR + Liveness + Face Match) dos profissionais.
        </p>

        <!-- Status Filter -->
        <div class="tablenav top">
            <div class="alignleft actions">
                <select name="kyc_status" id="kyc-status-filter">
                    <option value="all" <?php selected($statusFilter, 'all'); ?>>Todos os Status</option>
                    <option value="not_started" <?php selected($statusFilter, 'not_started'); ?>>❌ Não Iniciado</option>
                    <option value="pending" <?php selected($statusFilter, 'pending'); ?>>⏳ Pendente</option>
                    <option value="processing" <?php selected($statusFilter, 'processing'); ?>>🔄 Processando</option>
                    <option value="approved" <?php selected($statusFilter, 'approved'); ?>>✅ Aprovado</option>
                    <option value="rejected" <?php selected($statusFilter, 'rejected'); ?>>❌ Rejeitado</option>
                    <option value="expired" <?php selected($statusFilter, 'expired'); ?>>⏰ Expirado</option>
                </select>
                <button type="button" class="button" id="kyc-filter-submit">Filtrar</button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <?php $this->renderKycStatistics(); ?>

        <!-- Professionals Table -->
        <?php $this->renderKycProfessionalsTable($statusFilter); ?>

        <script>
        jQuery(document).ready(function($) {
            $('#kyc-filter-submit').on('click', function() {
                var status = $('#kyc-status-filter').val();
                window.location.href = '?page=<?php echo self::PAGE_SLUG; ?>&tab=kyc&kyc_status=' + status;
            });
        });
        </script>
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
        <div class="limpvix-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            <!-- Total -->
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #2271b1; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Total de Profissionais</div>
                <div style="font-size: 32px; font-weight: bold; color: #2271b1;"><?php echo $stats['total']; ?></div>
            </div>

            <!-- Não Iniciado -->
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #8c8f94; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Não Iniciado</div>
                <div style="font-size: 32px; font-weight: bold; color: #8c8f94;"><?php echo $stats['not_started']; ?></div>
            </div>

            <!-- Pendente -->
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #f0b849; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">⏳ Pendente</div>
                <div style="font-size: 32px; font-weight: bold; color: #f0b849;"><?php echo $stats['pending']; ?></div>
            </div>

            <!-- Processando -->
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a0d2; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">🔄 Processando</div>
                <div style="font-size: 32px; font-weight: bold; color: #00a0d2;"><?php echo $stats['processing']; ?></div>
            </div>

            <!-- Aprovado -->
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a32a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">✅ Aprovado</div>
                <div style="font-size: 32px; font-weight: bold; color: #00a32a;"><?php echo $stats['approved']; ?></div>
            </div>

            <!-- Rejeitado -->
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #d63638; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">❌ Rejeitado</div>
                <div style="font-size: 32px; font-weight: bold; color: #d63638;"><?php echo $stats['rejected']; ?></div>
            </div>

            <!-- Expirado -->
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #dba617; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">⏰ Expirado</div>
                <div style="font-size: 32px; font-weight: bold; color: #dba617;"><?php echo $stats['expired']; ?></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render KYC professionals table
     */
    private function renderKycProfessionalsTable(string $statusFilter): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_professionals';

        // Build query
        $where = 'WHERE 1=1';
        if ($statusFilter !== 'all') {
            $where .= $wpdb->prepare(' AND kyc_status = %s', $statusFilter);
        }

        $professionals = $wpdb->get_results("
            SELECT
                id,
                full_name,
                email,
                phone,
                kyc_status,
                kyc_started_at,
                kyc_submitted_at,
                kyc_approved_at,
                kyc_rejected_at,
                kyc_retry_count,
                kyc_rejection_reason
            FROM {$table}
            {$where}
            ORDER BY
                CASE kyc_status
                    WHEN 'pending' THEN 1
                    WHEN 'processing' THEN 2
                    WHEN 'rejected' THEN 3
                    WHEN 'not_started' THEN 4
                    WHEN 'approved' THEN 5
                    WHEN 'expired' THEN 6
                END,
                kyc_submitted_at DESC
            LIMIT 50
        ", ARRAY_A);

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Profissional</th>
                    <th>Contato</th>
                    <th>Status KYC</th>
                    <th>Data Submissão</th>
                    <th>Tentativas</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($professionals)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px;">
                            Nenhum profissional encontrado.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($professionals as $prof): ?>
                        <tr>
                            <td><strong>#<?php echo $prof['id']; ?></strong></td>
                            <td><?php echo esc_html($prof['full_name']); ?></td>
                            <td>
                                <?php echo esc_html($prof['email']); ?><br>
                                <small><?php echo esc_html($prof['phone']); ?></small>
                            </td>
                            <td><?php echo $this->renderKycStatusBadge($prof['kyc_status']); ?></td>
                            <td>
                                <?php
                                if ($prof['kyc_submitted_at']) {
                                    echo date('d/m/Y H:i', strtotime($prof['kyc_submitted_at']));
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $retries = (int) $prof['kyc_retry_count'];
                                if ($retries > 0) {
                                    echo "<span style='color: #ef4444;'>{$retries} tentativa(s)</span>";
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=kyc&kyc_action=view&id=<?php echo $prof['id']; ?>" class="button button-small">
                                    👁️ Ver Detalhes
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
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
        $statusFilter = $_GET['risk_status'] ?? 'all';

        // Provider connection status
        $providerStatus = \LimpVix\Infrastructure\Verification\Providers\VerificationProviderFactory::connectionStatus();
        ?>

        <!-- Provider Status Banner -->
        <div style="margin: 0 0 20px; padding: 12px 16px; background: <?php echo $providerStatus['kyc']['connected'] && $providerStatus['background']['connected'] ? '#f0fdf4' : '#fff8f1'; ?>; border: 1px solid <?php echo $providerStatus['kyc']['connected'] && $providerStatus['background']['connected'] ? '#bbf7d0' : '#fed7aa'; ?>; border-radius: 6px; display: flex; gap: 32px; align-items: center;">
            <div>
                <strong>KYC:</strong>
                <?php if ($providerStatus['kyc']['connected']): ?>
                    <span style="color:#16a34a;">✅ PPID Conectado</span>
                <?php else: ?>
                    <span style="color:#ea580c;">🔴 PPID Desconectado</span>
                    <small style="color:#9a3412;"> — usando mock provider (modo teste)</small>
                <?php endif; ?>
            </div>
            <div>
                <strong>Background Check:</strong>
                <?php if ($providerStatus['background']['connected']): ?>
                    <span style="color:#16a34a;">✅ Exato Digital Conectado</span>
                <?php else: ?>
                    <span style="color:#ea580c;">🔴 Exato Digital Desconectado</span>
                    <small style="color:#9a3412;"> — usando mock provider (modo teste)</small>
                <?php endif; ?>
            </div>
        </div>

        <p class="description">
            Pipeline de elegibilidade profissional: OTP → KYC → Background Check → Risk Engine → Status Final.
            <a href="<?php echo admin_url('admin.php?page=limpvix&tab=risk'); ?>" style="margin-left:8px;">
                ⚙️ Configurar credenciais PPID / Exato Digital
            </a>
        </p>

        <!-- Status Filter -->
        <div class="tablenav top">
            <div class="alignleft actions">
                <select name="risk_status" id="risk-status-filter">
                    <option value="all" <?php selected($statusFilter, 'all'); ?>>Todos os Status</option>
                    <option value="PENDING_VERIFICATION" <?php selected($statusFilter, 'PENDING_VERIFICATION'); ?>>⏳ Aguardando Verificação</option>
                    <option value="ACTIVE" <?php selected($statusFilter, 'ACTIVE'); ?>>✅ Ativo</option>
                    <option value="ACTIVE_MONITORED" <?php selected($statusFilter, 'ACTIVE_MONITORED'); ?>>👁️ Ativo (Monitorado)</option>
                    <option value="UNDER_REVIEW" <?php selected($statusFilter, 'UNDER_REVIEW'); ?>>🔍 Em Revisão</option>
                    <option value="NOT_ELIGIBLE" <?php selected($statusFilter, 'NOT_ELIGIBLE'); ?>>🚫 Não Elegível</option>
                    <option value="SUSPENDED" <?php selected($statusFilter, 'SUSPENDED'); ?>>⛔ Suspenso</option>
                </select>
                <button type="button" class="button" id="risk-filter-submit">Filtrar</button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <?php $this->renderRiskScoreStatistics(); ?>

        <!-- Professionals Table -->
        <?php $this->renderRiskScoreTable($statusFilter); ?>

        <script>
        jQuery(document).ready(function($) {
            $('#risk-filter-submit').on('click', function() {
                var status = $('#risk-status-filter').val();
                window.location.href = '?page=<?php echo self::PAGE_SLUG; ?>&tab=risk_score&risk_status=' + status;
            });
        });
        </script>
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

        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin: 20px 0;">

            <div style="background:#fff; padding:20px; border-left:4px solid #2271b1; box-shadow:0 1px 3px rgba(0,0,0,.1);">
                <div style="font-size:13px;color:#666;margin-bottom:4px;">Total no Pipeline</div>
                <div style="font-size:32px;font-weight:bold;color:#2271b1;"><?php echo (int)$stats['total']; ?></div>
            </div>

            <div style="background:#fff; padding:20px; border-left:4px solid #00a32a; box-shadow:0 1px 3px rgba(0,0,0,.1);">
                <div style="font-size:13px;color:#666;margin-bottom:4px;">✅ Ativos</div>
                <div style="font-size:32px;font-weight:bold;color:#00a32a;"><?php echo (int)$stats['active']; ?></div>
            </div>

            <div style="background:#fff; padding:20px; border-left:4px solid #f0b849; box-shadow:0 1px 3px rgba(0,0,0,.1);">
                <div style="font-size:13px;color:#666;margin-bottom:4px;">👁️ Monitorados</div>
                <div style="font-size:32px;font-weight:bold;color:#f0b849;"><?php echo (int)$stats['monitored']; ?></div>
            </div>

            <div style="background:#fff; padding:20px; border-left:4px solid #00a0d2; box-shadow:0 1px 3px rgba(0,0,0,.1);">
                <div style="font-size:13px;color:#666;margin-bottom:4px;">🔍 Em Revisão</div>
                <div style="font-size:32px;font-weight:bold;color:#00a0d2;"><?php echo (int)$stats['review']; ?></div>
            </div>

            <div style="background:#fff; padding:20px; border-left:4px solid #d63638; box-shadow:0 1px 3px rgba(0,0,0,.1);">
                <div style="font-size:13px;color:#666;margin-bottom:4px;">🚫 Não Elegíveis</div>
                <div style="font-size:32px;font-weight:bold;color:#d63638;"><?php echo (int)$stats['not_eligible']; ?></div>
            </div>

            <div style="background:#fff; padding:20px; border-left:4px solid #8c8f94; box-shadow:0 1px 3px rgba(0,0,0,.1);">
                <div style="font-size:13px;color:#666;margin-bottom:4px;">⛔ Suspensos</div>
                <div style="font-size:32px;font-weight:bold;color:#8c8f94;"><?php echo (int)$stats['suspended']; ?></div>
            </div>

            <div style="background:#fff; padding:20px; border-left:4px solid #dba617; box-shadow:0 1px 3px rgba(0,0,0,.1);">
                <div style="font-size:13px;color:#666;margin-bottom:4px;">⏰ BG Expirado</div>
                <div style="font-size:32px;font-weight:bold;color:#dba617;"><?php echo (int)$stats['bg_expired']; ?></div>
                <div style="font-size:11px;color:#999;">revalidação necessária</div>
            </div>

            <div style="background:#fff; padding:20px; border-left:4px solid #ef4444; box-shadow:0 1px 3px rgba(0,0,0,.1);">
                <div style="font-size:13px;color:#666;margin-bottom:4px;">⚠️ Alto Risco</div>
                <div style="font-size:32px;font-weight:bold;color:#ef4444;"><?php echo (int)$stats['high_risk']; ?></div>
            </div>

        </div>
        <?php
    }

    private function renderRiskScoreTable(string $statusFilter): void
    {
        global $wpdb;
        $vtable = $wpdb->prefix . 'limpvix_professional_verification';
        $ptable = $wpdb->prefix . 'limpvix_professionals';

        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$vtable}'");
        if (!$tableExists) {
            return;
        }

        $where = 'WHERE 1=1';
        if ($statusFilter !== 'all') {
            $where .= $wpdb->prepare(' AND v.final_status = %s', $statusFilter);
        }

        $rows = $wpdb->get_results("
            SELECT
                v.id            AS v_id,
                v.user_id,
                v.otp_verified,
                v.kyc_status,
                v.background_status,
                v.risk_level,
                v.final_status,
                v.background_expires_at,
                v.kyc_provider,
                v.background_provider,
                v.updated_at,
                p.full_name,
                p.email,
                p.phone
            FROM {$vtable} v
            LEFT JOIN {$ptable} p ON p.user_id = v.user_id
            {$where}
            ORDER BY
                CASE v.final_status
                    WHEN 'UNDER_REVIEW'         THEN 1
                    WHEN 'NOT_ELIGIBLE'         THEN 2
                    WHEN 'SUSPENDED'            THEN 3
                    WHEN 'ACTIVE_MONITORED'     THEN 4
                    WHEN 'PENDING_VERIFICATION' THEN 5
                    WHEN 'ACTIVE'               THEN 6
                END,
                v.updated_at DESC
            LIMIT 100
        ", ARRAY_A);

        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
            <thead>
                <tr>
                    <th style="width:180px;">Profissional</th>
                    <th style="width:60px;">OTP</th>
                    <th style="width:110px;">KYC</th>
                    <th style="width:130px;">Background</th>
                    <th style="width:90px;">Risco</th>
                    <th style="width:150px;">Status Final</th>
                    <th style="width:110px;">BG Expira</th>
                    <th style="width:80px;">Provedores</th>
                    <th style="width:100px;">Atualizado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px;color:#666;">
                            Nenhum profissional no pipeline de verificação ainda.<br>
                            <small>Inicie o pipeline via <code>RunVerificationPipeline</code> ou pelo app mobile.</small>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $bgExpired = !empty($r['background_expires_at']) && strtotime($r['background_expires_at']) < time();
                        ?>
                        <tr <?php echo $bgExpired ? 'style="background:#fff8f1;"' : ''; ?>>
                            <td>
                                <strong><?php echo esc_html($r['full_name'] ?? '(sem cadastro)'); ?></strong><br>
                                <small style="color:#666;"><?php echo esc_html($r['email'] ?? 'user_id #'.$r['user_id']); ?></small>
                            </td>
                            <td>
                                <?php echo $r['otp_verified'] ? '<span style="color:#16a34a;font-weight:bold;">✅</span>' : '<span style="color:#9ca3af;">—</span>'; ?>
                            </td>
                            <td><?php echo $this->renderRiskStatusBadge('kyc', $r['kyc_status']); ?></td>
                            <td><?php echo $this->renderRiskStatusBadge('background', $r['background_status']); ?></td>
                            <td><?php echo $this->renderRiskLevelBadge($r['risk_level']); ?></td>
                            <td><?php echo $this->renderFinalStatusBadge($r['final_status']); ?></td>
                            <td>
                                <?php if (!empty($r['background_expires_at'])): ?>
                                    <span style="color:<?php echo $bgExpired ? '#ef4444' : '#374151'; ?>;">
                                        <?php echo date('d/m/Y', strtotime($r['background_expires_at'])); ?>
                                        <?php if ($bgExpired): ?><br><small style="color:#ef4444;">⏰ Expirado</small><?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11px;color:#6b7280;">
                                KYC: <?php echo esc_html($r['kyc_provider'] ?? '—'); ?><br>
                                BG: <?php echo esc_html($r['background_provider'] ?? '—'); ?>
                            </td>
                            <td style="font-size:12px;color:#666;">
                                <?php echo $r['updated_at'] ? date('d/m/Y H:i', strtotime($r['updated_at'])) : '—'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
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
}
