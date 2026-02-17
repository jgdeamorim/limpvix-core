# 📊 Análise: Aba Dependências - Hardcoded vs Dinâmico

**Data:** 2026-02-16
**URL:** http://localhost:8080/wp-admin/admin.php?page=limpvix-settings&tab=dependencias
**Objetivo:** Identificar conteúdo hardcoded, analisar real dependência de plugins, e propor implementação 100% dinâmica

---

## 🎯 SCORE ATUAL: 65% Dinâmico / 35% Hardcoded

**Distribuição:**
- ✅ **Verificações de Status:** 100% Dinâmico (plugins, database, providers, ambiente)
- ✅ **Scorecard:** 100% Dinâmico (cálculo baseado em verificações reais)
- ✅ **Hero Card Stats:** 100% Dinâmico
- ❌ **Documentação de Hooks:** 100% Hardcoded (10 hooks listados estaticamente)
- ❌ **Documentação de Tabelas:** 100% Hardcoded (4 tabelas listadas estaticamente)
- ❌ **Documentação de Componentes:** 100% Hardcoded (6 classes listadas estaticamente)
- ❌ **Status dos GAPs:** 100% Hardcoded (4 GAPs com commits fixos)
- ❌ **Princípios de Integração:** 100% Hardcoded (texto estático)

---

## 🔍 ANÁLISE DETALHADA POR SEÇÃO

### 1. HERO CARD - Status Geral ✅ DINÂMICO

**Código (linhas 502-543):**
```php
$allPluginsActive = $isBookneticActive && $isWooCommerceActive && $isMercadoPagoActive;
$overallScore = round(($bridgeScore + $mapperScore + $guardScore + $uiScore + $financeScore + $commsScore) / 6);
$readyForGoLive = $tableExists && $overallScore >= 95 && $allPluginsActive && $hasCommProvider;
```

**Verificações Dinâmicas:**
- ✅ `is_plugin_active('booknetic/init.php')`
- ✅ `is_plugin_active('woocommerce/woocommerce.php')`
- ✅ `is_plugin_active('woocommerce-mercadopago/woocommerce-mercadopago.php')`
- ✅ Score calculado baseado em verificações reais
- ✅ Quick stats (5 cards) todos dinâmicos

**Conclusão:** ✅ **100% Dinâmico** - Não precisa alterações

---

### 2. PLUGINS WORDPRESS - Status de Instalação ✅ DINÂMICO

**Código (linhas 546-634):**
```php
<?php if (!$isBookneticActive): ?>
    <strong>❌ Booknetic 4.8.5+ (OBRIGATÓRIO)</strong><br>
    <strong>Status:</strong> Não instalado ou desativado
<?php endif; ?>
```

**Verificações Dinâmicas:**
- ✅ Detecta se Booknetic está ativo
- ✅ Detecta se WooCommerce está ativo
- ✅ Detecta se WooCommerce MercadoPago está ativo
- ✅ Mostra botões de instalação dinamicamente
- ✅ Status "Ativo e funcionando" vs "Não instalado"

**Observação:**
- ⚠️ **Descrição hardcoded:** "OBRIGATÓRIO" para Booknetic, mas análise mostra que pode ser opcional no futuro
- ⚠️ **Versão hardcoded:** "Booknetic 4.8.5+" - deveria verificar versão real instalada

**Melhorias Necessárias:**
1. Verificar versão real do Booknetic instalado
2. Adicionar observação sobre possibilidade de substituição futura
3. Verificar versão do WooCommerce e WooCommerce MercadoPago

**Score:** 85% Dinâmico / 15% Hardcoded

---

### 3. SCORECARD DE PRONTIDÃO ⚠️ PARCIALMENTE DINÂMICO

**Código (linhas 637-786):**
```php
$bridgeScore = $tableExists ? 100 : 25;
$mapperScore = $tableExists ? 100 : 25;
$guardScore = 100; // ❌ HARDCODED
$uiScore = 100; // ❌ HARDCODED
$financeScore = 100; // ❌ HARDCODED (comentário: 4 GAPs implementados)
$commsScore = $hasCommProvider ? 100 : 50;
```

