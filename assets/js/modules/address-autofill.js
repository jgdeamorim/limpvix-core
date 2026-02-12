/**
 * AddressAutofill Module
 * 
 * Módulo modular para autocompletar endereço via CEP
 * Arquitetura escalável com debounce, cache e loading state
 * 
 * @package LimpVix
 * @since 1.0.0
 */

(function(window, document) {
    'use strict';

    /**
     * CepService - Serviço de consulta CEP
     */
    const CepService = {
        cache: new Map(),

        /**
         * Buscar CEP via endpoint interno
         */
        async fetch(cep) {
            const sanitized = this.sanitize(cep);

            if (!this.validate(sanitized)) {
                throw new Error('CEP inválido');
            }

            // Verificar cache
            if (this.cache.has(sanitized)) {
                return { ...this.cache.get(sanitized), cached: true };
            }

            // Buscar via REST API
            const response = await fetch(, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                },
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Erro ao buscar CEP');
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'CEP não encontrado');
            }

            // Cachear resultado
            this.cache.set(sanitized, result.data);

            return result.data;
        },

        /**
         * Sanitizar CEP (apenas números)
         */
        sanitize(cep) {
            return cep.replace(/\D/g, '');
        },

        /**
         * Validar CEP (8 dígitos)
         */
        validate(cep) {
            return /^[0-9]{8}$/.test(cep);
        },
    };

    /**
     * AddressMapper - Mapear dados do CEP para campos do formulário
     */
    const AddressMapper = {
        /**
         * Preencher campos do formulário
         */
        fill(data, options = {}) {
            const {
                streetSelector = '#street',
                neighborhoodSelector = '#neighborhood',
                citySelector = '#city',
                stateSelector = '#state',
                overwrite = false,
            } = options;

            const fields = {
                [streetSelector]: data.logradouro,
                [neighborhoodSelector]: data.bairro,
                [citySelector]: data.localidade,
                [stateSelector]: data.uf,
            };

            Object.entries(fields).forEach(([selector, value]) => {
                const field = document.querySelector(selector);
                if (!field) return;

                // Não sobrescrever se já tem valor e overwrite = false
                if (!overwrite && field.value.trim() !== '') return;

                field.value = value || '';
                
                // Disparar evento change para compatibilidade com frameworks
                field.dispatchEvent(new Event('change', { bubbles: true }));
            });
        },
    };

    /**
     * Debounce helper
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * AddressAutofill - Controller principal
     */
    class AddressAutofill {
        constructor(cepSelector, options = {}) {
            this.cepField = document.querySelector(cepSelector);
            if (!this.cepField) {
                console.warn('[AddressAutofill] Campo CEP não encontrado:', cepSelector);
                return;
            }

            this.options = {
                debounceMs: 500,
                showLoading: true,
                loadingText: 'Buscando CEP...',
                errorDuration: 3000,
                overwriteFields: false,
                ...options,
            };

            this.init();
        }

        init() {
            // Criar elementos UI
            this.createLoadingIndicator();
            this.createErrorMessage();

            // Bind events com debounce
            const debouncedLookup = debounce(this.lookup.bind(this), this.options.debounceMs);
            
            this.cepField.addEventListener('blur', debouncedLookup);
            this.cepField.addEventListener('input', () => {
                this.clearError();
                this.formatCep();
            });
        }

        /**
         * Criar indicador de loading
         */
        createLoadingIndicator() {
            this.loadingEl = document.createElement('span');
            this.loadingEl.className = 'limpvix-cep-loading';
            this.loadingEl.style.cssText = 'display:none;margin-left:10px;color:#2271b1;font-size:12px;';
            this.loadingEl.textContent = this.options.loadingText;
            this.cepField.parentNode.appendChild(this.loadingEl);
        }

        /**
         * Criar mensagem de erro
         */
        createErrorMessage() {
            this.errorEl = document.createElement('p');
            this.errorEl.className = 'limpvix-cep-error';
            this.errorEl.style.cssText = 'display:none;color:#d63638;font-size:12px;margin:5px 0 0 0;';
            this.cepField.parentNode.appendChild(this.errorEl);
        }

        /**
         * Formatar CEP (12345-678)
         */
        formatCep() {
            let value = this.cepField.value.replace(/\D/g, '');
            if (value.length > 5) {
                value = value.substring(0, 5) + '-' + value.substring(5, 8);
            }
            this.cepField.value = value;
        }

        /**
         * Mostrar loading
         */
        showLoading() {
            if (this.options.showLoading) {
                this.loadingEl.style.display = 'inline';
            }
            this.cepField.disabled = true;
        }

        /**
         * Esconder loading
         */
        hideLoading() {
            this.loadingEl.style.display = 'none';
            this.cepField.disabled = false;
        }

        /**
         * Mostrar erro
         */
        showError(message) {
            this.errorEl.textContent = message;
            this.errorEl.style.display = 'block';

            setTimeout(() => {
                this.clearError();
            }, this.options.errorDuration);
        }

        /**
         * Limpar erro
         */
        clearError() {
            this.errorEl.style.display = 'none';
        }

        /**
         * Buscar CEP
         */
        async lookup() {
            const cep = this.cepField.value;

            if (!cep || cep.length < 8) return;

            this.showLoading();
            this.clearError();

            try {
                const data = await CepService.fetch(cep);
                
                AddressMapper.fill(data, {
                    overwrite: this.options.overwriteFields,
                    ...this.options,
                });

                // Disparar evento customizado
                this.cepField.dispatchEvent(new CustomEvent('limpvix:cep:success', {
                    detail: { data },
                }));

            } catch (error) {
                this.showError(error.message);

                this.cepField.dispatchEvent(new CustomEvent('limpvix:cep:error', {
                    detail: { error: error.message },
                }));

            } finally {
                this.hideLoading();
            }
        }
    }

    // Exportar para uso global
    window.LimpVix = window.LimpVix || {};
    window.LimpVix.AddressAutofill = AddressAutofill;
    window.LimpVix.CepService = CepService;
    window.LimpVix.AddressMapper = AddressMapper;

})(window, document);
