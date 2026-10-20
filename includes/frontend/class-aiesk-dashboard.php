<?php
/**
 * Shared-link management entry points. Private records are fetched through REST
 * only after authentication; this shell never embeds report content or tokens.
 */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Dashboard {
    public const ENDPOINT = 'aiesk-links';
    public const CONNECTIONS_ENDPOINT = 'aiesk-connections';
    private array $admin_hooks = [];
    private bool $assets_loaded = false;
    private static int $instance = 0;

    public function __construct() {
        add_action('init', [self::class, 'register_endpoint']);
        add_shortcode('aiesk_my_links', [$this, 'shortcode']);
        add_filter('woocommerce_get_query_vars', [$this, 'account_query_vars']);
        add_filter('woocommerce_account_menu_items', [$this, 'account_menu']);
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', [$this, 'account_title']);
        add_filter('woocommerce_endpoint_' . self::CONNECTIONS_ENDPOINT . '_title', [$this, 'connections_title']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'render_account']);
        add_action('woocommerce_account_' . self::CONNECTIONS_ENDPOINT . '_endpoint', [$this, 'render_connections_account']);
        add_action('admin_menu', [$this, 'admin_menu'], 20);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
    }

    /** Called on init and before the one-time activation/upgrade rewrite flush. */
    public static function register_endpoint(): void {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
        add_rewrite_endpoint(self::CONNECTIONS_ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function account_query_vars(array $vars): array {
        $vars[self::ENDPOINT] = self::ENDPOINT;
        $vars[self::CONNECTIONS_ENDPOINT] = self::CONNECTIONS_ENDPOINT;
        return $vars;
    }

    public function account_title(string $title = ''): string {
        return __('Shared links', 'ai-share-kit');
    }

    public function connections_title(string $title = ''): string {
        return __('Connected accounts', 'ai-share-kit');
    }

    public function account_menu(array $items): array {
        if (!is_user_logged_in()) { return $items; }
        // Preserve other plugins' ordering, including menus without logout.
        unset($items[self::ENDPOINT], $items[self::CONNECTIONS_ENDPOINT]);
        $result = [];
        foreach ($items as $key => $label) {
            if ($key === 'customer-logout') {
                $result[self::ENDPOINT] = __('Shared links', 'ai-share-kit');
                $result[self::CONNECTIONS_ENDPOINT] = __('Connected accounts', 'ai-share-kit');
            }
            $result[$key] = $label;
        }
        if (!isset($result[self::ENDPOINT])) {
            $result[self::ENDPOINT] = __('Shared links', 'ai-share-kit');
        }
        if (!isset($result[self::CONNECTIONS_ENDPOINT])) {
            $result[self::CONNECTIONS_ENDPOINT] = __('Connected accounts', 'ai-share-kit');
        }
        return $result;
    }

    /** Custom dashboard pages can override this with the aiesk_manager_url filter. */
    public static function manager_url(): string {
        $url = admin_url('index.php?page=' . self::ENDPOINT);
        if (function_exists('wc_get_account_endpoint_url') && function_exists('wc_get_page_id') && wc_get_page_id('myaccount') > 0) {
            $url = wc_get_account_endpoint_url(self::ENDPOINT);
        }
        return esc_url_raw((string) apply_filters('aiesk_manager_url', $url));
    }

    public static function connections_url(): string {
        $url = admin_url('index.php?page=' . self::CONNECTIONS_ENDPOINT);
        if (function_exists('wc_get_account_endpoint_url') && function_exists('wc_get_page_id') && wc_get_page_id('myaccount') > 0) {
            $url = wc_get_account_endpoint_url(self::CONNECTIONS_ENDPOINT);
        }
        return esc_url_raw((string) apply_filters('aiesk_connections_url', $url));
    }

    public function admin_menu(): void {
        $mine = add_submenu_page(
            'index.php', __('My shared links', 'ai-share-kit'), __('My shared links', 'ai-share-kit'),
            'read', self::ENDPOINT, [$this, 'render_admin_mine']
        );
        $all = add_submenu_page(
            AIESK_Admin_Menu::MENU_SLUG, __('All shared links', 'ai-share-kit'), __('All shared links', 'ai-share-kit'),
            'manage_options', 'aiesk-all-links', [$this, 'render_admin_all']
        );
        $this->admin_hooks = array_values(array_filter([$mine, $all]));
    }

    public function frontend_assets(): void {
        if (!is_user_logged_in()) { return; }
        $object = get_queried_object();
        $has_shortcode = is_object($object) && isset($object->post_content) && has_shortcode($object->post_content, 'aiesk_my_links');
        $account_endpoint = function_exists('is_account_page') && is_account_page()
            && function_exists('is_wc_endpoint_url')
            && (is_wc_endpoint_url(self::ENDPOINT) || is_wc_endpoint_url(self::CONNECTIONS_ENDPOINT));
        if ($has_shortcode || $account_endpoint) { $this->enqueue_assets(); }
    }

    public function admin_assets(string $hook): void {
        if (in_array($hook, $this->admin_hooks, true)) { $this->enqueue_assets(); }
    }

    public function enqueue_assets(): void {
        if ($this->assets_loaded || !is_user_logged_in()) { return; }
        $this->assets_loaded = true;
        if (class_exists('AIESK_Connections')) { AIESK_Connections::enqueue(); }
        if (class_exists('AIESK_I18n')) { AIESK_I18n::enqueue(); }
        wp_enqueue_style('aiesk-manager-css', AIESK_URL . 'public/css/aiesk-manager.css', [], AIESK_VERSION);
        wp_enqueue_script('aiesk-qrcode', AIESK_URL . 'public/vendor/qrcode-generator-2.0.4.js', [], '2.0.4', true);
        wp_enqueue_script('aiesk-manager-js', AIESK_URL . 'public/js/aiesk-manager.js', ['aiesk-qrcode', 'aiesk-i18n'], AIESK_VERSION, true);
        wp_localize_script('aiesk-manager-js', 'aieskManager', [
            'rest' => esc_url_raw(rest_url('aiesk/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'canManageAll' => current_user_can('manage_options'),
            'messengers' => aiesk_feature_enabled('link') ? array_values(array_filter(['whatsapp', 'viber'], 'aiesk_feature_enabled')) : [],
            'loginUrl' => esc_url_raw(wp_login_url(self::manager_url())),
        ]);
    }

    public function shortcode($attributes = []): string {
        $attributes = shortcode_atts(['scope' => 'mine'], (array) $attributes, 'aiesk_my_links');
        return $this->render((string) $attributes['scope']);
    }

    public function render_account(): void { echo $this->render('mine'); }

    public function render_connections_account(): void {
        echo class_exists('AIESK_Connections') ? AIESK_Connections::render() : '<p>' . esc_html__('Connected accounts are unavailable.', 'ai-share-kit') . '</p>';
    }

    public function render_admin_mine(): void {
        if (!current_user_can('read')) {
            wp_die(__('You are not allowed to view this page.', 'ai-share-kit'));
        }
        echo '<div class="wrap aiesk-manager-admin">' . $this->render('mine') . '</div>';
    }

    public function render_admin_all(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You are not allowed to view this page.', 'ai-share-kit'));
        }
        echo '<div class="wrap aiesk-manager-admin">' . $this->render('all') . '</div>';
    }

    public function render(string $scope = 'mine'): string {
        if (!is_user_logged_in()) {
            return '<p class="aiesk-manager-signin">' . esc_html__('Please sign in to manage your shared links.', 'ai-share-kit') . '</p>';
        }
        $can_manage_all = current_user_can('manage_options');
        $scope = $scope === 'all' && $can_manage_all ? 'all' : 'mine';
        $this->enqueue_assets();
        $id = 'aiesk-manager-' . ++self::$instance;
        $title = $scope === 'all' ? __('All shared links', 'ai-share-kit') : __('My shared links', 'ai-share-kit');
        $theme = get_option('aiesk_style_theme', 'light') === 'dark' ? 'dark' : 'light';
        ob_start();
        // A page builder may execute this shortcode after wp_head. Print only
        // our unprinted style in that case; scripts remain footer-enqueued.
        if (did_action('wp_head') && !wp_style_is('aiesk-manager-css', 'done')) {
            wp_print_styles(['aiesk-manager-css']);
        }
        ?>
        <section class="aiesk-manager aiesk-scope" data-aiesk-manager data-scope="<?php echo esc_attr($scope); ?>" data-theme="<?php echo esc_attr($theme); ?>" aria-labelledby="<?php echo esc_attr($id); ?>-title">
            <header class="aiesk-manager-header">
                <div>
                    <h2 id="<?php echo esc_attr($id); ?>-title" data-aiesk-manager-title><?php echo esc_html($title); ?></h2>
                    <p class="aiesk-manager-subtitle"><?php echo esc_html__('Find a shared report, copy its link, or stop access when you need to.', 'ai-share-kit'); ?></p>
                </div>
                <button type="button" class="aiesk-manager-refresh" data-aiesk-refresh aria-label="<?php echo esc_attr__('Refresh shared links', 'ai-share-kit'); ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M6.1 6.1A8.3 8.3 0 0 1 20 12M4 12a8.3 8.3 0 0 0 13.9 5.9"/></svg>
                    <span><?php echo esc_html__('Refresh', 'ai-share-kit'); ?></span>
                </button>
            </header>
            <div class="aiesk-manager-tools">
                <form class="aiesk-manager-search" data-aiesk-search-form role="search" aria-label="<?php echo esc_attr__('Search shared reports', 'ai-share-kit'); ?>">
                    <label class="aiesk-manager-sr" for="<?php echo esc_attr($id); ?>-search"><?php echo esc_html__('Search report titles', 'ai-share-kit'); ?></label>
                    <input id="<?php echo esc_attr($id); ?>-search" type="search" name="search" maxlength="180" placeholder="<?php echo esc_attr__('Search report titles…', 'ai-share-kit'); ?>" autocomplete="off">
                    <button type="submit"><?php echo esc_html__('Search', 'ai-share-kit'); ?></button>
                </form>
                <?php if ($can_manage_all): ?>
                    <label class="aiesk-manager-scope"><span><?php echo esc_html__('Show', 'ai-share-kit'); ?></span>
                        <select name="scope" aria-label="<?php echo esc_attr__('Whose links to show', 'ai-share-kit'); ?>">
                            <option value="mine"<?php echo $scope === 'mine' ? ' selected' : ''; ?>><?php echo esc_html__('My links', 'ai-share-kit'); ?></option>
                            <option value="all"<?php echo $scope === 'all' ? ' selected' : ''; ?>><?php echo esc_html__('All teachers', 'ai-share-kit'); ?></option>
                        </select>
                    </label>
                <?php endif; ?>
            </div>
            <div class="aiesk-manager-filters" role="group" aria-label="<?php echo esc_attr__('Filter by link status', 'ai-share-kit'); ?>">
                <?php foreach (['all' => __('All', 'ai-share-kit'), 'active' => __('Active', 'ai-share-kit'), 'expired' => __('Expired', 'ai-share-kit'), 'revoked' => __('Revoked', 'ai-share-kit')] as $status => $label): ?>
                    <button type="button" data-status="<?php echo esc_attr($status); ?>" aria-pressed="<?php echo $status === 'all' ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button>
                <?php endforeach; ?>
            </div>
            <p class="aiesk-manager-status" data-aiesk-manager-status role="status" aria-live="polite"><?php echo esc_html__('Loading your shared links…', 'ai-share-kit'); ?></p>
            <div class="aiesk-manager-list" data-aiesk-list aria-busy="true"></div>
            <nav class="aiesk-manager-pagination" aria-label="<?php echo esc_attr__('Shared link pages', 'ai-share-kit'); ?>">
                <button type="button" data-page="previous" disabled><?php echo esc_html__('Previous', 'ai-share-kit'); ?></button>
                <span data-aiesk-pagination-label></span>
                <button type="button" data-page="next" disabled><?php echo esc_html__('Next', 'ai-share-kit'); ?></button>
            </nav>
            <p class="aiesk-manager-note"><?php echo esc_html__('Anyone with an active link can view its shared report. Revoking it also stops access through its QR code.', 'ai-share-kit'); ?></p>
            <noscript><p><?php echo esc_html__('Enable JavaScript to load and manage your shared links.', 'ai-share-kit'); ?></p></noscript>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
