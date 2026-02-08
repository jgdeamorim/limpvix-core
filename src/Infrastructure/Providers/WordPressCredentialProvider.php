<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\Providers;

use LimpVix\Common\CredentialProviderInterface;

/**
 * WordPress Credential Provider
 *
 * Implementação que busca credenciais de:
 * 1. wp_options (limpvix_*)
 * 2. WooCommerce settings (woocommerce_mercadopago_settings)
 * 3. Constantes (LIMPVIX_* - fallback)
 *
 * @package LimpVix\Infrastructure\Providers
 */
final class WordPressCredentialProvider implements CredentialProviderInterface
{
    public function has(string $key): bool
    {
        return !empty($this->raw($key));
    }

    public function get(string $key): string
    {
        $value = $this->raw($key);

        if (!$value) {
            throw new \RuntimeException("Credential missing: {$key}");
        }

        return $value;
    }

    public function getOrDefault(string $key, ?string $default = null): ?string
    {
        $value = $this->raw($key);
        return $value ?: $default;
    }

    private function raw(string $key): ?string
    {
        return match ($key) {
            // TWILIO
            'twilio.account_sid' => $this->resolve([
                fn() => get_option('limpvix_twilio_account_sid'),
                fn() => defined('LIMPVIX_TWILIO_ACCOUNT_SID') ? LIMPVIX_TWILIO_ACCOUNT_SID : null,
            ]),
            'twilio.auth_token' => $this->resolve([
                fn() => get_option('limpvix_twilio_auth_token'),
                fn() => defined('LIMPVIX_TWILIO_AUTH_TOKEN') ? LIMPVIX_TWILIO_AUTH_TOKEN : null,
            ]),
            'twilio.from_number' => $this->resolve([
                fn() => get_option('limpvix_twilio_from_number'),
                fn() => defined('LIMPVIX_TWILIO_FROM_NUMBER') ? LIMPVIX_TWILIO_FROM_NUMBER : null,
            ]),

            // 360DIALOG
            'whatsapp.api_key' => $this->resolve([
                fn() => get_option('limpvix_360dialog_api_key'),
                fn() => defined('LIMPVIX_360DIALOG_API_KEY') ? LIMPVIX_360DIALOG_API_KEY : null,
            ]),

            // MERCADO PAGO (WooCommerce primeiro)
            'mercadopago.access_token' => $this->resolve([
                fn() => get_option('_mp_access_token_prod'), // WooCommerce MP Produção
                fn() => get_option('limpvix_mp_access_token_prod'), // LimpVix próprio (prod)
                fn() => get_option('_mp_access_token_test'), // WooCommerce MP Test
                fn() => get_option('limpvix_mercadopago_access_token'), // Fallback antigo
                fn() => defined('LIMPVIX_MERCADOPAGO_ACCESS_TOKEN') ? LIMPVIX_MERCADOPAGO_ACCESS_TOKEN : null,
            ]),

            'mercadopago.public_key' => $this->resolve([
                fn() => get_option('_mp_public_key_prod'), // WooCommerce MP Produção
                fn() => get_option('limpvix_mp_public_key_prod'), // LimpVix próprio
                fn() => get_option('_mp_public_key_test'), // WooCommerce MP Test
            ]),

            // GOOGLE MEU NEGÓCIO
            'gmb.place_id' => $this->resolve([
                fn() => get_option('limpvix_gmb_place_id'),
                fn() => defined('LIMPVIX_GMB_PLACE_ID') ? LIMPVIX_GMB_PLACE_ID : null,
            ]),
            'gmb.review_url' => $this->resolve([
                fn() => get_option('limpvix_gmb_review_url'),
                fn() => defined('LIMPVIX_GMB_REVIEW_URL') ? LIMPVIX_GMB_REVIEW_URL : null,
            ]),

            default => null,
        };
    }

    private function resolve(array $resolvers): ?string
    {
        foreach ($resolvers as $resolver) {
            $value = $resolver();
            if (!empty($value)) {
                return $value;
            }
        }
        return null;
    }

}
