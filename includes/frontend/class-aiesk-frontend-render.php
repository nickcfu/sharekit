<?php
/**
 * Shared markup for the page template and the legacy render-bar REST route.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Frontend_Render {

    public function __construct() {
        add_action('rest_api_init', function () {
            register_rest_route('aiesk/v1', '/render-bar', [
                'methods'             => 'GET',
                'callback'            => [$this, 'render_bar'],
                'permission_callback' => function () { return aiesk_rest_permission(); },
            ]);
        });
    }

    /**
     * Only offer actions that have an implementation connected on this site.
     * Cloud providers require administrator setup before they appear.
     */
    public static function available_features(): array {
        $enabled = [];
        foreach (['docx', 'link', 'qr', 'whatsapp', 'viber', 'email', 'pdf', 'drive', 'onedrive', 'dropbox'] as $feature) {
            $available = aiesk_feature_enabled($feature);
            if (in_array($feature, ['qr', 'whatsapp', 'viber'], true)) {
                // Messaging and QR actions share a private link.
                $available = $available && aiesk_feature_enabled('link');
            } elseif (in_array($feature, ['docx', 'pdf'], true)) {
                $available = $available && has_filter('aiesk_generate_' . $feature);
            } elseif (in_array($feature, ['drive', 'dropbox', 'onedrive'], true)) {
                $available = $available && class_exists('AIESK_Cloud_Auth') && AIESK_Cloud_Auth::configured($feature);
            }
            if ($available) {
                $enabled[$feature] = true;
            }
        }
        return $enabled;
    }

    /** GET /aiesk/v1/render-bar is retained for existing integrations. */
    public function render_bar(WP_REST_Request $req) {
        return rest_ensure_response(['html' => self::bar_html()]);
    }

    /** Render once per page; the browser clones this small, inert template. */
    public static function bar_html(): string {
        if (!aiesk_user_can_share()) {
            return '';
        }

        $enabled = self::available_features();
        if (!$enabled) {
            return '';
        }

        $layout = get_option('aiesk_style_layout', 'dropdown') === 'inline' ? 'inline' : 'dropdown';
        ob_start();
        echo '<div class="aiesk-scope aiesk-share-bar aiesk-' . esc_attr($layout) . '" data-aiesk>';
        if ($layout === 'dropdown') {
            echo '<button type="button" class="aiesk-trigger" aria-label="' . esc_attr__('Share response', 'ai-share-kit') . '" aria-expanded="false" aria-haspopup="menu">';
            echo self::icon('share');
            echo '<span>' . esc_html__('Share', 'ai-share-kit') . '</span>';
            echo '<svg class="aiesk-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>';
            echo '</button>';
            // JS assigns a unique menu ID and aria-controls to each clone.
            echo '<div class="aiesk-menu" role="menu" aria-label="' . esc_attr__('Share response', 'ai-share-kit') . '">';
            self::buttons($enabled, true);
            echo '</div>';
        } else {
            self::buttons($enabled, false);
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    private static function buttons(array $enabled, bool $menu): void {
        $labels = [
            'link'     => __('Create link', 'ai-share-kit'),
            'qr'       => __('QR code', 'ai-share-kit'),
            'whatsapp' => __('WhatsApp', 'ai-share-kit'),
            'viber'    => __('Viber', 'ai-share-kit'),
            'email'    => __('Email', 'ai-share-kit'),
            'docx'     => __('Download Word (.docx)', 'ai-share-kit'),
            'pdf'      => __('PDF document', 'ai-share-kit'),
            'drive'    => __('Google Drive', 'ai-share-kit'),
            'dropbox'  => __('Dropbox', 'ai-share-kit'),
            'onedrive' => __('OneDrive', 'ai-share-kit'),
        ];

        foreach ($enabled as $feature => $on) {
            if (!$on || !isset($labels[$feature])) {
                continue;
            }
            printf(
                '<button type="button" class="aiesk-btn aiesk-btn-%1$s" data-action="%1$s"%2$s>%3$s<span class="aiesk-action-label">%4$s</span></button>',
                esc_attr($feature),
                $menu ? ' role="menuitem"' : '',
                self::icon($feature),
                esc_html($labels[$feature])
            );
        }
    }

    /** All SVG paths below are fixed plugin assets, never supplied by a user. */
    private static function icon(string $name): string {
        $paths = [
            'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4m-6.8 7 6.8 4"/>',
            'link' => '<path d="m10 13 4-4m-6 6-1 1a4.24 4.24 0 0 1-6-6l4-4a4.24 4.24 0 0 1 6 0m2 3 1-1a4.24 4.24 0 0 1 6 6l-4 4a4.24 4.24 0 0 1-6 0"/>',
            'qr' => '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="3" width="6" height="6" rx="1"/><rect x="3" y="15" width="6" height="6" rx="1"/><path d="M15 15h3v3h3v3h-6v-3m6-3v-3M12 3v3M3 12h3m6 0v3"/>',
            'whatsapp' => '<path d="M21 11.5a9 9 0 0 1-13.3 7.9L3 21l1.6-4.7A9 9 0 1 1 21 11.5Z"/><path d="m8 7 2 3-1 1a9 9 0 0 0 4 4l1-1 3 2c-1 3-5 1-7-1s-4-6-2-8Z"/>',
            'viber' => '<path d="M20 4c-3-2-13-2-16 1-2 2-2 11 1 13h3v4l4-4h5c4-1 5-12 3-14Z"/><path d="m8 7 2 3-1 1 3 3 1-1 3 2c-1 3-4 1-6-1S6 9 8 7Zm5 0a4 4 0 0 1 4 4m-4-1a1 1 0 0 1 1 1"/>',
            'eml' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
            'email' => '<path d="m22 2-7 20-4-9-9-4 20-7ZM22 2 11 13"/>',
            'docx' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm0 0v6h6M8 13l2 5 2-5 2 5 2-5"/>',
            'pdf' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm0 0v6h6M8 13h8M8 17h6"/>',
            'drive' => '<path d="m9 3 6 0 7 12-3 6H5l-3-6 7-12Zm0 0 10 18M15 3 5 21M2 15h20"/>',
            'dropbox' => '<path d="m6 3 6 4-6 4-6-4 6-4Zm12 0 6 4-6 4-6-4 6-4ZM6 11l6 4-6 4-6-4 6-4Zm12 0 6 4-6 4-6-4 6-4Zm-12 8 6 4 6-4"/>',
            'onedrive' => '<path d="M7 18H5a4 4 0 0 1-.5-8A6 6 0 0 1 16 7a4 4 0 0 1 3 7.5A3.5 3.5 0 0 1 18.5 21H7a3.5 3.5 0 0 1 0-7 4.5 4.5 0 0 1 8-2 3.5 3.5 0 0 1 4 2.5"/>',
        ];
        return '<svg class="aiesk-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['share']) . '</svg>';
    }
}
