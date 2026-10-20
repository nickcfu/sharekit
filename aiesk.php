<?php
/**
 * Plugin Name: AI Share Kit
 * Description: Private report sharing, cloud saves, messaging links, local QR codes, and a teacher link-management dashboard.
 * Version: 2.4.0-rc.11
 * Author: Nick Syrigos CreativeOptions.eu
 * Text Domain: ai-share-kit
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

/** ----------------------------------------------------------------
 *  Constants
 * ---------------------------------------------------------------- */
if (!defined('AIESK_VERSION')) { define('AIESK_VERSION', '2.4.0-rc.11'); }
if (!defined('AIESK_FILE')) { define('AIESK_FILE', __FILE__); }
if (!defined('AIESK_PATH')) { define('AIESK_PATH', plugin_dir_path(__FILE__)); }
if (!defined('AIESK_URL')) { define('AIESK_URL', plugin_dir_url(__FILE__)); }

/** ----------------------------------------------------------------
 *  Load Text Domain
 * ---------------------------------------------------------------- */
add_action('init', function () {
    if (get_option('aiesk_storage_version') !== '2.3.0-rc.2') {
        aiesk_cleanup_private_downloads();
        update_option('aiesk_storage_version', '2.3.0-rc.2', false);
    }
    load_plugin_textdomain('ai-share-kit', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}, 1);

/** ----------------------------------------------------------------
 *  Includes
 * ---------------------------------------------------------------- */
add_action('plugins_loaded', function () {

    require_once AIESK_PATH . 'includes/class-aiesk-helpers.php';
    require_once AIESK_PATH . 'includes/class-aiesk-access.php';
    require_once AIESK_PATH . 'includes/class-aiesk-index.php';
    require_once AIESK_PATH . 'includes/class-aiesk-download-budget.php';
    require_once AIESK_PATH . 'includes/class-aiesk-report-context.php';
    require_once AIESK_PATH . 'includes/class-aiesk-uninstall.php';
    require_once AIESK_PATH . 'includes/class-aiesk-limits.php';
    require_once AIESK_PATH . 'includes/class-aiesk-privacy.php';
    require_once AIESK_PATH . 'includes/class-aiesk-i18n.php';

    // Admin
    require_once AIESK_PATH . 'includes/class-aiesk-admin-menu.php';
    require_once AIESK_PATH . 'includes/admin/class-aiesk-admin-sharing.php';
    require_once AIESK_PATH . 'includes/admin/class-aiesk-admin-styles.php';
    require_once AIESK_PATH . 'includes/admin/class-aiesk-admin-ttl.php';
    require_once AIESK_PATH . 'includes/admin/class-aiesk-admin-health.php';

    // Frontend
    require_once AIESK_PATH . 'includes/frontend/class-aiesk-frontend-injector.php';
    require_once AIESK_PATH . 'includes/frontend/class-aiesk-frontend-render.php';
    require_once AIESK_PATH . 'includes/frontend/class-aiesk-dashboard.php';
    require_once AIESK_PATH . 'includes/frontend/class-aiesk-connections.php';
    require_once AIESK_PATH . 'includes/class-aiesk-link-manager.php';

    // REST
    require_once AIESK_PATH . 'includes/routes/class-aiesk-rest.php';

    // Sharing features
    require_once AIESK_PATH . 'includes/sharing/class-aiesk-share-email.php';
    require_once AIESK_PATH . 'includes/sharing/class-aiesk-share-link.php';
    require_once AIESK_PATH . 'includes/sharing/class-aiesk-share-qr.php';

    // Exports
    require_once AIESK_PATH . 'includes/exports/class-aiesk-export-docx.php';
    require_once AIESK_PATH . 'includes/exports/class-aiesk-export-pdf.php';
    require_once AIESK_PATH . 'includes/integrations/class-aiesk-easy-forms.php';

    // Cloud providers
    require_once AIESK_PATH . 'includes/providers/class-aiesk-cloud-auth.php';
    require_once AIESK_PATH . 'includes/providers/class-aiesk-cloud-files.php';
    require_once AIESK_PATH . 'includes/admin/class-aiesk-admin-cloud.php';
    require_once AIESK_PATH . 'includes/providers/class-aiesk-drive.php';
    require_once AIESK_PATH . 'includes/providers/class-aiesk-dropbox.php';
    require_once AIESK_PATH . 'includes/providers/class-aiesk-onedrive.php';

    // Instantiate
    new AIESK_Access();
    new AIESK_Index();
    new AIESK_Download_Budget();
    new AIESK_Uninstall();
    new AIESK_Admin_Cloud();
    new AIESK_Cloud_Auth();
    new AIESK_Admin_Menu();
    new AIESK_Admin_Sharing();
    new AIESK_Admin_Styles();
    new AIESK_Admin_TTL();

    new AIESK_Frontend_Injector();
    new AIESK_Frontend_Render();

    new AIESK_REST();
    new AIESK_Link_Manager();
    new AIESK_Limits();
    new AIESK_Privacy();
    new AIESK_Dashboard();
    new AIESK_Share_Email();
    new AIESK_Share_Link();
    new AIESK_Share_QR();

    new AIESK_Export_DOCX();
    new AIESK_Export_PDF();
    new AIESK_Easy_Forms();

    new AIESK_Drive();
    new AIESK_Dropbox();
    new AIESK_OneDrive();
}, 5);

/** ----------------------------------------------------------------
 *  Activation / Deactivation (flush rewrites for /shared/{token})
 * ---------------------------------------------------------------- */
register_activation_hook(__FILE__, function () {
    // Make sure TTL CPT & rewrite are available BEFORE flushing.
    require_once AIESK_PATH . 'includes/admin/class-aiesk-admin-ttl.php';
    require_once AIESK_PATH . 'includes/frontend/class-aiesk-dashboard.php';
    // Instantiate so CPT + rewrites are registered for this request.
    require_once AIESK_PATH . 'includes/class-aiesk-index.php';
    require_once AIESK_PATH . 'includes/class-aiesk-download-budget.php';
    (new AIESK_Index())->initialize();
    AIESK_Download_Budget::install();
    $ttl = new AIESK_Admin_TTL();
    $ttl->register_cpt();
    $ttl->register_rewrite_rule();
    AIESK_Dashboard::register_endpoint();
    update_option('aiesk_rewrite_version', '2.4.0', false);

    if (!wp_next_scheduled('aiesk_cleanup_private_downloads')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'aiesk_cleanup_private_downloads');
    }
    if (!wp_next_scheduled('aiesk_purge_shared_links')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'aiesk_purge_shared_links');
    }

    flush_rewrite_rules(false);
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('aiesk_cleanup_private_downloads');
    wp_clear_scheduled_hook('aiesk_purge_shared_links');
    if (class_exists('AIESK_Index')) { wp_clear_scheduled_hook(AIESK_Index::CRON_HOOK); }
    if (class_exists('AIESK_Privacy')) { wp_clear_scheduled_hook(AIESK_Privacy::CONTINUE_HOOK); }
    wp_clear_scheduled_hook('aiesk_cleanup_download_backlog');
    flush_rewrite_rules(false);
});

add_action('aiesk_cleanup_private_downloads', 'aiesk_cleanup_private_downloads');

// Plugin replacement does not fire activation hooks. Flush once after all
// endpoint registrations, never on every request.
add_action('init', function () {
    if (!wp_next_scheduled('aiesk_cleanup_private_downloads')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'aiesk_cleanup_private_downloads');
    }
    if (!wp_next_scheduled('aiesk_purge_shared_links')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'aiesk_purge_shared_links');
    }
    if (get_option('aiesk_rewrite_version') !== '2.4.0') {
        flush_rewrite_rules(false);
        update_option('aiesk_rewrite_version', '2.4.0', false);
    }
}, 30);
