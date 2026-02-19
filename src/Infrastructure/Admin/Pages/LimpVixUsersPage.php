<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Core\UserRoles;
use LimpVix\Admin\Capabilities\FinanceCapabilities;

defined('ABSPATH') || exit;

/**
 * LimpVixUsersPage — Gestão da Equipe Interna LimpVix
 *
 * Gerencia usuários internos com hierarquia:
 * - Gerente Nacional  → Brasil todo
 * - Gerente Estadual  → UF
 * - Gerente Regional  → UF + Zona
 * - Financeiro        → Autorização e processamento de pagamentos
 *
 * URL: /wp-admin/admin.php?page=limpvix-users
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 */
class LimpVixUsersPage
{
    private const PAGE_SLUG = 'limpvix-settings';
    private const PAGE_TAB  = 'limpvix-users';

    private const STAFF_ROLES = [
        'limpvix_gerente_nacional',
        'limpvix_gerente_estadual',
        'limpvix_gerente_regional',
        'limpvix_financeiro',
    ];

    private const UFS = [
        'AC','AL','AM','AP','BA','CE','DF','ES','GO','MA',
        'MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN',
        'RO','RR','RS','SC','SE','SP','TO',
    ];

    /**
     * Processar ações POST/GET (create, update, delete) — deve ser chamado ANTES do output HTML.
     * Chamado por AdminBootstrap::renderSettingsPage() antes do HTML.
     */
    public function handleActions(): void
    {
        if (!FinanceCapabilities::canManageUsers()) {
            return;
        }
        $this->dispatchActions();
    }

    /**
     * Renderizar como aba dentro de limpvix-settings (sem wrap externo).
     * Chamado por AdminBootstrap::renderSettingsPage() quando tab=limpvix-users.
     * Nota: ações POST/GET já foram processadas pelo handleActions() antes do output HTML.
     */
    public function renderTabContent(): void
    {
        if (!FinanceCapabilities::canManageUsers()) {
            echo '<div class="notice notice-error"><p>Acesso negado. Capability <code>limpvix_manage_users</code> requerida.</p></div>';
            return;
        }

        $view       = sanitize_key($_GET['view'] ?? 'list');
        $editUserId = (int) ($_GET['user_id'] ?? 0);

        $this->renderNotices();

        // Botão "+ Novo Usuário" no topo da aba
        if ($view === 'list'): ?>
        <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
            <a href="<?php echo esc_url($this->tabUrl(['view' => 'create'])); ?>"
               class="button button-primary">+ Novo Usuário</a>
        </div>
        <?php endif;

        if ($view === 'create') {
            $this->renderForm();
        } elseif ($view === 'edit' && $editUserId) {
            $this->renderForm($editUserId);
        } else {
            $this->renderList();
        }
    }

