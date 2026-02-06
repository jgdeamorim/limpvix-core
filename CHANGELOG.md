# Changelog

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Semantic Versioning](https://semver.org/lang/pt-BR/).

## [0.1.1] - 2026-02-06

### 🔄 Modificado

#### Reorganização de Menus
- **Estrutura hierárquica melhorada**: Comunicação e Templates agora são submenus de Configurações
- **Nova navegação**:
  ```
  LimpVix
  ├─ Dashboard
  ├─ Orders
  ├─ Payouts
  └─ Configurações
      ├─ Comunicação
      └─ Templates
  ```
- **Removida duplicação**: Eliminado registro duplicado de menu em `MessageTemplatesPage::register()`

### 🐛 Corrigido

- **Menu duplicado**: Resolvido problema onde Templates aparecia em múltiplos locais
- **Organização**: Melhor agrupamento lógico de funcionalidades relacionadas

### 📝 Arquivos Modificados

- `src/Admin/Bootstrap/AdminBootstrap.php` - Reorganizado `registerMenu()`
- `src/Infrastructure/Admin/Pages/MessageTemplatesPage.php` - Removido hook `admin_menu` duplicado

---

## [0.1.0] - 2026-02-06

### 🎉 Lançamento Inicial

Primeira versão funcional do plugin LimpVix Core com arquitetura DDD (Domain-Driven Design) e integrações completas.

### ✨ Adicionado

#### Arquitetura Base
- **Domain-Driven Design (DDD)**: Estrutura completa com camadas Domain, Application e Infrastructure
- **Autoloader PSR-4**: Carregamento automático de classes com namespace `LimpVix\`
- **Dependency Injection**: Sistema de injeção de dependências via Container
- **Health Check**: Endpoint `/health-check.php` para monitoramento do plugin

#### Módulo Finance (Financeiro)
- **Dashboard Financeiro**: Visão geral de vendas, pedidos e receitas
- **Gerenciamento de Pedidos**: Interface completa para gestão de pedidos
- **Relatórios Financeiros**: Análises e exportação de dados
- **Configurações Financeiras**: Métodos de pagamento, taxas e comissões

#### Módulo Bookings (Agendamentos)
- **Integração Booknetic**: Conexão completa com plugin Booknetic
- **Adapter Pattern**: Camada de abstração para isolamento de dependências
- **CRUD de Agendamentos**: Criar, listar, atualizar e cancelar agendamentos
- **Dashboard de Agendamentos**: Visão consolidada de todos os agendamentos
- **Sincronização Automática**: Dados sincronizados em tempo real

#### Módulo Communication (Comunicação)
- **Provedores Multi-Canal**:
  - SMS via Twilio
  - WhatsApp Business via 360Dialog
- **Templates de Mensagens**: 6 fluxos de comunicação pré-configurados
  - C1: Confirmação de agendamento (D-1)
  - C2: Feedback pós-atendimento (D+1)
  - C3: Reagendamento de cancelamentos (D+3)
  - P1: Confirmação de pedido (D-1)
  - P2: Feedback de entrega (D+1)
  - P3: Recuperação de carrinho abandonado (D+3)
- **Governança de Envio**: Regras configuráveis (horários, tentativas, prioridades)
- **Logs e Auditoria**: Rastreamento completo de todas as mensagens
- **Interface de Configuração**: Páginas admin para gerenciar provedores e templates

#### Interface Admin Moderna
- **Design System Completo**: CSS moderno com variáveis e componentes reutilizáveis
- **Componentes UI**:
  - Cards com hover effects
  - Badges coloridos (success, warning, danger, info)
  - Toggles customizados
  - Forms estilizados
  - Grid system responsivo
  - Stat boxes com ícones
- **Páginas Admin**:
  - Dashboard principal
  - Pedidos e Financeiro
  - Agendamentos Booknetic
  - Configurações de Comunicação
  - Templates de Mensagens
  - Configurações Gerais
- **Menu Unificado**: Menu lateral com todos os módulos organizados

### 🔧 Configuração

#### Arquivos de Configuração
- `.gitignore`: Regras para versionamento (vendor/, logs, IDE)
- `composer.json`: Dependências e autoload PSR-4
- `README.md`: Documentação completa do projeto

#### Estrutura de Diretórios
```
limpvix-core/
├── assets/
│   ├── css/
│   │   └── limpvix-admin-modern.css (15KB)
│   └── js/
├── modules/
│   ├── finance/
│   ├── bookings/
│   └── communication/
├── src/
│   ├── Domain/
│   │   ├── Booking/
│   │   ├── Communication/
│   │   └── Finance/
│   ├── Application/
│   │   └── UseCases/
│   └── Infrastructure/
│       ├── Admin/
│       ├── Adapters/
│       └── Communication/
└── limpvix-core.php (Entry point)
```

### 🐛 Corrigido

#### Menu Duplicado
- **Problema**: Menus apareciam duplicados no admin (10 itens ao invés de 5)
- **Causa**: `AdminBootstrap::boot()` sendo chamado duas vezes
  1. Via `Kernel::boot()` → `Hooks::register()`
  2. Diretamente em `limpvix-core.php`
- **Solução**: Removida inicialização duplicada em `limpvix-core.php` (linhas 80-82)

#### Erro AdapterBootstrap
- **Problema**: "Non-static method AdapterBootstrap::boot() cannot be called statically"
- **Solução**: Instanciação correta do objeto antes de chamar `boot()`

#### Hooks Duplicados
- **Problema**: `CommunicationSettingsPage::register()` adicionava hook `admin_menu`
- **Solução**: Menus registrados diretamente em `AdminBootstrap::registerMenu()`

### 📊 Estatísticas

- **Arquivos**: 90 arquivos PHP
- **Linhas de Código**: 22.952 linhas
- **Namespaces**: 3 camadas (Domain, Application, Infrastructure)
- **Classes**: ~40 classes
- **CSS**: 15KB de design system moderno
- **Páginas Admin**: 6 páginas completas

### 🔐 Segurança

- **Verificações de Capabilities**: Todos os menus requerem `manage_options`
- **Nonce Validation**: Proteção CSRF em todos os formulários
- **Sanitização de Inputs**: Dados sempre validados e sanitizados
- **Escape de Outputs**: Uso consistente de `esc_html()`, `esc_attr()`, etc.

### 📝 Notas Técnicas

#### Requisitos
- WordPress 5.8+
- PHP 7.4+
- Booknetic plugin (para módulo de agendamentos)

#### Dependências
- PSR-4 Autoloading
- WordPress Admin APIs
- Twilio SDK (para SMS)
- 360Dialog API (para WhatsApp)

### 🚀 Próximos Passos

- [ ] Implementar testes unitários
- [ ] Adicionar suporte a outros provedores de SMS/WhatsApp
- [ ] Dashboard com gráficos e estatísticas em tempo real
- [ ] Exportação de relatórios em PDF
- [ ] API REST para integrações externas
- [ ] Sistema de notificações push
- [ ] Integração com WooCommerce

---

**Co-Authored-By:** Claude Sonnet 4.5 <noreply@anthropic.com>

[0.1.0]: https://github.com/jgdeamorim/wp_limpvix-core/releases/tag/v0.1.0
