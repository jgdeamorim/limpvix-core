<?php
/**
 * FeatureFlags - Controle de Features via wp_options
 *
 * RESPONSABILIDADE:
 * - Gerenciar flags de ativação de features
 * - Persistir via wp_options
 * - Cache em memória durante request
 * - Defaults seguros (tudo desabilitado)
 *
 * PRINCÍPIO:
 * - NUNCA assumir que algo está habilitado
 * - Default = false (seguro)
 * - Explícito > Implícito
 * - Cache para performance
 *
 * FLAGS PRINCIPAIS:
 * - core_enabled: Master switch (desliga TUDO)
 * - intercept_appointment_creation: Intercepta criação
 * - create_service_order: Cria OS
 * - validate_appointments: Valida antes de criar
 * - audit_logging: Habilita auditoria
 *
 * @package LimpVix\Core
 */

namespace LimpVix\Core;

defined('ABSPATH') || exit;

class FeatureFlags
{
    /**
     * Option name no wp_options
     *
     * @var string
     */
    private const OPTION_NAME = 'limpvix_feature_flags';

    /**
     * Cache em memória das flags
     *
     * @var array|null
     */
    private $cache = null;

    /**
     * Defaults de todas as flags
     *
     * ⚠️ IMPORTANTE: Default = FALSE (seguro)
     * Cada flag deve ser EXPLICITAMENTE habilitada
     *
     * @var array
     */
    private $defaults = [
        // Master switch - desliga TUDO se false
        'core_enabled' => false,

        // PASSO 3: Primeira interceptação real
        'intercept_booking' => false,

        // PASSO 4: Persistência controlada
        'persist_orders' => false,
        
        // PASSO 5: Sistema Financeiro
        'financial_workflow' => false,
        'payout_engine' => false,
        'admin_interface' => false,

        // Interceptação de lifecycle (futuro)
        'intercept_appointment_creation' => false,
        'intercept_appointment_update' => false,
        'intercept_status_change' => false,
        'prevent_appointment_deletion' => false,

        // Criação de entidades
        'create_service_order' => false,
        'sync_order_updates' => false,
        'sync_status_change' => false,

        // Validações
        'validate_appointments' => false,
        'validate_booking_data' => false,

        // Filtros de frontend
        'filter_services' => false,
        'filter_staff' => false,
        'filter_timeslots' => false,

        // Preços
        'calculate_custom_price' => false,

        // Auditoria
        'audit_logging' => false,

        // Notificações
        'notifications' => false,

        // Integrações externas
        'external_integrations' => false,

        // API REST
        'rest_api' => false,

        // UI Admin
        'admin_ui' => false,
    ];

    /**
     * Verifica se uma feature está habilitada
     *
     * @param string $flag Nome da flag
     * @return bool
     */
    public function isEnabled(string $flag): bool
    {
        // Carregar cache se necessário
        if ($this->cache === null) {
            $this->loadCache();
        }

        // Se não existe, usar default
        if (!isset($this->cache[$flag])) {
            return $this->defaults[$flag] ?? false;
        }

        // Retornar valor cacheado
        return (bool) $this->cache[$flag];
    }

    /**
     * Habilita uma feature
     *
     * @param string $flag Nome da flag
     * @return bool Sucesso
     */
    public function enable(string $flag): bool
    {
        return $this->set($flag, true);
    }

    /**
     * Desabilita uma feature
     *
     * @param string $flag Nome da flag
     * @return bool Sucesso
     */
    public function disable(string $flag): bool
    {
        return $this->set($flag, false);
    }

    /**
     * Define valor de uma flag
     *
     * @param string $flag Nome da flag
     * @param bool $value Valor
     * @return bool Sucesso
     */
    public function set(string $flag, bool $value): bool
    {
        // Carregar cache se necessário
        if ($this->cache === null) {
            $this->loadCache();
        }

        // Atualizar cache
        $this->cache[$flag] = $value;

        // Persistir no banco
        return update_option(self::OPTION_NAME, $this->cache, false);
    }

