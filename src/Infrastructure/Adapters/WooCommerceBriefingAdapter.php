<?php
/**
 * WooCommerceBriefingAdapter - Adaptador para WooCommerce
 *
 * RESPONSABILIDADE:
 * - Criar produto virtual WooCommerce baseado no Briefing
 * - Configurar preço calculado pelas métricas
 * - Vincular meta _limpvix_briefing_uuid ao produto
 * - Adicionar produto ao carrinho do usuário
 * - Redirecionar para checkout transparente
 *
 * FLUXO:
 * 1. Briefing transiciona para AWAITING_PAYMENT
 * 2. Este adapter é chamado via evento
 * 3. Cria produto virtual com preço estimado
 * 4. Adiciona ao carrinho
 * 5. Retorna URL do checkout
 *
 * INTEGRAÇÃO:
 * - Escuta: limpvix_briefing_awaiting_payment
 * - Cria: WC_Product_Simple (virtual=true)
 * - Meta: _limpvix_briefing_uuid, _limpvix_briefing_data
 *
 * @package LimpVix\Infrastructure\Adapters
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Domain\Briefing\Briefing;

defined('ABSPATH') || exit;

class WooCommerceBriefingAdapter
{
    /**
     * @var string Prefixo para produtos de Briefing
     */
    private const PRODUCT_PREFIX = 'briefing-';

    // P0.3: PRICE_PER_M2 removido - agora via PricingEngine SSOT
    // P0.3: MINIMUM_PRICE removido - agora via PricingEngine::MINIMUM_PRICE

    /**
     * Criar produto virtual para Briefing
     *
     * @param Briefing $briefing
     * @return int Product ID criado
     * @throws \RuntimeException Se WooCommerce não estiver ativo
     * @throws \RuntimeException Se falhar ao criar produto
     */
    public function createVirtualProduct(Briefing $briefing): int
    {
        // 1. Verificar WooCommerce
        if (!function_exists('WC') || !class_exists('WC_Product_Simple')) {
            throw new \RuntimeException('WooCommerce não está ativo');
        }

        // 2. Verificar se já existe produto para este briefing
        $existingProductId = $this->findExistingProduct($briefing->getUuid());
        if ($existingProductId) {
            return $existingProductId;
        }

        try {
            // 3. Calcular preço
            $price = $this->calculatePrice($briefing);

            // 4. Criar produto WooCommerce
            $product = new \WC_Product_Simple();

            // Nome e descrição
            $productName = $this->generateProductName($briefing);
            $productDescription = $this->generateProductDescription($briefing);

            $product->set_name($productName);
            $product->set_description($productDescription);
            $product->set_short_description($this->generateShortDescription($briefing));

            // Configurar como virtual (sem envio)
            $product->set_virtual(true);
            $product->set_downloadable(false);

            // Preço
            $product->set_regular_price($price);
            $product->set_price($price);

            // Status
            $product->set_status('publish');
            $product->set_catalog_visibility('hidden'); // Ocultar do catálogo

            // SKU único
            $product->set_sku(self::PRODUCT_PREFIX . $briefing->getUuid());

            // Gerenciar estoque (permitir apenas 1 compra)
            $product->set_manage_stock(true);
            $product->set_stock_quantity(1);
            $product->set_stock_status('instock');

            // Salvar produto
            $productId = $product->save();

            if ($productId === 0) {
                throw new \RuntimeException('Falha ao salvar produto WooCommerce');
            }

            // 5. Adicionar metadata customizado
            $this->addBriefingMeta($productId, $briefing);

            // 6. Log
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[LimpVix] Produto WC criado: ID=%d, Briefing=%s, Preço=R$%.2f',
                    $productId,
                    $briefing->getUuid(),
                    $price
                ));
            }

            return $productId;

        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[LimpVix] Erro ao criar produto WC: ' . $e->getMessage());
            }

            throw new \RuntimeException('Erro ao criar produto: ' . $e->getMessage());
        }
    }

    /**
     * Adicionar produto ao carrinho do usuário
     *
     * @param int $productId
     * @param int $userId
     * @return bool
     * @throws \RuntimeException Se WooCommerce não estiver ativo
     */
    public function addToCart(int $productId, int $userId): bool
    {
        if (!function_exists('WC')) {
            throw new \RuntimeException('WooCommerce não está ativo');
        }

        try {
            // Limpar carrinho existente (briefing é produto exclusivo)
            WC()->cart->empty_cart();

            // Adicionar produto ao carrinho
            $cartItemKey = WC()->cart->add_to_cart($productId, 1);

            if ($cartItemKey === false) {
                throw new \RuntimeException('Falha ao adicionar produto ao carrinho');
            }

            // Associar carrinho ao usuário
            if ($userId > 0) {
                WC()->session->set_customer_session_cookie(true);
            }

            return true;

        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[LimpVix] Erro ao adicionar ao carrinho: ' . $e->getMessage());
            }

            return false;
        }
    }

    /**
     * Obter URL do checkout
     *
     * @return string URL do checkout WooCommerce
     */
    public function getCheckoutUrl(): string
    {
        if (function_exists('wc_get_checkout_url')) {
            return wc_get_checkout_url();
        }

        return home_url('/checkout');
    }

    /**
     * Buscar produto existente por UUID do Briefing
     *
     * @param string $briefingUuid
     * @return int|null Product ID ou null se não encontrado
     */
    private function findExistingProduct(string $briefingUuid): ?int
    {
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_limpvix_briefing_uuid',
                    'value' => $briefingUuid,
                    'compare' => '='
                ]
            ],
            'fields' => 'ids'
        ];

        $products = get_posts($args);

        return !empty($products) ? (int) $products[0] : null;
    }

    /**
     * Calcular preço baseado nas métricas do Briefing
     *
     * P0.3: Delega para PricingEngine (SSOT)
     *
     * @param Briefing $briefing
     * @return float Preço em R$
     */
    private function calculatePrice(Briefing $briefing): float
    {
        $metrics = $briefing->getMetrics();

        if ($metrics === null) {
            return \LimpVix\Domain\Pricing\PricingEngine::MINIMUM_PRICE;
        }

        $result = \LimpVix\Domain\Pricing\PricingEngine::calculatePrice([
            'estimated_m2' => $metrics->getM2(),
            'property_type' => $briefing->getPropertyType()->isCommercial() ? 'commercial' : 'residential',
            'package_type' => $briefing->getPackage() ? $briefing->getPackage()->getType()->getValue() : 'basic',
        ]);

        return $result['total_price'];
    }

    /**
     * Gerar nome do produto
     *
     * @param Briefing $briefing
     * @return string
     */
    private function generateProductName(Briefing $briefing): string
    {
        $propertyTypeLabel = $briefing->getPropertyType()->isResidential() ? 'Residencial' : 'Comercial';

        $frequencyLabel = 'Serviço Único';
        if ($briefing->getFrequency() !== null) {
            if ($briefing->getFrequency()->isWeekly()) {
                $frequencyLabel = 'Semanal';
            } elseif ($briefing->getFrequency()->isMonthly()) {
                $frequencyLabel = 'Mensal';
            }
        }

        return sprintf(
            'Serviço de Limpeza %s - %s',
            $propertyTypeLabel,
            $frequencyLabel
        );
    }

    /**
     * Gerar descrição curta do produto
     *
     * @param Briefing $briefing
     * @return string
     */
    private function generateShortDescription(Briefing $briefing): string
    {
        $metrics = $briefing->getMetrics();

        if ($metrics === null) {
            return 'Serviço de limpeza profissional LimpVix.';
        }

        return sprintf(
            'Área estimada: %.2f m² | Tempo estimado: %d minutos | %s',
            $metrics->getM2(),
            $metrics->getDurationMinutes(),
            $briefing->requiresContract() ? 'Requer contrato' : 'Serviço avulso'
        );
    }

    /**
     * Gerar descrição completa do produto
     *
     * @param Briefing $briefing
     * @return string HTML
     */
    private function generateProductDescription(Briefing $briefing): string
    {
        $metrics = $briefing->getMetrics();
        $structure = $briefing->getStructure();

        $html = '<h3>Detalhes do Serviço</h3>';

        // Estrutura do imóvel
        if ($structure !== null) {
            $html .= '<p><strong>Estrutura do Imóvel:</strong></p>';
            $html .= '<ul>';

            if ($structure->getBedrooms() > 0) {
                $html .= sprintf('<li>Quartos: %d</li>', $structure->getBedrooms());
            }

            $html .= sprintf('<li>Banheiros: %d</li>', $structure->getBathrooms());

            if ($structure->hasLivingRoom()) {
                $html .= '<li>Sala: Sim</li>';
            }

            if ($structure->hasKitchen()) {
                $html .= '<li>Cozinha: Sim</li>';
            }

            if ($structure->hasOffice()) {
                $html .= '<li>Escritório: Sim</li>';
            }

            if ($structure->hasExternalArea()) {
                $html .= '<li>Área Externa: Sim</li>';
            }

            $html .= '</ul>';
        }

        // Métricas
        if ($metrics !== null) {
            $html .= '<p><strong>Estimativas:</strong></p>';
            $html .= '<ul>';
            $html .= sprintf('<li>Área: %.2f m²</li>', $metrics->getM2());
            $html .= sprintf('<li>Duração: %d minutos</li>', $metrics->getDurationMinutes());
            $html .= sprintf('<li>Buffer operacional: %d minutos</li>', $metrics->getBufferMinutes());
            $html .= '</ul>';
        }

        // Contrato
        if ($briefing->requiresContract()) {
            $html .= '<p><strong>⚠️ Este serviço requer assinatura de contrato.</strong></p>';
        }

        $html .= '<p><em>Produto gerado automaticamente via Briefing LimpVix.</em></p>';

        return $html;
    }

    /**
     * Adicionar metadata do Briefing ao produto
     *
     * @param int $productId
     * @param Briefing $briefing
     * @return void
     */
    private function addBriefingMeta(int $productId, Briefing $briefing): void
    {
        // UUID do Briefing (chave primária de busca)
        update_post_meta($productId, '_limpvix_briefing_uuid', $briefing->getUuid());

        // User ID
        update_post_meta($productId, '_limpvix_briefing_user_id', $briefing->getUserId());

        // Status
        update_post_meta($productId, '_limpvix_briefing_status', $briefing->getStatus()->getValue());

        // Tipo de propriedade
        update_post_meta($productId, '_limpvix_briefing_property_type', $briefing->getPropertyType()->getValue());

        // Requer contrato?
        update_post_meta($productId, '_limpvix_briefing_requires_contract', $briefing->requiresContract() ? 'yes' : 'no');

        // Dados serializados (backup completo)
        $briefingData = [
            'uuid' => $briefing->getUuid(),
            'user_id' => $briefing->getUserId(),
            'property_type' => $briefing->getPropertyType()->getValue(),
            'requires_contract' => $briefing->requiresContract(),
            'created_at' => $briefing->getCreatedAt()->format('Y-m-d H:i:s')
        ];

        if ($briefing->getMetrics() !== null) {
            $briefingData['metrics'] = [
                'm2' => $briefing->getMetrics()->getM2(),
                'duration_minutes' => $briefing->getMetrics()->getDurationMinutes(),
                'buffer_minutes' => $briefing->getMetrics()->getBufferMinutes()
            ];
        }

        update_post_meta($productId, '_limpvix_briefing_data', wp_json_encode($briefingData));
    }

    /**
     * Buscar Briefing UUID por Order ID
     *
     * @param int $orderId WooCommerce Order ID
     * @return string|null Briefing UUID ou null
     */
    public static function getBriefingUuidFromOrder(int $orderId): ?string
    {
        $order = wc_get_order($orderId);

        if (!$order) {
            return null;
        }

        // Buscar nos items da order
        foreach ($order->get_items() as $item) {
            $productId = $item->get_product_id();
            $briefingUuid = get_post_meta($productId, '_limpvix_briefing_uuid', true);

            if (!empty($briefingUuid)) {
                return $briefingUuid;
            }
        }

        return null;
    }
}
