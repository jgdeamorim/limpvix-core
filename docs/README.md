# LimpVix Core Plugin - Documentação Técnica

**Versão:** 1.1
**Data:** 2026-02-12
**Status:** Production Ready (Conditional Go-Live) + Análise Profunda Completa ✅

---

## 📋 Índice

1. [Visão Geral](#visão-geral)
2. [Análise Profunda](#análise-profunda-) ⭐ **NOVO**
3. [Arquitetura](#arquitetura)
4. [Módulos do Sistema](#módulos-do-sistema)
5. [Estrutura de Diretórios](#estrutura-de-diretórios)
6. [Guias de Desenvolvimento](#guias-de-desenvolvimento)
7. [Guias de Deployment](#guias-de-deployment)

---

## 🎯 Visão Geral

**LimpVix Core** é um plugin WordPress enterprise que gerencia um marketplace de serviços de limpeza profissional. Implementa Domain-Driven Design (DDD) e Clean Architecture para garantir manutenibilidade, escalabilidade e testabilidade.

### Estatísticas do Código

- **Total de Arquivos:** 483 arquivos
- **Código PHP:** 425 arquivos
- **Tamanho Total:** 3.94 MB
- **Diretórios:** 135
- **Migrations:** 17 SQL files
- **Tabelas no Banco:** 30 tabelas

### Características Principais

✅ **Domain-Driven Design (DDD)**
- Agregados bem definidos (Professional, Contract, Briefing, etc.)
- Value Objects imutáveis
- Domain Events para auditoria
- Repository pattern para persistência

✅ **Clean Architecture**
- Separação clara de camadas (Domain, Application, Infrastructure)
- Inversão de dependências
- Use Cases como ponto de entrada da aplicação
- Infrastructure desacoplada do Domain

✅ **WordPress Integration**
- Admin UI moderno e responsivo
- REST API completa
- Cron Jobs para processamento assíncrono
- WP_List_Table para listagens

✅ **Integrações Externas**
- MercadoPago (Pagamentos e Payouts)
- Twilio (SMS e WhatsApp - planejado)
- Firebase (Phone Authentication - planejado)
- ViaCEP (Consulta de endereços)

---

## 🔍 Análise Profunda ⭐

**NOVA SEÇÃO - Análise Completa Realizada em 2026-02-12**

Realizamos uma análise profunda linha a linha de todo o plugin identificando gaps, código órfão, dívida técnica e áreas críticas para refinamento.

### 📊 Resultados da Análise

- **613 arquivos PHP** analisados
- **528 classes** identificadas
- **75 Use Cases** mapeados
- **8 Aggregates** documentados
- **193 classes órfãs** detectadas
- **70 TODOs** pendentes
- **31 classes grandes** (>500 linhas)
- **18 God Objects** (>20 métodos públicos)

### 📚 Documentos de Análise

1. **[SUMARIO-EXECUTIVO-ANALISE.md](./SUMARIO-EXECUTIVO-ANALISE.md)** ⭐ **LEIA PRIMEIRO**
   - Visão geral dos principais achados
   - Recomendações por prioridade (P0, P1, P2, P3)
   - Insights estratégicos
   - Score geral: 7.5/10

2. **[ANALISE-PROFUNDA-DETALHADA.md](./ANALISE-PROFUNDA-DETALHADA.md)**
   - Relatório técnico completo
   - Lista detalhada de todos gaps
   - Código órfão classe por classe
   - Mapa de dependências
   - TODOs e FIXMEs pendentes

### 🎯 Principais Achados

**✅ Pontos Fortes:**
- Arquitetura DDD/Clean Architecture muito bem implementada
- Nenhum repository sem implementação (0 gaps críticos!)
- Aggregates bem definidos
- Separation of concerns respeitada

**⚠️ Áreas de Atenção:**
- **AdminBootstrap** com 3.218 linhas - precisa refatoração urgente (P1)
- **Professional aggregate** com 781 linhas e 82 métodos - sobrecarregado (P1)
- **193 classes órfãs** - precisam classificação (dead code vs não integrado) (P1)
- **31 classes grandes** - violam Single Responsibility Principle (P2)

### 📈 Próximos Passos Recomendados

**SPRINT ATUAL:**
1. ✅ Análise profunda - COMPLETA
2. 🔄 Documentação técnica detalhada - EM PROGRESSO
3. 📋 Criar plano de refatoração AdminBootstrap

**SPRINT PRÓXIMO:**
1. Refatorar AdminBootstrap (dividir em 4-5 classes menores)
2. Limpar código órfão (top 20 classes)
3. Resolver TODOs críticos

**MÉDIO PRAZO (2-4 semanas):**
1. Refatorar Professional aggregate
2. Aumentar coverage de testes para 40%+
3. Resolver classes deprecated (15 classes)

---

## 🏗️ Arquitetura

### Camadas da Aplicação

```
┌──────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                     │
│  (WordPress Admin UI + REST API + Cron Adapters)         │
└───────────────────────┬──────────────────────────────────┘
                        │
┌───────────────────────▼──────────────────────────────────┐
│                   APPLICATION LAYER                       │
│         (Use Cases - Orchestrate business logic)          │
└───────────────────────┬──────────────────────────────────┘
                        │
┌───────────────────────▼──────────────────────────────────┐
│                     DOMAIN LAYER                          │
│    (Aggregates, Entities, Value Objects, Events)         │
└───────────────────────┬──────────────────────────────────┘
                        │
┌───────────────────────▼──────────────────────────────────┐
│                 INFRASTRUCTURE LAYER                      │
│   (Repositories, External Services, Event Dispatchers)    │
└──────────────────────────────────────────────────────────┘
```

### Fluxo de Dados

```
User Request (Admin UI or REST API)
        ↓
WordPress Controller/Page
        ↓
Use Case (Application Layer)
        ↓
Domain Objects (Business Logic)
        ↓
Repository (Infrastructure Layer)
        ↓
WordPress Database (wpdb)
```

Veja detalhes em: [architecture/OVERVIEW.md](architecture/OVERVIEW.md)

---

## 📦 Módulos do Sistema

O sistema é dividido em módulos bounded contexts segundo DDD:

### 1. **Briefing Module** 🎯
Gerencia o processo de coleta de informações do cliente.

- **Aggregates:** Briefing, BriefingSnapshot
- **Use Cases:** CreateBriefing, LockBriefing, VerifyPhone
- **Admin:** BriefingManagementPage, BriefingDetailPage
- **API:** BriefingController, BriefingStepController

📖 [domain/BRIEFING.md](domain/BRIEFING.md)

### 2. **Professional Module** 👷
Gerencia profissionais, suas habilidades e disponibilidade.

- **Aggregates:** Professional
- **Use Cases:** RegisterProfessional, UpdateAvailability, ProcessKYC
- **Admin:** ProfessionalManagementPage
- **API:** ProfessionalController

📖 [domain/PROFESSIONAL.md](domain/PROFESSIONAL.md)

### 3. **Contract Module** 📋
Gerencia contratos recorrentes (semanal, quinzenal, mensal).

- **Aggregates:** Contract, ContractExecution
- **Use Cases:** CreateContract, ActivateContract, SendOffers
- **Admin:** ContractManagementPage
- **API:** ContractController

📖 [domain/CONTRACT.md](domain/CONTRACT.md)

### 4. **Execution Module** ⚙️
Gerencia execuções de serviços (check-in, check-out, feedback).

- **Aggregates:** Execution
- **Use Cases:** StartExecution, CompleteExecution, MarkNoShow
- **Admin:** Integrated in Contract pages
- **API:** ExecutionController

📖 [domain/EXECUTION.md](domain/EXECUTION.md)

### 5. **Finance Module** 💰
Gerencia pagamentos, payouts e ledger financeiro.

- **Aggregates:** Financial, RecurringPayment
- **Use Cases:** ProcessRecurringPayment, ProcessPendingPayouts
- **Admin:** PayoutsPage
- **API:** N/A (backend only)

📖 [domain/FINANCE.md](domain/FINANCE.md)

### 6. **Feedback Module** ⭐
Gerencia avaliações estruturadas de clientes.

- **Aggregates:** StructuredFeedback
- **Use Cases:** SubmitFeedback, DisputeFeedback
- **Admin:** FeedbackManagementPage
- **API:** N/A (integrated in Execution)

📖 [domain/FEEDBACK.md](domain/FEEDBACK.md)

### 7. **Communication Module** 📨
Gerencia envio de mensagens (email, SMS, WhatsApp, push).

- **Aggregates:** MessageDelivery, MessageTemplate
- **Use Cases:** SendMessage, ProcessQueue
- **Admin:** CommunicationCenterPage, MessageTemplatesPage
- **API:** N/A (backend only)

📖 [domain/COMMUNICATION.md](domain/COMMUNICATION.md)

### 8. **Scheduling Module** 📅
Gerencia agendamentos de serviços e alocação de profissionais.

- **Aggregates:** Schedule
- **Use Cases:** CreateSchedule, AllocateProfessional
- **Admin:** ScheduleManagementPage
- **API:** N/A (integrated)

📖 [domain/SCHEDULING.md](domain/SCHEDULING.md)

---

## 📁 Estrutura de Diretórios

```
limpvix-core/
├── limpvix-core.php              # Plugin principal
├── composer.json                  # Dependências PHP
├── database-migrations/           # Migrations SQL
│   ├── 001_create_orders_table.sql
│   ├── ...
│   └── 020_add_kyc_fields.sql
├── src/
│   ├── Core/                      # Bootstrap e configuração
│   │   ├── Plugin.php
│   │   └── Hooks.php
│   ├── Domain/                    # Camada de Domínio
│   │   ├── Briefing/
│   │   ├── Professional/
│   │   ├── Contract/
│   │   ├── Execution/
│   │   ├── Finance/
│   │   ├── Feedback/
│   │   ├── Communication/
│   │   └── Scheduling/
│   ├── Application/               # Camada de Aplicação
│   │   └── UseCase/
│   │       ├── Briefing/
│   │       ├── Professional/
│   │       ├── Contract/
│   │       └── Execution/
│   └── Infrastructure/            # Camada de Infraestrutura
│       ├── Persistence/           # Repositories
│       ├── Admin/                 # WordPress Admin UI
│       │   ├── Pages/
│       │   └── Tables/
│       ├── API/                   # REST API Controllers
│       ├── Cron/                  # Cron Job Adapters
│       ├── Events/                # Event Dispatchers
│       └── Finance/               # Payment Providers
│           └── Providers/
├── assets/                        # CSS, JS, Images
│   ├── css/
│   ├── js/
│   └── images/
└── tests/                         # Testes automatizados
    ├── Domain/
    ├── Application/
    └── Infrastructure/
```

---

## 🛠️ Guias de Desenvolvimento

### Pré-requisitos

- PHP 8.0+
- WordPress 6.0+
- MySQL 8.0+
- Composer
- Docker (para ambiente local)

### Setup Local

```bash
# 1. Clone o repositório
git clone <repo-url>

# 2. Subir containers Docker
docker-compose up -d

# 3. Instalar dependências
composer install

# 4. Executar migrations
docker exec limpvix_wordpress_clean php /var/www/html/wp-content/plugins/limpvix-core/database-migrations/run-all-migrations.php
```

### Criando um Novo Use Case

Veja: [development/CREATE_USE_CASE.md](development/CREATE_USE_CASE.md)

### Criando um Novo Aggregate

Veja: [development/CREATE_AGGREGATE.md](development/CREATE_AGGREGATE.md)

### Adicionando uma Nova Migration

Veja: [database/MIGRATIONS_GUIDE.md](database/MIGRATIONS_GUIDE.md)

---

## 🚀 Guias de Deployment

### Production Deployment

Veja: [deployment/PRODUCTION_DEPLOYMENT.md](deployment/PRODUCTION_DEPLOYMENT.md)

### Go-Live Checklist

Veja: [deployment/GO_LIVE_CHECKLIST.md](deployment/GO_LIVE_CHECKLIST.md)

---

## 📊 Status do Projeto

### Módulos Implementados

| Módulo | Domain | Application | Infrastructure | Status |
|--------|--------|-------------|----------------|--------|
| Briefing | ✅ 21 files | ⚠️ 1 use case | ✅ Complete | 80% |
| Professional | ✅ 2 files | ✅ 11 use cases | ✅ Complete | 95% |
| Contract | ✅ 3 files | ✅ 12 use cases | ✅ Complete | 90% |
| Execution | ✅ 6 files | ✅ 9 use cases | ✅ Complete | 85% |
| Finance | ✅ 10 files | ⚠️ Partial | ⚠️ Partial | 70% |
| Feedback | ✅ 6 files | ⚠️ Partial | ✅ Complete | 75% |
| Communication | ✅ 6 files | ⚠️ Partial | ✅ Complete | 70% |
| Scheduling | ✅ 2 files | ⚠️ Missing | ✅ Complete | 60% |

### Gaps Críticos (P0)

Veja: [PENDING_GAPS.md](PENDING_GAPS.md)

---

## 📞 Suporte

Para questões técnicas ou reportar bugs, consulte:
- [GitHub Issues](https://github.com/limpvix/limpvix-core/issues)
- [Development Wiki](https://wiki.limpvix.com)
- Email: dev@limpvix.com

---

**Última Atualização:** 2026-02-11
**Mantido por:** LimpVix Engineering Team
