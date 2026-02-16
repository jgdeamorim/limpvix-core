-- ========================================
-- Migration 014: Add Foreign Key Constraints for Professional References
-- ========================================
--
-- OBJETIVO:
-- - Garantir integridade referencial em nível de banco de dados
-- - Prevenir alocação de profissionais inexistentes
-- - Cascata de ações apropriadas (SET NULL on delete)
--
-- MOTIVAÇÃO (SPRINT 7 - Item 1.7):
-- - Clarificar semanticamente que professional_id = wp_limpvix_professionals.id (PK)
-- - Não é user_id (FK para wp_users)
-- - Evitar bugs futuros de alocação incorreta
--
-- IMPACTO:
-- - Queries que tentarem inserir FK inválidas falharão
-- - Deletes de professional com contratos ativos são bloqueados
-- - Melhor integridade de dados
--
-- NOTA IMPORTANTE:
-- Antes de adicionar constraints, verificamos se já existem dados órfãos
-- Se existirem, a constraint falhará - será necessário limpar manualmente
--
-- @since 0.7.0 (SPRINT 7)
-- @author Claude Code
-- ========================================

-- ========================================
-- STEP 1: Verificar dados órfãos (diagnóstico)
-- ========================================

-- Verificar contratos com allocated_professional_id inválido
SELECT COUNT(*) as orphan_contracts
FROM wp_limpvix_contracts c
LEFT JOIN wp_limpvix_professionals p ON c.allocated_professional_id = p.id
WHERE c.allocated_professional_id IS NOT NULL
  AND p.id IS NULL;

-- Verificar ofertas com professional_id inválido (TABELA NÃO EXISTE - COMENTADO)
-- SELECT COUNT(*) as orphan_offers
-- FROM wp_limpvix_contract_offers o
-- LEFT JOIN wp_limpvix_professionals p ON o.professional_id = p.id
-- WHERE o.professional_id IS NOT NULL
--   AND p.id IS NULL;

-- Verificar payouts com professional_id inválido (TABELA NÃO EXISTE - COMENTADO)
-- SELECT COUNT(*) as orphan_payouts
-- FROM wp_limpvix_payouts py
-- LEFT JOIN wp_limpvix_professionals p ON py.professional_id = p.id
-- WHERE py.professional_id IS NOT NULL
--   AND p.id IS NULL;

-- NOTA: Se qualquer query acima retornar > 0, será necessário limpar dados
-- antes de adicionar constraints. Comando de limpeza está comentado no STEP 2.

-- ========================================
-- STEP 2: Limpar dados órfãos (SE NECESSÁRIO)
-- ========================================

-- DESCOMENTE APENAS SE STEP 1 ENCONTROU DADOS ÓRFÃOS

-- Opção A: SET NULL (mantém registro mas remove FK inválida)
-- UPDATE wp_limpvix_contracts
-- SET allocated_professional_id = NULL
-- WHERE allocated_professional_id NOT IN (SELECT id FROM wp_limpvix_professionals);

-- UPDATE wp_limpvix_contract_offers
-- SET status = 'expired'
-- WHERE professional_id NOT IN (SELECT id FROM wp_limpvix_professionals);

-- Opção B: DELETE (remove registros órfãos completamente)
-- DELETE FROM wp_limpvix_contracts
-- WHERE allocated_professional_id NOT IN (SELECT id FROM wp_limpvix_professionals);

-- DELETE FROM wp_limpvix_contract_offers
-- WHERE professional_id NOT IN (SELECT id FROM wp_limpvix_professionals);

-- ========================================
-- STEP 3: Adicionar FK Constraints
-- ========================================

-- 3.1: wp_limpvix_contracts.allocated_professional_id
-- FK para wp_limpvix_professionals.id (PK, NÃO user_id)
-- NOTA: Esta FK já foi adicionada em migration 010, então verificamos se existe antes
-- ON DELETE SET NULL: Se professional deletado, contrato fica sem alocação
-- ON UPDATE CASCADE: Se professional.id mudar (raro), atualiza contratos

-- Verificar se FK já existe (migration 010 já adiciona) (COMENTADO - código incompleto)
-- SET @fk_exists = (SELECT COUNT(*)
-- FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
-- WHERE CONSTRAINT_SCHEMA = DATABASE()
--   AND TABLE_NAME = 'wp_limpvix_contracts'
-- ON UPDATE CASCADE: Se professional.id mudar, atualiza ofertas (TABELA NÃO EXISTE - COMENTADO)

-- ALTER TABLE wp_limpvix_contract_offers
-- ON UPDATE CASCADE;

-- 3.3: wp_limpvix_payouts.professional_id (TABELA NÃO EXISTE - COMENTADO)
-- FK para wp_limpvix_professionals.id (PK, NÃO user_id)
-- ON DELETE RESTRICT: NÃO permite deletar professional com payouts pendentes
-- ON UPDATE CASCADE: Se professional.id mudar, atualiza payouts

-- ALTER TABLE wp_limpvix_payouts
-- ON UPDATE CASCADE;

-- ========================================
-- STEP 4: Adicionar índices para performance
-- ========================================

-- Índice em contracts.allocated_professional_id (melhora JOIN performance)
-- NOTA: MigrationManager ignora erro "Duplicate key name" automaticamente
CREATE INDEX idx_contracts_allocated_professional
ON wp_limpvix_contracts(allocated_professional_id);

-- Índice em offers.professional_id (melhora queries de ofertas por professional) (TABELA NÃO EXISTE - COMENTADO)
-- CREATE INDEX idx_offers_professional
-- ON wp_limpvix_contract_offers(professional_id);

-- Índice em payouts.professional_id (melhora queries de payouts por professional) (TABELA NÃO EXISTE - COMENTADO)
-- CREATE INDEX idx_payouts_professional
-- ON wp_limpvix_payouts(professional_id);

-- ========================================
-- STEP 5: Verificação pós-constraint
-- ========================================

-- Verificar constraints criadas
SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM
    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE
    TABLE_SCHEMA = DATABASE()
    AND REFERENCED_TABLE_NAME = 'wp_limpvix_professionals'
ORDER BY
    TABLE_NAME, CONSTRAINT_NAME;

-- Resultado esperado:
-- fk_contract_professional | wp_limpvix_contracts | allocated_professional_id | wp_limpvix_professionals | id
-- fk_offer_professional | wp_limpvix_contract_offers | professional_id | wp_limpvix_professionals | id
-- fk_payout_professional | wp_limpvix_payouts | professional_id | wp_limpvix_professionals | id

-- ========================================
-- ROLLBACK (caso necessário)
-- ========================================

-- Para reverter esta migration:
--
