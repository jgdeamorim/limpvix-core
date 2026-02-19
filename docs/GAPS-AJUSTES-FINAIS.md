# GAPS - Ajustes Finais (Documentados durante Fase 3 P0)

> Gaps encontrados durante a implementacao dos 6 itens P0 (blockers para producao).
> Organizados por prioridade: P1 (essenciais), P2 (qualidade), P3 (producao).

---

## P1 - Essenciais (proximo sprint)

### G-EXEC-BRIEFING
- **Descricao:** Execution nao tem referencia direta ao Briefing. Nao sabe quantos comodos o imovel tem para validar se o profissional enviou fotos de todos os comodos.
- **Encontrado em:** P0.2 (PerformCheckIn, PerformCheckOut)
- **Solucao:** Adicionar campo `expected_rooms_count` no Execution (populado na criacao via Order -> Briefing) ou lookup via Order -> Briefing na hora da validacao.
- **Arquivos afetados:**
  - `src/Domain/Execution/Execution.php` (novo campo)
  - `src/Application/UseCases/Execution/PerformCheckIn.php` (validacao obrigatoria)
  - `src/Application/UseCases/Execution/PerformCheckOut.php` (validacao obrigatoria)
- **Complexidade:** Media (precisa definir onde vem o room count)
- **Status:** WARN implementado (nao bloqueia check-in/out, apenas loga)

### G-FRONTEND-WIZARD
- **Descricao:** Frontend `web-app/app/cliente/novo-briefing.tsx` eh esqueleto (~10% pronto). Precisa rebuild completo consumindo a API `/limpvix/v1/briefing/schema` com os 10 steps dinamicos.
- **Encontrado em:** P0.5 (schema pronto, frontend nao)
- **Solucao:** Rebuild do wizard React Native Web com stepper dinamico, validacao por step, preview de preco em tempo real.
- **Arquivos afetados:**
  - `web-app/app/cliente/novo-briefing.tsx` (rebuild completo)
  - `web-app/services/api.ts` (integrar endpoints)
- **Complexidade:** Alta (frontend completo)

### G-PREVIEW-REALTIME
- **Descricao:** Falta endpoint REST para recalcular preco em tempo real a cada mudanca de step no wizard. O PricingEngine SSOT esta pronto, mas nao exposto via API.
- **Encontrado em:** P0.3/P0.5 (PricingEngine criado, sem endpoint)
- **Solucao:** Criar endpoint `POST /limpvix/v1/briefing/price-preview` que recebe os dados parciais do briefing e retorna PricingEngine::calculatePrice().
- **Arquivos afetados:**
  - Novo: `src/Infrastructure/API/PricingPreviewController.php`
- **Complexidade:** Baixa (PricingEngine ja existe)

### G-BRIEFING-CLEANING-TYPES
- **Descricao:** Briefing aggregate (`Briefing.php`) nao tem property `cleaningTypes` no modelo de dominio. O step `cleaning_types` eh processado pelo controller mas nao persiste no aggregate.
- **Encontrado em:** P0.5 (ao revisar schema vs aggregate)
- **Solucao:** Adicionar `cleaningTypes: array` no Briefing aggregate com getter/setter.
- **Arquivos afetados:**
  - `src/Domain/Briefing/Briefing.php`
  - `src/Application/UseCases/Briefing/UpdateBriefingStep.php` (case cleaning_types)
- **Complexidade:** Media

### G-BRIEFING-GEO-DOMAIN
- **Descricao:** Campos geo (geo_index, geo_classification, geo_multiplier) foram adicionados na tabela via migration, mas o Briefing aggregate nao tem esses campos no modelo de dominio. UpdateBriefingStep usa wpdb direto para salvar.
- **Encontrado em:** P0.4 (UpdateBriefingStep::applyStepUpdate case location)
- **Solucao:** Adicionar campos geo no Briefing aggregate e repository.
- **Arquivos afetados:**
  - `src/Domain/Briefing/Briefing.php`
  - `src/Infrastructure/Persistence/BriefingRepository.php` (se existir)
- **Complexidade:** Media

### G-EPI-DOMAIN
- **Descricao:** Nao existe tabela EPI definida no dominio. Professional.requiresEpi() eh basico. Falta modelar quais EPIs sao necessarios por tipo de servico (luvas, botas, mascara, oculos, etc.).
- **Encontrado em:** P0.2 (ao revisar evidence system)
- **Solucao:** Criar EPI catalog e vincular a service_catalog por tipo de servico.
- **Arquivos afetados:**
  - Novo: `src/Domain/Execution/ValueObjects/EpiRequirement.php`
  - `database-migrations/XXX_create_epi_catalog.sql`
- **Complexidade:** Media

---

## P2 - Qualidade (melhoria de experiencia)

### G-ROOM-MATCH
- **Descricao:** Falta validacao de correspondencia 1:1 entre fotos de check-in e check-out por comodo. Hoje se o profissional tirar 3 fotos no check-in e 2 no check-out, nao ha alerta.
- **Encontrado em:** P0.2 (Evidence room system)
- **Solucao:** Comparar `countUniqueRooms('check_in')` vs `countUniqueRooms('check_out')` e alertar/bloquear se faltarem comodos.
- **Arquivos afetados:**
  - `src/Application/UseCases/Execution/PerformCheckOut.php`
- **Complexidade:** Baixa (logica simples, UI que eh complexa)

