-- Migration 007: Adicionar colunas de taxa/comissão LimpVix
-- Data: 2026-02-07
-- Blocker: BLC-000
--
-- OBJETIVO:
-- Adicionar campos para armazenar cálculo de taxa da plataforma
-- e valor líquido do profissional antes do repasse.
--
-- COLUNAS ADICIONADAS:
-- - total_amount: Valor total pago pelo cliente (mover de metadata para coluna)
-- - platform_fee_percentage: Percentual da taxa (ex: 15.00 = 15%)
-- - platform_fee_amount: Valor em reais da taxa
-- - professional_net_amount: Valor líquido para o profissional (total - fee)

USE wordpress;

-- 1. Adicionar coluna total_amount (valor total da order)
ALTER TABLE wp_limpvix_orders
ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER financial_status;

-- 2. Adicionar coluna platform_fee_percentage (taxa em %)
ALTER TABLE wp_limpvix_orders
ADD COLUMN platform_fee_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER total_amount;

-- 3. Adicionar coluna platform_fee_amount (taxa em R$)
ALTER TABLE wp_limpvix_orders
ADD COLUMN platform_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER platform_fee_percentage;

-- 4. Adicionar coluna professional_net_amount (valor líquido profissional)
ALTER TABLE wp_limpvix_orders
ADD COLUMN professional_net_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER platform_fee_amount;

-- 5. Criar índice para queries por valor
CREATE INDEX idx_total_amount ON wp_limpvix_orders(total_amount);

-- 6. Migrar dados existentes de metadata para total_amount
UPDATE wp_limpvix_orders
SET total_amount = CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.total_amount')) AS DECIMAL(10,2))
WHERE metadata IS NOT NULL
  AND JSON_EXTRACT(metadata, '$.total_amount') IS NOT NULL;

-- 7. Calcular fee retroativo para orders existentes (15% padrão)
UPDATE wp_limpvix_orders
SET platform_fee_percentage = 15.00,
    platform_fee_amount = total_amount * 0.15,
    professional_net_amount = total_amount * 0.85
WHERE total_amount > 0;

-- Verificação
SELECT
    uuid,
    total_amount,
    platform_fee_percentage,
    platform_fee_amount,
    professional_net_amount,
    (total_amount - platform_fee_amount) as verificacao_calculo
FROM wp_limpvix_orders
WHERE total_amount > 0
LIMIT 5;
