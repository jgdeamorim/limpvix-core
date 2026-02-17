# 🔌 Observações sobre Dependências - LimpVix Core

**Data:** 2026-02-16
**Versão:** 1.0.0
**Autor:** Claude Code Assistant

---

## 📊 Visão Geral

O LimpVix Core possui **3 dependências principais** de plugins WordPress:

1. **Booknetic** - Sistema de agendamento (OBRIGATÓRIO)
2. **WooCommerce** - E-commerce e pagamentos (OBRIGATÓRIO)
3. **WooCommerce Mercado Pago** - Gateway de pagamento (RECOMENDADO)

Este documento detalha a relação do LimpVix com cada dependência, estratégias de isolamento e possibilidades de substituição futura.

---

## 🎯 DEPENDÊNCIA #1: Booknetic

### Status Atual
- **Criticidade:** 🔴 OBRIGATÓRIO
- **Tipo:** "Soft Dependency" (arquitetura permite substituição)
- **Versão Mínima:** 4.8.5+

### Por Que É Necessário Hoje?

**1. Agendamento Inicial:**
- Cliente cria appointment no Booknetic
- Hook `bkntc_appointment_created` dispara criação de Order no LimpVix
- **Sem Booknetic:** Não há input inicial de appointments

**2. Gestão de Staff:**
- Profissionais são cadastrados como Staff no Booknetic
- LimpVix intercepta e sincroniza com Professional entity
- **Sem Booknetic:** Precisaria UI alternativa de cadastro

**3. Fluxo de Pagamento:**
- Appointment "completed" dispara fluxo financeiro
- Hook `bkntc_appointment_completed` é trigger para payout
- **Sem Booknetic:** Não há trigger automático

**4. Calendário e Disponibilidade:**
- Booknetic gerencia agenda dos profissionais
- LimpVix consome essa agenda via leitura de tabelas
- **Sem Booknetic:** Precisaria sistema de calendário próprio

### Arquitetura de Isolamento

```
┌─────────────────────────────────────────────────────┐
│ Booknetic (Engine Operacional)                     │
│ - Agendamento                                       │
│ - Calendário                                        │
│ - Staff management                                  │
│ - UI de booking                                     │
└──────────────────┬──────────────────────────────────┘
                   │
                   │ 10 Hooks WordPress (interceptação)
                   │ READ-ONLY 4 tabelas (bkntc_*)
                   │
┌──────────────────▼──────────────────────────────────┐
│ BookneticBridge (Camada de Isolamento)             │
│ ✅ Intercepta eventos via hooks                    │
│ ✅ Lê dados das tabelas Booknetic                  │
│ ✅ Mantém mapeamento 1:1 em tabela própria         │
│ ✅ Sobrescreve UI apenas para staff (Guards)       │
│ ❌ NUNCA modifica código do Booknetic              │
│ ❌ NUNCA escreve em tabelas do Booknetic           │
└──────────────────┬──────────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────────┐
│ LimpVix Core (Soberano)                            │
│ - Domain layer completo                            │
│ - Regras de negócio próprias                       │
│ - Fluxo financeiro independente                    │
│ - Compliance e auditoria                           │
│ - REST API própria                                 │
└─────────────────────────────────────────────────────┘
```

### Princípios de Isolamento

**✅ O QUE FAZEMOS:**
1. Interceptamos eventos via hooks do WordPress
2. Lemos dados das tabelas Booknetic (READ-ONLY)
3. Mantemos mapeamento 1:1 em tabela própria (`wp_limpvix_appointment_order_map`)
4. Sobrescrevemos UI apenas para staff (Guards)
5. Validamos permissões antes de cada ação

**❌ O QUE NÃO FAZEMOS:**
1. NUNCA modificamos código do Booknetic
2. NUNCA escrevemos em tabelas do Booknetic
3. NUNCA sobrescrevemos classes do Booknetic
4. NUNCA dependemos de métodos internos
5. NUNCA quebramos compatibilidade com updates