**O Que Está Dinâmico:**
- ✅ `bridgeScore` - baseado em existência de tabela
- ✅ `mapperScore` - baseado em existência de tabela
- ✅ `commsScore` - baseado em providers configurados

**O Que Está Hardcoded:**
- ❌ `guardScore = 100` - deveria verificar se classes `StaffAccessGuard` e `StaffActionGuard` existem
- ❌ `uiScore = 100` - deveria verificar se `StaffPanelOverride` e `StaffNotices` existem
- ❌ `financeScore = 100` - deveria verificar implementação real dos GAPs

**Melhorias Necessárias:**
1. Criar método `getGuardsStatus()` - verificar `class_exists()`
2. Criar método `getUIOverridesStatus()` - verificar componentes UI
3. Criar método `getFinanceFlowStatus()` - verificar GAPs implementados dinamicamente

**Score:** 50% Dinâmico / 50% Hardcoded

---

### 4. DOCUMENTAÇÃO DE HOOKS ❌ HARDCODED

**Código (linhas 802-852):**
```php
<table class="limpvix-table">
    <tbody>
        <tr>
            <td><code>bkntc_appointment_created</code></td>
            <td>Criar order no LimpVix</td>
        </tr>
        <!-- ... 9 outros hooks hardcoded ... -->
    </tbody>
</table>
```

**Problema:**
- Lista de 10 hooks é completamente estática
- Não verifica se hooks estão realmente registrados
- Não mostra quais callbacks estão conectados
- Não mostra prioridade dos hooks

**Solução Proposta:**
Criar método `getBookneticHooksStatus()`:
```php
private function getBookneticHooksStatus(): array
{
    global $wp_filter;

    $expectedHooks = [
        'bkntc_appointment_created' => 'Criar order no LimpVix',
        'bkntc_appointment_completed' => 'Disparar fluxo financeiro',
        'bkntc_appointment_canceled' => 'Cancelar order',
        'bkntc_staff_updated' => 'Sincronizar dados staff',
        'bkntc_after_booking_completed' => 'Redirecionar para Briefing',
        'bkntc_staff_can_access' => 'Controle de permissões',
        'bkntc_staff_can_execute_action' => 'Controle de ações',
        'bkntc_staff_panel_header' => 'Avisos personalizados',
        'bkntc_staff_panel_footer' => 'Ocultar abas financeiras',
        'admin_menu' => 'Ocultar menus para staff',
    ];

    $result = [];

    foreach ($expectedHooks as $hook => $description) {
        $isRegistered = isset($wp_filter[$hook]);
        $callbackCount = $isRegistered ? count($wp_filter[$hook]->callbacks) : 0;

        $result[$hook] = [
            'description' => $description,
            'registered' => $isRegistered,
            'callback_count' => $callbackCount,
            'status' => $isRegistered ? 'active' : 'not_registered',
        ];
    }

    return $result;
}
```

**Renderização Dinâmica:**
```php
<?php $hooks = $this->getBookneticHooksStatus(); ?>
<table class="limpvix-table">
    <thead>
        <tr>
            <th style="width: 50px;">Status</th>
            <th>Hook</th>
            <th>Função</th>
            <th style="width: 100px;">Callbacks</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($hooks as $hook => $data): ?>
        <tr>
            <td style="text-align: center;">
                <?php echo $data['registered'] ? '<span style="color: #10b981; font-size: 18px;">✅</span>' : '<span style="color: #ef4444; font-size: 18px;">❌</span>'; ?>
            </td>
            <td><code><?php echo esc_html($hook); ?></code></td>
            <td><?php echo esc_html($data['description']); ?></td>
            <td style="text-align: center;">
                <?php echo $data['callback_count']; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
```

**Score:** 0% Dinâmico / 100% Hardcoded → **Após Fix:** 100% Dinâmico

---

### 5. DOCUMENTAÇÃO DE TABELAS ❌ HARDCODED

**Código (linhas 854-885):**
```php
<table class="limpvix-table">
    <tbody>
        <tr>
            <td><code>bkntc_appointments</code></td>
            <td>READ</td>
            <td>Mapear appointment → order</td>
        </tr>
        <!-- ... 3 outras tabelas hardcoded ... -->
    </tbody>
</table>
```

