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
use LimpVix\Application\UseCases\Professional\RegisterProfessional;
use LimpVix\Application\UseCases\Professional\UpdateProfessionalScore;

defined('ABSPATH') || exit;

class ProfessionalManagementPage
{
    private const PAGE_SLUG = 'limpvix-professionals';
    private const NONCE_ACTION = 'limpvix_professional_action';

    private $wpdb;
    private $repository;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->repository = new WpMarketplaceProfessionalRepository();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'handleFormSubmission']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'limpvix',
            'Profissionais',
            'Profissionais',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function enqueueAssets($hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        // Styles
        wp_enqueue_style(
            'limpvix-professionals',
            plugins_url('assets/css/professionals.css', LIMPVIX_PLUGIN_FILE),
            [],
            LIMPVIX_VERSION
        );

        // Scripts
        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'limpvix-professionals',
            plugins_url('assets/js/professionals.js', LIMPVIX_PLUGIN_FILE),
            ['jquery'],
            LIMPVIX_VERSION,
            true
        );

        // Localize
        wp_localize_script('limpvix-professionals', 'limpvixProfessionals', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('limpvix_professionals_ajax'),
        ]);
    }

    public function handleFormSubmission(): void
    {
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
        // Handle transient messages
        $messages = get_transient('limpvix_professional_action_result');
        if ($messages) {
            delete_transient('limpvix_professional_action_result');
        }

        // Get filters
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : 'all';
        $filter_verified = isset($_GET['filter_verified']) ? sanitize_text_field($_GET['filter_verified']) : 'all';
        $filter_score = isset($_GET['filter_score']) ? (float)$_GET['filter_score'] : 0;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

        // Get professionals
        $professionals = $this->getProfessionals($filter_status, $filter_verified, $filter_score, $search);

        // Get statistics
        $stats = $this->repository->getStatistics();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-groups" style="font-size: 28px; vertical-align: middle;"></span>
                Profissionais
            </h1>
            <a href="#" class="page-title-action" id="btn-register-professional">Adicionar Profissional</a>
            <hr class="wp-heading-inline">

            <?php $this->renderMessages($messages); ?>
            <?php $this->renderStatistics($stats); ?>
            <?php $this->renderFilters($filter_status, $filter_verified, $filter_score, $search); ?>
            <?php $this->renderProfessionalsTable($professionals); ?>
            <?php $this->renderRegisterModal(); ?>
            <?php $this->renderActionsModals(); ?>
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

    private function renderStatistics(array $stats): void
    {
        $active = $stats['active'] ?? 0;
        $total = $stats['total'] ?? 0;
        $avgScore = $stats['avg_score'] ?? 0;
        $verified = $this->repository->countVerified();
        $suspended = count($this->repository->findSuspended());
        $pending = count($this->repository->findPendingVerification());

        ?>
        <div class="limpvix-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #2271b1; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Total</div>
                <div style="font-size: 32px; font-weight: bold; color: #2271b1;"><?php echo $total; ?></div>
            </div>
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a32a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Ativos</div>
                <div style="font-size: 32px; font-weight: bold; color: #00a32a;"><?php echo $active; ?></div>
            </div>
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #4ab866; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Verificados</div>
                <div style="font-size: 32px; font-weight: bold; color: #4ab866;"><?php echo $verified; ?></div>
            </div>
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #f0b849; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Pendentes</div>
                <div style="font-size: 32px; font-weight: bold; color: #f0b849;"><?php echo $pending; ?></div>
            </div>
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #d63638; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Suspensos</div>
                <div style="font-size: 32px; font-weight: bold; color: #d63638;"><?php echo $suspended; ?></div>
            </div>
            <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #f0b849; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Score Médio</div>
                <div style="font-size: 32px; font-weight: bold; color: #f0b849;"><?php echo number_format($avgScore, 2, ',', '.'); ?></div>
            </div>
        </div>
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

    private function getProfessionals($filter_status, $filter_verified, $filter_score, $search): array
    {
        $table = $this->wpdb->prefix . 'limpvix_professionals';

        $where = ['1=1'];
        $params = [];

        // Filter by status
        if ($filter_status === 'active') {
            $where[] = 'is_active = 1 AND (suspended_until IS NULL OR suspended_until < NOW())';
        } elseif ($filter_status === 'inactive') {
            $where[] = 'is_active = 0';
        } elseif ($filter_status === 'suspended') {
            $where[] = 'is_active = 1 AND suspended_until IS NOT NULL AND suspended_until > NOW()';
        }

        // Filter by verified
        if ($filter_verified === 'verified') {
            $where[] = 'is_verified = 1';
        } elseif ($filter_verified === 'not_verified') {
            $where[] = 'is_verified = 0';
        }

        // Filter by score
        if ($filter_score > 0) {
            $where[] = $this->wpdb->prepare('score >= %f', $filter_score);
        }

        // Search
        if (!empty($search)) {
            $like = '%' . $this->wpdb->esc_like($search) . '%';
            $where[] = $this->wpdb->prepare(
                '(full_name LIKE %s OR cpf LIKE %s OR email LIKE %s)',
                $like, $like, $like
            );
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 100";

        return $this->wpdb->get_results($sql, ARRAY_A);
    }

    private function renderProfessionalsTable(array $professionals): void
    {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>CPF</th>
                    <th>Email / Telefone</th>
                    <th>Score</th>
                    <th>Status</th>
                    <th>Verificado</th>
                    <th>Região</th>
                    <th>Skills</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($professionals)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 40px;">
                            <span class="dashicons dashicons-info" style="font-size: 48px; color: #ccc;"></span>
                            <p style="color: #666; margin-top: 10px;">Nenhum profissional encontrado</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($professionals as $prof): ?>
                        <?php $this->renderProfessionalRow($prof); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
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

    private function renderRegisterModal(): void
    {
        $skills = [
            'limpeza_basica' => 'Limpeza Básica',
            'pos_obra' => 'Pós-Obra',
            'teto' => 'Limpeza de Teto',
            'esquadrias' => 'Esquadrias/Vidros',
            'carpete' => 'Carpete',
            'estofados' => 'Estofados',
            'jardim' => 'Jardim',
        ];

        $certifications = [
            'nr35_altura' => 'NR35 - Trabalho em Altura',
            'nr10_eletrica' => 'NR10 - Eletricidade',
            'manipulacao_quimicos' => 'Manipulação de Químicos',
            'primeiros_socorros' => 'Primeiros Socorros',
        ];

        ?>
        <div id="modal-register-professional" style="display:none;">
            <div style="max-width: 900px; margin: 0 auto;">
                <h2>Registrar Novo Profissional</h2>
                <form method="post" id="form-register-professional">
                    <?php wp_nonce_field(self::NONCE_ACTION . '_register', 'limpvix_professional_nonce_register'); ?>
                    <input type="hidden" name="limpvix_professional_action_type" value="register">

                    <table class="form-table">
                        <tr>
                            <th colspan="2"><h3>Dados Pessoais</h3></th>
                        </tr>
                        <tr>
                            <th><label for="full_name">Nome Completo *</label></th>
                            <td><input type="text" name="full_name" id="full_name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="cpf">CPF *</label></th>
                            <td><input type="text" name="cpf" id="cpf" class="regular-text" required placeholder="000.000.000-00"></td>
                        </tr>
                        <tr>
                            <th><label for="email">Email *</label></th>
                            <td><input type="email" name="email" id="email" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="phone">Telefone *</label></th>
                            <td><input type="text" name="phone" id="phone" class="regular-text" required placeholder="(27) 99999-9999"></td>
                        </tr>

                        <tr>
                            <th colspan="2"><h3>Endereço</h3></th>
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
                            <th><label for="zipcode">CEP</label></th>
                            <td><input type="text" name="zipcode" id="zipcode" class="regular-text" placeholder="00000-000"></td>
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
                        <tr>
                            <th><label>Skills *</label></th>
                            <td>
                                <?php foreach ($skills as $key => $label): ?>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="skills[]" value="<?php echo esc_attr($key); ?>">
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description">Selecione pelo menos uma skill</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Certificações</label></th>
                            <td>
                                <?php foreach ($certifications as $key => $label): ?>
                                    <label style="display: block; margin: 5px 0;">
                                        <input type="checkbox" name="certifications[]" value="<?php echo esc_attr($key); ?>">
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
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
                        <button type="submit" class="button button-primary button-large">Registrar Profissional</button>
                        <button type="button" class="button button-large" onclick="tb_remove();">Cancelar</button>
                    </p>
                </form>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#btn-register-professional').on('click', function(e) {
                e.preventDefault();
                tb_show('Registrar Profissional', '#TB_inline?inlineId=modal-register-professional&width=900&height=600');
            });
        });
        </script>
        <?php
    }

    private function renderActionsModals(): void
    {
        ?>
        <!-- Modal Verificar -->
        <div id="modal-verify-professional" style="display:none;">
            <div style="padding: 20px;">
                <h2>Verificar Profissional</h2>
                <p>Tem certeza que deseja <strong>verificar</strong> este profissional?</p>
                <p>Profissionais verificados podem receber ofertas de serviço.</p>
                <form method="post" id="form-verify">
                    <?php wp_nonce_field(self::NONCE_ACTION . '_verify', 'limpvix_professional_nonce_verify'); ?>
                    <input type="hidden" name="limpvix_professional_action_type" value="verify">
                    <input type="hidden" name="professional_id" id="verify-professional-id">
                    <p class="submit">
                        <button type="submit" class="button button-primary">Sim, Verificar</button>
                        <button type="button" class="button" onclick="tb_remove();">Cancelar</button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Modal Suspender -->
        <div id="modal-suspend-professional" style="display:none;">
            <div style="padding: 20px;">
                <h2>Suspender Profissional</h2>
                <form method="post" id="form-suspend">
                    <?php wp_nonce_field(self::NONCE_ACTION . '_suspend', 'limpvix_professional_nonce_suspend'); ?>
                    <input type="hidden" name="limpvix_professional_action_type" value="suspend">
                    <input type="hidden" name="professional_id" id="suspend-professional-id">

                    <table class="form-table">
                        <tr>
                            <th><label for="suspension_days">Dias de Suspensão</label></th>
                            <td>
                                <input type="number" name="suspension_days" id="suspension_days"
                                       value="7" min="1" max="365" required class="small-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="suspension_reason">Motivo *</label></th>
                            <td>
                                <textarea name="suspension_reason" id="suspension_reason"
                                          rows="4" class="large-text" required></textarea>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Suspender</button>
                        <button type="button" class="button" onclick="tb_remove();">Cancelar</button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Modal Atualizar Score -->
        <div id="modal-update-score" style="display:none;">
            <div style="padding: 20px;">
                <h2>Atualizar Score Manualmente</h2>
                <form method="post" id="form-update-score">
                    <?php wp_nonce_field(self::NONCE_ACTION . '_update_score', 'limpvix_professional_nonce_update_score'); ?>
                    <input type="hidden" name="limpvix_professional_action_type" value="update_score">
                    <input type="hidden" name="professional_id" id="score-professional-id">

                    <table class="form-table">
                        <tr>
                            <th><label for="score_reason">Motivo *</label></th>
                            <td>
                                <select name="score_reason" id="score_reason" required>
                                    <option value="">Selecione...</option>
                                    <option value="feedback">Feedback Manual</option>
                                    <option value="good_execution">Boa Execução</option>
                                    <option value="late_checkin">Atraso no Check-in</option>
                                    <option value="no_show">Não Comparecimento</option>
                                    <option value="epi_violation">Violação de EPI</option>
                                    <option value="client_complaint">Reclamação do Cliente</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="rating-row" style="display: none;">
                            <th><label for="rating">Rating (0-5)</label></th>
                            <td>
                                <input type="number" name="rating" id="rating"
                                       min="0" max="5" step="0.1" class="small-text">
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Atualizar Score</button>
                        <button type="button" class="button" onclick="tb_remove();">Cancelar</button>
                    </p>
                </form>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Verificar
            $('.btn-verify-professional').on('click', function(e) {
                e.preventDefault();
                $('#verify-professional-id').val($(this).data('id'));
                tb_show('Verificar Profissional', '#TB_inline?inlineId=modal-verify-professional&width=400&height=250');
            });

            // Suspender
            $('.btn-suspend-professional').on('click', function(e) {
                e.preventDefault();
                $('#suspend-professional-id').val($(this).data('id'));
                tb_show('Suspender Profissional', '#TB_inline?inlineId=modal-suspend-professional&width=500&height=400');
            });

            // Remover suspensão
            $('.btn-unsuspend-professional').on('click', function(e) {
                e.preventDefault();
                if (confirm('Remover suspensão deste profissional?')) {
                    var form = $('<form method="post">')
                        .append('<?php echo wp_nonce_field(self::NONCE_ACTION . '_unsuspend', 'limpvix_professional_nonce_unsuspend', true, false); ?>')
                        .append($('<input type="hidden" name="limpvix_professional_action_type" value="unsuspend">'))
                        .append($('<input type="hidden" name="professional_id">').val($(this).data('id')));
                    $('body').append(form);
                    form.submit();
                }
            });

            // Atualizar Score
            $('.btn-update-score').on('click', function(e) {
                e.preventDefault();
                $('#score-professional-id').val($(this).data('id'));
                tb_show('Atualizar Score', '#TB_inline?inlineId=modal-update-score&width=500&height=350');
            });

            $('#score_reason').on('change', function() {
                if ($(this).val() === 'feedback') {
                    $('#rating-row').show();
                    $('#rating').attr('required', true);
                } else {
                    $('#rating-row').hide();
                    $('#rating').attr('required', false);
                }
            });

            // Desativar
            $('.btn-deactivate-professional').on('click', function(e) {
                e.preventDefault();
                if (confirm('Tem certeza que deseja DESATIVAR este profissional?\n\nProfissionais desativados não podem receber ofertas e não aparecem nas buscas.')) {
                    var form = $('<form method="post">')
                        .append('<?php echo wp_nonce_field(self::NONCE_ACTION . '_deactivate', 'limpvix_professional_nonce_deactivate', true, false); ?>')
                        .append($('<input type="hidden" name="limpvix_professional_action_type" value="deactivate">'))
                        .append($('<input type="hidden" name="professional_id">').val($(this).data('id')));
                    $('body').append(form);
                    form.submit();
                }
            });
        });
        </script>
        <?php
    }
}
