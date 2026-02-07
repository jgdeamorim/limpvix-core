# 📚 ÍNDICE DE DOCUMENTAÇÃO - LIMPVIX CORE

**Versão:** 0.1.0
**Última Atualização:** 2026-02-07

---

## 🎯 DOCUMENTAÇÃO DE GO LIVE (Prioridade Máxima)

### 1. **Executive Summary**
**Arquivo:** `/docs-limpvix/EXECUTIVE_SUMMARY_GO_LIVE.md`
**Público:** Stakeholders, Product Owners, Tech Leads
**Resumo:** Visão geral do status atual (65%), plano de 7 dias, riscos e ROI esperado

**Leia primeiro se você quer:**
- Entender rapidamente onde estamos
- Ver timeline para produção
- Conhecer riscos e mitigações
- Validar critérios de sucesso

---

### 2. **Roadmap Detalhado para Go Live**
**Arquivo:** `/docs-limpvix/ROADMAP_GO_LIVE_DETALHADO.md`
**Público:** Desenvolvedores, QA, DevOps
**Resumo:** Plano operacional completo com 4 sprints, tasks detalhadas, validações e checklists

**Leia se você vai:**
- Executar as tarefas de desenvolvimento
- Validar cada entrega (critérios de aceite)
- Fazer deploy em produção
- Monitorar pós-Go Live

**Estrutura:**
- Sprint 0: Correções Críticas (1 dia)
- Sprint 1: Fluxo End-to-End (2 dias)
- Sprint 2: Hardening + Observabilidade (2 dias)
- Sprint 3: Reconciliação + Otimizações (1 dia)
- Sprint 4: Go Live + Monitoramento (1 dia)

---

### 3. **Arquitetura Técnica Detalhada**
**Arquivo:** `/docs-limpvix/ARQUITETURA_TECNICA_DETALHADA.md`
**Público:** Arquitetos, Tech Leads, Desenvolvedores Seniores
**Resumo:** Diagramas de fluxo, modelo de dados, segurança, performance, testes e deployment

**Leia se você precisa:**
- Entender fluxos técnicos (Appointment → Order → Payment → Payout)
- Conhecer schema do banco de dados
- Implementar novas features mantendo arquitetura
- Debugar problemas complexos
- Fazer code review

**Conteúdo:**
- Visão geral da arquitetura (DDD + Clean Architecture)
- 4 fluxos técnicos críticos com diagramas
- Modelo de dados completo (5 tabelas)
- Estratégias de segurança
- Otimizações de performance
- Estratégia de testes (unit, integration, e2e)
- Scripts de deploy e rollback

---

### 4. **Análise Crítica: Dependências Booknetic**
**Arquivo:** `/docs-limpvix/ANALISE_CRITICA_BOOKNETIC.md`
**Público:** Tech Leads, Product Owners
**Resumo:** Auditoria completa revelando que migration crítica estava faltante (agora corrigida)

**Leia se você quer:**
- Entender o que realmente funciona hoje
- Ver scorecard de prontidão (43% → 82% após correção)
- Conhecer gaps para Go Live
- Entender dependências do Booknetic

**Descoberta Crítica:**
- Tabela `wp_limpvix_appointment_order_map` não estava sendo criada
- Bug corrigido em commit `2dfc4bb`
- Sistema passou de 43% para 65% de prontidão após reativação

---

## 🛠️ FERRAMENTAS E SCRIPTS

### Script de Auditoria do Sistema
**Arquivo:** `/wp-content/plugins/limpvix-core/audit-system.php`
**Uso:** `docker exec limpvix_wordpress php /var/www/html/wp-content/plugins/limpvix-core/audit-system.php`

**O que faz:**
- ✅ Verifica existência de tabelas no banco
- ✅ Valida integrações externas (Twilio, Firebase, Mercado Pago, etc)
- ✅ Lista classes críticas (Domain, Application, Infrastructure)
- ✅ Verifica hooks Booknetic registrados
- ✅ Mostra estatísticas (orders, ledger, appointments)
- ✅ Calcula scorecard de prontidão em tempo real
- ✅ Identifica gaps críticos automaticamente

**Quando executar:**
- Após ativar/desativar plugin
- Antes de cada deploy
- Durante troubleshooting
- Para validar estado do sistema