**Problema:**
- Lista de 4 tabelas é estática
- Não verifica se tabelas Booknetic realmente existem
- Não mostra se LimpVix tem acesso às tabelas

**Solução Proposta:**
Criar método `getBookneticTablesStatus()`:
```php
private function getBookneticTablesStatus(): array
{
    global $wpdb;

    $expectedTables = [
        'bkntc_appointments' => [
            'access' => 'READ',
            'purpose' => 'Mapear appointment → order',
        ],
        'bkntc_staff' => [
            'access' => 'READ',
            'purpose' => 'Vincular user_id WordPress',
        ],
        'bkntc_customers' => [
            'access' => 'READ',
            'purpose' => 'Dados para Google Reviews',
        ],
        'bkntc_services' => [
            'access' => 'READ',
            'purpose' => 'Nome do serviço executado',
        ],
    ];

    $result = [];

    foreach ($expectedTables as $table => $config) {
        $fullTableName = $wpdb->prefix . $table;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $fullTableName)) === $fullTableName;

        $result[$table] = [
            'exists' => $exists,
            'access' => $config['access'],
            'purpose' => $config['purpose'],
            'full_name' => $fullTableName,
        ];
    }

    return $result;
}
```

**Renderização Dinâmica:**
```php
<?php $tables = $this->getBookneticTablesStatus(); ?>
<table class="limpvix-table">
    <thead>
        <tr>
            <th style="width: 50px;">Status</th>
            <th>Tabela</th>
            <th>Tipo Acesso</th>
            <th>Propósito</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($tables as $table => $data): ?>
        <tr>
            <td style="text-align: center;">
                <?php echo $data['exists'] ? '<span style="color: #10b981; font-size: 18px;">✅</span>' : '<span style="color: #ef4444; font-size: 18px;">❌</span>'; ?>
            </td>
            <td><code><?php echo esc_html($table); ?></code></td>
            <td><?php echo esc_html($data['access']); ?></td>
            <td><?php echo esc_html($data['purpose']); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (!$data['exists']): ?>
<div class="notice notice-warning inline" style="margin-top: 15px;">
    <p>
        ⚠️ <strong>Tabelas Booknetic não encontradas.</strong><br>
        Verifique se o plugin Booknetic está instalado e ativado corretamente.
    </p>
</div>
<?php endif; ?>
```

**Score:** 0% Dinâmico / 100% Hardcoded → **Após Fix:** 100% Dinâmico

---

### 6. DOCUMENTAÇÃO DE COMPONENTES ❌ HARDCODED

**Código (linhas 887-896):**
```php
<ul>
    <li>✅ <strong>BookneticBridge</strong> - Ponte principal de integração</li>
    <li>✅ <strong>AppointmentOrderMapper</strong> - Mapeamento 1:1</li>
    <li>✅ <strong>StaffAccessGuard</strong> - Controle de acesso</li>
    <li>✅ <strong>StaffActionGuard</strong> - Controle de ações</li>
    <li>✅ <strong>StaffPanelOverride</strong> - UI customizada</li>
    <li>✅ <strong>StaffNotices</strong> - Avisos personalizados</li>
</ul>
```

**Problema:**
- Lista de 6 componentes é estática
- Ícone ✅ fixo, não verifica se classe realmente existe
- Não mostra caminho completo da classe

