# LimpVix Core Plugin - Documentação Enterprise

**Versão:** 1.1.0
**Data:** 2026-02-12
**Status:** Production Ready (Conditional)
**Análise:** ✅ Completa (Score: 7.5/10)

---

## 📚 Navegação Rápida

### 🎯 Começar Aqui

1. **[README.md](./README.md)** - Visão geral do projeto
2. **[SUMARIO-EXECUTIVO-ANALISE.md](./SUMARIO-EXECUTIVO-ANALISE.md)** - Análise profunda e principais achados
3. **[CHANGELOG.md](./CHANGELOG.md)** - Histórico de mudanças versionadas
4. **[CONTRIBUTING.md](./CONTRIBUTING.md)** - Guia de contribuição

---

## 🏗️ Documentação por Camada

### Domain Layer (Camada de Domínio)

Documentação dos aggregates, entities, value objects e domain events:

- **[domain/BRIEFING.md](./domain/BRIEFING.md)** - Módulo de Briefing (Coleta de informações)
- **[domain/PROFESSIONAL.md](./domain/PROFESSIONAL.md)** - Módulo de Profissionais
- **[domain/CONTRACT.md](./domain/CONTRACT.md)** - Módulo de Contratos Recorrentes
- **[domain/EXECUTION.md](./domain/EXECUTION.md)** - Módulo de Execuções de Serviço
- **[domain/FINANCE.md](./domain/FINANCE.md)** - Módulo Financeiro (Payouts, Ledger)
- **[domain/FEEDBACK.md](./domain/FEEDBACK.md)** - Módulo de Avaliações Estruturadas
- **[domain/COMMUNICATION.md](./domain/COMMUNICATION.md)** - Módulo de Comunicação (SMS, Email, WhatsApp)
- **[domain/SCHEDULING.md](./domain/SCHEDULING.md)** - Módulo de Agendamento e Alocação

### Application Layer (Camada de Aplicação)

Documentação dos use cases, DTOs e services:

- **[application/USE_CASES.md](./application/USE_CASES.md)** - Catálogo de todos os 75 Use Cases
- **[application/DTOS.md](./application/DTOS.md)** - Data Transfer Objects (Request/Response)
- **[application/SERVICES.md](./application/SERVICES.md)** - Application Services e Helpers

### Infrastructure Layer (Camada de Infraestrutura)

Documentação da integração com WordPress e serviços externos:

- **[infrastructure/REPOSITORIES.md](./infrastructure/REPOSITORIES.md)** - Implementações de Repository Pattern
- **[infrastructure/ADMIN_UI.md](./infrastructure/ADMIN_UI.md)** - Admin Pages e WordPress Integration
- **[infrastructure/REST_API.md](./infrastructure/REST_API.md)** - REST API Endpoints
- **[infrastructure/CRON_JOBS.md](./infrastructure/CRON_JOBS.md)** - Background Jobs e Automation
- **[infrastructure/INTEGRATIONS.md](./infrastructure/INTEGRATIONS.md)** - MercadoPago, Twilio, ViaCEP

---

## 🗄️ Database & Migrations

- **[database/SCHEMA.md](./database/SCHEMA.md)** - Esquema completo do banco (30 tabelas)
- **[database/MIGRATIONS_GUIDE.md](./database/MIGRATIONS_GUIDE.md)** - Como criar e executar migrations
- **[database/DATA_DICTIONARY.md](./database/DATA_DICTIONARY.md)** - Dicionário de dados linha a linha

---

## 🏛️ Arquitetura

- **[architecture/OVERVIEW.md](./architecture/OVERVIEW.md)** - Visão geral da arquitetura (DDD + Clean Architecture)
- **[architecture/BOUNDED_CONTEXTS.md](./architecture/BOUNDED_CONTEXTS.md)** - Bounded Contexts e integração entre módulos
- **[architecture/PATTERNS.md](./architecture/PATTERNS.md)** - Design Patterns utilizados
- **[architecture/ADRs/](./architecture/ADRs/)** - Architecture Decision Records

---

## 🚀 Deployment & DevOps

- **[deployment/PRODUCTION_DEPLOYMENT.md](./deployment/PRODUCTION_DEPLOYMENT.md)** - Guia de deploy em produção
- **[deployment/GO_LIVE_CHECKLIST.md](./deployment/GO_LIVE_CHECKLIST.md)** - Checklist de go-live
- **[deployment/ENVIRONMENT_SETUP.md](./deployment/ENVIRONMENT_SETUP.md)** - Setup de ambientes (local, staging, prod)
- **[deployment/BACKUP_RECOVERY.md](./deployment/BACKUP_RECOVERY.md)** - Estratégias de backup e recovery

