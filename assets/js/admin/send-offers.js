jQuery(document).ready(function($) {
    // Handler para botão "Enviar Offers"
    $(document).on('click', '.limpvix-send-offers', function(e) {
        e.preventDefault();

        var $button = $(this);
        var contractId = $button.data('contract-id');
        var contractNumber = $button.data('contract-number');

        if (confirm('Enviar offers para os profissionais mais qualificados para o contrato ' + contractNumber + '?')) {
            // Disable button
            $button.prop('disabled', true).text('Enviando...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'limpvix_send_contract_offers',
                    nonce: limpvixContracts.sendOffersNonce,
                    contract_id: contractId
                },
                success: function(response) {
                    if (response.success) {
                        // Success feedback
                        $button.removeClass('button-primary')
                               .addClass('button-secondary')
                               .text('✓ ' + response.data.message)
                               .prop('disabled', true);

                        // Show toast notification
                        if (response.data.offers_count > 0) {
                            showNotification('success',
                                response.data.offers_count + ' offers enviados para ' +
                                response.data.professionals_count + ' profissionais!'
                            );
                        }
                    } else {
                        // Error feedback
                        $button.removeClass('button-primary')
                               .addClass('button-link-delete')
                               .text('✗ Erro')
                               .prop('disabled', false);

                        showNotification('error', response.data.message || 'Erro ao enviar offers');
                    }
                },
                error: function(xhr, status, error) {
                    $button.removeClass('button-primary')
                           .addClass('button-link-delete')
                           .text('✗ Erro')
                           .prop('disabled', false);

                    showNotification('error', 'Erro de conexão: ' + error);
                }
            });
        }
    });

    // Show notification toast
    function showNotification(type, message) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap').prepend($notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeTo(500, 0, function() {
                $notice.slideUp(500, function() {
                    $notice.remove();
                });
            });
        }, 5000);
    }
});