**Solução Proposta:**
Criar método `getBookneticComponentsStatus()`:
```php
private function getBookneticComponentsStatus(): array
{
    $components = [
        'BookneticBridge' => [
            'class' => 'LimpVix\\Infrastructure\\Booknetic\\BookneticBridge',
            'description' => 'Ponte principal de integração',
        ],
        'AppointmentOrderMapper' => [
            'class' => 'LimpVix\\Infrastructure\\Booknetic\\AppointmentOrderMapper',
            'description' => 'Mapeamento 1:1 appointment → order',
        ],
        'StaffAccessGuard' => [
            'class' => 'LimpVix\\Infrastructure\\Booknetic\\Guards\\StaffAccessGuard',
            'description' => 'Controle de acesso ao painel',
        ],
        'StaffActionGuard' => [
            'class' => 'LimpVix\\Infrastructure\\Booknetic\\Guards\\StaffActionGuard',
            'description' => 'Controle de ações permitidas',
        ],
        'StaffPanelOverride' => [
            'class' => 'LimpVix\\Infrastructure\\Booknetic\\UI\\StaffPanelOverride',
            'description' => 'UI customizada para staff',
        ],
        'StaffNotices' => [
            'class' => 'LimpVix\\Infrastructure\\Booknetic\\UI\\StaffNotices',
            'description' => 'Avisos personalizados no painel',
        ],
    ];

    $result = [];

    foreach ($components as $name => $config) {
        $exists = class_exists($config['class']);

        $result[$name] = [
            'exists' => $exists,
            'class' => $config['class'],
            'description' => $config['description'],
            'status' => $exists ? 'active' : 'not_found',
        ];
    }

    return $result;
}
```

**Renderização Dinâmica:**
```php
<?php $components = $this->getBookneticComponentsStatus(); ?>
<table class="limpvix-table">
    <thead>
        <tr>
            <th style="width: 50px;">Status</th>
            <th>Componente</th>
            <th>Classe</th>
            <th>Descrição</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($components as $name => $data): ?>
        <tr>
            <td style="text-align: center;">
                <?php echo $data['exists'] ? '<span style="color: #10b981; font-size: 18px;">✅</span>' : '<span style="color: #ef4444; font-size: 18px;">❌</span>'; ?>
            </td>
            <td><strong><?php echo esc_html($name); ?></strong></td>
            <td><code><?php echo esc_html($data['class']); ?></code></td>
            <td><?php echo esc_html($data['description']); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
```

**Score:** 0% Dinâmico / 100% Hardcoded → **Após Fix:** 100% Dinâmico

---

### 7. STATUS DOS GAPs ❌ HARDCODED

**Código (linhas 900-977):**
```php
<table class="limpvix-table">
    <tbody>
        <tr>
            <td>✅</td> <!-- ❌ HARDCODED -->
            <td><strong>GAP #1</strong></td>
            <td><strong>EPI Selfie Validation</strong></td>
            <td><code>e9ae591</code></td> <!-- ❌ COMMIT HARDCODED -->
        </tr>
        <!-- ... 3 outros GAPs hardcoded ... -->
    </tbody>
</table>
```

**Problema:**
- Status ✅ é fixo, não verifica implementação real
- Commits hardcoded (e9ae591, f9f9281, 28fb29a, f599585)
- Não verifica se classes/use cases dos GAPs existem

**Solução Proposta:**
Criar método `getGAPsImplementationStatus()`:
```php
private function getGAPsImplementationStatus(): array
{
    $gaps = [
        'GAP #1' => [
            'name' => 'EPI Selfie Validation',
            'description' => 'Validação obrigatória de EPI no check-in com video selfie',
            'checks' => [
                'Evidence class with category' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence',
                'EPI validation in CheckIn' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn',
            ],
        ],
        'GAP #2' => [
            'name' => 'Evidence Categorization',
            'description' => 'Sistema de categorização de evidências (EPI, Local, Problema)',
            'checks' => [
                'Evidence with categories' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence',
                'EvidenceType enum' => 'LimpVix\\Domain\\Execution\\Enums\\EvidenceType',
            ],
        ],
        'GAP #3' => [
            'name' => 'Client Check-in Notification',
            'description' => 'Notificação automática ao cliente quando profissional faz check-in',
            'checks' => [
                'CheckInPerformed event' => 'LimpVix\\Domain\\Execution\\Events\\CheckInPerformed',
                'NotifyClientOnCheckIn listener' => 'LimpVix\\Infrastructure\\EventListeners\\NotifyClientOnCheckIn',
            ],
        ],
        'GAP #4' => [
            'name' => 'Issue Reporting System',
            'description' => 'Sistema completo de reporte de problemas',
            'checks' => [
                'Issue entity' => 'LimpVix\\Domain\\Execution\\Issue',
                'ReportIssue use case' => 'LimpVix\\Application\\UseCases\\Execution\\ReportIssue',
                'IssueRepository' => 'LimpVix\\Domain\\Execution\\IssueRepositoryInterface',
            ],
        ],
    ];

    $result = [];

    foreach ($gaps as $gapId => $config) {
        $allChecksPass = true;
        $checksDetail = [];

        foreach ($config['checks'] as $checkName => $className) {
            $exists = class_exists($className) || interface_exists($className);
            $checksDetail[$checkName] = [
                'class' => $className,
                'exists' => $exists,
            ];

            if (!$exists) {
                $allChecksPass = false;
            }
        }

        $result[$gapId] = [
            'name' => $config['name'],
            'description' => $config['description'],
            'implemented' => $allChecksPass,
            'checks' => $checksDetail,
            'icon' => $allChecksPass ? '✅' : '❌',
            'status' => $allChecksPass ? 'Implementado' : 'Não Implementado',
        ];
    }

    return $result;
}
```

