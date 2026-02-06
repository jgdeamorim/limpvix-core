<?php
/**
 * TestVendorsManager - Gerenciamento de Contas Vendedor de Teste
 *
 * FUNCIONALIDADES:
 * - Adicionar/editar/remover contas vendedor de teste
 * - Testar payout real para uma conta
 * - Histórico de testes realizados
 * - Validação de User ID via API
 *
 * @package LimpVix\Admin\Settings
 */

namespace LimpVix\Admin\Settings;

defined('ABSPATH') || exit;

class TestVendorsManager
{
    /**
     * Option name para salvar contas de teste
     */
    private const OPTION_TEST_VENDORS = 'limpvix_mp_test_vendors';
    private const OPTION_TEST_HISTORY = 'limpvix_mp_test_history';

    /**
     * Registrar hooks AJAX
     *
     * @return void
     */
    public static function registerHooks(): void
    {
        add_action('wp_ajax_limpvix_add_test_vendor', [__CLASS__, 'ajaxAddVendor']);
        add_action('wp_ajax_limpvix_delete_test_vendor', [__CLASS__, 'ajaxDeleteVendor']);
        add_action('wp_ajax_limpvix_test_payout', [__CLASS__, 'ajaxTestPayout']);
    }

    /**
     * Renderizar seção de contas de teste
     *
     * @return void
     */
    public static function render(): void
    {
        $vendors = self::getVendors();
        $history = self::getHistory();
        $mpActive = MercadoPagoSettings::isActive();

        ?>
        <div class="limpvix-test-vendors">
            <h2>
                <span class="dashicons dashicons-admin-users"></span>
                🧪 Contas de Teste (Vendedores)
            </h2>

            <?php if (!$mpActive): ?>
                <div class="notice notice-warning">
                    <p>
                        ⚠️ <strong>Mercado Pago não configurado</strong><br>
                        Configure o Access Token acima antes de gerenciar contas de teste.
                    </p>
                </div>
            <?php else: ?>

            <p class="description" style="margin-bottom: 20px;">
                Gerencie contas <strong>Vendedor de Teste</strong> do Mercado Pago para simular prestadores de serviço.
                <a href="https://www.mercadopago.com.br/developers/panel/test-users" target="_blank">
                    Criar novas contas de teste →
                </a>
            </p>

            <!-- Lista de Contas -->
            <div class="limpvix-vendors-list">
                <?php if (empty($vendors)): ?>
                    <div class="limpvix-empty-state">
                        <p style="text-align: center; color: #646970; padding: 40px 20px;">
                            <span class="dashicons dashicons-admin-users" style="font-size: 48px; opacity: 0.3;"></span><br>
                            Nenhuma conta vendedor cadastrada.<br>
                            <small>Adicione uma conta de teste para simular payouts.</small>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($vendors as $vendor): ?>
                        <div class="limpvix-vendor-card" data-vendor-id="<?php echo esc_attr($vendor['id']); ?>">
                            <div class="vendor-info">
                                <div class="vendor-icon">
                                    <span class="dashicons dashicons-businessman"></span>
                                </div>
                                <div class="vendor-details">
                                    <strong class="vendor-name"><?php echo esc_html($vendor['name']); ?></strong>
                                    <div class="vendor-meta">
                                        <span class="vendor-id">
                                            <span class="dashicons dashicons-id"></span>
                                            User ID: <code><?php echo esc_html($vendor['user_id']); ?></code>
                                        </span>
                                        <?php if (!empty($vendor['last_test'])): ?>
                                            <span class="vendor-last-test">
                                                <span class="dashicons dashicons-clock"></span>
                                                Último teste: <?php echo human_time_diff($vendor['last_test']); ?> atrás
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="vendor-actions">
                                <button type="button"
                                        class="button button-primary btn-test-payout"
                                        data-vendor-id="<?php echo esc_attr($vendor['id']); ?>"
                                        data-vendor-name="<?php echo esc_attr($vendor['name']); ?>"
                                        data-user-id="<?php echo esc_attr($vendor['user_id']); ?>">
                                    <span class="dashicons dashicons-money-alt"></span>
                                    Testar Payout
                                </button>
                                <button type="button"
                                        class="button button-link-delete btn-delete-vendor"
                                        data-vendor-id="<?php echo esc_attr($vendor['id']); ?>"
                                        data-vendor-name="<?php echo esc_attr($vendor['name']); ?>">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Formulário Adicionar Conta -->
            <div class="limpvix-add-vendor-form">
                <h3>➕ Adicionar Nova Conta Vendedor</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="vendor_name">Nome/Apelido</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="vendor_name"
                                   class="regular-text"
                                   placeholder="Ex: limpvix-vende, João Silva">
                            <p class="description">Nome identificador para esta conta</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="vendor_user_id">User ID</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="vendor_user_id"
                                   class="regular-text"
                                   placeholder="Ex: 3186056326">
                            <p class="description">
                                User ID da conta vendedor de teste<br>
                                <a href="https://www.mercadopago.com.br/developers/panel/test-users" target="_blank">
                                    Criar/visualizar contas de teste →
                                </a>
                            </p>
                        </td>
                    </tr>
                </table>

                <button type="button" id="btn_add_vendor" class="button button-secondary">
                    <span class="dashicons dashicons-plus-alt"></span>
                    Adicionar Conta
                </button>
            </div>

            <!-- Histórico de Testes -->
            <?php if (!empty($history)): ?>
                <div class="limpvix-test-history">
                    <h3>
                        <span class="dashicons dashicons-backup"></span>
                        Histórico de Testes
                    </h3>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Data/Hora</th>
                                <th>Conta</th>
                                <th>Valor</th>
                                <th>Status</th>
                                <th>Transfer ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice(array_reverse($history), 0, 10) as $test): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i:s', $test['timestamp']); ?></td>
                                    <td><?php echo esc_html($test['vendor_name']); ?></td>
                                    <td>R$ <?php echo number_format($test['amount'], 2, ',', '.'); ?></td>
                                    <td>
                                        <?php if ($test['success']): ?>
                                            <span class="limpvix-badge success">✅ Sucesso</span>
                                        <?php else: ?>
                                            <span class="limpvix-badge error">❌ Erro</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($test['transfer_id'])): ?>
                                            <code><?php echo esc_html($test['transfer_id']); ?></code>
                                        <?php else: ?>
                                            <small><?php echo esc_html($test['error_message']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php endif; // mpActive ?>
        </div>

        <!-- CSS -->
        <style>
            .limpvix-test-vendors {
                max-width: 900px;
                margin: 30px 0;
            }

            .limpvix-test-vendors h2 {
                display: flex;
                align-items: center;
                gap: 10px;
                border-bottom: 2px solid #2271b1;
                padding-bottom: 10px;
                margin-bottom: 20px;
            }

            /* Vendor Cards */
            .limpvix-vendors-list {
                margin-bottom: 30px;
            }

            .limpvix-vendor-card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 15px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                transition: box-shadow 0.2s;
            }

            .limpvix-vendor-card:hover {
                box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            }

            .vendor-info {
                display: flex;
                align-items: center;
                gap: 15px;
                flex: 1;
            }

            .vendor-icon {
                width: 50px;
                height: 50px;
                background: #f0f0f1;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .vendor-icon .dashicons {
                font-size: 28px;
                color: #2271b1;
            }

            .vendor-name {
                font-size: 16px;
                display: block;
                margin-bottom: 5px;
            }

            .vendor-meta {
                display: flex;
                flex-direction: column;
                gap: 5px;
                font-size: 13px;
                color: #646970;
            }

            .vendor-meta .dashicons {
                font-size: 16px;
                vertical-align: middle;
            }

            .vendor-actions {
                display: flex;
                gap: 10px;
            }

            /* Formulário */
            .limpvix-add-vendor-form {
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 30px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }

            .limpvix-add-vendor-form h3 {
                margin-top: 0;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }

            /* Histórico */
            .limpvix-test-history {
                margin-top: 30px;
                padding: 20px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }

            .limpvix-test-history h3 {
                margin-top: 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .limpvix-badge {
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
            }

            .limpvix-badge.success {
                background: #d7f0d7;
                color: #00a32a;
            }

            .limpvix-badge.error {
                background: #f7dddd;
                color: #d63638;
            }

            .limpvix-empty-state {
                background: white;
                border: 2px dashed #ddd;
                border-radius: 8px;
                padding: 20px;
            }
        </style>

        <!-- JavaScript -->
        <script>
        jQuery(document).ready(function($) {
            // Adicionar Conta Vendedor
            $('#btn_add_vendor').on('click', function() {
                const $btn = $(this);
                const name = $('#vendor_name').val().trim();
                const userId = $('#vendor_user_id').val().trim();

                if (!name || !userId) {
                    alert('Por favor, preencha todos os campos');
                    return;
                }

                if (!/^\d+$/.test(userId)) {
                    alert('User ID deve conter apenas números');
                    return;
                }

                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Adicionando...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'limpvix_add_test_vendor',
                        nonce: '<?php echo wp_create_nonce('limpvix_test_vendor'); ?>',
                        name: name,
                        user_id: userId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ Conta adicionada com sucesso!');
                            location.reload();
                        } else {
                            alert('❌ Erro: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('❌ Erro na comunicação com o servidor');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> Adicionar Conta');
                    }
                });
            });

            // Deletar Conta
            $(document).on('click', '.btn-delete-vendor', function() {
                const vendorId = $(this).data('vendor-id');
                const vendorName = $(this).data('vendor-name');

                if (!confirm(`⚠️ Tem certeza que deseja remover a conta "${vendorName}"?\n\nIsso não afeta a conta no Mercado Pago.`)) {
                    return;
                }

                const $btn = $(this);
                $btn.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'limpvix_delete_test_vendor',
                        nonce: '<?php echo wp_create_nonce('limpvix_test_vendor'); ?>',
                        vendor_id: vendorId
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ Conta removida com sucesso!');
                            location.reload();
                        } else {
                            alert('❌ Erro: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('❌ Erro na comunicação com o servidor');
                    }
                });
            });

            // Testar Payout
            $(document).on('click', '.btn-test-payout', function() {
                const $btn = $(this);
                const vendorId = $btn.data('vendor-id');
                const vendorName = $btn.data('vendor-name');
                const userId = $btn.data('user-id');

                if (!confirm(`🧪 Testar payout para "${vendorName}"?\n\nValor: R$ 0,01 (um centavo)\nDestino: User ID ${userId}`)) {
                    return;
                }

                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Testando...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'limpvix_test_payout',
                        nonce: '<?php echo wp_create_nonce('limpvix_test_payout'); ?>',
                        vendor_id: vendorId
                    },
                    success: function(response) {
                        if (response.success) {
                            const data = response.data;
                            alert(
                                '✅ Payout realizado com sucesso!\n\n' +
                                'Transfer ID: ' + data.transfer_id + '\n' +
                                'Status: ' + data.status + '\n' +
                                'Valor: R$ ' + data.amount + '\n' +
                                'Receiver ID: ' + data.receiver_id
                            );
                            location.reload();
                        } else {
                            alert('❌ Erro no payout:\n\n' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('❌ Erro na comunicação com o servidor');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-money-alt"></span> Testar Payout');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Adicionar conta vendedor
     */
    public static function ajaxAddVendor(): void
    {
        check_ajax_referer('limpvix_test_vendor', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }

        $name = sanitize_text_field($_POST['name'] ?? '');
        $userId = sanitize_text_field($_POST['user_id'] ?? '');

        if (empty($name) || empty($userId)) {
            wp_send_json_error(['message' => 'Campos obrigatórios não preenchidos']);
        }

        $vendors = self::getVendors();

        // Gerar ID único
        $id = uniqid('vendor_');

        $vendors[] = [
            'id' => $id,
            'name' => $name,
            'user_id' => $userId,
            'created_at' => time(),
            'last_test' => null,
        ];

        update_option(self::OPTION_TEST_VENDORS, $vendors);

        wp_send_json_success(['message' => 'Conta adicionada']);
    }

    /**
     * AJAX: Deletar conta vendedor
     */
    public static function ajaxDeleteVendor(): void
    {
        check_ajax_referer('limpvix_test_vendor', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }

        $vendorId = sanitize_text_field($_POST['vendor_id'] ?? '');
        $vendors = self::getVendors();

        $vendors = array_filter($vendors, function($v) use ($vendorId) {
            return $v['id'] !== $vendorId;
        });

        update_option(self::OPTION_TEST_VENDORS, array_values($vendors));

        wp_send_json_success(['message' => 'Conta removida']);
    }

    /**
     * AJAX: Testar payout
     */
    public static function ajaxTestPayout(): void
    {
        check_ajax_referer('limpvix_test_payout', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada']);
        }

        $vendorId = sanitize_text_field($_POST['vendor_id'] ?? '');
        $vendors = self::getVendors();

        // Encontrar vendor
        $vendor = null;
        foreach ($vendors as &$v) {
            if ($v['id'] === $vendorId) {
                $vendor = &$v;
                break;
            }
        }

        if (!$vendor) {
            wp_send_json_error(['message' => 'Conta não encontrada']);
        }

        // Obter Access Token
        $accessToken = MercadoPagoSettings::getAccessToken();
        if (empty($accessToken)) {
            wp_send_json_error(['message' => 'Access Token não configurado']);
        }

        // Fazer requisição à API do Mercado Pago
        $idempotencyKey = wp_generate_uuid4();
        $amount = 0.01; // 1 centavo

        $response = wp_remote_post('https://api.mercadopago.com/v1/money-transfers', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'X-Idempotency-Key' => $idempotencyKey,
            ],
            'body' => json_encode([
                'amount' => $amount,
                'receiver_id' => $vendor['user_id'],
                'description' => 'Teste de payout - LimpVix',
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Salvar no histórico
        $history = self::getHistory();

        if ($statusCode === 201 && isset($body['id'])) {
            // Sucesso
            $vendor['last_test'] = time();
            update_option(self::OPTION_TEST_VENDORS, $vendors);

            $history[] = [
                'timestamp' => time(),
                'vendor_id' => $vendorId,
                'vendor_name' => $vendor['name'],
                'amount' => $amount,
                'success' => true,
                'transfer_id' => $body['id'],
                'status' => $body['status'] ?? 'unknown',
            ];
            update_option(self::OPTION_TEST_HISTORY, $history);

            wp_send_json_success([
                'transfer_id' => $body['id'],
                'status' => $body['status'] ?? 'unknown',
                'amount' => number_format($amount, 2, ',', '.'),
                'receiver_id' => $vendor['user_id'],
            ]);
        } else {
            // Erro
            $errorMessage = $body['message'] ?? 'Erro desconhecido';

            $history[] = [
                'timestamp' => time(),
                'vendor_id' => $vendorId,
                'vendor_name' => $vendor['name'],
                'amount' => $amount,
                'success' => false,
                'error_message' => $errorMessage,
                'status_code' => $statusCode,
            ];
            update_option(self::OPTION_TEST_HISTORY, $history);

            wp_send_json_error([
                'message' => $errorMessage,
                'status_code' => $statusCode,
            ]);
        }
    }

    /**
     * Obter lista de vendors
     */
    private static function getVendors(): array
    {
        return get_option(self::OPTION_TEST_VENDORS, []);
    }

    /**
     * Obter histórico de testes
     */
    private static function getHistory(): array
    {
        return get_option(self::OPTION_TEST_HISTORY, []);
    }
}
