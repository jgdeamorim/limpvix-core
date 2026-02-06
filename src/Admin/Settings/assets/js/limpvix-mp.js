/**
 * LimpVix Mercado Pago Settings - JavaScript Interativo
 * Gerencia modais, toggles de ambiente e visibilidade de credenciais
 */

(function($) {
    'use strict';

    const LimpVixMP = {
        
        /**
         * Inicializa todos os componentes
         */
        init: function() {
            this.initEnvironmentToggle();
            this.initCredentialToggle();
            this.initModals();
            this.initNotifications();
            this.initSyncStatus();
            console.log('LimpVix MP: Initialized');
        },

        /**
         * Toggle de ambiente (Test/Production)
         */
        initEnvironmentToggle: function() {
            $('.limpvix-mp-env-btn').on('click', function() {
                const $btn = $(this);
                const environment = $btn.data('env');
                
                if ($btn.hasClass('active')) {
                    return;
                }

                // Visual feedback
                $('.limpvix-mp-env-btn').removeClass('active');
                $btn.addClass('active');

                // Loading state
                $btn.append(' <span class="limpvix-mp-loading"></span>');

                // AJAX request
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'limpvix_mp_toggle_environment',
                        environment: environment,
                        nonce: limpvixMPData.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            LimpVixMP.showNotification('Ambiente alterado com sucesso!', 'success');
                            
                            // Atualiza credenciais visíveis
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            LimpVixMP.showNotification('Erro ao alterar ambiente.', 'error');
                        }
                    },
                    error: function() {
                        LimpVixMP.showNotification('Erro na comunicação com o servidor.', 'error');
                    },
                    complete: function() {
                        $btn.find('.limpvix-mp-loading').remove();
                    }
                });
            });
        },

        /**
         * Toggle de visibilidade de credenciais
         */
        initCredentialToggle: function() {
            $('.limpvix-mp-toggle-visibility').on('click', function() {
                const $btn = $(this);
                const $input = $btn.siblings('input');
                const $icon = $btn.find('.dashicons');

                if ($input.attr('type') === 'password') {
                    $input.attr('type', 'text');
                    $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                    $btn.attr('title', 'Ocultar');
                } else {
                    $input.attr('type', 'password');
                    $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                    $btn.attr('title', 'Mostrar');
                }
            });

            // Copiar para clipboard
            $('.limpvix-mp-cred-value input').on('click', function() {
                if ($(this).attr('type') === 'text') {
                    $(this).select();
                    
                    try {
                        document.execCommand('copy');
                        LimpVixMP.showNotification('Copiado para área de transferência!', 'success', 2000);
                    } catch (err) {
                        console.error('Erro ao copiar:', err);
                    }
                }
            });
        },

        /**
         * Gerenciamento de modais
         */
        initModals: function() {
            // Abre modal
            $('[data-modal]').on('click', function(e) {
                e.preventDefault();
                const modalId = $(this).data('modal');
                $('#' + modalId).fadeIn(300);
                $('body').css('overflow', 'hidden');
            });

            // Fecha modal
            $('.limpvix-mp-modal-close, .limpvix-mp-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    $(this).closest('.limpvix-mp-modal-overlay').fadeOut(300);
                    $('body').css('overflow', '');
                }
            });

            // Fecha com ESC
            $(document).on('keyup', function(e) {
                if (e.key === 'Escape') {
                    $('.limpvix-mp-modal-overlay').fadeOut(300);
                    $('body').css('overflow', '');
                }
            });
        },

        /**
         * Sistema de notificações toast
         */
        showNotification: function(message, type = 'info', duration = 4000) {
            const icons = {
                success: 'dashicons-yes-alt',
                error: 'dashicons-warning',
                warning: 'dashicons-warning',
                info: 'dashicons-info'
            };

            const $toast = $(`
                <div class="limpvix-mp-toast limpvix-mp-toast-${type}">
                    <span class="dashicons ${icons[type]}"></span>
                    <span>${message}</span>
                </div>
            `);

            // Adiciona CSS se não existir
            if (!$('#limpvix-mp-toast-styles').length) {
                $('head').append(`
                    <style id="limpvix-mp-toast-styles">
                        .limpvix-mp-toast {
                            position: fixed;
                            bottom: 30px;
                            right: 30px;
                            padding: 16px 24px;
                            border-radius: 8px;
                            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                            display: flex;
                            align-items: center;
                            gap: 12px;
                            font-weight: 600;
                            z-index: 999999;
                            animation: slideInRight 0.3s ease-out;
                        }
                        .limpvix-mp-toast-success {
                            background: #00a650;
                            color: white;
                        }
                        .limpvix-mp-toast-error {
                            background: #f23d4f;
                            color: white;
                        }
                        .limpvix-mp-toast-warning {
                            background: #ff9800;
                            color: white;
                        }
                        .limpvix-mp-toast-info {
                            background: #3483fa;
                            color: white;
                        }
                        @keyframes slideInRight {
                            from {
                                opacity: 0;
                                transform: translateX(100px);
                            }
                            to {
                                opacity: 1;
                                transform: translateX(0);
                            }
                        }
                    </style>
                `);
            }

            $('body').append($toast);

            setTimeout(function() {
                $toast.fadeOut(300, function() {
                    $(this).remove();
                });
            }, duration);
        },

        /**
         * Auto-dismiss de notificações
         */
        initNotifications: function() {
            $('.limpvix-mp-notice.is-dismissible').each(function() {
                const $notice = $(this);
                
                // Adiciona botão de fechar
                if (!$notice.find('.notice-dismiss').length) {
                    $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>');
                }

                // Handler de click
                $notice.find('.notice-dismiss').on('click', function() {
                    $notice.fadeOut(300, function() {
                        $(this).remove();
                    });
                });
            });
        },

        /**
         * Status de sincronização em tempo real
         */
        initSyncStatus: function() {
            const $syncStatus = $('.limpvix-mp-sync-status');
            
            if ($syncStatus.length) {
                setInterval(function() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'limpvix_mp_check_sync',
                            nonce: limpvixMPData.nonce
                        },
                        success: function(response) {
                            if (response.success && response.data) {
                                const lastSync = response.data.last_sync;
                                const timeAgo = LimpVixMP.timeAgo(lastSync);
                                
                                $syncStatus.find('.limpvix-mp-sync-time').text(timeAgo);
                            }
                        }
                    });
                }, 60000); // Atualiza a cada minuto
            }
        },

        /**
         * Formata tempo relativo
         */
        timeAgo: function(timestamp) {
            const now = Math.floor(Date.now() / 1000);
            const diff = now - timestamp;

            if (diff < 60) {
                return 'há menos de 1 minuto';
            } else if (diff < 3600) {
                const minutes = Math.floor(diff / 60);
                return `há ${minutes} minuto${minutes > 1 ? 's' : ''}`;
            } else if (diff < 86400) {
                const hours = Math.floor(diff / 3600);
                return `há ${hours} hora${hours > 1 ? 's' : ''}`;
            } else {
                const days = Math.floor(diff / 86400);
                return `há ${days} dia${days > 1 ? 's' : ''}`;
            }
        },

        /**
         * Teste de conexão com MP
         */
        testConnection: function() {
            const $btn = $('.limpvix-mp-test-connection');
            
            if (!$btn.length) return;

            $btn.on('click', function(e) {
                e.preventDefault();
                
                const $this = $(this);
                const originalText = $this.html();
                
                $this.prop('disabled', true).html('<span class="limpvix-mp-loading"></span> Testando...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'limpvix_mp_test_connection',
                        nonce: limpvixMPData.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            LimpVixMP.showNotification('Conexão testada com sucesso!', 'success');
                        } else {
                            LimpVixMP.showNotification('Erro ao testar conexão: ' + response.data.message, 'error');
                        }
                    },
                    error: function() {
                        LimpVixMP.showNotification('Erro na comunicação com o servidor.', 'error');
                    },
                    complete: function() {
                        $this.prop('disabled', false).html(originalText);
                    }
                });
            });
        },

        /**
         * Sincronização manual
         */
        manualSync: function() {
            const $btn = $('.limpvix-mp-manual-sync');
            
            if (!$btn.length) return;

            $btn.on('click', function(e) {
                e.preventDefault();
                
                const $this = $(this);
                const originalText = $this.html();
                
                $this.prop('disabled', true).html('<span class="limpvix-mp-loading"></span> Sincronizando...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'limpvix_mp_manual_sync',
                        nonce: limpvixMPData.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            LimpVixMP.showNotification('Credenciais sincronizadas com sucesso!', 'success');
                            
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            LimpVixMP.showNotification('Erro ao sincronizar: ' + response.data.message, 'error');
                        }
                    },
                    error: function() {
                        LimpVixMP.showNotification('Erro na comunicação com o servidor.', 'error');
                    },
                    complete: function() {
                        $this.prop('disabled', false).html(originalText);
                    }
                });
            });
        }
    };

    // Inicializa quando o documento estiver pronto
    $(document).ready(function() {
        LimpVixMP.init();
        LimpVixMP.testConnection();
        LimpVixMP.manualSync();
    });

    // Expõe globalmente para uso externo
    window.LimpVixMP = LimpVixMP;

})(jQuery);