    /**
     * Renderizar página standalone (mantido para compatibilidade).
     */
    public function render(): void
    {
        if (!FinanceCapabilities::canManageUsers()) {
            wp_die('Acesso negado. Capability limpvix_manage_users requerida.');
        }

        $this->dispatchActions();

        $view       = sanitize_key($_GET['view'] ?? 'list');
        $editUserId = (int) ($_GET['user_id'] ?? 0);

        ?>
        <div class="wrap limpvix-admin">
            <div class="limpvix-page-header">
                <div>
                    <h1>
                        <span class="dashicons dashicons-groups"></span>
                        Equipe LimpVix
                    </h1>
                    <p class="limpvix-page-subtitle">Gerentes (Nacional / Estadual / Regional) e Financeiro</p>
                </div>
                <?php if ($view === 'list'): ?>
                <a href="<?php echo esc_url($this->tabUrl(['view' => 'create'])); ?>"
                   class="button button-primary">+ Novo Usuário</a>
                <?php endif; ?>
            </div>

            <?php $this->renderNotices(); ?>

            <?php if ($view === 'create'): ?>
                <?php $this->renderForm(); ?>
            <?php elseif ($view === 'edit' && $editUserId): ?>
                <?php $this->renderForm($editUserId); ?>
            <?php else: ?>
                <?php $this->renderList(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Helper: URL da aba users em limpvix-settings com args extras.
     */
    private function tabUrl(array $extra = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE_SLUG, 'tab' => self::PAGE_TAB], $extra),
            admin_url('admin.php')
        );
    }

    /**
     * Despachar ações POST/GET (create, update, delete).
     */
    private function dispatchActions(): void
    {
        $action = sanitize_key($_POST['limpvix_user_action'] ?? $_GET['action'] ?? '');

        if ($action === 'create' && isset($_POST['_wpnonce'])) {
            $this->handleCreate();
        } elseif ($action === 'update' && isset($_POST['_wpnonce'])) {
            $this->handleUpdate();
        } elseif ($action === 'delete' && isset($_GET['user_id'])) {
            $this->handleDelete();
        }
    }

    private function renderList(): void
    {
        $users      = $this->getStaffUsers();
        $roleLabels = UserRoles::getStaffRoleLabels();
        ?>
        <div class="limpvix-card">
            <div class="limpvix-card-body" style="padding:0;">
                <table class="wp-list-table widefat fixed striped" style="margin:0;">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th style="width:170px;">Role</th>
                            <th style="width:55px;">UF</th>
                            <th>Zona</th>
                            <th style="width:150px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:24px;color:#6b7280;">
                                Nenhum usuário da equipe.
                                <a href="<?php echo esc_url($this->tabUrl(['view' => 'create'])); ?>">Criar o primeiro</a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($users as $user): ?>
                        <?php
                            $uf        = UserRoles::getUserUf($user->ID) ?? '—';
                            $zona      = UserRoles::getUserZona($user->ID) ?? '—';
                            $role      = $this->getUserStaffRole($user);
                            $roleLabel = $roleLabels[$role] ?? $role;
                            $editUrl   = $this->tabUrl(['view' => 'edit', 'user_id' => $user->ID]);
                            $delUrl    = $this->tabUrl(['action' => 'delete', 'user_id' => $user->ID, '_wpnonce' => wp_create_nonce('limpvix_delete_user_' . $user->ID)]);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($user->display_name); ?></strong></td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td>
                                <span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600;">
                                    <?php echo esc_html($roleLabel); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($uf); ?></td>
                            <td><?php echo esc_html($zona); ?></td>
                            <td>
                                <a href="<?php echo esc_url($editUrl); ?>" class="button button-small">Editar</a>
                                <a href="<?php echo esc_url($delUrl); ?>" class="button button-small" style="color:#dc2626;"
                                   onclick="return confirm('Remover <?php echo esc_js($user->display_name); ?> da equipe?')">Remover</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function renderForm(int $userId = 0): void
    {
        $isEdit      = $userId > 0;
        $user        = $isEdit ? get_user_by('id', $userId) : null;
        $roleLabels  = UserRoles::getStaffRoleLabels();
        $currentRole = $isEdit ? $this->getUserStaffRole($user) : '';
        $currentUf   = $isEdit ? (UserRoles::getUserUf($userId) ?? '') : '';
        $currentZona = $isEdit ? (UserRoles::getUserZona($userId) ?? '') : '';
        ?>
        <div class="limpvix-card" style="max-width:680px;">
            <div class="limpvix-card-header">
                <h3><?php echo $isEdit ? 'Editar Usuário' : 'Novo Usuário da Equipe'; ?></h3>
            </div>
            <div class="limpvix-card-body">
                <form method="post" action="<?php echo esc_url($this->tabUrl()); ?>">
                    <?php wp_nonce_field($isEdit ? 'limpvix_update_user_' . $userId : 'limpvix_create_user'); ?>
                    <input type="hidden" name="limpvix_user_action" value="<?php echo $isEdit ? 'update' : 'create'; ?>">
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="edit_user_id" value="<?php echo (int) $userId; ?>">
                    <?php endif; ?>

                    <table class="form-table">
                        <?php if (!$isEdit): ?>
                        <tr>
                            <th><label for="user_login">Login *</label></th>
                            <td><input type="text" id="user_login" name="user_login" required class="regular-text" autocomplete="off"></td>
                        </tr>
                        <tr>
                            <th><label for="user_pass">Senha *</label></th>
                            <td>
                                <input type="password" id="user_pass" name="user_pass" required class="regular-text" autocomplete="new-password">
                                <button type="button" class="button button-small"
                                        onclick="document.getElementById('user_pass').value='<?php echo esc_js(wp_generate_password(12, true, false)); ?>'">Gerar</button>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th><label for="display_name">Nome *</label></th>
                            <td><input type="text" id="display_name" name="display_name" required class="regular-text" value="<?php echo esc_attr($user?->display_name ?? ''); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="user_email">E-mail *</label></th>
                            <td><input type="email" id="user_email" name="user_email" required class="regular-text" value="<?php echo esc_attr($user?->user_email ?? ''); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="staff_role">Role *</label></th>
                            <td>
                                <select id="staff_role" name="staff_role" required onchange="lmpxUpdateScope(this.value)">
                                    <option value="">— Selecione —</option>
                                    <?php foreach ($roleLabels as $role => $label): ?>
                                    <option value="<?php echo esc_attr($role); ?>" <?php selected($currentRole, $role); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Nacional = Brasil todo | Estadual = UF | Regional = UF + Zona | Financeiro = pagamentos</p>
                            </td>
                        </tr>
                        <tr id="row_uf" style="<?php echo in_array($currentRole, ['limpvix_gerente_nacional','limpvix_financeiro','']) ? 'display:none' : ''; ?>">
                            <th><label for="scope_uf">Estado (UF)</label></th>
                            <td>
                                <select id="scope_uf" name="scope_uf">
                                    <option value="">— Todo Brasil —</option>
                                    <?php foreach (self::UFS as $uf): ?>
                                    <option value="<?php echo esc_attr($uf); ?>" <?php selected($currentUf, $uf); ?>><?php echo esc_html($uf); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr id="row_zona" style="<?php echo $currentRole !== 'limpvix_gerente_regional' ? 'display:none' : ''; ?>">
                            <th><label for="scope_zona">Zona</label></th>
                            <td>
                                <input type="text" id="scope_zona" name="scope_zona" class="regular-text"
                                       placeholder="Ex: Zona Sul, Centro, ABC..." value="<?php echo esc_attr($currentZona); ?>">
                                <p class="description">Deixe vazio para cobrir toda a UF.</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php echo $isEdit ? '💾 Salvar' : '+ Criar Usuário'; ?></button>
                        <a href="<?php echo esc_url($this->tabUrl()); ?>" class="button">Cancelar</a>
                    </p>
                </form>
            </div>
        </div>
        <script>
        function lmpxUpdateScope(role) {
            var ru = document.getElementById('row_uf');
            var rz = document.getElementById('row_zona');
            var noScope = ['limpvix_gerente_nacional','limpvix_financeiro',''];
            ru.style.display = noScope.indexOf(role) >= 0 ? 'none' : '';
            rz.style.display = role === 'limpvix_gerente_regional' ? '' : 'none';
        }
        </script>
        <?php
    }

    private function handleCreate(): void
    {
        if (!check_admin_referer('limpvix_create_user')) {
            return;
        }
        $role = sanitize_key($_POST['staff_role'] ?? '');
        if (!in_array($role, self::STAFF_ROLES, true)) {
            $this->addNotice('error', 'Role inválido.');
            return;
        }
        $userId = wp_insert_user([
            'user_login'   => sanitize_user($_POST['user_login'] ?? ''),
            'user_pass'    => $_POST['user_pass'] ?? wp_generate_password(),
            'user_email'   => sanitize_email($_POST['user_email'] ?? ''),
            'display_name' => sanitize_text_field($_POST['display_name'] ?? ''),
            'role'         => $role,
        ]);
        if (is_wp_error($userId)) {
            $this->addNotice('error', $userId->get_error_message());
            return;
        }
        UserRoles::setUserScope(
            $userId,
            sanitize_text_field($_POST['scope_uf'] ?? ''),
            sanitize_text_field($_POST['scope_zona'] ?? '')
        );
        wp_redirect($this->tabUrl(['lmpx_notice' => 'created']));
        exit;
    }

    private function handleUpdate(): void
    {
        $userId = (int) ($_POST['edit_user_id'] ?? 0);
        if (!check_admin_referer('limpvix_update_user_' . $userId)) {
            return;
        }
        $role = sanitize_key($_POST['staff_role'] ?? '');
        if (!in_array($role, self::STAFF_ROLES, true)) {
            $this->addNotice('error', 'Role inválido.');
            return;
        }
        wp_update_user([
            'ID'           => $userId,
            'user_email'   => sanitize_email($_POST['user_email'] ?? ''),
            'display_name' => sanitize_text_field($_POST['display_name'] ?? ''),
            'role'         => $role,
        ]);
        UserRoles::setUserScope(
            $userId,
            sanitize_text_field($_POST['scope_uf'] ?? ''),
            sanitize_text_field($_POST['scope_zona'] ?? '')
        );
        wp_redirect($this->tabUrl(['lmpx_notice' => 'updated']));
        exit;
    }

    private function handleDelete(): void
    {
        $userId = (int) ($_GET['user_id'] ?? 0);
        if (!check_admin_referer('limpvix_delete_user_' . $userId)) {
            return;
        }
        if ($userId === get_current_user_id()) {
            $this->addNotice('error', 'Não é possível remover sua própria conta.');
            wp_redirect($this->tabUrl());
            exit;
        }
        $user = get_user_by('id', $userId);
        if ($user) {
            foreach (self::STAFF_ROLES as $r) {
                $user->remove_role($r);
            }
            $user->add_role('subscriber');
            delete_user_meta($userId, UserRoles::META_UF);
            delete_user_meta($userId, UserRoles::META_ZONA);
        }
        wp_redirect($this->tabUrl(['lmpx_notice' => 'removed']));
        exit;
    }

    private function getStaffUsers(): array
    {
        return get_users([
            'role__in' => self::STAFF_ROLES,
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            'number'   => 200,
        ]);
    }

    private function getUserStaffRole(\WP_User $user): string
    {
        foreach (self::STAFF_ROLES as $role) {
            if (in_array($role, (array) $user->roles, true)) {
                return $role;
            }
        }
        return '';
    }

    private function addNotice(string $type, string $message): void
    {
        set_transient('lmpx_admin_notice_' . get_current_user_id(), ['type' => $type, 'message' => $message], 30);
    }

    private function renderNotices(): void
    {
        $notice = get_transient('lmpx_admin_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('lmpx_admin_notice_' . get_current_user_id());
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($notice['type'] === 'success' ? 'success' : 'error'),
                wp_kses_post($notice['message'])
            );
        }
        $param = sanitize_key($_GET['lmpx_notice'] ?? '');
        $msgs  = [
            'created' => '✅ Usuário criado com sucesso.',
            'updated' => '✅ Usuário atualizado com sucesso.',
            'removed' => '✅ Role LimpVix removido. Conta WP mantida.',
        ];
        if (isset($msgs[$param])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msgs[$param]) . '</p></div>';
        }
    }
}
