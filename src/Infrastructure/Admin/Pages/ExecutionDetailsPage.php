<?php
/**
 * ExecutionDetailsPage - Página de Detalhes de Execution com Evidence Gallery
 *
 * RESPONSABILIDADE:
 * - Exibir detalhes de uma execution
 * - Galeria visual de evidências (fotos e vídeos)
 * - Lightbox para visualização em tela cheia
 * - Ações admin: aprovar/rejeitar evidências
 * - Delete evidência individual
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.12.0 (GAP #5)
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Application\UseCases\Execution\ApproveEvidence;
use LimpVix\Application\UseCases\Execution\RejectEvidence;
use LimpVix\Application\UseCases\Execution\RemoveEvidence;

class ExecutionDetailsPage
{
    /**
     * Registrar hooks
     */
    public static function register(): void
    {
        $instance = new self();
        add_action('admin_post_limpvix_approve_evidence', [$instance, 'handleApproveEvidence']);
        add_action('admin_post_limpvix_reject_evidence', [$instance, 'handleRejectEvidence']);
        add_action('admin_post_limpvix_remove_evidence', [$instance, 'handleRemoveEvidence']);
        
        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', [$instance, 'enqueueAssets']);
    }

    /**
     * Enqueue assets (CSS + JS for lightbox)
     */
    public function enqueueAssets($hook): void
    {
        // Only load on execution details page
        if (strpos($hook, 'limpvix-execution-details') === false) {
            return;
        }

        // Lightbox CSS (inline for simplicity)
        wp_add_inline_style('wp-admin', $this->getLightboxCSS());
        
        // Lightbox JS (inline for simplicity)
        wp_add_inline_script('jquery', $this->getLightboxJS());
    }

    /**
     * Renderizar página
     */
    public function render(): void
    {
        // Verificar permissão
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $execution_id = absint($_GET['execution_id'] ?? 0);

        if ($execution_id === 0) {
            wp_die('Execution ID inválido');
        }

        // Buscar execution
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_executions';
        
        $execution = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $execution_id
        ), ARRAY_A);

        if (!$execution) {
            wp_die("Execution #{$execution_id} não encontrada");
        }

        // Decodificar evidências
        $evidences = !empty($execution['evidence'])
            ? json_decode($execution['evidence'], true)
            : [];

        $evidence_status = $execution['evidence_status'] ?? 'pending';

        ?>
        <div class="wrap">
            <h1>📋 Execution #<?php echo $execution_id; ?> - Detalhes</h1>

            <!-- Evidence Status -->
            <div class="limpvix-evidence-status" style="margin: 20px 0;">
                <?php if ($evidence_status === 'approved'): ?>
                    <div class="notice notice-success">
                        <p><strong>✅ Evidências Aprovadas</strong></p>
                        <p>Validado em: <?php echo $execution['evidence_validated_at'] ?? 'N/A'; ?></p>
                    </div>
                <?php elseif ($evidence_status === 'rejected'): ?>
                    <div class="notice notice-error">
                        <p><strong>❌ Evidências Rejeitadas</strong></p>
                        <p>Motivo: <?php echo esc_html($execution['evidence_rejection_reason'] ?? 'N/A'); ?></p>
                        <p>Rejeitado em: <?php echo $execution['evidence_validated_at'] ?? 'N/A'; ?></p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-warning">
                        <p><strong>⏳ Evidências Pendentes de Validação</strong></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Evidence Gallery -->
            <div class="limpvix-evidence-gallery-container" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ddd;">
                <h2>📸 Evidências (<?php echo count($evidences); ?>)</h2>

                <?php if (empty($evidences)): ?>
                    <p>Nenhuma evidência enviada ainda.</p>
                <?php else: ?>
                    <div class="limpvix-evidence-gallery" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-top: 20px;">
                        <?php foreach ($evidences as $index => $evidence): ?>
                            <div class="limpvix-evidence-item" style="position: relative; border: 1px solid #ddd; border-radius: 4px; overflow: hidden;">
                                <?php if ($evidence['type'] === 'photo'): ?>
                                    <!-- Photo Preview -->
                                    <img src="<?php echo esc_url($evidence['url']); ?>" 
                                         alt="Evidence Photo" 
                                         class="limpvix-evidence-thumbnail"
                                         data-index="<?php echo $index; ?>"
                                         data-url="<?php echo esc_url($evidence['url']); ?>"
                                         data-type="photo"
                                         style="width: 100%; height: 200px; object-fit: cover; cursor: pointer;">
                                <?php else: ?>
                                    <!-- Video Preview -->
                                    <video class="limpvix-evidence-thumbnail" 
                                           data-index="<?php echo $index; ?>"
                                           data-url="<?php echo esc_url($evidence['url']); ?>"
                                           data-type="video"
                                           style="width: 100%; height: 200px; object-fit: cover; cursor: pointer;">
                                        <source src="<?php echo esc_url($evidence['url']); ?>" type="video/mp4">
                                    </video>
                                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 48px; color: white; pointer-events: none;">▶</div>
                                <?php endif; ?>

                                <!-- Evidence Info -->
                                <div style="padding: 8px; background: #f9fafb; font-size: 12px;">
                                    <div><strong><?php echo ucfirst($evidence['type']); ?></strong></div>
                                    <div>Enviado: <?php echo $evidence['uploadedAt'] ?? 'N/A'; ?></div>
                                </div>

                                <!-- Delete Button (Admin Only) -->
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="position: absolute; top: 8px; right: 8px;" onsubmit="return confirm('Remover esta evidência?');">
                                    <input type="hidden" name="action" value="limpvix_remove_evidence">
                                    <input type="hidden" name="execution_id" value="<?php echo $execution_id; ?>">
                                    <input type="hidden" name="evidence_index" value="<?php echo $index; ?>">
                                    <?php wp_nonce_field('limpvix_remove_evidence'); ?>
                                    <button type="submit" class="button button-small" style="background: rgba(239, 68, 68, 0.9); color: white; border: none; padding: 4px 8px; cursor: pointer;">🗑️</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Evidence Validation Actions -->
            <?php if (!empty($evidences) && $evidence_status === 'pending'): ?>
                <div class="limpvix-validation-actions" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ddd;">
                    <h3>Validar Evidências</h3>
                    
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block; margin-right: 10px;">
                        <input type="hidden" name="action" value="limpvix_approve_evidence">
                        <input type="hidden" name="execution_id" value="<?php echo $execution_id; ?>">
                        <?php wp_nonce_field('limpvix_approve_evidence'); ?>
                        <button type="submit" class="button button-primary button-large">✅ Aprovar Evidências</button>
                    </form>

                    <button type="button" class="button button-secondary button-large" onclick="document.getElementById('reject-form').style.display='block'">❌ Rejeitar Evidências</button>

                    <div id="reject-form" style="display: none; margin-top: 20px; padding: 20px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px;">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <input type="hidden" name="action" value="limpvix_reject_evidence">
                            <input type="hidden" name="execution_id" value="<?php echo $execution_id; ?>">
                            <?php wp_nonce_field('limpvix_reject_evidence'); ?>
                            
                            <label for="rejection_reason"><strong>Motivo da Rejeição (mínimo 10 caracteres):</strong></label><br>
                            <textarea name="rejection_reason" id="rejection_reason" rows="4" style="width: 100%; max-width: 600px;" required minlength="10" placeholder="Ex: Foto está muito desfocada, por favor envie uma foto mais nítida mostrando todo o trabalho realizado."></textarea><br><br>
                            
                            <button type="submit" class="button button-primary">Confirmar Rejeição</button>
                            <button type="button" class="button" onclick="document.getElementById('reject-form').style.display='none'">Cancelar</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Lightbox Modal -->
            <div id="limpvix-lightbox" class="limpvix-lightbox" style="display: none;">
                <span class="limpvix-lightbox-close">&times;</span>
                <div class="limpvix-lightbox-content">
                    <img id="limpvix-lightbox-image" src="" alt="Evidence" style="display: none;">
                    <video id="limpvix-lightbox-video" controls style="display: none;">
                        <source src="" type="video/mp4">
                    </video>
                </div>
                <button class="limpvix-lightbox-prev">&#10094;</button>
                <button class="limpvix-lightbox-next">&#10095;</button>
            </div>

            <script>
                <?php echo $this->getLightboxJS(); ?>
            </script>

            <style>
                <?php echo $this->getLightboxCSS(); ?>
            </style>
        </div>
        <?php
    }

    /**
     * Handle Approve Evidence
     */
    public function handleApproveEvidence(): void
    {
        check_admin_referer('limpvix_approve_evidence');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $execution_id = absint($_POST['execution_id']);
        $admin_user_id = get_current_user_id();

        $useCase = new ApproveEvidence();
        $result = $useCase->execute($execution_id, $admin_user_id);

        if ($result->isOk()) {
            wp_redirect(add_query_arg(['page' => 'limpvix-execution-details', 'execution_id' => $execution_id, 'message' => 'approved'], admin_url('admin.php')));
        } else {
            wp_die('Erro ao aprovar: ' . $result->error());
        }
        exit;
    }

    /**
     * Handle Reject Evidence
     */
    public function handleRejectEvidence(): void
    {
        check_admin_referer('limpvix_reject_evidence');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $execution_id = absint($_POST['execution_id']);
        $rejection_reason = sanitize_textarea_field($_POST['rejection_reason']);
        $admin_user_id = get_current_user_id();

        $useCase = new RejectEvidence();
        $result = $useCase->execute($execution_id, $admin_user_id, $rejection_reason);

        if ($result->isOk()) {
            wp_redirect(add_query_arg(['page' => 'limpvix-execution-details', 'execution_id' => $execution_id, 'message' => 'rejected'], admin_url('admin.php')));
        } else {
            wp_die('Erro ao rejeitar: ' . $result->error());
        }
        exit;
    }

    /**
     * Handle Remove Evidence
     */
    public function handleRemoveEvidence(): void
    {
        check_admin_referer('limpvix_remove_evidence');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $execution_id = absint($_POST['execution_id']);
        $evidence_index = absint($_POST['evidence_index']);
        $admin_user_id = get_current_user_id();

        $useCase = new RemoveEvidence();
        $result = $useCase->execute($execution_id, $evidence_index, $admin_user_id);

        if ($result->isOk()) {
            wp_redirect(add_query_arg(['page' => 'limpvix-execution-details', 'execution_id' => $execution_id, 'message' => 'removed'], admin_url('admin.php')));
        } else {
            wp_die('Erro ao remover: ' . $result->error());
        }
        exit;
    }

    /**
     * Get Lightbox CSS
     */
    private function getLightboxCSS(): string
    {
        return <<<CSS
        .limpvix-lightbox {
            position: fixed;
            z-index: 999999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
        }

        .limpvix-lightbox-content {
            position: relative;
            margin: auto;
            padding: 0;
            width: 90%;
            max-width: 1200px;
            height: 90vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .limpvix-lightbox-content img,
        .limpvix-lightbox-content video {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
        }

        .limpvix-lightbox-close {
            position: absolute;
            top: 15px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
        }

        .limpvix-lightbox-prev,
        .limpvix-lightbox-next {
            cursor: pointer;
            position: absolute;
            top: 50%;
            width: auto;
            padding: 16px;
            margin-top: -22px;
            color: white;
            font-weight: bold;
            font-size: 30px;
            background-color: rgba(0, 0, 0, 0.5);
            border: none;
            transition: 0.3s;
        }

        .limpvix-lightbox-prev { left: 0; }
        .limpvix-lightbox-next { right: 0; }

        .limpvix-lightbox-prev:hover,
        .limpvix-lightbox-next:hover {
            background-color: rgba(0, 0, 0, 0.8);
        }
        CSS;
    }

    /**
     * Get Lightbox JS
     */
    private function getLightboxJS(): string
    {
        return <<<'JS'
        jQuery(document).ready(function($) {
            var currentIndex = 0;
            var evidences = [];

            // Collect all evidences
            $('.limpvix-evidence-thumbnail').each(function() {
                evidences.push({
                    url: $(this).data('url'),
                    type: $(this).data('type'),
                    index: $(this).data('index')
                });
            });

            // Open lightbox
            $('.limpvix-evidence-thumbnail').on('click', function() {
                currentIndex = $(this).data('index');
                showLightbox(currentIndex);
            });

            // Close lightbox
            $('.limpvix-lightbox-close').on('click', function() {
                $('#limpvix-lightbox').hide();
                $('#limpvix-lightbox-video').get(0).pause();
            });

            // Navigation
            $('.limpvix-lightbox-prev').on('click', function() {
                currentIndex = (currentIndex - 1 + evidences.length) % evidences.length;
                showLightbox(currentIndex);
            });

            $('.limpvix-lightbox-next').on('click', function() {
                currentIndex = (currentIndex + 1) % evidences.length;
                showLightbox(currentIndex);
            });

            // Keyboard navigation
            $(document).on('keydown', function(e) {
                if ($('#limpvix-lightbox').is(':visible')) {
                    if (e.key === 'ArrowLeft') $('.limpvix-lightbox-prev').click();
                    if (e.key === 'ArrowRight') $('.limpvix-lightbox-next').click();
                    if (e.key === 'Escape') $('.limpvix-lightbox-close').click();
                }
            });

            function showLightbox(index) {
                var evidence = evidences[index];
                
                $('#limpvix-lightbox-image').hide();
                $('#limpvix-lightbox-video').hide();

                if (evidence.type === 'photo') {
                    $('#limpvix-lightbox-image').attr('src', evidence.url).show();
                } else {
                    $('#limpvix-lightbox-video').find('source').attr('src', evidence.url);
                    $('#limpvix-lightbox-video').get(0).load();
                    $('#limpvix-lightbox-video').show();
                }

                $('#limpvix-lightbox').show();
            }
        });
        JS;
    }
}
