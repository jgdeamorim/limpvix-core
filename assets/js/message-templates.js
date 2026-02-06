/**
 * Message Templates Admin - JavaScript
 */

(function($) {
    'use strict';

    const MessageTemplates = {
        init() {
            this.bindEvents();
        },

        bindEvents() {
            // Visualizar preview de template
            window.viewTemplatePreview = this.viewTemplatePreview.bind(this);

            // Deletar template customizado
            window.deleteTemplate = this.deleteTemplate.bind(this);

            // Preview ao mudar tipo de template
            $('#test_template_type').on('change', this.updateTestPreview.bind(this));
        },

        viewTemplatePreview(templateId) {
            // Modal com preview
            const modal = $('<div class="limpvix-modal-overlay"></div>');
            const content = $(`
                <div class="limpvix-modal">
                    <div class="limpvix-modal-header">
                        <h2>Preview: Template ${templateId}</h2>
                        <button class="limpvix-modal-close">&times;</button>
                    </div>
                    <div class="limpvix-modal-body">
                        <div class="template-preview">
                            <div class="preview-phone">
                                <div class="preview-message">
                                    ${this.getTemplatePreview(templateId)}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `);

            modal.append(content);
            $('body').append(modal);

            // Close handlers
            modal.on('click', function(e) {
                if (e.target === this) {
                    modal.remove();
                }
            });

            content.find('.limpvix-modal-close').on('click', function() {
                modal.remove();
            });
        },

        getTemplatePreview(templateId) {
            const previews = {
                'client_feedback_d1': `
                    Olá João! 👋<br><br>
                    O serviço realizado ontem atendeu suas expectativas?<br><br>
                    Sua avaliação ajuda a Limpvix a manter a qualidade ⭐<br>
                    Leva menos de 1 minuto:<br><br>
                    👉 Avaliar agora: <a href="#">link</a><br><br>
                    Equipe Limpvix
                `,
                'client_feedback_d3': `
                    Oi João!<br><br>
                    Ainda não vimos sua avaliação do serviço 😊<br>
                    Se puder, sua opinião é muito importante:<br><br>
                    👉 Avaliar: <a href="#">link</a><br><br>
                    Obrigado!
                `,
                'client_feedback_d6': `
                    João, esta é a última mensagem 😊<br>
                    Avalie o serviço da Limpvix em 1 minuto:<br><br>
                    <a href="#">link</a>
                `,
                'google_review': `
                    Obrigado pela sua avaliação, João! 🌟<br><br>
                    Se puder, compartilhe sua experiência no Google — isso ajuda muito a Limpvix 💙<br><br>
                    👉 Avaliar no Google: <a href="#">link</a><br><br>
                    Muito obrigado!
                `,
                'staff_completed': `
                    Olá Maria 👋<br><br>
                    O serviço Limpeza Residencial foi concluído com sucesso.<br><br>
                    Status financeiro atual:<br>
                    ⏳ Aguardando avaliação do cliente<br><br>
                    Você será avisado assim que houver atualização.
                `,
                'staff_authorized': `
                    Boa notícia, Maria! ✅<br><br>
                    O pagamento do serviço Limpeza Residencial foi AUTORIZADO.<br><br>
                    💰 Valor: R$ 150,00<br>
                    📆 Repasse previsto conforme cronograma.<br><br>
                    Equipe Limpvix
                `,
                'staff_review': `
                    Olá Maria.<br><br>
                    O pagamento do serviço Limpeza Residencial está em análise.<br><br>
                    Nossa equipe está avaliando o ocorrido e poderá entrar em contato se necessário.<br><br>
                    Equipe Limpvix
                `
            };

            return previews[templateId] || 'Preview não disponível';
        },

        deleteTemplate(templateId) {
            if (!confirm('Tem certeza que deseja deletar este template?')) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'limpvix_delete_template',
                    template_id: templateId,
                    nonce: limpvixAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Erro ao deletar template: ' + response.data.message);
                    }
                }
            });
        },

        updateTestPreview() {
            const templateType = $('#test_template_type').val();
            if (!templateType) {
                $('#template-preview').hide();
                return;
            }

            const preview = this.getTemplatePreview(templateType);
            $('#preview-content').html(preview);
            $('#template-preview').show();
        }
    };

    // Inicializar
    $(document).ready(function() {
        MessageTemplates.init();
    });

})(jQuery);
