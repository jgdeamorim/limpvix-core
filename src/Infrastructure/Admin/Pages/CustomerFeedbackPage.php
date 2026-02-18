<?php
/**
 * CustomerFeedbackPage
 *
 * Página onde o cliente avalia o serviço com estrelas e motivos
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.1.2
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Application\UseCases\ProcessCustomerFeedback;

class CustomerFeedbackPage
{
    /**
     * Registrar hooks
     */
    public static function register(): void
    {
        add_action('template_redirect', [__CLASS__, 'handlePublicPage']);
        add_action('wp_ajax_nopriv_limpvix_submit_feedback', [__CLASS__, 'handleSubmit']);
        add_action('wp_ajax_limpvix_submit_feedback', [__CLASS__, 'handleSubmit']);
    }

    /**
     * Detectar se é a página de feedback
     */
    public static function handlePublicPage(): void
    {
        if (!isset($_GET['limpvix_feedback']) || empty($_GET['order_id'])) {
            return;
        }

        $order_id = absint($_GET['order_id']);
        $hash = sanitize_text_field($_GET['hash'] ?? '');

        if (!self::validateHash($order_id, $hash)) {
            wp_die('Link inválido ou expirado.', 'Erro', ['response' => 403]);
        }

        self::render($order_id);
        exit;
    }

    /**
     * Renderizar página de feedback
     */
    public static function render(int $order_id): void
    {
        global $wpdb;
        
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_orders WHERE id = %d",
            $order_id
        ), ARRAY_A);

        if (!$order) {
            wp_die('Pedido não encontrado.', 'Erro', ['response' => 404]);
        }

        // Verificar se já avaliou
        $already_rated = !empty($order['customer_rating']);

        $positive_reasons = [
            'professional' => 'Profissional atencioso e educado',
            'quality' => 'Qualidade excepcional do serviço',
            'punctuality' => 'Pontualidade e compromisso',
            'attention' => 'Atenção aos detalhes',
            'value' => 'Ótimo custo-benefício'
        ];

        $negative_reasons = [
            'delay' => 'Atraso no horário combinado',
            'incomplete' => 'Serviço incompleto',
            'quality_issue' => 'Qualidade abaixo do esperado',
            'communication' => 'Problemas de comunicação',
            'damage' => 'Danos ou problemas'
        ];

        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Avalie seu Serviço - LimpVix</title>
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

                .rating-section {
                    text-align: center;
                    margin: 32px 0;
                }

                .rating-section h2 {
                    font-size: 20px;
                    color: #2d3748;
                    margin-bottom: 16px;
                }

                .stars {
                    display: flex;
                    justify-content: center;
                    gap: 8px;
                    margin-bottom: 16px;
                }

                .star {
                    font-size: 48px;
                    cursor: pointer;
                    transition: all 0.2s;
                    color: #cbd5e0;
                }

                .star:hover,
                .star.active {
                    color: #fbbf24;
                    transform: scale(1.1);
                }

                .rating-text {
                    font-size: 14px;
                    color: #718096;
                    min-height: 20px;
                }

                .impact-box {
                    background: #fff5f5;
                    border: 2px solid #fc8181;
                    border-radius: 8px;
                    padding: 16px;
                    margin: 24px 0;
                    display: none;
                }

                .impact-box.show {
                    display: block;
                }

                .impact-box.positive {
                    background: #f0fff4;
                    border-color: #9ae6b4;
                }

                .impact-box h3 {
                    font-size: 16px;
                    margin-bottom: 8px;
                    display: flex;
                    align-items: center;
                }

                .impact-box.positive h3 {
                    color: #22543d;
                }

                .impact-box:not(.positive) h3 {
                    color: #c53030;
                }

                .impact-box p {
                    line-height: 1.6;
                    font-size: 14px;
                }

                .impact-box.positive p {
                    color: #2f855a;
                }

                .impact-box:not(.positive) p {
                    color: #742a2a;
                }

                .reasons-section {
                    margin: 24px 0;
                    display: none;
                }

                .reasons-section.show {
                    display: block;
                }

                .reasons-section h3 {
                    font-size: 16px;
                    color: #2d3748;
                    margin-bottom: 12px;
                }

                .reason-item {
                    display: flex;
                    align-items: flex-start;
                    padding: 12px;
                    margin-bottom: 8px;
                    border: 2px solid #e2e8f0;
                    border-radius: 8px;
                    cursor: pointer;
                    transition: all 0.2s;
                }

                .reason-item:hover {
                    border-color: #667eea;
                    background: #f7fafc;
                }

                .reason-item.selected {
                    border-color: #667eea;
                    background: #eef2ff;
                }

                .reason-item input[type="checkbox"] {
                    width: 20px;
                    height: 20px;
                    margin-right: 12px;
                    cursor: pointer;
                }

                .reason-item label {
                    flex: 1;
                    cursor: pointer;
                    color: #4a5568;
                }

                .comment-section {
                    margin: 24px 0;
                }

                .comment-section label {
                    display: block;
                    font-weight: 600;
                    color: #2d3748;
                    margin-bottom: 8px;
                }

                .comment-section textarea {
                    width: 100%;
                    min-height: 100px;
                    padding: 12px;
                    border: 2px solid #e2e8f0;
                    border-radius: 8px;
                    font-family: inherit;
                    font-size: 14px;
                    resize: vertical;
                }

                .comment-section textarea:focus {
                    outline: none;
                    border-color: #667eea;
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

                .success-message,
                .error-message,
                .already-rated {
                    border-radius: 8px;
                    padding: 20px;
                    text-align: center;
                    display: none;
                }

                .success-message.show,
                .error-message.show,
                .already-rated.show {
                    display: block;
                }

                .success-message {
                    background: #f0fff4;
                    border: 2px solid #9ae6b4;
                }

                .success-message h2 {
                    color: #22543d;
                    margin-bottom: 12px;
                }

                .already-rated {
                    background: #fffaf0;
                    border: 2px solid #fbd38d;
                }

                .error-message {
                    background: #fff5f5;
                    border: 2px solid #fc8181;
                    color: #c53030;
                    margin-bottom: 20px;
                }

                @media (max-width: 640px) {
                    .star {
                        font-size: 36px;
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
                    <h1>⭐ Avalie seu Serviço</h1>
                    <p>Sua opinião é muito importante!</p>
                </div>

                <div class="content">
                    <div class="order-info">
                        <strong>Pedido #<?php echo esc_html($order['id']); ?></strong>
                        <?php if (!empty($order['customer_name'])): ?>
                            <p>Cliente: <?php echo esc_html($order['customer_name']); ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($already_rated): ?>
                        <div class="already-rated show">
                            <h2>⭐ Avaliação já Registrada</h2>
                            <p>Você já avaliou este serviço com <?php echo esc_html($order['customer_rating']); ?> estrelas.</p>
                            <p>Obrigado pelo seu feedback!</p>
                        </div>
                    <?php else: ?>
                        <div id="error-message" class="error-message"></div>
                        <div id="success-message" class="success-message">
                            <h2>✅ Avaliação Enviada!</h2>
                            <p>Muito obrigado pelo seu feedback.</p>
                            <p>Sua avaliação nos ajuda a melhorar continuamente nossos serviços.</p>
                        </div>

                        <div id="feedback-form">
                            <div class="rating-section">
                                <h2>Como foi seu serviço?</h2>
                                <div class="stars" id="stars">
                                    <span class="star" data-value="1">★</span>
                                    <span class="star" data-value="2">★</span>
                                    <span class="star" data-value="3">★</span>
                                    <span class="star" data-value="4">★</span>
                                    <span class="star" data-value="5">★</span>
                                </div>
                                <div class="rating-text" id="rating-text"></div>
                            </div>

                            <div class="impact-box" id="impact-box">
                                <h3 id="impact-title"></h3>
                                <p id="impact-description"></p>
                            </div>

                            <div class="reasons-section" id="positive-reasons">
                                <h3>O que você mais gostou?</h3>
                                <?php foreach ($positive_reasons as $key => $label): ?>
                                    <div class="reason-item" data-reason="<?php echo esc_attr($key); ?>">
                                        <input type="checkbox" id="pos_<?php echo esc_attr($key); ?>" name="positive_reasons[]" value="<?php echo esc_attr($key); ?>">
                                        <label for="pos_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="reasons-section" id="negative-reasons">
                                <h3>O que podemos melhorar?</h3>
                                <?php foreach ($negative_reasons as $key => $label): ?>
                                    <div class="reason-item" data-reason="<?php echo esc_attr($key); ?>">
                                        <input type="checkbox" id="neg_<?php echo esc_attr($key); ?>" name="negative_reasons[]" value="<?php echo esc_attr($key); ?>">
                                        <label for="neg_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="comment-section">
                                <label for="comment">Comentário adicional (opcional):</label>
                                <textarea id="comment" name="comment" placeholder="Conte-nos mais sobre sua experiência..."></textarea>
                            </div>

                            <button id="btn-submit" class="btn btn-primary" disabled>
                                Enviar Avaliação
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <script>
                (function() {
                    let selectedRating = 0;
                    
                    const stars = document.querySelectorAll('.star');
                    const ratingText = document.getElementById('rating-text');
                    const impactBox = document.getElementById('impact-box');
                    const impactTitle = document.getElementById('impact-title');
                    const impactDescription = document.getElementById('impact-description');
                    const positiveReasons = document.getElementById('positive-reasons');
                    const negativeReasons = document.getElementById('negative-reasons');
                    const btnSubmit = document.getElementById('btn-submit');
                    const feedbackForm = document.getElementById('feedback-form');
                    const successMessage = document.getElementById('success-message');
                    const errorMessage = document.getElementById('error-message');

                    const ratingTexts = {
                        1: 'Muito insatisfeito',
                        2: 'Insatisfeito',
                        3: 'Regular',
                        4: 'Satisfeito',
                        5: 'Muito satisfeito'
                    };

                    const impactMessages = {
                        5: {
                            icon: '✅',
                            title: 'Excelente! O profissional receberá imediatamente.',
                            description: 'Com sua avaliação 5 estrelas, o pagamento do profissional será liberado imediatamente. Muito obrigado!',
                            positive: true
                        },
                        4: {
                            icon: '⏱️',
                            title: 'Muito bom! Liberação em 24 horas.',
                            description: 'O pagamento do profissional será liberado após 24 horas como medida de segurança.',
                            positive: true
                        },
                        3: {
                            icon: '⚠️',
                            title: 'O pagamento ficará retido para análise.',
                            description: 'Com avaliação de 3 estrelas ou menos, o pagamento do profissional ficará retido até análise manual. Nossa equipe entrará em contato.'
                        },
                        2: {
                            icon: '⚠️',
                            title: 'O pagamento ficará retido para análise.',
                            description: 'Com avaliação de 3 estrelas ou menos, o pagamento do profissional ficará retido até análise manual. Nossa equipe entrará em contato.'
                        },
                        1: {
                            icon: '⚠️',
                            title: 'O pagamento ficará retido para análise.',
                            description: 'Com avaliação de 3 estrelas ou menos, o pagamento do profissional ficará retido até análise manual. Nossa equipe entrará em contato.'
                        }
                    };

                    // Estrelas
                    stars.forEach(star => {
                        star.addEventListener('click', function() {
                            selectedRating = parseInt(this.dataset.value);
                            updateStars();
                            updateImpact();
                            updateReasons();
                            btnSubmit.disabled = false;
                        });

                        star.addEventListener('mouseenter', function() {
                            const value = parseInt(this.dataset.value);
                            stars.forEach((s, i) => {
                                if (i < value) {
                                    s.style.color = '#fbbf24';
                                }
                            });
                        });

                        star.addEventListener('mouseleave', updateStars);
                    });

                    function updateStars() {
                        stars.forEach((star, i) => {
                            star.classList.toggle('active', i < selectedRating);
                        });
                        ratingText.textContent = ratingTexts[selectedRating] || '';
                    }

                    function updateImpact() {
                        if (!selectedRating) {
                            impactBox.classList.remove('show');
                            return;
                        }

                        const message = impactMessages[selectedRating];
                        impactBox.classList.add('show');
                        impactBox.classList.toggle('positive', message.positive);
                        impactTitle.innerHTML = message.icon + ' ' + message.title;
                        impactDescription.textContent = message.description;
                    }

                    function updateReasons() {
                        if (selectedRating >= 4) {
                            positiveReasons.classList.add('show');
                            negativeReasons.classList.remove('show');
                        } else if (selectedRating <= 3 && selectedRating > 0) {
                            positiveReasons.classList.remove('show');
                            negativeReasons.classList.add('show');
                        } else {
                            positiveReasons.classList.remove('show');
                            negativeReasons.classList.remove('show');
                        }
                    }

                    // Reason items toggle
                    document.querySelectorAll('.reason-item').forEach(item => {
                        item.addEventListener('click', function() {
                            const checkbox = this.querySelector('input[type="checkbox"]');
                            checkbox.checked = !checkbox.checked;
                            this.classList.toggle('selected', checkbox.checked);
                        });
                    });

                    // Submit
                    btnSubmit.addEventListener('click', async function() {
                        if (!selectedRating) return;

                        btnSubmit.disabled = true;
                        btnSubmit.textContent = 'Enviando...';
                        errorMessage.classList.remove('show');

                        const formData = new FormData();
                        formData.append('action', 'limpvix_submit_feedback');
                        formData.append('order_id', '<?php echo esc_js($order_id); ?>');
                        formData.append('rating', selectedRating);

                        // Reasons
                        const reasons = [];
                        document.querySelectorAll('input[type="checkbox"]:checked').forEach(checkbox => {
                            reasons.push(checkbox.value);
                        });
                        formData.append('reasons', JSON.stringify(reasons));

                        // Comment
                        const comment = document.getElementById('comment').value.trim();
                        if (comment) {
                            formData.append('comment', comment);
                        }

                        try {
                            const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                method: 'POST',
                                body: formData
                            });

                            const result = await response.json();

                            if (result.success) {
                                feedbackForm.style.display = 'none';
                                successMessage.classList.add('show');
                            } else {
                                throw new Error(result.data?.message || 'Erro ao enviar avaliação');
                            }
                        } catch (error) {
                            errorMessage.textContent = error.message;
                            errorMessage.classList.add('show');
                            btnSubmit.disabled = false;
                            btnSubmit.textContent = 'Enviar Avaliação';
                        }
                    });
                })();
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * Handler AJAX para envio de feedback
     */
    public static function handleSubmit(): void
    {
        // Security: verify nonce to prevent CSRF
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'limpvix_feedback_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        $order_id = absint($_POST['order_id'] ?? 0);
        $rating = absint($_POST['rating'] ?? 0);
        $reasons = json_decode(stripslashes($_POST['reasons'] ?? '[]'), true);
        $comment = sanitize_textarea_field($_POST['comment'] ?? '');

        if (!$order_id || !$rating || $rating < 1 || $rating > 5) {
            wp_send_json_error(['message' => 'Dados inválidos']);
        }

        try {
            $useCase = new ProcessCustomerFeedback();
            $result = $useCase->execute($order_id, $rating, $reasons, $comment);

            wp_send_json_success([
                'message' => 'Avaliação registrada com sucesso',
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
        $expected_hash = wp_hash($order_id . 'limpvix_feedback');
        return hash_equals($expected_hash, $hash);
    }

    /**
     * Gerar URL de feedback
     */
    public static function generateUrl(int $order_id): string
    {
        $hash = wp_hash($order_id . 'limpvix_feedback');
        return add_query_arg([
            'limpvix_feedback' => '1',
            'order_id' => $order_id,
            'hash' => $hash
        ], home_url());
    }
}
