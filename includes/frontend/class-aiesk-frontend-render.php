<?php
/**
 * Frontend — Render
 * Returns the share bar HTML for auto-injection below the last Meow AI reply.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Frontend_Render {

    public function __construct() {
        add_action('rest_api_init', function () {
            register_rest_route('aiesk/v1', '/render-bar', [
                'methods'             => 'GET',
                'callback'            => [$this, 'render_bar'],
                'permission_callback' => function () { return is_user_logged_in(); },
            ]);
        });
    }

    /**
     * GET /aiesk/v1/render-bar
     * Returns { html: "...share bar..." }
     */
    public function render_bar(WP_REST_Request $req) {
        $layout = get_option('aiesk_style_layout', 'dropdown'); // dropdown|inline

        // Enabled features
        $enabled = [
            'email'    => (bool) get_option('aiesk_enable_email',    false),
            'eml'      => (bool) get_option('aiesk_enable_eml',      true),
            'link'     => (bool) get_option('aiesk_enable_link',     true),
            'qr'       => (bool) get_option('aiesk_enable_qr',       true),
            'docx'     => (bool) get_option('aiesk_enable_docx',     true),
            'pdf'      => (bool) get_option('aiesk_enable_pdf',      true),
            'dropbox'  => (bool) get_option('aiesk_enable_dropbox',  false),
            'drive'    => (bool) get_option('aiesk_enable_drive',    false),
            'onedrive' => (bool) get_option('aiesk_enable_onedrive', false),
        ];

        ob_start();

        if ($layout === 'inline') {
            echo '<div class="aiesk-share-bar aiesk-inline" data-aiesk>';
            $this->buttons($enabled);
            echo '</div>';
        } else {
            echo '<div class="aiesk-share-bar aiesk-dropdown" data-aiesk>';
            echo '<button type="button" class="aiesk-trigger">' . esc_html__('share', 'ai-share-kit') . '</button>';
            echo '<div class="aiesk-menu">';
            $this->buttons($enabled);
            echo '</div></div>';
        }

        $html = (string) ob_get_clean();
        return rest_ensure_response(['html' => $html]);
    }

    /**
     * Print enabled buttons with matching data-action for JS core.
     */
    private function buttons(array $enabled): void {
        foreach ($enabled as $key => $on) {
            if (!$on) continue;

            // Map label casing
            $labels = [
                'email'    => __('Email', 'ai-share-kit'),
                'eml'      => __('EML', 'ai-share-kit'),
                'link'     => __('Link', 'ai-share-kit'),
                'qr'       => __('QR', 'ai-share-kit'),
                'docx'     => __('DOCX', 'ai-share-kit'),
                'pdf'      => __('PDF', 'ai-share-kit'),
                'dropbox'  => __('Dropbox', 'ai-share-kit'),
                'drive'    => __('Google Drive', 'ai-share-kit'),
                'onedrive' => __('OneDrive', 'ai-share-kit'),
            ];

            printf(
                '<button type="button" class="aiesk-btn aiesk-btn-%1$s" data-action="%1$s">%2$s</button>',
                esc_attr($key),
                esc_html($labels[$key] ?? ucfirst($key))
            );
        }
    }
}
