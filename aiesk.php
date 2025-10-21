<?php
/**
 * Plugin Name: AI Share Kit
 * Description: Share/export AI messages with TTL links, QR, email (.eml), DOCX/PDF, and cloud saves — auto-injected below Meow AI replies.
 * Version: 2.0.0-modular
 * Author: Nick Syrigos CreativeOptions.eu
 * Text Domain: ai-share-kit
 * Requires PHP: 8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

/** ----------------------------------------------------------------
 *  Constants
 * ---------------------------------------------------------------- */
define('AIESK_VERSION', '2.0.0-modular');
define('AIESK_FILE', __FILE__);
define('AIESK_PATH', plugin_dir_path(__FILE__));
define('AIESK_URL', plugin_dir_url(__FILE__));

/** ----------------------------------------------------------------
 *  Load Text Domain
 * ---------------------------------------------------------------- */
add_action('init', function () {
    load_plugin_textdomain('ai-share-kit', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}, 1);

/** ----------------------------------------------------------------
 *  Includes
 * ---------------------------------------------------------------- */
add_action('plugins_loaded', function () {

    // Admin
    require_once AIESK_PATH . 'includes/admin/class-aiesk-admin-menu.php';
    require_once AIESK_PATH . 'includes/admin/class-aiesk-admin-sharing.php';
    require_once AIESK_PATH . 'includes/admin/class-aiesk-admin-styles.php';
    require_once AIESK_PATH . 'includes/admin/class-aiesk-admin-ttl.php';

    // Frontend
    require_once AIESK_PATH . 'includes/frontend/class-aiesk-frontend-injector.php';
    require_once AIESK_PATH . 'includes/frontend/class-aiesk-frontend-render.php';

    // REST
    require_once AIESK_PATH . 'includes/routes/class-aiesk-rest.php';

    // Sharing features
    require_once AIESK_PATH . 'includes/sharing/class-aiesk-share-email.php';
    require_once AIESK_PATH . 'includes/sharing/class-aiesk-share-link.php';
    require_once AIESK_PATH . 'includes/sharing/class-aiesk-share-qr.php';

    // Exports
    require_once AIESK_PATH . 'includes/exports/class-aiesk-export-docx.php';
    require_once AIESK_PATH . 'includes/exports/class-aiesk-export-pdf.php';

    // Cloud providers
    require_once AIESK_PATH . 'includes/providers/class-aiesk-drive.php';
    require_once AIESK_PATH . 'includes/providers/class-aiesk-dropbox.php';
    require_once AIESK_PATH . 'includes/providers/class-aiesk-onedrive.php';

    // Instantiate
    new AIESK_Admin_Menu();
    new AIESK_Admin_Sharing();
    new AIESK_Admin_Styles();
    new AIESK_Admin_TTL();

    new AIESK_Frontend_Injector();
    new AIESK_Frontend_Render();

    new AIESK_REST();
    new AIESK_Share_Email();
    new AIESK_Share_Link();
    new AIESK_Share_QR();

    new AIESK_Export_DOCX();
    new AIESK_Export_PDF();

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
    // Instantiate so CPT + rewrite are registered for this request.
    new AIESK_Admin_TTL();

    flush_rewrite_rules();
});