### Integração Técnica

**Hooks Capturados (10):**
```php
// Appointment lifecycle
'bkntc_appointment_created'       → Criar order no LimpVix
'bkntc_appointment_completed'     → Disparar fluxo financeiro
'bkntc_appointment_canceled'      → Cancelar order

// Staff management
'bkntc_staff_updated'             → Sincronizar dados staff
'bkntc_after_booking_completed'   → Redirecionar para Briefing

// Access control
'bkntc_staff_can_access'          → Controle de permissões
'bkntc_staff_can_execute_action'  → Controle de ações

// UI customization
'bkntc_staff_panel_header'        → Avisos personalizados
'bkntc_staff_panel_footer'        → Ocultar abas financeiras
'admin_menu' (999)                → Ocultar menus para staff
```

**Tabelas Acessadas (4) - READ-ONLY:**
```sql
bkntc_appointments  → Mapear appointment → order
bkntc_staff         → Vincular user_id WordPress
bkntc_customers     → Dados para Google Reviews
bkntc_services      → Nome do serviço executado
```

**Componentes de Integração (6):**
```
BookneticBridge              → Ponte principal de integração
AppointmentOrderMapper       → Mapeamento 1:1 appointment → order
StaffAccessGuard             → Controle de acesso ao painel
StaffActionGuard             → Controle de ações permitidas
StaffPanelOverride           → UI customizada para staff
StaffNotices                 → Avisos personalizados no painel
```

### Estratégia de Substituição Futura

#### Opção A: Substituir por UI Própria
**Esforço:** 120-160 horas (3-4 semanas)

**Fases:**
1. Implementar frontend React Native para agendamento
2. API REST do LimpVix recebe appointments diretamente
3. Sistema de calendário próprio
4. Gestão de disponibilidade de profissionais
5. Booknetic deixa de ser necessário

**Vantagens:**
- ✅ Controle total sobre UX
- ✅ Sem dependência de terceiros
- ✅ Customização ilimitada

**Desvantagens:**
- ❌ Alto esforço de desenvolvimento
- ❌ Manutenção adicional
- ❌ Precisa reimplementar calendário

#### Opção B: Suportar Múltiplos Engines
**Esforço:** 80-100 horas (2-3 semanas)

**Arquitetura:**
```php
interface AppointmentProviderInterface
{
    public function createAppointment(): Appointment;
    public function getStaffAvailability(): array;
    public function cancelAppointment(): void;
}

// Implementações
class BookneticProvider implements AppointmentProviderInterface { }
class CustomProvider implements AppointmentProviderInterface { }
class CalendlyProvider implements AppointmentProviderInterface { }
```

**Vantagens:**
- ✅ Flexibilidade para escolher engine
- ✅ LimpVix agnóstico
- ✅ Pode usar Calendly, Acuity, etc.

**Desvantagens:**
- ❌ Precisa implementar adapters
- ❌ Complexidade adicional

#### Opção C: Migração Progressiva (Recomendado)
**Esforço:** Gradual (sem parar operação)

**Fases:**
1. **Fase 1 (Atual):** Booknetic para agendamento ✅
2. **Fase 2:** LimpVix UI para briefing ✅ (já implementado)
3. **Fase 3:** LimpVix UI para execução ✅ (já implementado)
4. **Fase 4 (Futuro):** LimpVix UI para agendamento
5. **Fase 5 (Futuro):** Deprecar Booknetic (opcional)

**Vantagens:**
- ✅ Migração gradual sem quebrar operação
- ✅ Risco baixo
- ✅ Pode manter Booknetic se necessário

### Conclusão: Booknetic É "Soft Dependency"

**Status:**
- ✅ **Obrigatório no curto prazo** (6-12 meses)
- ✅ **Arquitetura permite substituição** (médio/longo prazo)
- ✅ **Isolamento evita vendor lock-in**

