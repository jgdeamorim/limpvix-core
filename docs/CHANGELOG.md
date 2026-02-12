# Changelog

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Documentação enterprise completa e organizada
- Análise profunda linha a linha do codebase (613 arquivos)
- INDEX.md centralizado para navegação

### Changed
- README.md atualizado com seção de Análise Profunda
- Estrutura de documentação reorganizada

### Fixed
- N/A

---

## [1.1.0] - 2026-02-12

### Added
- **Análise Profunda Completa** - 613 arquivos PHP analisados
  - Identificados 193 classes órfãs
  - Mapeados 70 TODOs pendentes
  - Detectadas 31 classes grandes (>500 linhas)
  - Encontrados 18 God Objects (>20 métodos públicos)
- **SUMARIO-EXECUTIVO-ANALISE.md** - Relatório executivo com score 7.5/10
- **ANALISE-PROFUNDA-DETALHADA.md** - Relatório técnico completo
- Token Encryption Service para MercadoPago OAuth tokens
- Pagination em todas as queries do WpMarketplaceProfessionalRepository
- SendOffers Use Case - Matching automático de profissionais

### Changed
- Professional aggregate - adicionados métodos para payout dual-mode
- README.md - adicionada seção "Análise Profunda"

### Fixed
- Menu parent bugs em ContractManagementPage e ProfessionalManagementPage

### Security
- Tokens MercadoPago agora são criptografados (OpenSSL AES-256-CBC)

---

## [1.0.0] - 2026-02-10

### Added
- **GAP #1: Feedback Window System** - Sistema completo de janela de feedback
  - Migration 015 - campos feedback_window_*
  - Execution aggregate methods (startFeedbackWindow, closeFeedbackWindow)
  - CheckFeedbackWindowStatus Use Case
  - FeedbackReminderCronAdapter - cron job para lembretes
  - FeedbackWindowMonitorWidget - widget admin dashboard
  - ProcessTimerExpired - validação de feedback antes de payout

- **Domain Layer:**
  - 8 Aggregates principais (Briefing, Contract, Professional, Execution, Financial, Order, Schedule, Feedback)
  - 47 Interfaces
  - Value Objects imutáveis
  - Domain Events para auditoria

- **Application Layer:**
  - 75 Use Cases implementados
  - DTOs para Request/Response
  - Application Services (BriefingMetricsCalculator, ContractNumberGenerator, etc.)

- **Infrastructure Layer:**
  - WordPress Admin UI (20+ páginas admin)
  - REST API (11 controllers)
  - Repository implementations (18 repositories)
  - Cron Jobs (RecurringPaymentCronAdapter, FeedbackReminderCronAdapter)
  - Event Dispatchers (WordPressEventDispatcher)

- **Integrações Externas:**
  - MercadoPago (Payments + Payouts)
  - ViaCEP (Consulta de endereços)
  - Twilio (planejado - SMS/WhatsApp)
  - Firebase (planejado - Phone Auth)

- **Database:**
  - 30 tabelas criadas
  - 17 migrations SQL
  - Índices otimizados

### Infrastructure
- Docker Compose setup para desenvolvimento local
- WordPress 6.0+ integration
- MySQL 8.0+ database

### Documentation
- README.md principal
- Documentação por módulo (8 módulos)
- Architecture documentation (DDD + Clean Architecture)
- Deployment guides
- Database schema documentation

---

## [0.5.0] - 2026-01-20 (Alpha)

### Added
- Protótipo inicial do plugin
- Estrutura básica de Briefing
- CRUD de profissionais
- Integração básica com MercadoPago

---

## Tipos de Mudanças

- **Added** - para novas funcionalidades
- **Changed** - para mudanças em funcionalidades existentes
- **Deprecated** - para funcionalidades que serão removidas
- **Removed** - para funcionalidades removidas
- **Fixed** - para correções de bugs
- **Security** - para vulnerabilidades de segurança corrigidas

---

## Versionamento Semântico

**MAJOR.MINOR.PATCH**

- **MAJOR** (1.x.x): Breaking changes - incompatível com versão anterior
- **MINOR** (x.1.x): Novas features - backward compatible
- **PATCH** (x.x.1): Bug fixes - backward compatible

---

**Versão Atual:** 1.1.0
**Última Atualização:** 2026-02-12