---

## 📖 MEMÓRIA DO PROJETO

### MEMORY.md
**Arquivo:** `/.claude/projects/.../memory/MEMORY.md`
**Público:** Desenvolvedores, Claude Code
**Resumo:** Regras fundamentais, comandos úteis, estrutura do projeto, erros comuns

**Conteúdo:**
- ⚠️ REGRA DE OURO: sempre trabalhar no diretório montado pelo Docker
- Estrutura do projeto
- Comandos úteis (logs, validação PHP, permissões)
- Ambientes (DEV vs PROD)
- Git workflow e semantic commits
- Erros comuns e soluções

---

## 🔄 FLUXO DE LEITURA RECOMENDADO

### Para começar agora (Sprint 0):
1. **Executive Summary** → entender status geral
2. **Roadmap → Sprint 0** → tarefas imediatas
3. **Executar audit-system.php** → validar estado atual
4. **Implementar Task 0.1, 0.2, 0.3** → corrigir bloqueadores

### Para entender arquitetura:
1. **Arquitetura Técnica** → seções "Visão Geral" e "Fluxos Técnicos"
2. **Modelo de Dados** → schema das tabelas
3. **Análise Booknetic** → entender integração

### Para validar produção:
1. **Roadmap → Checklist Pré-Go Live**
2. **Arquitetura → Estratégia de Testes**
3. **Roadmap → Sprint 4** → smoke tests e monitoramento

---

## 🚀 PRÓXIMOS PASSOS IMEDIATOS

```bash
# 1. Executar auditoria
docker exec limpvix_wordpress php /var/www/html/wp-content/plugins/limpvix-core/audit-system.php

# 2. Ler Executive Summary
cat /media/jeffer/.../docs-limpvix/EXECUTIVE_SUMMARY_GO_LIVE.md

# 3. Ler Roadmap Sprint 0
cat /media/jeffer/.../docs-limpvix/ROADMAP_GO_LIVE_DETALHADO.md | head -300

# 4. Iniciar desenvolvimento
cd /media/jeffer/.../wp-content/plugins/limpvix-core
# Seguir tasks do Sprint 0
```

---

## 📞 ACESSO RÁPIDO

### Admin WordPress
- **Configurações:** `http://localhost/wp-admin/admin.php?page=limpvix-settings`
- **Aba Geral:** `?page=limpvix-settings&tab=geral`
- **Aba Dependências:** `?page=limpvix-settings&tab=dependencias`
- **Aba Monitoramento:** `?page=limpvix-settings&tab=monitoring` (disponível após Sprint 2)

### Logs
```bash
# Logs do WordPress (debug.log)
docker exec limpvix_wordpress tail -f /var/www/html/wp-content/debug.log | grep LIMPVIX

# Logs do container
docker logs limpvix_wordpress -f

# Últimos 100 eventos do Ledger
docker exec limpvix_wordpress mysql -u root -proot wordpress -e "
SELECT * FROM wp_limpvix_ledger ORDER BY occurred_at DESC LIMIT 100
"
```

### Banco de Dados
```bash
# Conectar ao MySQL
docker exec -it limpvix_wordpress mysql -u root -proot wordpress

# Verificar tabelas LimpVix
SHOW TABLES LIKE 'wp_limpvix%';

# Ver últimas orders
SELECT * FROM wp_limpvix_orders ORDER BY created_at DESC LIMIT 10;
```

---

## 🎯 METAS E SCORECARD

**Meta Go Live:** 98/100
**Status Atual:** 65/100
**Gap:** -33 pontos
**Timeline:** 7 dias (4 sprints)

**Componentes com maior gap:**
1. Monitoring: 0% → 90% (Sprint 2)
2. Error Handling: 20% → 90% (Sprint 2)
3. External Integrations: 40% → 100% (Sprint 0)
4. Financial Flow: 60% → 100% (Sprint 1)
5. Communication Auto: 60% → 100% (Sprint 1)

---

**CONCLUSÃO:**

Toda documentação necessária para Go Live está disponível. Siga a ordem recomendada de leitura e execute as tasks conforme roadmap.

**Data Estimada Go Live:** 14/02/2026

---

**Preparado por:** Claude Sonnet 4.5 + Jeffer G. de Amorim
**Data:** 2026-02-07
