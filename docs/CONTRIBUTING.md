# Guia de Contribuição - LimpVix Core Plugin

Obrigado por contribuir com o LimpVix Core Plugin! Este documento descreve as práticas e padrões que seguimos.

---

## 📋 Índice

1. [Código de Conduta](#código-de-conduta)
2. [Como Contribuir](#como-contribuir)
3. [Convenção de Commits](#convenção-de-commits)
4. [Branching Strategy](#branching-strategy)
5. [Pull Request Process](#pull-request-process)
6. [Coding Standards](#coding-standards)
7. [Testes](#testes)

---

## 🤝 Código de Conduta

- Seja respeitoso com todos os contribuidores
- Forneça feedback construtivo
- Foque no problema, não na pessoa
- Aceite críticas com profissionalismo

---

## 🔄 Como Contribuir

### 1. Setup do Ambiente Local

```bash
# Clone o repositório
git clone git@github.com:jgdeamorim/limpvix-core.git
cd limpvix-core

# Instale dependências
composer install

# Configure Docker
docker-compose up -d

# Execute migrations
docker exec limpvix_wordpress_clean php /var/www/html/wp-content/plugins/limpvix-core/database-migrations/run-all-migrations.php
```

### 2. Crie uma Branch

```bash
# Sempre crie uma branch a partir de main
git checkout main
git pull origin main

# Crie uma feature branch
git checkout -b feature/nome-da-feature

# Ou uma bugfix branch
git checkout -b bugfix/nome-do-bug
```

### 3. Faça suas Mudanças

- Siga os [Coding Standards](#coding-standards)
- Adicione testes quando aplicável
- Atualize a documentação se necessário
- Execute os testes localmente

### 4. Commit suas Mudanças

Use a [Convenção de Commits](#convenção-de-commits) (Conventional Commits)

```bash
git add .
git commit -m "feat: adicionar método de pagamento PIX"
```

### 5. Push e Abra um Pull Request

```bash
git push origin feature/nome-da-feature
```

Abra um Pull Request no GitHub descrevendo suas mudanças.

---

## 📝 Convenção de Commits

Seguimos **Conventional Commits 1.0.0** (https://www.conventionalcommits.org/)

### Formato

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Types (Tipos)

| Type | Descrição | Exemplo |
|------|-----------|---------|
| **feat** | Nova feature | `feat(briefing): adicionar validação de telefone` |
| **fix** | Correção de bug | `fix(contract): corrigir cálculo de recorrência` |
| **docs** | Mudanças em documentação | `docs(api): atualizar referência REST API` |
| **style** | Formatação, sem mudança de código | `style: formatar com PSR-12` |
| **refactor** | Refatoração sem mudança de comportamento | `refactor(admin): dividir AdminBootstrap em classes menores` |
| **test** | Adição ou correção de testes | `test(execution): adicionar testes de check-in` |
| **chore** | Mudanças em build, configs, etc. | `chore: atualizar dependências composer` |
| **perf** | Melhoria de performance | `perf(repository): adicionar índice em query` |
| **ci** | Mudanças em CI/CD | `ci: adicionar GitHub Actions workflow` |
| **build** | Mudanças em build system | `build: atualizar webpack config` |
| **revert** | Reverter commit anterior | `revert: reverter "feat: adicionar feature X"` |

### Scopes (Escopos)

Use o nome do módulo ou componente afetado:

- `briefing` - Módulo Briefing
- `professional` - Módulo Professional
- `contract` - Módulo Contract
- `execution` - Módulo Execution
- `finance` - Módulo Finance
- `feedback` - Módulo Feedback
- `communication` - Módulo Communication
- `scheduling` - Módulo Scheduling
- `admin` - Admin UI
- `api` - REST API
- `db` - Database/Migrations
- `docs` - Documentação
- `infra` - Infraestrutura

### Exemplos de Commits

**Feature:**
```bash
git commit -m "feat(professional): adicionar método canReceivePayouts()"
```

**Bug Fix:**
```bash
git commit -m "fix(execution): corrigir validação de feedback window

- Validar se janela está aberta antes de aceitar feedback
- Adicionar teste para janela expirada
- Closes #123"
```

**Breaking Change:**
```bash
git commit -m "feat(contract)!: mudar formato de service_address para JSON

BREAKING CHANGE: O campo service_address agora é JSON ao invés de VARCHAR.
Migration necessária para converter dados existentes."
```

**Documentation:**
```bash
git commit -m "docs(api): adicionar exemplos de webhook MercadoPago"
```

**Refactor:**
```bash
git commit -m "refactor(admin): extrair SettingsBootstrap de AdminBootstrap

- AdminBootstrap tinha 3218 linhas (God Class)
- Extrair configurações para SettingsBootstrap
- Reduz AdminBootstrap para ~800 linhas"
```

**Multiple Scopes:**
```bash
git commit -m "feat(professional,finance): implementar dual-mode payouts

- MercadoPago OAuth (automático)
- PIX Manual (processado por admin)
- Migration 018 com novos campos"
```

---

## 🌿 Branching Strategy

Usamos **Git Flow** simplificado:

### Branches Principais

- **`main`** - Production-ready code
  - Sempre deve estar estável
  - Tags de versão (v1.0.0, v1.1.0, etc.)

- **`develop`** - Integration branch
  - Novas features são mergeadas aqui
  - Testes de integração rodam aqui

### Branches de Suporte

- **`feature/*`** - Novas features
  - Criadas a partir de `develop`
  - Mergeadas de volta para `develop`
  - Exemplo: `feature/oauth-professional`

- **`bugfix/*`** - Correções de bugs
  - Criadas a partir de `develop`
  - Mergeadas de volta para `develop`
  - Exemplo: `bugfix/contract-calculation`

- **`hotfix/*`** - Correções urgentes em produção
  - Criadas a partir de `main`
  - Mergeadas para `main` E `develop`
  - Exemplo: `hotfix/payment-webhook`

- **`release/*`** - Preparação de release
  - Criadas a partir de `develop`
  - Mergeadas para `main` e `develop`
  - Exemplo: `release/1.1.0`

### Fluxo de Trabalho

**Nova Feature:**
```bash
git checkout develop
git pull origin develop
git checkout -b feature/minha-feature
# ... trabalho ...
git commit -m "feat: minha feature"
git push origin feature/minha-feature
# Abrir Pull Request para develop
```

**Bug Fix:**
```bash
git checkout develop
git checkout -b bugfix/meu-bug
# ... correção ...
git commit -m "fix: corrigir bug X"
git push origin bugfix/meu-bug
# Abrir Pull Request para develop
```

**Hotfix (Produção):**
```bash
git checkout main
git checkout -b hotfix/critical-bug
# ... correção urgente ...
git commit -m "fix: corrigir vulnerabilidade crítica"
git push origin hotfix/critical-bug
# Abrir Pull Request para main
# Depois mergear também para develop
```

**Release:**
```bash
git checkout develop
git checkout -b release/1.2.0
# Atualizar CHANGELOG.md
# Atualizar versão em limpvix-core.php
git commit -m "chore(release): versão 1.2.0"
git push origin release/1.2.0
# Abrir Pull Request para main
# Tag a versão: git tag v1.2.0
```

---

## 🔀 Pull Request Process

### Checklist antes de Abrir PR

- [ ] Código segue os [Coding Standards](#coding-standards)
- [ ] Testes passam localmente (`composer test`)
- [ ] Documentação atualizada (se aplicável)
- [ ] CHANGELOG.md atualizado (para features/fixes significativos)
- [ ] Commits seguem [Conventional Commits](#convenção-de-commits)
- [ ] Branch está atualizada com base (`git rebase develop`)

### Template de Pull Request

```markdown
## Descrição

Breve descrição do que este PR faz.

## Tipo de Mudança

- [ ] Bug fix (mudança que corrige um bug)
- [ ] Nova feature (mudança que adiciona funcionalidade)
- [ ] Breaking change (mudança que quebra compatibilidade)
- [ ] Documentação

## Testes Realizados

Descreva os testes que você executou:
- [ ] Teste A
- [ ] Teste B

## Screenshots (se aplicável)

Adicione screenshots para mudanças visuais.

## Checklist

- [ ] Código segue PSR-12 / WordPress Coding Standards
- [ ] Testes unitários adicionados/atualizados
- [ ] Documentação atualizada
- [ ] CHANGELOG.md atualizado

## Issues Relacionadas

Closes #123
Relates to #456
```

### Code Review Process

1. **Automated Checks** (futuramente com GitHub Actions):
   - PHP CS Fixer (PSR-12)
   - PHPStan (análise estática)
   - PHPUnit (testes)

2. **Manual Review**:
   - Pelo menos 1 aprovação necessária
   - Reviewer verifica:
     - Lógica de negócio correta
     - Código limpo e legível
     - Testes adequados
     - Sem vulnerabilidades

3. **Merge**:
   - Squash and merge (para manter histórico limpo)
   - Delete branch após merge

---

## 💻 Coding Standards

### PHP

Seguimos **PSR-12** e **WordPress Coding Standards**.

```bash
# Verificar código
composer run phpcs

# Corrigir automaticamente
composer run phpcbf
```

**Principais regras:**
- Indentação: 4 espaços (não tabs)
- Line length: máximo 120 caracteres
- Naming:
  - Classes: `PascalCase`
  - Methods: `camelCase`
  - Constants: `UPPER_SNAKE_CASE`
  - Variables: `camelCase`

### Domain-Driven Design

- **Aggregates** devem proteger invariantes
- **Value Objects** devem ser imutáveis
- **Domain Events** para side effects
- **Repository Interfaces** no Domain, implementações na Infrastructure

### Clean Architecture

- **Domain** não depende de Infrastructure
- **Application** orquestra Domain
- **Infrastructure** implementa detalhes técnicos
- **Use Cases** têm método `execute()`

### Exemplos

**Aggregate Root:**
```php
final class Contract
{
    private ContractId $id;
    private ContractStatus $status;

    public function activate(): void
    {
        if ($this->status->isActive()) {
            throw new InvalidContractTransition('Contract already active');
        }

        $this->status = ContractStatus::active();
        $this->recordEvent(new ContractActivated($this->id));
    }
}
```

**Use Case:**
```php
final class ActivateContract
{
    public function __construct(
        private ContractRepositoryInterface $contractRepo
    ) {}

    public function execute(int $contractId): void
    {
        $contract = $this->contractRepo->findById(
            ContractId::fromInt($contractId)
        );

        $contract->activate();

        $this->contractRepo->save($contract);
    }
}
```

---

## 🧪 Testes

### Estrutura de Testes

```
tests/
├── Unit/           # Testes unitários (Domain, Application)
├── Integration/    # Testes de integração (Repositories, APIs)
└── E2E/            # Testes end-to-end (fluxos completos)
```

### Executar Testes

```bash
# Todos os testes
composer test

# Apenas unit tests
composer test:unit

# Apenas integration tests
composer test:integration

# Com coverage
composer test:coverage
```

### Escrever Testes

**Unit Test Example:**
```php
class ContractTest extends TestCase
{
    public function test_activate_changes_status_to_active(): void
    {
        $contract = ContractFactory::create(['status' => 'draft']);

        $contract->activate();

        $this->assertTrue($contract->getStatus()->isActive());
    }
}
```

**Integration Test Example:**
```php
class WpContractRepositoryTest extends WP_UnitTestCase
{
    public function test_save_persists_contract_to_database(): void
    {
        $contract = ContractFactory::create();
        $repository = new WpContractRepository();

        $repository->save($contract);

        $found = $repository->findById($contract->getId());
        $this->assertEquals($contract->getId(), $found->getId());
    }
}
```

---

## 📚 Recursos

- [DDD Reference](https://www.domainlanguage.com/ddd/reference/)
- [Clean Architecture](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [PSR-12](https://www.php-fig.org/psr/psr-12/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- [Conventional Commits](https://www.conventionalcommits.org/)
- [Semantic Versioning](https://semver.org/)

---

## 🤔 Dúvidas?

- Abra uma issue no GitHub
- Entre em contato: dev@limpvix.com
- Consulte a documentação: [docs/INDEX.md](./INDEX.md)

---

**Obrigado por contribuir! 🚀**
