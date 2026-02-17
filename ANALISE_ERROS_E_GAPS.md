# 🔍 Análise de Erros e Novos GAPS Identificados

**Data:** 2026-02-16
**Contexto:** Após execução das migrations e navegação no admin

---

## 🔴 PROBLEMA 1: Migration 025 - Erro de Sintaxe SQL

### Erro Reportado
```
Migration query failed: UPDATE wp_limpvix_service_catalog
SET required_skills = JSON_ARRAY('limpeza_residencial', 'limpeza_pesada')
WHERE service_code = 'residential_pre_move' AND required_skills IS NULL

Error: Unknown column 'required_skills' in 'where clause'
```

### Causa Raiz
**Arquivo:** `database-migrations/025_add_service_catalog_required_skills.sql`

**Problemas identificados:**

1. **Linha 8:** Sintaxe `ADD COLUMN IF NOT EXISTS` não funciona em MySQL < 8.0.29
   ```sql
   ADD COLUMN IF NOT EXISTS required_skills JSON NULL
   ```

   Se a coluna já existe, o MySQL 5.7 lança erro. Se não executou, as linhas seguintes falham.

2. **Linha 13:** Sintaxe de índice JSON incompatível
   ```sql
   ADD INDEX IF NOT EXISTS idx_required_skills ((CAST(required_skills AS CHAR(255) ARRAY)));
   ```

   Esta sintaxe é do MySQL 8.0+ para multi-valued indexes. MySQL 5.7 não suporta.

3. **Linhas 19-48:** UPDATEs usam `WHERE required_skills IS NULL`

   Se ADD COLUMN falhou, a coluna não existe, então WHERE clause falha.

### ✅ Solução Aplicada

**Arquivo criado:** `025_add_service_catalog_required_skills.sql` (versão corrigida)

**Mudanças:**
- ❌ Removido `IF NOT EXISTS` (incompatível MySQL 5.7)
- ❌ Removido índice JSON multi-valued (incompatível MySQL 5.7)
- ✅ Removido `AND required_skills IS NULL` dos UPDATEs (execução idempotente)
- ✅ UPDATEs agora sobrescrevem valores anteriores (safe para re-run)

**Nova sintaxe:**
```sql
-- Compatible MySQL 5.7+
ALTER TABLE wp_limpvix_service_catalog
ADD COLUMN required_skills JSON NULL
    COMMENT 'Array de skills necessárias...';

-- UPDATE sem WHERE clause de coluna inexistente
UPDATE wp_limpvix_service_catalog
SET required_skills = JSON_ARRAY('limpeza_residencial')
WHERE service_code = 'residential_standard';
```

### Como Re-executar
1. **Via browser:** `http://localhost:8080/wp-content/plugins/limpvix-core/database-migrations/execute-migration-025.php`
2. **Resultado esperado:** ✅ Coluna criada, 6 serviços populados com skills

### Verificação
```sql
-- Ver coluna
SHOW COLUMNS FROM wp_limpvix_service_catalog WHERE Field = 'required_skills';

-- Ver dados
SELECT service_code, required_skills
FROM wp_limpvix_service_catalog
WHERE required_skills IS NOT NULL;
```

---

## 🔴 PROBLEMA 2: Erro Crítico na Página limpvix-professionals

### Erro Reportado
```
Ocorreu um erro crítico neste site.
Verifique a caixa de entrada do e-mail do administrador do site para obter instruções.
```

**URL:** `http://localhost:8080/wp-admin/admin.php?page=limpvix-professionals`

### Causas Possíveis

#### Hipótese 1: Migration 025 Falhou (Mais Provável)
Se a migration 025 falhou e a coluna `required_skills` não foi criada:
- `SendOffers::getRequiredSkillsFromServiceCode()` tenta consultar coluna inexistente
- Query SQL falha → Fatal error
- ProfessionalManagementPage pode chamar SendOffers em algum contexto