**Recomendação:**
1. Manter como OBRIGATÓRIO por enquanto
2. Documentar arquitetura de isolamento (✅ feito)
3. Planejar substituição no médio prazo (roadmap 2027)

---

## 💳 DEPENDÊNCIA #2: WooCommerce

### Status Atual
- **Criticidade:** 🔴 OBRIGATÓRIO
- **Tipo:** Hard Dependency (crítico para e-commerce)
- **Versão Mínima:** 5.0.0+

### Por Que É Necessário?

**1. E-commerce Foundation:**
- WooCommerce gerencia products, orders, checkout
- LimpVix cria produtos de serviço no WooCommerce
- Cliente finaliza compra via WooCommerce checkout

**2. Processamento de Pagamentos:**
- WooCommerce processa payments de clientes
- Integração com gateways (MercadoPago, PIX, cartão)
- Orders vinculadas a appointments do Booknetic

**3. Funcionalidades Complementares:**
- Cupons de desconto
- Gestão de estoque (se aplicável)
- Relatórios financeiros
- Nota fiscal (via plugins)

### Integração com LimpVix

**Fluxo de Criação de Order:**
```
1. Cliente agenda serviço no Booknetic
   ↓
2. Hook bkntc_appointment_created dispara
   ↓
3. LimpVix cria produto WooCommerce (se não existir)
   ↓
4. LimpVix cria order WooCommerce vinculada a appointment
   ↓
5. Cliente finaliza pagamento via WooCommerce
   ↓
6. Hook woocommerce_order_status_completed dispara
   ↓
7. LimpVix marca order como paga e inicia fluxo operacional
```

**Tabelas Utilizadas:**
- `wp_posts` (type = 'shop_order', 'product')
- `wp_postmeta` (order metadata, product metadata)
- `wp_woocommerce_order_items`
- `wp_woocommerce_order_itemmeta`

### Alternativa Possível

**Implementar Gateway de Pagamento Próprio:**
- Integração direta com MercadoPago REST API
- Criar orders no LimpVix sem WooCommerce
- **Esforço:** 80-120 horas
- **Risco:** Perder funcionalidades (cupons, relatórios, plugins)

**Recomendação:** Manter WooCommerce (benefícios superam esforço de substituição)

---

## 🛒 DEPENDÊNCIA #3: WooCommerce Mercado Pago

### Status Atual
- **Criticidade:** ⚠️ RECOMENDADO (não obrigatório)
- **Tipo:** Soft Dependency (pode usar outros gateways)
- **Versão Mínima:** 6.0.0+

### Por Que É Recomendado?

**1. Gateway Oficial MercadoPago:**
- Plugin oficial desenvolvido pelo MercadoPago
- Suporta PIX, cartão, boleto, parcelamento
- Checkout transparente integrado

**2. Sincronização Automática de Credenciais:**
- WooCommerce MP armazena credenciais
- LimpVix sincroniza automaticamente a cada 5 min
- Admin não precisa duplicar configuração

**3. Sistema Dual MercadoPago:**
- **Sistema 1:** WooCommerce MP (pagamentos de clientes)
- **Sistema 2:** LimpVix OAuth (payouts profissionais)
- Complementares e independentes

### Arquitetura Dual MercadoPago