### G-PRICING-ADDITIONALS
- **Descricao:** PricingEngine aceita additionals no calculo, mas o flow de selecao de adicionais no briefing (Step 4) ainda nao propaga os precos para o PricingEngine. O schema mostra os adicionais mas o calculo final nao os inclui automaticamente.
- **Encontrado em:** P0.3/P0.5 (PricingEngine + Schema)
- **Solucao:** Quando step=additionals for salvo, recalcular preco com PricingEngine incluindo os adicionais selecionados.
- **Arquivos afetados:**
  - `src/Application/UseCases/Briefing/UpdateBriefingStep.php` (case additionals)
- **Complexidade:** Baixa

### G-IBGE-FALLBACK
- **Descricao:** IBGEAreaIndexService retorna null se APIs do IBGE estiverem fora. Pricing usa multiplicador 1.0 (neutro) como fallback. Nao ha mecanismo de retry ou notificacao ao admin.
- **Encontrado em:** P0.4
- **Solucao:** Implementar retry com backoff exponencial e notificacao ao admin se falhar 3x consecutivas. Considerar cache local com dados pre-carregados dos municipios do ES.
- **Arquivos afetados:**
  - `src/Infrastructure/Services/IBGEAreaIndexService.php`
- **Complexidade:** Media

### G-PROPERTY-STRUCTURE-COUNTS
- **Descricao:** PropertyStructure usa booleans (hasLivingRoom, hasKitchen, etc.) quando deveria usar integers (quantos quartos, quantos banheiros, quantas salas). Isso impede calcular m2 correto e validar evidencias por comodo.
- **Encontrado em:** P0.2/P0.5 (ao revisar schema vs domain)
- **Solucao:** Converter booleans para integer counts no PropertyStructure VO.
- **Arquivos afetados:**
  - `src/Domain/Briefing/PropertyStructure.php`
  - `src/Application/Services/BriefingMetricsCalculator.php`
  - `src/Application/UseCases/Briefing/GetBriefingSchema.php` (step structure)
- **Complexidade:** Media (breaking change no VO)

### G-MATCHING-DUAL
- **Descricao:** Existem 2 sistemas paralelos de matching (SendOffers e AllocationEngine) com pesos diferentes. Podem dar resultados conflitantes.
- **Encontrado em:** Auditoria pre-P0
- **Solucao:** Unificar em um unico MatchingEngine ou definir claramente quando cada um eh usado.
- **Arquivos afetados:**
  - `src/Infrastructure/Matching/SendOffers.php`
  - `src/Application/Services/AllocationEngine.php`
- **Complexidade:** Alta

### G-FLUXOS-DUAL
- **Descricao:** FluxosTab usa `limpvix_enabled_flows` (lowercase) enquanto MessageFlowsAdminPage usa `limpvix_active_flows` (uppercase). Semanticas diferentes para a mesma feature.
- **Encontrado em:** Auditoria pre-P0
- **Solucao:** Unificar em uma unica chave e formato.
- **Arquivos afetados:**
  - `src/Admin/Settings/Tabs/FluxosTab.php`
  - `src/Infrastructure/Admin/Pages/MessageFlowsAdminPage.php`
- **Complexidade:** Baixa

---

## P3 - Producao (go-live)

### G-KYC-REAL
- **Descricao:** KYC (Know Your Customer) usa provider stub/mock. Nao valida documentos reais.
- **Encontrado em:** Auditoria pre-P0
- **Solucao:** Integrar com provider real (Serpro, BigDataCorp, ou similar).
- **Arquivos afetados:**
  - `src/Infrastructure/KYC/`
- **Complexidade:** Alta (integracao externa)

### G-BACKGROUND-CHECK-REAL
- **Descricao:** Background check nao tem provider real integrado.
- **Encontrado em:** Auditoria pre-P0
- **Solucao:** Integrar com provider (consulta criminal, antecedentes).
- **Complexidade:** Alta

### G-PAYOUT-EFI
- **Descricao:** Payout provider referencia MercadoPago nos comentarios mas deve usar EFI Bank PIX. Provider real nao implementado.
- **Encontrado em:** Auditoria pre-P0
- **Solucao:** Implementar EFI Bank PayoutProvider com certificado mTLS.
- **Arquivos afetados:**
  - `src/Infrastructure/Payment/EfiBankPayoutProvider.php` (novo)
- **Complexidade:** Alta

### G-GERENTE-MUNICIPAL
- **Descricao:** Role "gerente municipal" mencionado nos requisitos mas nao implementado. Deve ter dashboard especifico por regiao.
- **Encontrado em:** Auditoria pre-P0
- **Solucao:** Criar role WordPress + capabilities + dashboard filtrado por municipio.
- **Complexidade:** Alta

### G-METRICS-TYPO
- **Descricao:** BriefingMetricsCalculator tem metodo com typo: `getTimeFa()` (linha 227) - funcao quebrada.
- **Encontrado em:** Auditoria pre-P0
- **Solucao:** Corrigir nome do metodo.
- **Arquivos afetados:**
  - `src/Application/Services/BriefingMetricsCalculator.php`
- **Complexidade:** Trivial

---

## Resumo

| Prioridade | Total | Complexidade Media |
|------------|-------|--------------------|
| P1         | 6     | Media              |
| P2         | 6     | Media              |
| P3         | 5     | Alta               |
| **Total**  | **17**|                    |

**Score estimado apos P0:** ~70% (era 60% antes dos ajustes P0)

**Proximos passos:**
1. P1 - Implementar os 6 gaps essenciais (1-2 sprints)
2. P2 - Melhorias de qualidade (1 sprint)
3. P3 - Integracoes reais para producao (2-3 sprints)