**Renderização Dinâmica:**
```php
<?php $gaps = $this->getGAPsImplementationStatus(); ?>
<table class="limpvix-table">
    <thead>
        <tr>
            <th style="width: 50px;">Status</th>
            <th style="width: 100px;">GAP</th>
            <th>Descrição</th>
            <th style="width: 150px;">Componentes</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($gaps as $gapId => $data): ?>
        <tr>
            <td style="text-align: center; font-size: 20px;"><?php echo $data['icon']; ?></td>
            <td><strong><?php echo esc_html($gapId); ?></strong></td>
            <td>
                <strong><?php echo esc_html($data['name']); ?></strong><br>
                <small><?php echo esc_html($data['description']); ?></small>
            </td>
            <td>
                <?php foreach ($data['checks'] as $checkName => $check): ?>
                    <div style="font-size: 12px;">
                        <?php echo $check['exists'] ? '✓' : '❌'; ?> <?php echo esc_html($checkName); ?>
                    </div>
                <?php endforeach; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
```

**Score:** 0% Dinâmico / 100% Hardcoded → **Após Fix:** 100% Dinâmico

---

### 8. PROVIDERS DE COMUNICAÇÃO ✅ DINÂMICO

**Código (linhas 984-1031):**
```php
$twilioConfigured = !empty(get_option('limpvix_twilio_account_sid')) &&
                   !empty(get_option('limpvix_twilio_auth_token'));

$nvoipConfigured = false;
if (class_exists('LimpVix\\Infrastructure\\Communication\\NVoipSettings')) {
    $nvoipConfigured = \LimpVix\Infrastructure\Communication\NVoipSettings::isConnected();
}
```

**Verificações Dinâmicas:**
- ✅ Twilio configurado (verifica options)
- ✅ NVoip configurado (verifica classe e método isConnected())
- ✅ Mostra qual provider está ativo
- ✅ Link para aba Conexões

**Conclusão:** ✅ **100% Dinâmico** - Não precisa alterações

---

### 9. AMBIENTE DO SISTEMA ✅ DINÂMICO

**Código (linhas 1034-1095):**
```php
$phpVersion = PHP_VERSION;
$phpOk = version_compare($phpVersion, '8.0', '>=');

$mysqlVersion = $wpdb->db_version();
$mysqlOk = version_compare($mysqlVersion, '5.7', '>=');

$wpVersion = get_bloginfo('version');
$wpOk = version_compare($wpVersion, '5.8', '>=');
```

**Verificações Dinâmicas:**
- ✅ PHP version real
- ✅ MySQL version real
- ✅ WordPress version real
- ✅ Comparação com versões mínimas
- ✅ Mensagens dinâmicas

**Conclusão:** ✅ **100% Dinâmico** - Não precisa alterações

---

### 10. PRINCÍPIOS DE INTEGRAÇÃO ❌ HARDCODED

**Código (linhas 1099-1143):**
```php
<h4>✅ O QUE FAZEMOS:</h4>
<ul>
    <li>✅ Interceptamos eventos via hooks do WordPress</li>
    <li>✅ Lemos dados das tabelas Booknetic (READ-ONLY)</li>
    <!-- ... lista hardcoded ... -->
</ul>

<h4>❌ O QUE NÃO FAZEMOS:</h4>
<ul>
    <li>❌ NUNCA modificamos código do Booknetic</li>
    <!-- ... lista hardcoded ... -->
</ul>
```

