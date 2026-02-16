<?php
/**
 * UIComponents - Componentes Reutilizáveis de UI
 *
 * @package LimpVix\Infrastructure\Admin\UI
 */

namespace LimpVix\Infrastructure\Admin\UI;

defined('ABSPATH') || exit;

final class UIComponents
{
    /**
     * Header da página
     */
    public static function header(string $title, array $actions = []): void
    {
        ?>
        <div class="limpvix-page-header">
            <h1><?php echo esc_html($title); ?></h1>
            <?php if (!empty($actions)): ?>
                <div class="limpvix-page-actions">
                    <?php foreach ($actions as $action): ?>
                        <a href="<?php echo esc_url($action['url']); ?>"
                           class="button button-<?php echo esc_attr($action['class'] ?? 'secondary'); ?>"
                           <?php if (isset($action['onclick'])): ?>
                               onclick="<?php echo esc_attr($action['onclick']); ?>; return false;"
                           <?php endif; ?>>
                            <?php echo esc_html($action['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Badge
     */
    public static function badge(string $label, string $type = 'default'): void
    {
        $colors = [
            'success' => '#46b450',
            'danger' => '#dc3232',
            'warning' => '#ffb900',
            'info' => '#6c70dc',
            'secondary' => '#999',
            'default' => '#666',
        ];

        $color = $colors[$type] ?? $colors['default'];

        ?>
        <span class="limpvix-badge" style="background: <?php echo esc_attr($color); ?>; color: white; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
            <?php echo esc_html($label); ?>
        </span>
        <?php
    }

    /**
     * Portlet Start
     */
    public static function portletStart(?string $title = null, ?string $subtitle = null, array $headerActions = []): void
    {
        ?>
        <div class="limpvix-portlet" style="background: white; padding: 0; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <?php if ($title): ?>
                <div class="limpvix-portlet-header" style="padding: 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h2 style="margin: 0; font-size: 18px;"><?php echo esc_html($title); ?></h2>
                        <?php if ($subtitle): ?>
                            <p style="margin: 5px 0 0; color: #666; font-size: 13px;"><?php echo esc_html($subtitle); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($headerActions)): ?>
                        <div class="limpvix-portlet-actions">
                            <?php foreach ($headerActions as $action): ?>
                                <a href="<?php echo esc_url($action['url']); ?>"
                                   class="button button-small">
                                    <?php echo esc_html($action['label']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="limpvix-portlet-body" style="padding: 20px;">
        <?php
    }

    /**
     * Portlet End
     */
    public static function portletEnd(): void
    {
        ?>
            </div>
        </div>
        <?php
    }
}
