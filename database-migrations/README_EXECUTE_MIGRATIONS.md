# 🚀 Como Executar as Migrations dos GAPs

**Data:** 2026-02-16
**Migrations Pendentes:** 023, 024, 025 (GAPs A, C, D)

---

## 📋 Opções de Execução

### ✅ OPÇÃO 1: Executor Consolidado (RECOMENDADO)

Execute **todas as 3 migrations** de uma vez via navegador:

**URL:**
```
http://seu-site.local/wp-content/plugins/limpvix-core/database-migrations/execute-all-gaps-migrations.php
```

**Features:**
- ✅ Executa migrations 023, 024 e 025 automaticamente
- ✅ Exibe resultados detalhados de cada migration
- ✅ Verifica criação de tabelas e colunas
- ✅ Mostra serviços com skills populados (GAP D)
- ✅ Resumo final com estatísticas
- ✅ Interface visual clara e organizada

**Requer:**
- Usuário logado como administrador WordPress
- Permissão `manage_options`

---

### ⚙️ OPÇÃO 2: Executores Individuais

Execute cada migration separadamente:

#### Migration 023: Professional Documents Table (GAP A)
```
http://seu-site.local/wp-content/plugins/limpvix-core/database-migrations/execute-migration-023.php
```

**O que faz:**
- Cria tabela `wp_limpvix_professional_documents`
- 14 colunas: id, professional_id, document_type, file_path, status, etc.
- Índices em professional_id, status, document_type

#### Migration 024: Manual Payout Fields (GAP C)
```
http://seu-site.local/wp-content/plugins/limpvix-core/database-migrations/execute-migration-024.php
```

**O que faz:**
- Adiciona 6 colunas em `wp_limpvix_payouts`: is_manual, manual_reason, created_by, approved_by, etc.
- Adiciona status 'manual_pending' ao ENUM
- Cria tabela `wp_limpvix_payout_audit_trail` (audit log)

#### Migration 025: Service Catalog Required Skills (GAP D)
```
http://seu-site.local/wp-content/plugins/limpvix-core/database-migrations/execute-migration-025.php
```

**O que faz:**
- Adiciona coluna `required_skills` (JSON) em `wp_limpvix_service_catalog`
- Popula 6 serviços existentes com skills corretos
- Cria índice JSON para performance

---

## 🔍 Verificação Manual (via phpMyAdmin ou MySQL)

Se preferir executar via SQL direto:

### 1. Migration 023

```sql
-- Execute conteúdo de: 023_create_professional_documents_table.sql
SOURCE /path/to/023_create_professional_documents_table.sql;

-- Verificar tabela criada
SHOW TABLES LIKE '%professional_documents%';

-- Ver estrutura
DESCRIBE wp_limpvix_professional_documents;
```

### 2. Migration 024

```sql
-- Execute conteúdo de: 024_add_manual_payout_fields.sql
SOURCE /path/to/024_add_manual_payout_fields.sql;

-- Verificar colunas adicionadas em payouts
SHOW COLUMNS FROM wp_limpvix_payouts WHERE Field IN ('is_manual', 'manual_reason', 'created_by', 'approved_by');

-- Verificar tabela de audit trail
SHOW TABLES LIKE '%payout_audit_trail%';
DESCRIBE wp_limpvix_payout_audit_trail;
```

### 3. Migration 025

```sql
-- Execute conteúdo de: 025_add_service_catalog_required_skills.sql
SOURCE /path/to/025_add_service_catalog_required_skills.sql;

-- Verificar coluna adicionada
SHOW COLUMNS FROM wp_limpvix_service_catalog WHERE Field = 'required_skills';

-- Ver serviços com skills populados
SELECT service_code, display_name, required_skills
FROM wp_limpvix_service_catalog
ORDER BY service_code;
```

---

## ✅ Checklist de Verificação

Após executar as migrations, verificar:

### Migration 023 ✅
- [ ] Tabela `wp_limpvix_professional_documents` existe
- [ ] Possui 14 colunas
- [ ] Índices criados (professional_id, status, document_type)

### Migration 024 ✅
- [ ] Tabela `wp_limpvix_payouts` possui colunas: is_manual, manual_reason, created_by, approved_by, approved_manually_at, requires_approval
- [ ] Status 'manual_pending' existe no ENUM
- [ ] Tabela `wp_limpvix_payout_audit_trail` existe
- [ ] Audit trail possui colunas: payout_id, action, performed_by, reason, metadata

### Migration 025 ✅
- [ ] Coluna `required_skills` existe em `wp_limpvix_service_catalog`
- [ ] Tipo da coluna é JSON
- [ ] 6 serviços possuem skills populados (residential_standard, residential_pre_move, etc.)
- [ ] Índice JSON criado

---

## 🚨 Erros Comuns e Soluções

### Erro: "Table already exists"
**Solução:** Tabela já foi criada em execução anterior. Pode ignorar ou usar `DROP TABLE IF EXISTS` antes de re-executar.

### Erro: "Duplicate column name"
**Solução:** Coluna já existe. Migration já foi executada anteriormente. Seguro ignorar.

### Erro: "Access denied"
**Solução:** Usuário MySQL precisa de permissões ALTER, CREATE, INSERT.

### Erro: "Can't DROP 'status'; check that column/key exists"
**Solução:** ENUM status já foi modificado. Seguro ignorar.

---

## 📊 Impacto das Migrations

### Migration 023 (GAP A - Document Upload)
**Impacto:** ~2 KB por documento upload
**Uso Esperado:** 100 profissionais × 5 docs = 500 registros (~1 MB)

### Migration 024 (GAP C - ManualPayout)
**Impacto:** +6 colunas em payouts, nova tabela audit_trail
**Uso Esperado:** ~5% dos payouts serão manuais (~50/mês), audit trail ~200 registros/mês

### Migration 025 (GAP D - Service Catalog)
**Impacto:** +1 coluna JSON, ~500 bytes por serviço
**Uso Esperado:** 10-20 serviços ativos (~10 KB)

---

## 🔗 Arquivos Relacionados

**SQL Files:**
- `023_create_professional_documents_table.sql` (2.4 KB)
- `024_add_manual_payout_fields.sql` (2.6 KB)
- `025_add_service_catalog_required_skills.sql` (2.1 KB)

**Executors:**
- `execute-all-gaps-migrations.php` (executor consolidado - RECOMENDADO)
- `execute-migration-023.php` (individual)
- `execute-migration-024.php` (individual)
- `execute-migration-025.php` (individual)

**Documentação:**
- `GAP_A_DOCUMENT_UPLOAD_IMPLEMENTATION.md`
- `GAP_C_MANUAL_PAYOUT_IMPLEMENTATION.md`
- `GAP_D_SERVICE_CATALOG_MAPPING_IMPLEMENTATION.md`

---

## 📞 Suporte

Se encontrar problemas ao executar as migrations:

1. Verificar logs de erro do WordPress (`wp-content/debug.log`)
2. Verificar logs de erro do MySQL
3. Verificar permissões do usuário MySQL
4. Revisar documentação de cada GAP para contexto

---

**Última Atualização:** 2026-02-16
**Autor:** LimpVix Development Team
