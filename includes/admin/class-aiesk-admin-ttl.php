<?php
/**
 * Admin – TTL Links & QR
 * Provides default TTL settings and the /s/{token} and legacy /shared/{token} endpoints.
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
        add_action('init', [$this, 'register_rewrite_rule']);
        add_action('template_redirect', [$this, 'render_shared']);
    }

    /** Register hidden CPT to store shared messages */
    public function register_cpt(): void {
        register_post_type('aiesk_link', [
            'labels' => ['name' => 'Shared Messages'],
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'can_export' => false,
            // Hidden storage must not inherit Editor's ordinary post powers.
            // Owner operations are exposed only through the plugin's services.
            'capability_type' => ['aiesk_link', 'aiesk_links'],
            'map_meta_cap' => false,
            'capabilities' => array_fill_keys([
                'read_post', 'edit_post', 'delete_post', 'read', 'edit_posts',
                'edit_others_posts', 'publish_posts', 'read_private_posts',
                'delete_posts', 'delete_private_posts', 'delete_published_posts',
                'delete_others_posts', 'edit_private_posts', 'edit_published_posts',
                'create_posts',
            ], 'manage_options'),
            'supports' => ['title'],
        ]);
    }

    public function register_rewrite_rule(): void {
        add_rewrite_rule('^s/([A-Za-z0-9_-]{22})/?$', 'index.php?aiesk_token=$matches[1]', 'top');
        add_rewrite_rule('^shared/([a-f0-9]{64}|[A-Za-z0-9_-]{20,128})/?$', 'index.php?aiesk_token=$matches[1]', 'top');
    }

    /** Settings for default TTL (amount + unit) */
    public function register_settings(): void {
        register_setting('aiesk_ttl', 'aiesk_ttl_default_amount', [
            'type' => 'integer', 'default' => 7, 'sanitize_callback' => function ($value) {
                return max(1, min(720, absint($value)));
            }
        ]);
        register_setting('aiesk_ttl', 'aiesk_ttl_default_unit', [
            'type' => 'string', 'default' => 'days', 'sanitize_callback' => function ($value) {
                $value = sanitize_key((string) $value);
                return in_array($value, ['hours', 'days', 'weeks', 'months'], true) ? $value : 'days';
            }
        ]);
        register_setting('aiesk_ttl', 'aiesk_ttl_max_hours', [
            'type' => 'integer', 'default' => 720, 'sanitize_callback' => function ($value) {
                return max(1, min(720, absint($value)));
            }
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
                echo '<input type="number" name="aiesk_ttl_default_amount" value="' . esc_attr($v) . '" min="1" max="720" />';
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
                echo '<option value="hours"' . selected($u, 'hours', false) . '>Hours</option>';
                echo '<option value="days"' . selected($u, 'days', false) . '>Days</option>';
                echo '<option value="weeks"' . selected($u, 'weeks', false) . '>Weeks</option>';
                echo '<option value="months"' . selected($u, 'months', false) . '>Months</option>';
                echo '</select>';
            },
            'aiesk_ttl',
            'aiesk_ttl_section'
        );

        add_settings_field(
            'aiesk_ttl_max_hours',
            __('Maximum lifetime (hours)', 'ai-share-kit'),
            function () {
                $v = (int) get_option('aiesk_ttl_max_hours', 720);
                echo '<input type="number" name="aiesk_ttl_max_hours" value="' . esc_attr($v) . '" min="1" max="720" />';
                echo '<p class="description">' . esc_html__('The hard maximum is 720 hours (30 days).', 'ai-share-kit') . '</p>';
            },
            'aiesk_ttl',
            'aiesk_ttl_section'
        );
    }

    public static function find_by_token(string $token): int {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)) {
            return 0;
        }

        if (class_exists('AIESK_Index')) {
            $indexed = AIESK_Index::find_token($token);
            // null means migration is incomplete; 0 is a definitive miss.
            if ($indexed !== null) {
                $post_id = (int) $indexed;
                $post = $post_id > 0 ? get_post($post_id) : null;
                if (!$post || $post->post_type !== 'aiesk_link' || $post->post_status !== 'publish') { return 0; }
                $stored = (string) get_post_meta($post_id, 'aiesk_token', true);
                return $stored !== '' && hash_equals($stored, $token) ? $post_id : 0;
            }
        }

        $posts = get_posts([
            'post_type' => 'aiesk_link',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => 'aiesk_token_hash',
            'meta_value' => aiesk_token_hash($token),
        ]);

        if (!empty($posts)) {
            $post_id = (int) $posts[0];
            $stored = (string) get_post_meta($post_id, 'aiesk_token', true);
            return $stored !== '' && hash_equals($stored, $token) ? $post_id : 0;
        }

        // Resolve links created by the earlier version and transparently upgrade them.
        $legacy = get_posts([
            'post_type' => 'aiesk_link',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => 'aiesk_token',
            'meta_value' => $token,
            'meta_type' => 'BINARY',
        ]);
        if (empty($legacy)) {
            return 0;
        }

        $post_id = (int) $legacy[0];
        $stored = (string) get_post_meta($post_id, 'aiesk_token', true);
        // WordPress database collations are commonly case-insensitive.
        // Never accept a case-folded bearer token, even on an old share record.
        if ($stored === '' || !hash_equals($stored, $token)) {
            return 0;
        }
        update_post_meta($post_id, 'aiesk_token_hash', aiesk_token_hash($token));
        return $post_id;
    }

    public static function token_for_post(int $post_id): string {
        $token = (string) get_post_meta($post_id, 'aiesk_token', true);
        return preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token) ? $token : '';
    }

    /** Render public shared link without loading the theme or third-party assets. */
    public function render_shared(): void {
        $token = get_query_var('aiesk_token');
        if (!$token) return;

        $post_id = self::find_by_token((string) $token);
        if (!$post_id || aiesk_link_status($post_id) !== 'active') {
            self::reader_headers(404);
            echo self::unavailable_document();
            exit;
        }

        self::reader_headers(200);
        echo self::report_document((string) get_the_title($post_id), (string) get_post_meta($post_id, 'aiesk_payload_html', true), (string) get_post_meta($post_id, 'aiesk_report_lang', true));
        exit;
    }

    private static function reader_headers(int $status): void {
        status_header($status);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8', true);
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        header('Referrer-Policy: no-referrer', true);
        header('X-Content-Type-Options: nosniff', true);
        header('X-Frame-Options: DENY', true);
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()', true);
        header('Content-Security-Policy: ' . self::reader_policy(), true);
    }

    public static function reader_policy(): string {
        // Same-site or embedded images remain useful for reports. Remote images
        // and fonts are blocked so opening a private report cannot ping trackers.
        return "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none';";
    }

    public static function report_document(string $title, string $html, string $language = ''): string {
        $html = wp_kses_post($html);
        if (class_exists('WP_HTML_Tag_Processor')) {
            $processor = new WP_HTML_Tag_Processor($html);
            while ($processor->next_tag('table')) {
                $processor->set_attribute('tabindex', '0');
                $processor->set_attribute('aria-label', __('Scrollable report table', 'ai-share-kit'));
            }
            $html = $processor->get_updated_html();
        }
        return self::document_start($title, $language)
            . '<main class="aiesk-report"><header class="aiesk-report-header"><h1 dir="auto">' . esc_html($title) . '</h1></header>'
            . '<article class="aiesk-report-content aiesk-scope" dir="auto">' . $html . '</article></main></body></html>';
    }

    public static function unavailable_document(): string {
        $title = __('This private link is unavailable.', 'ai-share-kit');
        return self::document_start($title, str_replace('_', '-', determine_locale()))
            . '<main class="aiesk-report-error"><h1>' . esc_html($title) . '</h1><p>'
            . esc_html__('Ask your teacher for a new link.', 'ai-share-kit') . '</p></main></body></html>';
    }

    private static function document_start(string $title, string $language): string {
        // Never label a report with the site's or the currently selected UI
        // language. Legacy snapshots have no reliable language metadata.
        $language = trim($language);
        $lang = strlen($language) <= 63 && preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $language)
            ? ' lang="' . esc_attr($language) . '"' : '';
        $css = (string) file_get_contents(__DIR__ . '/../../public/css/aiesk-report.css');
        return '<!doctype html><html' . $lang . ' dir="auto"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive"><meta name="referrer" content="no-referrer">'
            // frame-ancestors is enforced by the HTTP header, not a meta policy.
            . '<meta http-equiv="Content-Security-Policy" content="' . esc_attr(str_replace(" frame-ancestors 'none';", '', self::reader_policy())) . '">'
            . '<title>' . esc_html($title) . '</title><style>' . $css . '</style></head><body>';
    }
}
