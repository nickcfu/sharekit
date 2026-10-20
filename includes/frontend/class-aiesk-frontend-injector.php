<?php
/**
 * Frontend assets and a local HTML template for immediately available controls.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Frontend_Injector {
    private bool $mounted = false;

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('wp_footer', [$this, 'mount_container'], 5);
    }

    private function has_controls(): bool {
        return aiesk_user_can_share() && (bool) AIESK_Frontend_Render::available_features();
    }

    /** No share assets, nonce, template, or handlers are sent to guests. */
    public function assets(): void {
        if (!$this->has_controls()) {
            return;
        }
        if (function_exists('is_account_page') && is_account_page() && function_exists('is_wc_endpoint_url')
            && (is_wc_endpoint_url(AIESK_Dashboard::ENDPOINT) || is_wc_endpoint_url(AIESK_Dashboard::CONNECTIONS_ENDPOINT))) { return; }
        if (class_exists('AIESK_I18n')) { AIESK_I18n::enqueue(); }
        wp_enqueue_style('aiesk-css', AIESK_URL . 'public/css/aiesk.css', [], AIESK_VERSION);
        wp_enqueue_script('aiesk-qrcode', AIESK_URL . 'public/vendor/qrcode-generator-2.0.4.js', [], '2.0.4', true);
        wp_enqueue_script('aiesk-core', AIESK_URL . 'public/js/aiesk-core.js', ['aiesk-qrcode', 'aiesk-i18n'], AIESK_VERSION, true);

        $ttl = aiesk_parse_ttl();
        $max_hours = max(1, min(720, absint(get_option('aiesk_ttl_max_hours', 720))));
        $default_hours = is_wp_error($ttl) ? min(168, $max_hours) : (int) $ttl['hours'];

        wp_localize_script('aiesk-core', 'aieskCore', [
            'rest' => esc_url_raw(rest_url('aiesk/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'canShare' => true,
            'cloudCallbackOrigin' => class_exists('AIESK_Cloud_Auth') ? AIESK_Cloud_Auth::callback_origin() : '',
            'cloudFormats' => array_values(array_filter(['html', has_filter('aiesk_generate_pdf') ? 'pdf' : null, has_filter('aiesk_generate_docx') ? 'docx' : null])),
            'docxNeedsEasyForms' => class_exists('AIESK_Easy_Forms') && AIESK_Easy_Forms::requires_form_context(),
            'retentionDays' => max(0, min(365, (int) get_option('aiesk_retention_days', 30))),
            'maxHtmlBytes' => AIESK_MAX_SHARE_HTML_BYTES,
            'loginUrl' => esc_url_raw(wp_login_url()),
            'managerUrl' => class_exists('AIESK_Dashboard') ? AIESK_Dashboard::manager_url() : '',
            'settings' => [
                'defaultHours' => $default_hours,
                'maxHours' => $max_hours,
                'theme' => get_option('aiesk_style_theme', 'light') === 'dark' ? 'dark' : 'light',
                'layout' => get_option('aiesk_style_layout', 'dropdown') === 'inline' ? 'inline' : 'dropdown',
            ],
            'labels' => [
                'share' => __('Share', 'ai-share-kit'),
                'expired' => __('This link has expired.', 'ai-share-kit'),
            ],
            'selectors' => [
                '.mwaief-message-list .mwaief-message-assistant',
                '.mwai-reply.mwai-ai.mwai-rendered',
                '.mwai-reply.mwai-ai',
                '[data-mwai-reply]',
            ],
        ]);
    }

    /** The template remains inert until cloned beside an assistant response. */
    public function mount_container(): void {
        if ($this->mounted || !$this->has_controls()) {
            return;
        }
        $html = AIESK_Frontend_Render::bar_html();
        if ($html === '') {
            return;
        }
        $this->mounted = true;
        echo '<template id="aiesk-bar-template">' . $html . '</template>';
        echo '<div id="aiesk-ui-root" class="aiesk-scope"></div>';
    }
}