```
┌─────────────────────────────────────────────────────┐
│ SISTEMA 1: WooCommerce MercadoPago                 │
│ (Pagamentos de Clientes)                           │
├─────────────────────────────────────────────────────┤
│ Propósito: Processar pagamentos de clientes        │
│ Plugin: WooCommerce Mercado Pago (oficial)         │
│ Fluxo: Cliente → Plataforma MP                     │
│                                                     │
│ Credenciais (armazenadas pelo WooCommerce):        │
│ ├─ _mp_access_token_prod                           │
│ ├─ _mp_public_key_prod                             │
│ ├─ _mp_access_token_test                           │
│ └─ _mp_public_key_test                             │
│                                                     │
│ Sincronização (a cada 5 min):                      │
│ WooCommerce _mp_* → LimpVix limpvix_mp_*           │
│                                                     │
│ Detector:                                           │
│ LimpVix\Admin\Settings\MercadoPagoDetector         │
│ ├─ isOfficialPluginConnected()                     │
│ ├─ getOfficialPluginCredentials()                  │
│ └─ syncCredentials()                                │
└─────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────┐
│ SISTEMA 2: LimpVix OAuth MercadoPago               │
│ (Payouts para Profissionais)                       │
├─────────────────────────────────────────────────────┤
│ Propósito: Transferências MP→MP automáticas        │
│ Tecnologia: OAuth 2.0                              │
│ Fluxo: Plataforma MP → Profissional MP             │
│                                                     │
│ Credenciais da Plataforma:                         │
│ ├─ limpvix_mercadopago_client_id                   │
│ └─ limpvix_mercadopago_client_secret                │
│                                                     │
│ Por Profissional (tabela wp_limpvix_professionals):│
│ ├─ mp_access_token (OAuth, criptografado)          │
│ ├─ mp_refresh_token (criptografado)                │
│ ├─ mp_user_id                                      │
│ ├─ mp_public_key                                   │
│ ├─ mp_oauth_connected_at                           │
│ ├─ mp_oauth_expires_at                             │
│ └─ mp_oauth_status (connected/expired/revoked)     │
│                                                     │
│ Fluxo OAuth:                                        │
│ 1. Profissional conecta via app React Native       │
│ 2. Autoriza aplicação LimpVix no MercadoPago       │
│ 3. Callback troca code por access token            │
│ 4. Token salvo criptografado                       │
│ 5. Payouts automáticos MP→MP                       │
└─────────────────────────────────────────────────────┘
```

### Cenários de Configuração

| WooCommerce | WooCommerce MP | LimpVix OAuth | Status | Observação |
|-------------|----------------|---------------|--------|------------|
| ✅ Ativo | ✅ Conectado | ✅ Configurado | ✅ 100% Operacional | Setup ideal - tudo automático |
| ✅ Ativo | ✅ Conectado | ❌ Não config. | ⚠️ 50% | Clientes pagam, profissionais PIX manual |
| ✅ Ativo | ❌ Não config. | ✅ Configurado | ⚠️ 50% | Payouts automáticos, mas clientes sem gateway MP |
| ✅ Ativo | ❌ Não config. | ❌ Não config. | ❌ Bloqueado | Sem pagamentos e sem payouts |
| ❌ Não ativo | N/A | N/A | ❌ Bloqueado | Sem e-commerce |

### Alternativas ao WooCommerce MercadoPago

**Outros Gateways WooCommerce:**
- PagSeguro
- PayPal
- Stripe
- Cielo
- Rede
- Outros (100+ disponíveis)

**Observação:** Se usar outro gateway, credenciais MercadoPago não são sincronizadas automaticamente. Admin precisa configurar manualmente na aba Pagamentos do LimpVix.

### Sincronização de Credenciais

**Detector:** `LimpVix\Admin\Settings\MercadoPagoDetector`

**Processo:**
1. Detecta se WooCommerce MP está ativo
2. Verifica se tem credenciais configuradas
3. Sincroniza credenciais:
   ```php
   _mp_access_token_prod  → limpvix_mp_access_token_prod
   _mp_public_key_prod    → limpvix_mp_public_key_prod
   _mp_access_token_test  → limpvix_mp_access_token_test
   _mp_public_key_test    → limpvix_mp_public_key_test
   _mp_client_id          → limpvix_mp_client_id
   _site_id_v1            → limpvix_mp_site_id
   _collector_id_v1       → limpvix_mp_collector_id
   ```

4. Atualiza status:
   ```php
   limpvix_mp_status = [
       'connected' => true,
       'source' => 'official_plugin',
       'last_sync' => time(),
       'environment' => 'test' // ou 'production'
   ]
   ```