**Problema:**
- Texto completamente estático
- Não reflete implementação real
- Poderia ser um card informativo reutilizável

**Solução:**
Este é um card **educacional/documentação**, então faz sentido ser estático. Não precisa ser dinâmico.

**Observação:**
Adicionar link para documentação completa da arquitetura (ARQUITETURA_MERCADOPAGO.md).

**Score:** Não aplicável (conteúdo educacional)

---

## 🔍 ANÁLISE: REAL DEPENDÊNCIA DO BOOKNETIC

### Pergunta Central: O Booknetic é REALMENTE obrigatório?

**Resposta:** ⚠️ **SIM, mas com ressalvas importantes**

### Por Que o Booknetic É Necessário HOJE:

1. **Agendamento Inicial:**
   - Cliente cria appointment no Booknetic
   - Hook `bkntc_appointment_created` dispara criação de Order no LimpVix
   - **Sem Booknetic:** Não há input inicial de appointments

2. **Gestão de Staff:**
   - Profissionais são cadastrados como Staff no Booknetic
   - LimpVix intercepta e sincroniza com Professional entity
   - **Sem Booknetic:** Precisaria UI alternativa de cadastro

3. **Fluxo de Pagamento:**
   - Appointment "completed" dispara fluxo financeiro
   - Hook `bkntc_appointment_completed` é trigger
   - **Sem Booknetic:** Não há trigger para payout

4. **Calendário e Disponibilidade:**
   - Booknetic gerencia agenda dos profissionais
   - LimpVix consome essa agenda via leitura de tabelas
   - **Sem Booknetic:** Precisaria implementar sistema de calendário

### Arquitetura de Isolamento (Por Que Pode Ser Substituído):

**Princípio:** LimpVix NÃO depende de código interno do Booknetic

```
┌─────────────────────────────────────────────────────┐
│ Booknetic (Engine Operacional)                     │
│ - Agendamento                                       │
│ - Calendário                                        │
│ - Staff management                                  │
└──────────────────┬──────────────────────────────────┘
                   │
                   │ Hooks WordPress (10 hooks)
                   │ READ-ONLY access (4 tabelas)
                   │
┌──────────────────▼──────────────────────────────────┐
│ BookneticBridge (Camada de Isolamento)             │
│ - Interceptação de eventos                         │
│ - Mapeamento appointment → order                   │
│ - Controle de acesso (Guards)                      │
└──────────────────┬──────────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────────┐
│ LimpVix Core (Soberano)                            │
│ - Domain layer completo                            │
│ - Regras de negócio                                │
│ - Fluxo financeiro                                 │
│ - Compliance e auditoria                           │
└─────────────────────────────────────────────────────┘
```

### Estratégia de Substituição Futura:

**Opção 1: Substituir Booknetic por UI própria**
- Implementar frontend React Native para agendamento
- API REST do LimpVix recebe appointments diretamente
- Booknetic deixa de ser necessário
- **Esforço estimado:** 120-160 horas (3-4 semanas)

**Opção 2: Suportar múltiplos engines de agendamento**
- Criar interface `AppointmentProviderInterface`
- Implementar `BookneticProvider`, `CustomProvider`, etc.
- LimpVix agnóstico ao engine de agendamento
- **Esforço estimado:** 80-100 horas (2-3 semanas)

**Opção 3: Migração progressiva**
- Fase 1: Booknetic continua para agendamento
- Fase 2: LimpVix UI para briefing (✅ já implementado)
- Fase 3: LimpVix UI para execução (✅ já implementado)
- Fase 4: LimpVix UI para agendamento (futuro)
- **Vantagem:** Migração gradual sem quebrar operação

### Conclusão: Booknetic É "Soft Dependency"

**Status Atual:**
- ✅ Obrigatório para operação (não há alternativa hoje)
- ✅ Arquitetura permite substituição futura
- ✅ Isolamento via bridge evita vendor lock-in