**Arquivo afetado:** `src/Application/UseCase/Briefing/SendOffers.php` (linha 177)
```php
$requiredSkills = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT required_skills FROM {$table} WHERE service_code = %s AND is_active = 1",
        $serviceCode
    )
);
```

Se coluna não existe → SQL error → Fatal error

#### Hipótese 2: Classe Não Encontrada
Após GAP B (remoção de duplicatas), algum código ainda referencia:
- `LimpVix\Application\UseCases\Scheduling\PerformCheckIn`
- `LimpVix\Application\UseCases\Scheduling\PerformCheckOut`

**Verificação:**
```bash
grep -r "Scheduling\\\\PerformCheck" src/
```

Se retornar resultados → remover imports

#### Hipótese 3: Memory Limit ou Timeout
ProfessionalManagementPage pode ter N+1 queries não otimizadas:
- Loop sobre profissionais
- Para cada um, busca earnings, allocations, scores
- 100+ profissionais = timeout ou memory exhaustion

### ✅ Soluções

#### Solução Imediata
1. **Re-executar migration 025 corrigida:**
   ```
   http://localhost:8080/wp-content/plugins/limpvix-core/database-migrations/execute-migration-025.php
   ```

2. **Verificar coluna criada:**
   ```sql
   SHOW COLUMNS FROM wp_limpvix_service_catalog LIKE 'required_skills';
   ```

3. **Tentar acessar página novamente:**
   ```
   http://localhost:8080/wp-admin/admin.php?page=limpvix-professionals
   ```

