<?php
/** WordPress invokes this file only after an administrator deletes the plugin. */
if (!defined('WP_UNINSTALL_PLUGIN') || !defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-aiesk-uninstall.php';
if (!AIESK_Uninstall::run()) {
    wp_die(esc_html__('AI Share Kit cleanup could not finish. The plugin has not been removed. Check database and upload permissions, then retry deletion.', 'ai-share-kit'));
}