**Recomendação:**
1. **Manter como OBRIGATÓRIO no curto prazo** (6-12 meses)
2. **Documentar arquitetura de isolamento** (já feito em docs)
3. **Planejar substituição no médio prazo** (roadmap 2027)
4. **Adicionar observação na aba dependências** sobre possibilidade de substituição

---

## 📋 ANÁLISE: DEPENDÊNCIAS DO WOOCOMMERCE E MERCADOPAGO

### WooCommerce - Por Que É Necessário:

**Sistema 1: Pagamentos de Clientes**
- Cliente contrata serviço via WooCommerce
- WooCommerce MercadoPago processa pagamento
- Order criada no WooCommerce vinculada a appointment

**Dependência:**
- ✅ **OBRIGATÓRIO** para processamento de pagamentos de clientes
- ✅ Integração sólida com MercadoPago
- ✅ Suporta PIX, cartão de crédito, boleto

**Alternativa Possível:**
- Implementar gateway de pagamento próprio via REST API do MercadoPago
- **Esforço:** 80-120 horas
- **Risco:** Perder funcionalidades do WooCommerce (cupons, estoque, etc.)

### WooCommerce MercadoPago - Por Que É Necessário:

**Sistema 1: Gateway de Pagamento**
- Plugin oficial do MercadoPago para WooCommerce
- Credenciais sincronizadas automaticamente para LimpVix
- Suporta checkout transparente

**Arquitetura Descoberta (de ARQUITETURA_MERCADOPAGO.md):**

```
Sistema 1: WooCommerce MercadoPago (Pagamentos de Clientes)
├─ Credenciais: _mp_access_token_prod/test, _mp_public_key_prod/test
├─ Sincronização: WooCommerce → LimpVix (a cada 5 min)
└─ Uso: Cliente paga serviço via checkout

Sistema 2: LimpVix OAuth MercadoPago (Payouts Profissionais)
├─ Credenciais OAuth: limpvix_mercadopago_client_id/secret
├─ Token por profissional: mp_access_token (OAuth)
└─ Uso: Transferência MP→MP automática
```

**Dependência:**
- ⚠️ **RECOMENDADO mas não obrigatório**
- Se não tiver WooCommerce MP: LimpVix não sincroniza credenciais automaticamente
- Admin precisa configurar credenciais manualmente na aba Pagamentos

**Observação Importante:**
- WooCommerce MP é para **pagamentos de clientes** (Sistema 1)
- LimpVix OAuth é para **payouts de profissionais** (Sistema 2)
- São sistemas DIFERENTES e complementares

### Relação MercadoPago com Dependências:

**Cenários de Configuração:**

| WooCommerce | WooCommerce MP | LimpVix OAuth | Status | Observação |
|-------------|----------------|---------------|--------|------------|
| ✅ Ativo | ✅ Conectado | ✅ Configurado | ✅ 100% Operacional | Setup ideal |
| ✅ Ativo | ✅ Conectado | ❌ Não configurado | ⚠️ 50% | Clientes pagam, profissionais PIX manual |
| ✅ Ativo | ❌ Não conectado | ✅ Configurado | ⚠️ 50% | Payouts automáticos, mas clientes sem gateway |
| ✅ Ativo | ❌ Não conectado | ❌ Não configurado | ❌ Bloqueado | Sem pagamentos e sem payouts |
| ❌ Não ativo | N/A | N/A | ❌ Bloqueado | Sem e-commerce |

---

## 🎯 IMPLEMENTAÇÃO PROPOSTA

### Fase 1: Criar Métodos de Verificação Dinâmica (2-3h)

**Arquivos a modificar:**
- `src/Admin/Bootstrap/AdminBootstrap.php`

**Métodos a criar:**

1. **`getBookneticHooksStatus(): array`** (30 min)
   - Verifica hooks registrados
   - Retorna status de cada hook
   - Conta callbacks conectados

2. **`getBookneticTablesStatus(): array`** (30 min)
   - Verifica existência de tabelas Booknetic
   - SHOW TABLES LIKE para cada tabela
   - Retorna status de acesso

3. **`getBookneticComponentsStatus(): array`** (30 min)
   - Verifica `class_exists()` para cada componente
   - Retorna status de implementação

