<?php
/**
 * Admin – TTL Links & QR
 * Provides default TTL settings and the /shared/{token} public endpoint.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Admin_TTL {

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('query_vars', function ($vars) {
            $vars[] = 'aiesk_token';
            return $vars;
        });
        add_action('init', function () {
            add_rewrite_rule('^shared/([^/]+)/?$', 'index.php?aiesk_token=$matches[1]', 'top');
        });
        add_action('template_redirect', [$this, 'render_shared']);
    }

    /** Register hidden CPT to store shared messages */
    public function register_cpt(): void {
        register_post_type('aiesk_link', [
            'labels' => ['name' => 'Shared Messages'],
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
        ]);
    }

    /** Settings for default TTL (amount + unit) */
    public function register_settings(): void {
        register_setting('aiesk_ttl', 'aiesk_ttl_default_amount', [
            'type' => 'integer', 'default' => 7, 'sanitize_callback' => 'absint'
        ]);
        register_setting('aiesk_ttl', 'aiesk_ttl_default_unit', [
            'type' => 'string', 'default' => 'days', 'sanitize_callback' => 'sanitize_text_field'
        ]);

        add_settings_section(
            'aiesk_ttl_section',
            __('Defaults', 'ai-share-kit'),
            fn() => print('<p>' . esc_html__('Default TTL for generated links or QR codes.', 'ai-share-kit') . '</p>'),
            'aiesk_ttl'
        );

        add_settings_field(
            'aiesk_ttl_default_amount',
            __('Amount', 'ai-share-kit'),
            function () {
                $v = (int) get_option('aiesk_ttl_default_amount', 7);
                echo '<input type="number" name="aiesk_ttl_default_amount" value="' . esc_attr($v) . '" min="1" max="365" />';
            },
            'aiesk_ttl',
            'aiesk_ttl_section'
        );

        add_settings_field(
            'aiesk_ttl_default_unit',
            __('Unit', 'ai-share-kit'),
            function () {
                $u = get_option('aiesk_ttl_default_unit', 'days');
                echo '<select name="aiesk_ttl_default_unit">';
                echo '<option value="days"' . selected($u, 'days', false) . '>Days</option>';
                echo '<option value="weeks"' . selected($u, 'weeks', false) . '>Weeks</option>';
                echo '<option value="months"' . selected($u, 'months', false) . '>Months</option>';
                echo '</select>';
            },
            'aiesk_ttl',
            'aiesk_ttl_section'
        );
    }

    /** Render public shared link or "expired" message */
    public function render_shared(): void {
        $token = get_query_var('aiesk_token');
        if (!$token) return;

        // Find by token
        $q = new WP_Query([
            'post_type'      => 'aiesk_link',
            'posts_per_page' => 1,
            'meta_key'       => 'aiesk_token',
            'meta_value'     => sanitize_text_field($token),
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);

        if (empty($q->posts)) {
            status_header(404);
            echo esc_html__('this link expired', 'ai-share-kit');
            exit;
        }

        $post_id = $q->posts[0];
        $expires_at = (int) get_post_meta($post_id, 'aiesk_expires_at', true);
        if ($expires_at && time() > $expires_at) {
            status_header(410);
            echo esc_html__('this link expired', 'ai-share-kit');
            exit;
        }

        $html = get_post_meta($post_id, 'aiesk_payload_html', true);
        echo '<!doctype html><meta charset="utf-8">';
        echo '<title>' . esc_html(get_the_title($post_id)) . '</title>';
        echo '<div class="aiesk-scope" style="max-width:840px;margin:24px auto;padding:16px;background:var(--aiesk-bg);color:var(--aiesk-fg)">';
        echo wp_kses_post($html);
        echo '</div>';
        exit;
    }
}
