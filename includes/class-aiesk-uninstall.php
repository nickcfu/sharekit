<?php
/** Explicit uninstall policy. Deactivation never runs this cleanup. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Uninstall {
    private const HOOKS = ['aiesk_cleanup_private_downloads', 'aiesk_cleanup_download_backlog', 'aiesk_purge_shared_links', 'aiesk_purge_shared_links_continue', 'aiesk_build_report_index'];

    public function __construct() { add_action('admin_init', [$this, 'settings']); }

    public function settings(): void {
        register_setting('aiesk_privacy', 'aiesk_uninstall_delete_reports', ['type' => 'boolean', 'default' => false, 'sanitize_callback' => [self::class, 'enabled']]);
        add_settings_section('aiesk_uninstall_policy', __('When deleting the plugin', 'ai-share-kit'), static function () {
            echo '<p>' . esc_html__('Deleting AI Share Kit always removes its cloud credentials, pending connections, temporary downloads, settings and scheduled tasks. Deactivating it keeps those settings. Files already saved to cloud accounts are not removed.', 'ai-share-kit') . '</p>';
        }, 'aiesk_privacy');
        add_settings_field('aiesk_uninstall_delete_reports', __('Stored shared reports', 'ai-share-kit'), [$this, 'field'], 'aiesk_privacy', 'aiesk_uninstall_policy');
    }

    public static function enabled($value): bool { return $value === true || $value === 1 || $value === '1'; }

    public function field(): void {
        echo '<input type="hidden" name="aiesk_uninstall_delete_reports" value="0">';
        echo '<label><input type="checkbox" name="aiesk_uninstall_delete_reports" value="1" ' . checked(self::enabled(get_option('aiesk_uninstall_delete_reports', false)), true, false) . '> ' . esc_html__('Permanently delete shared report copies when this plugin is deleted.', 'ai-share-kit') . '</label>';
        echo '<p class="description">' . esc_html__('Off by default: stored shared reports remain for reinstallation. Enable only if you want to remove those copies and their links permanently. Original AI Engine conversations, recipient copies and cloud files are never deleted. On multisite, each site uses its own saved choice.', 'ai-share-kit') . '</p>';
    }

    /** Called only by WordPress uninstall.php, never a REST/admin action. */
    public static function run(): bool {
        if (!defined('WP_UNINSTALL_PLUGIN')) { return false; }
        if (!is_multisite()) { return self::site(); }
        $offset = 0;
        do {
            $ids = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC']);
            foreach ($ids as $id) {
                switch_to_blog((int) $id);
                try { $ok = self::site(); } finally { restore_current_blog(); }
                if (!$ok) { return false; }
            }
            $offset += count($ids);
        } while (count($ids) === 100);
        return true;
    }

    private static function site(): bool {
        global $wpdb;
        $remove_reports = self::enabled(get_option('aiesk_uninstall_delete_reports', false));
        foreach (self::HOOKS as $hook) {
            $cleared = wp_clear_scheduled_hook($hook, [], true);
            if (is_wp_error($cleared)) { return false; }
        }
        if ($remove_reports) {
            $after = 0;
            do {
                // Keyset batches avoid loading report bodies and do not skip rows
                // when the previous batch has already been deleted.
                $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='aiesk_link' AND ID>%d ORDER BY ID ASC LIMIT 50", $after));
                if ($wpdb->last_error) { return false; }
                foreach ($ids as $id) {
                    $after = (int) $id;
                    wp_delete_post($after, true);
                    $remaining = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d", $after));
                    if ($wpdb->last_error || $remaining) { return false; }
                    // Verify/remove metadata left behind by a deletion filter,
                    // but only for an ID just confirmed to be our deleted copy.
                    if ($wpdb->delete($wpdb->postmeta, ['post_id' => $after], ['%d']) === false) { return false; }
                    wp_cache_delete($after, 'post_meta');
                }
            } while (count($ids) === 50);
        }
        if (!self::legacy_exports()) { return false; }
        // The only custom tables owned by this plugin. Never touch wp_posts or
        // another plugin's tables; retained snapshots rebuild their index later.
        foreach (['aiesk_reports', 'aiesk_downloads'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            if ($wpdb->query("DROP TABLE IF EXISTS `$table`") === false) { return false; }
        }
        // Options contain app secrets, per-account encrypted tokens/states,
        // limits, leases and legacy temporary-download records. No usermeta or
        // network-wide credentials are used by this plugin.
        foreach (['aiesk_', '_transient_aiesk_', '_transient_timeout_aiesk_'] as $prefix) {
            do {
                $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 100", $wpdb->esc_like($prefix) . '%'));
                if ($wpdb->last_error) { return false; }
                foreach ($names as $name) {
                    if (str_starts_with($name, '_transient_') && !str_starts_with($name, '_transient_timeout_')) { delete_transient(substr($name, strlen('_transient_'))); }
                    delete_option($name);
                    $remaining = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name=%s", $name));
                    if ($wpdb->last_error || $remaining !== null) { return false; }
                }
            } while (count($names) === 100);
        }
        // Rewrites are a derived cache shared with WordPress. Delete the cache
        // instead of flushing active-plugin routes into it during uninstall.
        delete_option('rewrite_rules');
        return true;
    }

    /** Never follow a saved path, URL, symlink or an unrecognised upload. */
    private static function legacy_exports(): bool {
        $uploads = wp_upload_dir();
        $base = (string) ($uploads['basedir'] ?? '');
        if ($base === '') { return true; }
        $directory = rtrim($base, '/\\') . '/aiesk-private';
        if (!is_dir($directory) || is_link($directory)) { return true; }
        $real_base = realpath($base); $real_directory = realpath($directory);
        if (!$real_base || !$real_directory || dirname($real_directory) !== $real_base) { return true; }
        try { $files = new DirectoryIterator($directory); } catch (UnexpectedValueException $e) { return false; }
        foreach ($files as $file) {
            $name = $file->getFilename(); $path = $file->getPathname();
            if (!preg_match('/^[A-Za-z0-9]{64}-.+\.(?:eml|pdf|docx)$/iD', $name) || is_link($path) || !is_file($path)) { continue; }
            $real = realpath($path);
            if ($real && dirname($real) === $real_directory && !@unlink($path)) { return false; }
        }
        return true;
    }
}