---

## 🛠️ Development

- **[development/SETUP.md](./development/SETUP.md)** - Setup local com Docker
- **[development/CODING_STANDARDS.md](./development/CODING_STANDARDS.md)** - PSR-12, WordPress Coding Standards
- **[development/TESTING.md](./development/TESTING.md)** - Guia de testes (Unit, Integration, E2E)
- **[development/CREATE_USE_CASE.md](./development/CREATE_USE_CASE.md)** - Como criar um novo Use Case
- **[development/CREATE_AGGREGATE.md](./development/CREATE_AGGREGATE.md)** - Como criar um novo Aggregate
- **[development/DEBUGGING.md](./development/DEBUGGING.md)** - Debugging e troubleshooting

---

## 📊 API Reference

- **[api/REST_API_REFERENCE.md](./api/REST_API_REFERENCE.md)** - Referência completa da REST API
- **[api/AUTHENTICATION.md](./api/AUTHENTICATION.md)** - Autenticação e autorização
- **[api/WEBHOOKS.md](./api/WEBHOOKS.md)** - Webhooks (MercadoPago, etc.)
- **[api/POSTMAN_COLLECTION.md](./api/POSTMAN_COLLECTION.md)** - Coleção Postman para testes

---

## 🔍 Análise & Qualidade

- **[ANALISE-PROFUNDA-DETALHADA.md](./ANALISE-PROFUNDA-DETALHADA.md)** - Relatório técnico completo
- **[quality/CODE_METRICS.md](./quality/CODE_METRICS.md)** - Métricas de código
- **[quality/TECHNICAL_DEBT.md](./quality/TECHNICAL_DEBT.md)** - Dívida técnica mapeada
- **[quality/REFACTORING_PLAN.md](./quality/REFACTORING_PLAN.md)** - Plano de refatoração

---

## 📖 Glossário & Referências

- **[GLOSSARY.md](./GLOSSARY.md)** - Glossário de termos técnicos e de negócio
- **[REFERENCES.md](./REFERENCES.md)** - Referências externas (DDD, Clean Architecture, WordPress)
- **[FAQ.md](./FAQ.md)** - Perguntas frequentes

---

## 🎯 Roadmap

- **[ROADMAP.md](./ROADMAP.md)** - Roadmap de features e melhorias
- **[PENDING_GAPS.md](./PENDING_GAPS.md)** - Gaps pendentes (P0, P1, P2)

---

## 📄 Templates

- **[templates/USE_CASE_TEMPLATE.md](./templates/USE_CASE_TEMPLATE.md)**
- **[templates/AGGREGATE_TEMPLATE.md](./templates/AGGREGATE_TEMPLATE.md)**
- **[templates/MIGRATION_TEMPLATE.sql](./templates/MIGRATION_TEMPLATE.sql)**
- **[templates/ADR_TEMPLATE.md](./templates/ADR_TEMPLATE.md)**

---

## 🔐 Security & Compliance

- **[security/SECURITY_POLICY.md](./security/SECURITY_POLICY.md)**
- **[security/VULNERABILITIES.md](./security/VULNERABILITIES.md)**
- **[security/LGPD_COMPLIANCE.md](./security/LGPD_COMPLIANCE.md)**

---

## 📝 Versionamento

Este projeto segue **Semantic Versioning 2.0.0** (https://semver.org/)

**Formato:** MAJOR.MINOR.PATCH

- **MAJOR:** Breaking changes (incompatibilidade com versão anterior)
- **MINOR:** Novas features (backward compatible)
- **PATCH:** Bug fixes (backward compatible)

**Versão Atual:** 1.1.0

**Histórico de Versões:** Ver [CHANGELOG.md](./CHANGELOG.md)

---

## 🤝 Contribuindo

Leia [CONTRIBUTING.md](./CONTRIBUTING.md) para entender:
- Como criar branches
- Convenção de commits (Conventional Commits)
- Como abrir Pull Requests
- Code review process

---

## 📞 Suporte

- **Issues:** Reporte bugs e sugira features via GitHub Issues
- **Email:** dev@limpvix.com
- **Documentação Online:** https://docs.limpvix.com (em breve)

---

**Última Atualização:** 2026-02-12
**Mantido por:** LimpVix Engineering Team
**Licença:** Proprietário