#### Solução Debug (se erro persistir)
1. **Habilitar debug logs:**
   ```php
   // wp-config.php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

2. **Ver logs:**
   ```
   tail -f wp-content/debug.log
   ```

3. **Acessar página novamente e ver erro exato**

#### Solução Preventiva
Adicionar try-catch em SendOffers.php:
```php
try {
    $requiredSkills = $wpdb->get_var($wpdb->prepare(...));
} catch (\Exception $e) {
    error_log('[SendOffers] Database error: ' . $e->getMessage());
    return ['limpeza_residencial']; // Fallback
}
```

---

## 🔴 PROBLEMA 3: Novos GAPS Identificados

### GAP #2: Evidence Categorization ❌

**Status:** Parcialmente implementado (50%)
**Prioridade:** P1 - Alta (UX crítico)

**O que existe:**
- ✅ `Evidence` entity com campo photos (JSON array)
- ✅ `Evidence::addPhoto(string $url, ?string $description)`

**O que falta:**
- ❌ **EvidenceType enum** (EPI, Local, Problema, Resultado)
- ❌ **Photo categorization** (cada foto tem tipo)
- ❌ **Admin UI** para ver evidências por categoria

**Impacto:**
- Admin não consegue filtrar evidências por tipo
- Dificulta auditoria de uso de EPI (compliance NR-06)
- Profissional não sabe que tipo de foto enviar

**Estimativa:** 4 horas

**Implementação:**

1. **EvidenceType enum** (1h)
   ```php
   // src/Domain/Execution/Enums/EvidenceType.php
   enum EvidenceType: string
   {
       case EPI = 'epi';           // Equipamentos de Proteção Individual
       case LOCAL = 'local';        // Antes/depois do local
       case PROBLEMA = 'problema';  // Problema identificado
       case RESULTADO = 'resultado'; // Resultado final
   }
   ```

2. **Evidence entity refactor** (1h)
   ```php
   // src/Domain/Execution/Evidence.php
   class Evidence {
       private array $photos = []; // [['url' => '...', 'type' => 'epi', 'description' => '...']]

       public function addPhoto(string $url, EvidenceType $type, ?string $description): void
       {
           $this->photos[] = [
               'url' => $url,
               'type' => $type->value,
               'description' => $description,
               'uploaded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
           ];
       }

       public function getPhotosByType(EvidenceType $type): array
       {
           return array_filter($this->photos, fn($p) => $p['type'] === $type->value);
       }
   }
   ```

3. **Admin UI - Evidence Gallery** (2h)
   - Tabs por tipo (EPI, Local, Problema, Resultado)
   - Lightbox por categoria
   - Download de evidências por tipo

---

### GAP #3: Client Check-in Notification ❌

**Status:** Não implementado (0%)
**Prioridade:** P2 - Média (UX, não bloqueador)

**Problema:**
Cliente não é notificado quando profissional faz check-in (chegou no local).

**Fluxo esperado:**
1. Profissional faz check-in → `PerformCheckIn` use case
2. Use case dispara evento `CheckInPerformed`
3. Listener `NotifyClientOnCheckIn` envia notificação
4. Cliente recebe: "Profissional João chegou no local - 10:05 AM"

**O que existe:**
- ✅ `PerformCheckIn` use case (Execution namespace)
- ✅ `Execution::performCheckIn()` domain method

**O que falta:**
- ❌ **CheckInPerformed** domain event
- ❌ **NotifyClientOnCheckIn** event listener
- ❌ Integração com serviço de notificação (SMS/WhatsApp/Push)

**Estimativa:** 3 horas

**Implementação:**

1. **CheckInPerformed event** (30min)
   ```php
   // src/Domain/Execution/Events/CheckInPerformed.php
   final class CheckInPerformed
   {
       public function __construct(
           public readonly int $executionId,
           public readonly int $professionalId,
           public readonly int $customerId,
           public readonly \DateTimeImmutable $checkInAt,
           public readonly array $location // [lat, lng]
       ) {}
   }
   ```

2. **Dispatch event in PerformCheckIn** (30min)
   ```php
   // src/Application/UseCase/Execution/PerformCheckIn.php
   public function execute(PerformCheckInCommand $command): void
   {
       // ... existing logic
       $execution->performCheckIn($command->latitude, $command->longitude);

       // Dispatch event
       do_action('limpvix_check_in_performed', new CheckInPerformed(
           $execution->getId(),
           $execution->getProfessionalId(),
           $execution->getCustomerId(),
           $execution->getCheckInAt(),
           ['lat' => $command->latitude, 'lng' => $command->longitude]
       ));

       $this->repository->save($execution);
   }
   ```

3. **NotifyClientOnCheckIn listener** (2h)
   ```php
   // src/Infrastructure/EventListeners/NotifyClientOnCheckIn.php
   final class NotifyClientOnCheckIn
   {
       public function handle(CheckInPerformed $event): void
       {
           // Get customer phone
           $customer = get_userdata($event->customerId);
           $phone = get_user_meta($event->customerId, 'phone', true);

           // Get professional name
           $professional = $this->professionalRepo->findById($event->professionalId);

           // Send WhatsApp/SMS
           $message = sprintf(
               "🧹 *LimpVix*\n\nO profissional *%s* chegou no local de serviço.\n\nHorário: %s",
               $professional->getFullName(),
               $event->checkInAt->format('H:i')
           );

           $this->notificationService->sendWhatsApp($phone, $message);
       }
   }
   ```

---

### GAP #4: Issue Reporting System (Incomplete) ⚠️

**Status:** 50% implementado
**Prioridade:** P1 - Alta (Operacional crítico)

**O que existe:**
- ✅ `Issue` entity (src/Domain/Execution/Issue.php)
- ✅ `ReportIssue` use case (src/Application/UseCase/Execution/ReportIssue.php)
- ✅ `Execution::reportIssue()` domain method

**O que falta:**
- ❌ **IssueRepository** interface + implementation
- ❌ **Issue persistence** (banco de dados)
- ❌ **Issue listing** (admin UI)
- ❌ **Issue resolution** workflow

**Impacto:**
- Profissional pode reportar issues, mas **não são salvas**
- Admin não vê issues reportadas
- Sem tracking de resolução

**Estimativa:** 6 horas

**Implementação:**

1. **Database migration** (1h)
   ```sql
   CREATE TABLE wp_limpvix_execution_issues (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       execution_id BIGINT UNSIGNED NOT NULL,
       reported_by INT UNSIGNED NOT NULL,
       issue_type ENUM('quality','damage','missing_items','late_arrival','other') NOT NULL,
       description TEXT NOT NULL,
       severity ENUM('low','medium','high','critical') NOT NULL,
       photos JSON NULL,
       status ENUM('open','investigating','resolved','closed') DEFAULT 'open',
       resolved_by INT NULL,
       resolved_at DATETIME NULL,
       resolution_notes TEXT NULL,
       created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
       INDEX idx_execution (execution_id),
       INDEX idx_status (status),
       INDEX idx_severity (severity)
   );
   ```

2. **IssueRepository** (2h)
   ```php
   interface IssueRepositoryInterface {
       public function save(Issue $issue): void;
       public function findById(int $id): ?Issue;
       public function findByExecution(int $executionId): array;
       public function findOpenIssues(): array;
       public function findBySeverity(string $severity): array;
   }
   ```

3. **Admin UI - Issues Page** (3h)
   - Lista de issues abertas
   - Filtros: severity, status, execution
   - Ação: Resolver issue (adicionar notes, mudar status)
   - Badge no menu: "3 issues críticas"

---

## 📊 Resumo dos GAPS

| GAP | Status | Prioridade | Esforço | Bloqueador |
|-----|--------|------------|---------|------------|
| **#2: Evidence Categorization** | 50% | P1 - Alta | 4h | ❌ Não |
| **#3: Client Check-in Notification** | 0% | P2 - Média | 3h | ❌ Não |
| **#4: Issue Reporting (Complete)** | 50% | P1 - Alta | 6h | ⚠️ Parcial |

**Total esforço:** 13 horas (1.5 dias)

---

## 🎯 Plano de Ação Recomendado

### Ação Imediata (HOJE)
1. ✅ **Corrigir Migration 025** - FEITO
2. ⚠️ **Re-executar migration 025** via browser
3. ⚠️ **Verificar página limpvix-professionals** funciona

### Esta Semana
1. **GAP #4: Issue Reporting** (6h) - Prioridade 1
   - Criar migration para tabela issues
   - Implementar IssueRepository
   - Admin UI básico (lista + resolver)

2. **GAP #2: Evidence Categorization** (4h) - Prioridade 2
   - EvidenceType enum
   - Refatorar Evidence entity
   - Admin UI com tabs por categoria

### Próxima Semana
1. **GAP #3: Client Check-in Notification** (3h)
   - CheckInPerformed event
   - NotifyClientOnCheckIn listener
   - Integração SMS/WhatsApp

---

## 🔗 Arquivos Afetados

**Migration 025 Corrigida:**
- `database-migrations/025_add_service_catalog_required_skills.sql` (FIXED)
- `database-migrations/025_add_service_catalog_required_skills_OLD.sql` (backup)

**SendOffers (afetado por Migration 025):**
- `src/Application/UseCase/Briefing/SendOffers.php` (linha 177)

**Evidence System (GAP #2):**
- `src/Domain/Execution/Evidence.php` (refactor)
- `src/Domain/Execution/Enums/EvidenceType.php` (novo)

**Check-in Notification (GAP #3):**
- `src/Domain/Execution/Events/CheckInPerformed.php` (novo)
- `src/Infrastructure/EventListeners/NotifyClientOnCheckIn.php` (novo)

**Issue Reporting (GAP #4):**
- `src/Domain/Execution/IssueRepositoryInterface.php` (novo)
- `src/Infrastructure/Persistence/WpIssueRepository.php` (novo)
- `database-migrations/026_create_execution_issues_table.sql` (novo)

---

**Última Atualização:** 2026-02-16
**Próxima Revisão:** Após re-execução de migration 025
