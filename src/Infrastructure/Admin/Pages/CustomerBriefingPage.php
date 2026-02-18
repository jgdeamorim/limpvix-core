<?php
/**
 * CustomerBriefingPage
 *
 * Página onde o cliente aceita o briefing jurídico antes do serviço
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.1.2
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Application\UseCases\RegisterBriefingAcceptance;

class CustomerBriefingPage
{
    /**
     * Registrar hooks
     */
    public static function register(): void
    {
        // Página acessível publicamente (não requer login admin)
        add_action('template_redirect', [__CLASS__, 'handlePublicPage']);
        add_action('wp_ajax_nopriv_limpvix_accept_briefing', [__CLASS__, 'handleAcceptance']);
        add_action('wp_ajax_limpvix_accept_briefing', [__CLASS__, 'handleAcceptance']);
    }

    /**
     * Detectar se é a página de briefing
     */
    public static function handlePublicPage(): void
    {
        if (!isset($_GET['limpvix_briefing']) || empty($_GET['order_id'])) {
            return;
        }

        $order_id = absint($_GET['order_id']);
        $hash = sanitize_text_field($_GET['hash'] ?? '');

        // Validar hash de segurança
        if (!self::validateHash($order_id, $hash)) {
            wp_die('Link inválido ou expirado.', 'Erro', ['response' => 403]);
        }

        self::render($order_id);
        exit;
    }

    /**
     * Renderizar página de briefing
     */
    public static function render(int $order_id): void
    {
        global $wpdb;
        
        // Buscar order
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_orders WHERE id = %d",
            $order_id
        ), ARRAY_A);

        if (!$order) {
            wp_die('Pedido não encontrado.', 'Erro', ['response' => 404]);
        }

        // Verificar se já aceitou
        $already_accepted = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_ledger 
            WHERE order_id = %d AND event_type = 'briefing_accepted'",
            $order_id
        ));

        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Aceite de Termos - LimpVix</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }

                .container {
                    background: white;
                    border-radius: 16px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                    max-width: 600px;
                    width: 100%;
                    overflow: hidden;
                }

                .header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 32px;
                    text-align: center;
                }

                .header h1 {
                    font-size: 28px;
                    margin-bottom: 8px;
                }

                .header p {
                    opacity: 0.9;
                    font-size: 14px;
                }

                .content {
                    padding: 32px;
                }

                .order-info {
                    background: #f7fafc;
                    border-left: 4px solid #667eea;
                    padding: 16px;
                    margin-bottom: 24px;
                    border-radius: 4px;
                }

                .order-info strong {
                    color: #2d3748;
                    display: block;
                    margin-bottom: 4px;
                }

                .briefing-section {
                    margin-bottom: 24px;
                }

                .briefing-section h2 {
                    font-size: 18px;
                    color: #2d3748;
                    margin-bottom: 12px;
                    display: flex;
                    align-items: center;
                }

                .briefing-section h2::before {
                    content: '✓';
                    display: inline-block;
                    width: 24px;
                    height: 24px;
                    background: #667eea;
                    color: white;
                    border-radius: 50%;
                    text-align: center;
                    line-height: 24px;
                    margin-right: 12px;
                    font-size: 14px;
                }

                .briefing-section ul {
                    list-style: none;
                    padding-left: 0;
                }

                .briefing-section li {
                    padding: 8px 0;
                    padding-left: 36px;
                    position: relative;
                    color: #4a5568;
                    line-height: 1.6;
                }

                .briefing-section li::before {
                    content: '•';
                    position: absolute;
                    left: 20px;
                    color: #667eea;
                    font-weight: bold;
                }

                .impact-warning {
                    background: #fff5f5;
                    border: 2px solid #fc8181;
                    border-radius: 8px;
                    padding: 16px;
                    margin: 24px 0;
                }

                .impact-warning h3 {
                    color: #c53030;
                    font-size: 16px;
                    margin-bottom: 8px;
                    display: flex;
                    align-items: center;
                }

                .impact-warning h3::before {
                    content: '⚠️';
                    margin-right: 8px;
                }

                .impact-warning p {
                    color: #742a2a;
                    line-height: 1.6;
                }

                .acceptance-box {
                    background: #f7fafc;
                    border: 2px solid #e2e8f0;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 24px 0;
                }

                .checkbox-wrapper {
                    display: flex;
                    align-items: flex-start;
                    cursor: pointer;
                }

                .checkbox-wrapper input[type="checkbox"] {
                    width: 20px;
                    height: 20px;
                    margin-right: 12px;
                    margin-top: 2px;
                    cursor: pointer;
                }

                .checkbox-wrapper label {
                    flex: 1;
                    cursor: pointer;
                    color: #2d3748;
                    line-height: 1.6;
                }

                .btn {
                    width: 100%;
                    padding: 16px;
                    border: none;
                    border-radius: 8px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s;
                }

                .btn-primary {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                }

                .btn-primary:hover:not(:disabled) {
                    transform: translateY(-2px);
                    box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
                }

                .btn-primary:disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }

                .already-accepted {
                    background: #f0fff4;
                    border: 2px solid #9ae6b4;
                    border-radius: 8px;
                    padding: 20px;
                    text-align: center;
                }

                .already-accepted h2 {
                    color: #22543d;
                    margin-bottom: 8px;
                }

                .already-accepted p {
                    color: #2f855a;
                }

                .success-message {
                    background: #f0fff4;
                    border: 2px solid #9ae6b4;
                    border-radius: 8px;
                    padding: 20px;
                    text-align: center;
                    display: none;
                }

                .success-message.show {
                    display: block;
                }

                .success-message h2 {
                    color: #22543d;
                    margin-bottom: 12px;
                }

                .success-message p {
                    color: #2f855a;
                    line-height: 1.6;
                }

                .error-message {
                    background: #fff5f5;
                    border: 2px solid #fc8181;
                    border-radius: 8px;
                    padding: 16px;
                    margin-bottom: 20px;
                    color: #c53030;
                    display: none;
                }

                .error-message.show {
                    display: block;
                }

                @media (max-width: 640px) {
                    .header h1 {
                        font-size: 24px;
                    }

                    .content {
                        padding: 24px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>📋 Aceite de Termos</h1>
                    <p>Leia atentamente antes de prosseguir</p>
                </div>

                <div class="content">
                    <div class="order-info">
                        <strong>Pedido #<?php echo esc_html($order['id']); ?></strong>
                        <?php if (!empty($order['customer_name'])): ?>
                            <p>Cliente: <?php echo esc_html($order['customer_name']); ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($already_accepted): ?>
                        <div class="already-accepted">
                            <h2>✅ Termos já Aceitos</h2>
                            <p>Você já aceitou os termos deste serviço anteriormente.</p>
                            <p>Pode fechar esta janela.</p>
                        </div>
                    <?php else: ?>
                        <div id="error-message" class="error-message"></div>
                        <div id="success-message" class="success-message">
                            <h2>✅ Termos Aceitos com Sucesso!</h2>
                            <p>Seu aceite foi registrado. Você pode fechar esta janela.</p>
                            <p>Em breve entraremos em contato para confirmar seu agendamento.</p>
                        </div>

                        <div id="briefing-form">
                            <div class="briefing-section">
                                <h2>Termos do Serviço</h2>
                                <ul>
                                    <li>O serviço será executado conforme agendamento confirmado</li>
                                    <li>Cancelamentos devem ser feitos com 24h de antecedência</li>
                                    <li>É necessário acesso ao local no horário agendado</li>
                                    <li>Materiais de limpeza são fornecidos pela LimpVix</li>
                                </ul>
                            </div>

                            <div class="briefing-section">
                                <h2>Sistema de Avaliação</h2>
                                <ul>
                                    <li>Após o serviço, você receberá um pedido de avaliação</li>
                                    <li>Sua avaliação é fundamental para a qualidade do serviço</li>
                                    <li>Avaliações honestas nos ajudam a melhorar continuamente</li>
                                </ul>
                            </div>

                            <div class="impact-warning">
                                <h3>Importante: Impacto da sua Avaliação</h3>
                                <p>
                                    <strong>Sua avaliação impacta diretamente o profissional:</strong><br>
                                    • <strong>5 estrelas:</strong> Liberação imediata do pagamento<br>
                                    • <strong>4 estrelas:</strong> Liberação após 24 horas<br>
                                    • <strong>3 estrelas ou menos:</strong> Pagamento retido para análise<br><br>
                                    Por isso, pedimos que avalie com justiça e honestidade.
                                </p>
                            </div>

                            <div class="briefing-section">
                                <h2>Política de Privacidade</h2>
                                <ul>
                                    <li>Seus dados são protegidos conforme LGPD</li>
                                    <li>Utilizamos seus dados apenas para prestação do serviço</li>
                                    <li>Você pode solicitar exclusão dos dados a qualquer momento</li>
                                </ul>
                            </div>

                            <div class="acceptance-box">
                                <div class="checkbox-wrapper">
                                    <input type="checkbox" id="accept-terms" required>
                                    <label for="accept-terms">
                                        <strong>Declaro que li e aceito todos os termos acima.</strong>
                                        Estou ciente que minha avaliação impactará o profissional e me comprometo a avaliar com justiça.
                                    </label>
                                </div>
                            </div>

                            <button id="btn-submit" class="btn btn-primary" disabled>
                                Aceitar e Prosseguir
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <script>
                (function() {
                    const checkbox = document.getElementById('accept-terms');
                    const btnSubmit = document.getElementById('btn-submit');
                    const errorMessage = document.getElementById('error-message');
                    const successMessage = document.getElementById('success-message');
                    const briefingForm = document.getElementById('briefing-form');

                    if (!checkbox || !btnSubmit) return;

                    checkbox.addEventListener('change', function() {
                        btnSubmit.disabled = !this.checked;
                    });

                    btnSubmit.addEventListener('click', async function() {
                        if (!checkbox.checked) return;

                        btnSubmit.disabled = true;
                        btnSubmit.textContent = 'Salvando...';
                        errorMessage.classList.remove('show');

                        try {
                            const formData = new FormData();
                            formData.append('action', 'limpvix_accept_briefing');
                            formData.append('order_id', '<?php echo esc_js($order_id); ?>');
                            formData.append('accepted', '1');

                            const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                method: 'POST',
                                body: formData
                            });

                            const result = await response.json();

                            if (result.success) {
                                briefingForm.style.display = 'none';
                                successMessage.classList.add('show');
                            } else {
                                throw new Error(result.data?.message || 'Erro ao salvar aceite');
                            }
                        } catch (error) {
                            errorMessage.textContent = error.message;
                            errorMessage.classList.add('show');
                            btnSubmit.disabled = false;
                            btnSubmit.textContent = 'Aceitar e Prosseguir';
                        }
                    });
                })();
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * Handler AJAX para aceite
     */
    public static function handleAcceptance(): void
    {
        // Security: verify nonce to prevent CSRF
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'limpvix_briefing_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        $order_id = absint($_POST['order_id'] ?? 0);
        $accepted = boolval($_POST['accepted'] ?? false);

        if (!$order_id || !$accepted) {
            wp_send_json_error(['message' => 'Dados inválidos']);
        }

        // Use Case
        try {
            $useCase = new RegisterBriefingAcceptance();
            $result = $useCase->execute($order_id, $accepted);

            wp_send_json_success([
                'message' => 'Aceite registrado com sucesso',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Validar hash de segurança
     */
    private static function validateHash(int $order_id, string $hash): bool
    {
        $expected_hash = wp_hash($order_id . 'limpvix_briefing');
        return hash_equals($expected_hash, $hash);
    }

    /**
     * Gerar URL de briefing
     */
    public static function generateUrl(int $order_id): string
    {
        $hash = wp_hash($order_id . 'limpvix_briefing');
        return add_query_arg([
            'limpvix_briefing' => '1',
            'order_id' => $order_id,
            'hash' => $hash
        ], home_url());
    }
}
