-- P1.5: Create EPI (Equipamento de Proteção Individual) catalog
-- Models which EPIs are required per service type

CREATE TABLE IF NOT EXISTS wp_limpvix_epi_catalog (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    required_for_types JSON DEFAULT NULL COMMENT 'Service types requiring this EPI, e.g. ["pos_obra","limpeza_pesada"]. NULL/empty = all types if mandatory',
    is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (active),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: EPIs padrão
INSERT INTO wp_limpvix_epi_catalog (slug, name, description, required_for_types, is_mandatory, sort_order) VALUES
('luvas', 'Luvas de Proteção', 'Luvas de látex ou nitrilo para proteção das mãos', '["limpeza_basica","limpeza_completa","limpeza_pesada","pos_obra","pre_mudanca"]', 1, 1),
('botas', 'Botas de Segurança', 'Botas impermeáveis antiderrapantes', '["limpeza_pesada","pos_obra"]', 1, 2),
('mascara', 'Máscara de Proteção', 'Máscara PFF2 ou similar para poeira/produtos químicos', '["limpeza_pesada","pos_obra"]', 1, 3),
('oculos', 'Óculos de Proteção', 'Óculos de segurança contra respingos', '["pos_obra"]', 1, 4),
('avental', 'Avental Impermeável', 'Avental de PVC para proteção do corpo', '["limpeza_pesada","pos_obra","pre_mudanca"]', 0, 5),
('touca', 'Touca Descartável', 'Touca para proteção dos cabelos', '["pos_obra"]', 0, 6);