**Frequência de Sincronização:**
- Cron job a cada 5 minutos
- Sincronização manual via botão "Verificar Sincronização"

**Documentação Completa:**
- Consulte `ARQUITETURA_MERCADOPAGO.md` para detalhes completos

---

## 📊 Comparação de Dependências

| Dependência | Status | Versão Mínima | Pode Substituir? | Esforço | Risco |
|-------------|--------|---------------|------------------|---------|-------|
| **Booknetic** | 🔴 OBRIGATÓRIO | 4.8.5+ | ✅ Sim (futuro) | 120-160h | Baixo (isolamento) |
| **WooCommerce** | 🔴 OBRIGATÓRIO | 5.0.0+ | ⚠️ Possível | 80-120h | Alto (perder features) |
| **WooCommerce MP** | ⚠️ RECOMENDADO | 6.0.0+ | ✅ Sim (outros gateways) | 0h | Baixo (só config manual) |

---

## 🛡️ Estratégia de Mitigação de Riscos

### Risco 1: Booknetic Descontinuado
**Probabilidade:** Baixa
**Impacto:** Alto
**Mitigação:**
- ✅ Arquitetura de isolamento já implementada
- ✅ Mapeamento 1:1 em tabela própria
- ✅ Hooks WordPress (não API interna)
- ✅ Plano de substituição documentado (Opção C)

### Risco 2: WooCommerce Breaking Changes
**Probabilidade:** Média
**Impacto:** Alto
**Mitigação:**
- ✅ Usar APIs públicas do WooCommerce
- ✅ Evitar acessar tabelas diretamente quando possível
- ✅ Testes de compatibilidade antes de updates
- ⚠️ Considerar implementar gateway próprio (futuro)

### Risco 3: WooCommerce MP Descontinuado
**Probabilidade:** Muito Baixa (plugin oficial)
**Impacto:** Médio
**Mitigação:**
- ✅ Sistema 2 (OAuth) não depende do plugin
- ✅ Pode usar outros gateways WooCommerce
- ✅ Sincronização é opcional (pode configurar manual)

### Risco 4: Versões Incompatíveis
**Probabilidade:** Média
**Impacto:** Médio
**Mitigação:**
- ✅ Verificação de versão mínima implementada
- ✅ Warning se versão não atende requisitos
- ✅ Documentação de versões compatíveis

---

## 📚 Documentação Relacionada

- **ARQUITETURA_MERCADOPAGO.md** - Sistema dual MercadoPago detalhado
- **ANALISE_ABA_DEPENDENCIAS.md** - Análise técnica da aba dependências
- **STATUS_ABA_PAGAMENTOS_DINAMICA.md** - Implementação aba pagamentos

---

## ✅ Checklist de Verificação

### Para Go-Live:
- [ ] Booknetic 4.8.5+ instalado e ativo
- [ ] WooCommerce 5.0.0+ instalado e ativo
- [ ] WooCommerce MercadoPago 6.0.0+ instalado e conectado (ou outro gateway)
- [ ] Credenciais MercadoPago sincronizadas (verificar aba Pagamentos)
- [ ] OAuth MercadoPago configurado para payouts (System 2)
- [ ] Todos os hooks Booknetic registrados (verificar aba Dependências)
- [ ] Todas as tabelas Booknetic acessíveis
- [ ] Todos os componentes de integração ativos

### Verificação de Saúde:
```bash
# Via aba Dependências:
1. Verificar "Score Geral" ≥ 95%
2. Verificar "Pronto para Go Live" = ✅
3. Verificar "Plugins" = 3/3 ativos
4. Verificar "Hooks Capturados" = 10/10
5. Verificar "Tabelas Acessadas" = 4/4
6. Verificar "Componentes" = 6/6
7. Verificar "GAPs Implementados" = 100%
```

---

**Documentado por:** Claude Code Assistant
**Data:** 2026-02-16
**Versão:** 1.0.0