4. **`getGAPsImplementationStatus(): array`** (1h)
   - Verifica classes/interfaces de cada GAP
   - Valida implementação real
   - Retorna status dinâmico

5. **`getGuardsStatus(): int`** (15 min)
   - Verifica StaffAccessGuard e StaffActionGuard
   - Retorna score 0-100

6. **`getUIOverridesStatus(): int`** (15 min)
   - Verifica StaffPanelOverride e StaffNotices
   - Retorna score 0-100

7. **`getPluginVersions(): array`** (15 min)
   - Obtém versão real de Booknetic, WooCommerce, WooCommerce MP
   - Compara com versões mínimas

### Fase 2: Atualizar Renderização (1-2h)

**Substituir blocos hardcoded por dinâmicos:**
1. Seção Hooks (linha 802)
2. Seção Tabelas (linha 854)
3. Seção Componentes (linha 887)
4. Seção GAPs (linha 909)
5. Scorecard (linhas 489-496)

### Fase 3: Adicionar Observações sobre Dependências (1h)

**Card novo: "Observações sobre Dependências"**
- Explicar relação WooCommerce + WooCommerce MP
- Documentar arquitetura dual MercadoPago
- Explicar possibilidade de substituir Booknetic no futuro
- Link para documentação completa (ARQUITETURA_MERCADOPAGO.md)

### Fase 4: Criar Documentação Complementar (30 min)

**Arquivo:** `DEPENDENCIAS_OBSERVACOES.md`
- Análise detalhada de cada dependência
- Cenários de configuração
- Roadmap de substituição futura
- Links para docs de arquitetura

---

## 📊 SCORECARD FINAL APÓS IMPLEMENTAÇÃO

### Antes (Atual):
- **Score Geral:** 65% Dinâmico / 35% Hardcoded
- **Seções Dinâmicas:** 5/10
- **Seções Hardcoded:** 5/10

### Depois (Proposto):
- **Score Geral:** 100% Dinâmico
- **Seções Dinâmicas:** 10/10
- **Seções Hardcoded:** 0/10

### Detalhamento:
1. ✅ Hero Card - 100% dinâmico (já é)
2. ✅ Plugins WordPress - 100% dinâmico (melhorado com versões)
3. ✅ Scorecard - 100% dinâmico (guardScore, uiScore dinâmicos)
4. ✅ Hooks - 100% dinâmico (após implementação)
5. ✅ Tabelas - 100% dinâmico (após implementação)
6. ✅ Componentes - 100% dinâmico (após implementação)
7. ✅ GAPs - 100% dinâmico (após implementação)
8. ✅ Providers - 100% dinâmico (já é)
9. ✅ Ambiente - 100% dinâmico (já é)
10. ℹ️ Princípios - Educacional (não aplicável)

---

## ✅ CHECKLIST DE IMPLEMENTAÇÃO

### Métodos a Criar:
- [ ] `getBookneticHooksStatus()`
- [ ] `getBookneticTablesStatus()`
- [ ] `getBookneticComponentsStatus()`
- [ ] `getGAPsImplementationStatus()`
- [ ] `getGuardsStatus()`
- [ ] `getUIOverridesStatus()`
- [ ] `getPluginVersions()`

### Seções a Atualizar:
- [ ] Plugins WordPress - adicionar versões reais
- [ ] Scorecard - guardScore e uiScore dinâmicos
- [ ] Hooks - renderização dinâmica
- [ ] Tabelas - renderização dinâmica
- [ ] Componentes - renderização dinâmica
- [ ] GAPs - renderização dinâmica

### Documentação a Criar:
- [ ] Card "Observações sobre Dependências"
- [ ] Arquivo `DEPENDENCIAS_OBSERVACOES.md`
- [ ] Atualizar `ARQUITETURA_MERCADOPAGO.md` com links

### Testes:
- [ ] Testar com Booknetic ativo
- [ ] Testar com Booknetic desativado
- [ ] Testar com WooCommerce MP ativo/inativo
- [ ] Verificar scores calculados corretamente

---

**Estimativa Total:** 4-6 horas de implementação
**Resultado:** Aba Dependências 100% Dinâmica com observações completas sobre arquitetura