    /**
     * Obtém todas as flags
     *
     * @return array
     */
    public function getAll(): array
    {
        if ($this->cache === null) {
            $this->loadCache();
        }

        return $this->cache;
    }

    /**
     * Reseta todas as flags para defaults
     *
     * @return bool Sucesso
     */
    public function reset(): bool
    {
        $this->cache = $this->defaults;
        return update_option(self::OPTION_NAME, $this->defaults, false);
    }

    /**
     * Obtém valor de uma opção específica de uma flag
     *
     * Exemplo: getOption('headless_mode', 'cors_origins')
     *
     * @param string $flag Nome da flag
     * @param string $option Nome da opção
     * @param mixed $default Valor default
     * @return mixed
     */
    public function getOption(string $flag, string $option, $default = null)
    {
        $flagValue = $this->get($flag);

        if (!is_array($flagValue)) {
            return $default;
        }

        return $flagValue[$option] ?? $default;
    }

    /**
     * Obtém valor completo de uma flag (pode ser array)
     *
     * @param string $flag Nome da flag
     * @return mixed
     */
    public function get(string $flag)
    {
        if ($this->cache === null) {
            $this->loadCache();
        }

        return $this->cache[$flag] ?? ($this->defaults[$flag] ?? null);
    }

    /**
     * Carrega flags do banco para cache
     *
     * @return void
     */
    private function loadCache(): void
    {
        // Tentar carregar do banco
        $saved = get_option(self::OPTION_NAME, false);

        if ($saved === false) {
            // Primeira vez - usar defaults e salvar
            $this->cache = $this->defaults;
            update_option(self::OPTION_NAME, $this->defaults, false);
        } else {
            // Merge com defaults (caso novas flags tenham sido adicionadas)
            $this->cache = array_merge($this->defaults, $saved);
        }
    }

    /**
     * Invalida cache (força reload)
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = null;
        delete_transient('limpvix_feature_flags');
    }

    /**
     * Retorna lista de flags disponíveis com descrições
     *
     * @return array
     */
    public function getAvailableFlags(): array
    {
        return [
            'core_enabled' => [
                'name' => 'Core Habilitado',
                'description' => 'Master switch - desabilita TUDO se false',
                'default' => false,
                'category' => 'core'
            ],
            'intercept_booking' => [
                'name' => 'Interceptar Booking (PASSO 3)',
                'description' => 'Primeira interceptação real: loga payload e cria Order em memória (NÃO persiste)',
                'default' => false,
                'category' => 'interception',
                'step' => 3
            ],
            'persist_orders' => [
                'name' => 'Persistir Orders (PASSO 4)',
                'description' => 'Persistência controlada da OS no banco (wp_limpvix_orders)',
                'default' => false,
                'category' => 'persistence',
                'step' => 4
            ],
            'intercept_appointment_creation' => [
                'name' => 'Interceptar Criação de Appointments',
                'description' => 'Valida appointments antes de criar no Booknetic (futuro)',
                'default' => false,
                'category' => 'interception'
            ],
            'create_service_order' => [
                'name' => 'Criar Ordem de Serviço',
                'description' => 'Cria OS no LimpVix após appointment',
                'default' => false,
                'category' => 'entities'
            ],
            'validate_appointments' => [
                'name' => 'Validar Appointments',
                'description' => 'Aplica validações customizadas',
                'default' => false,
                'category' => 'validation'
            ],
            'audit_logging' => [
                'name' => 'Auditoria',
                'description' => 'Registra todas as ações em log',
                'default' => false,
                'category' => 'audit'
            ],
            'filter_timeslots' => [
                'name' => 'Filtrar Horários',
                'description' => 'Aplica SLA e regras de negócio aos horários',
                'default' => false,
                'category' => 'frontend'
            ],
            'calculate_custom_price' => [
                'name' => 'Calcular Preço Customizado',
                'description' => 'Aplica cálculo de preço LimpVix',
                'default' => false,
                'category' => 'pricing'
            ],
            // ... outras flags conforme necessário
        ];
    }
}
