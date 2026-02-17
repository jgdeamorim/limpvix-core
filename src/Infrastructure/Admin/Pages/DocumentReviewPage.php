<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Application\UseCases\Professional\ListDocuments;
use LimpVix\Application\UseCases\Professional\ReviewDocument;
use LimpVix\Infrastructure\Persistence\WpProfessionalDocumentRepository;

/**
 * Document Review Admin Page
 *
 * Lista e permite aprovação/rejeição de documentos enviados por profissionais
 */
final class DocumentReviewPage
{
    private ListDocuments $listDocuments;
    private ReviewDocument $reviewDocument;

    public function __construct()
    {
        $repository = new WpProfessionalDocumentRepository();
        $this->listDocuments = new ListDocuments($repository);
        $this->reviewDocument = new ReviewDocument($repository);
    }

    public function render(): void
    {
        // Handle actions
        $this->handleActions();

        // Get documents
        $limit = 50;
        $offset = isset($_GET['paged']) ? ((int)$_GET['paged'] - 1) * $limit : 0;

        $filter = $_GET['status_filter'] ?? 'pending';

        $result = match($filter) {
            'expired' => $this->listDocuments->expired($limit, $offset),
            'expiring_soon' => $this->listDocuments->expiringSoon(30, $limit, $offset),
            default => $this->listDocuments->pending($limit, $offset),
        };

        $documents = $result['documents'];
        $total = $result['total'];

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-media-document"></span>
                Revisão de Documentos
            </h1>

            <hr class="wp-header-end">

            <?php $this->renderStats(); ?>
            <?php $this->renderFilters($filter); ?>
            <?php $this->renderDocumentsTable($documents); ?>
            <?php $this->renderPagination($total, $limit, $offset); ?>
        </div>

        <style>
            .document-review-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .stat-card {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                border-left: 4px solid #2271b1;
            }
            .stat-card.warning { border-left-color: #dba617; }
            .stat-card.success { border-left-color: #00a32a; }
            .stat-card.danger { border-left-color: #d63638; }
            .stat-number {
                font-size: 32px;
                font-weight: bold;
                margin: 10px 0;
            }
            .stat-label {
                color: #646970;
                font-size: 13px;
                text-transform: uppercase;
            }
            .document-thumbnail {
                width: 60px;
                height: 60px;
                object-fit: cover;
                border-radius: 4px;
                cursor: pointer;
            }
            .document-actions {
                display: flex;
                gap: 8px;
            }
            .btn-approve {
                background: #00a32a;
                color: white;
                border: none;
                padding: 6px 12px;
                border-radius: 4px;
                cursor: pointer;
            }
            .btn-approve:hover { background: #008a20; }
            .btn-reject {
                background: #d63638;
                color: white;
                border: none;
                padding: 6px 12px;
                border-radius: 4px;
                cursor: pointer;
            }
            .btn-reject:hover { background: #b32d2e; }
            .status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
            }
            .status-badge.pending { background: #fcf3cf; color: #996515; }
            .status-badge.approved { background: #d7fae0; color: #00401b; }
            .status-badge.rejected { background: #fcdbdb; color: #6a0001; }
            .status-badge.expired { background: #e5e5e5; color: #50575e; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Approve document
            $('.btn-approve').on('click', function() {
                const docId = $(this).data('doc-id');

                if (!confirm('Tem certeza que deseja aprovar este documento?')) {
                    return;
                }

                $.post(ajaxurl, {
                    action: 'limpvix_approve_document',
                    nonce: '<?php echo wp_create_nonce('limpvix_document_review'); ?>',
                    document_id: docId
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Erro: ' + response.data.message);
                    }
                });
            });

            // Reject document
            $('.btn-reject').on('click', function() {
                const docId = $(this).data('doc-id');
                const reason = prompt('Informe o motivo da rejeição:');

                if (!reason || reason.trim() === '') {
                    alert('Motivo da rejeição é obrigatório');
                    return;
                }

                $.post(ajaxurl, {
                    action: 'limpvix_reject_document',
                    nonce: '<?php echo wp_create_nonce('limpvix_document_review'); ?>',
                    document_id: docId,
                    reason: reason
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Erro: ' + response.data.message);
                    }
                });
            });

            // View image in lightbox
            $('.document-thumbnail').on('click', function() {
                const url = $(this).data('full-url');
                tb_show('Documento', url);
            });
        });
        </script>
        <?php
    }

    private function renderStats(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_professional_documents';

        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
        $approved = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'approved'");
        $rejected = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'rejected'");
        $expired = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'expired' OR (expires_at IS NOT NULL AND expires_at < NOW())");

        ?>
        <div class="document-review-stats">
            <div class="stat-card warning">
                <div class="stat-label">Aguardando Revisão</div>
                <div class="stat-number"><?php echo $pending; ?></div>
            </div>

            <div class="stat-card success">
                <div class="stat-label">Aprovados</div>
                <div class="stat-number"><?php echo $approved; ?></div>
            </div>

            <div class="stat-card danger">
                <div class="stat-label">Rejeitados</div>
                <div class="stat-number"><?php echo $rejected; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Expirados</div>
                <div class="stat-number"><?php echo $expired; ?></div>
            </div>
        </div>
        <?php
    }

    private function renderFilters(string $currentFilter): void
    {
        $filters = [
            'pending' => 'Aguardando Revisão',
            'expiring_soon' => 'Expirando em Breve',
            'expired' => 'Expirados',
        ];

        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<select name="status_filter" id="status_filter">';

        foreach ($filters as $value => $label) {
            $selected = $currentFilter === $value ? 'selected' : '';
            echo "<option value=\"$value\" $selected>$label</option>";
        }

        echo '</select>';
        echo '<button type="button" class="button" onclick="location.href=\'?page=limpvix-document-review&status_filter=\' + document.getElementById(\'status_filter\').value">Filtrar</button>';
        echo '</div>';
        echo '</div>';
    }

    private function renderDocumentsTable(array $documents): void
    {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Preview</th>
                    <th>Profissional</th>
                    <th>Tipo de Documento</th>
                    <th>Data de Envio</th>
                    <th>Status</th>
                    <th>Expira em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($documents)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px;">
                            <p style="color: #646970;">Nenhum documento encontrado</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($documents as $doc): ?>
                        <?php
                        $attachmentUrl = wp_get_attachment_url($doc->getAttachmentId());
                        $thumbUrl = wp_get_attachment_image_url($doc->getAttachmentId(), 'thumbnail');
                        $isPdf = $doc->getMimeType() === 'application/pdf';

                        // Get professional data
                        global $wpdb;
                        $professional = $wpdb->get_row($wpdb->prepare(
                            "SELECT id, name FROM {$wpdb->prefix}limpvix_professionals WHERE id = %d",
                            $doc->getProfessionalId()
                        ));
                        ?>
                        <tr>
                            <td>
                                <?php if ($isPdf): ?>
                                    <a href="<?php echo esc_url($attachmentUrl); ?>" target="_blank">
                                        <span class="dashicons dashicons-pdf" style="font-size: 40px;"></span>
                                    </a>
                                <?php else: ?>
                                    <img src="<?php echo esc_url($thumbUrl ?: $attachmentUrl); ?>"
                                         class="document-thumbnail"
                                         data-full-url="<?php echo esc_url($attachmentUrl); ?>"
                                         alt="Document">
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($professional->name ?? 'N/A'); ?></strong><br>
                                <small>ID: <?php echo $doc->getProfessionalId(); ?></small>
                            </td>
                            <td><?php echo esc_html($doc->getDocumentType()->getLabel()); ?></td>
                            <td><?php echo $doc->getCreatedAt()->format('d/m/Y H:i'); ?></td>
                            <td>
                                <span class="status-badge <?php echo $doc->getStatus()->getValue(); ?>">
                                    <?php echo esc_html($doc->getStatus()->getLabel()); ?>
                                </span>
                                <?php if ($doc->getRejectionReason()): ?>
                                    <br><small style="color: #d63638;">
                                        <?php echo esc_html($doc->getRejectionReason()); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($doc->getExpiresAt()): ?>
                                    <?php echo $doc->getExpiresAt()->format('d/m/Y'); ?>
                                    <?php if ($doc->isExpired()): ?>
                                        <br><span class="status-badge expired">EXPIRADO</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($doc->needsReview()): ?>
                                    <div class="document-actions">
                                        <button class="btn-approve" data-doc-id="<?php echo $doc->getId(); ?>">
                                            ✓ Aprovar
                                        </button>
                                        <button class="btn-reject" data-doc-id="<?php echo $doc->getId(); ?>">
                                            ✗ Rejeitar
                                        </button>
                                    </div>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderPagination(int $total, int $limit, int $offset): void
    {
        $totalPages = ceil($total / $limit);
        $currentPage = floor($offset / $limit) + 1;

        if ($totalPages <= 1) {
            return;
        }

        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages">';
        echo paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => __('&laquo;'),
            'next_text' => __('&raquo;'),
            'total' => $totalPages,
            'current' => $currentPage,
        ]);
        echo '</div>';
        echo '</div>';
    }

    private function handleActions(): void
    {
        if (!isset($_POST['action'])) {
            return;
        }

        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'limpvix_document_action')) {
            wp_die('Invalid nonce');
        }

        $action = $_POST['action'];
        $documentId = isset($_POST['document_id']) ? (int) $_POST['document_id'] : 0;

        if (!$documentId) {
            return;
        }

        $reviewerId = get_current_user_id();

        try {
            if ($action === 'approve') {
                $this->reviewDocument->approve($documentId, $reviewerId);
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Documento aprovado com sucesso!</p></div>';
                });
            } elseif ($action === 'reject' && isset($_POST['reason'])) {
                $reason = sanitize_textarea_field($_POST['reason']);
                $this->reviewDocument->reject($documentId, $reviewerId, $reason);
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Documento rejeitado com sucesso!</p></div>';
                });
            }
        } catch (\Exception $e) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>Erro: ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
}
