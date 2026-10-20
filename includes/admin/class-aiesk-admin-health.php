<?php
/** Read-only operational diagnostics. No report content or credentials are exposed. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Admin_Health {
    public static function render(): void {
        if (!current_user_can('manage_options')) { return; }
        $index = AIESK_Index::health();
        $downloads = AIESK_Download_Budget::health();
        $retention = AIESK_Privacy::health();
        echo '<h2>' . esc_html__('Share Health', 'ai-share-kit') . '</h2>';
        echo '<p>' . esc_html__('Storage, background maintenance and cloud setup for this site. These checks do not open reports or contact cloud accounts.', 'ai-share-kit') . '</p>';
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('WordPress automatic cron is disabled. Make sure your server runs WordPress cron regularly so expired files, retention and index updates are processed.', 'ai-share-kit') . '</p></div>';
        }
        if (empty($downloads['available'])) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Temporary download storage could not be checked. New downloads remain blocked until storage is available.', 'ai-share-kit') . '</p></div>';
        }
        self::section(__('Report index', 'ai-share-kit'), [
            __('Status', 'ai-share-kit') => !empty($index['ready']) ? __('Ready', 'ai-share-kit') : __('Building or recovering; source queries remain available', 'ai-share-kit'),
            __('Migration cursor', 'ai-share-kit') => (string) ($index['cursor'] ?? 0),
            __('Next index batch', 'ai-share-kit') => self::when((int) ($index['next_build'] ?? 0)),
            __('Indexed reports', 'ai-share-kit') => isset($index['records']) ? number_format_i18n($index['records']) : __('Available when the index is ready', 'ai-share-kit'),
            __('Stored report content', 'ai-share-kit') => isset($index['bytes']) ? size_format($index['bytes'], 1) : __('Available when the index is ready', 'ai-share-kit'),
            __('Reports awaiting retention deletion', 'ai-share-kit') => isset($index['overdue']) ? number_format_i18n($index['overdue']) : __('Available when the index is ready', 'ai-share-kit'),
        ]);
        $unavailable = __('Unavailable', 'ai-share-kit');
        self::section(__('Temporary downloads', 'ai-share-kit'), [
            __('Charged storage / site limit', 'ai-share-kit') => isset($downloads['site_bytes']) ? size_format($downloads['site_bytes'], 1) . ' / ' . size_format($downloads['site_limit_bytes'], 1) : $unavailable,
            __('Limit per account', 'ai-share-kit') => size_format($downloads['user_limit_bytes'], 1),
            __('Stored files and reservations', 'ai-share-kit') => isset($downloads['records']) ? number_format_i18n($downloads['records']) : $unavailable,
            __('Pending conversion reservations', 'ai-share-kit') => isset($downloads['reserved_records']) ? number_format_i18n($downloads['reserved_records']) : $unavailable,
            __('Expired records awaiting cleanup', 'ai-share-kit') => isset($downloads['expired_records']) ? number_format_i18n($downloads['expired_records']) : $unavailable,
            __('Legacy temporary records', 'ai-share-kit') => isset($downloads['legacy_records']) ? number_format_i18n($downloads['legacy_records']) : $unavailable,
            __('Next scheduled cleanup', 'ai-share-kit') => self::when((int) wp_next_scheduled('aiesk_cleanup_private_downloads')),
            __('Next cleanup continuation', 'ai-share-kit') => self::when((int) wp_next_scheduled('aiesk_cleanup_download_backlog')),
        ]);
        echo '<p class="description">' . esc_html__('Temporary storage includes encoded file data, reservations and a small allowance per file. Expired records still count until cleanup removes them.', 'ai-share-kit') . '</p>';
        self::section(__('Retention maintenance', 'ai-share-kit'), [
            __('Last completed batch', 'ai-share-kit') => self::when((int) ($retention['last_run'] ?? 0), true),
            __('Examined / deleted / failed', 'ai-share-kit') => isset($retention['last_run']) ? implode(' / ', array_map('number_format_i18n', [(int) ($retention['processed'] ?? 0), (int) ($retention['removed'] ?? 0), (int) ($retention['failed'] ?? 0)])) : __('No completed batch yet', 'ai-share-kit'),
            __('Next scheduled batch', 'ai-share-kit') => self::when((int) ($retention['next_run'] ?? 0)),
            __('Next retention continuation', 'ai-share-kit') => self::when((int) ($retention['next_continuation'] ?? 0)),
        ]);
        echo '<p class="description">' . esc_html__('Maintenance runs in bounded batches. Scheduled times depend on WordPress cron; low-traffic sites may need a server cron job.', 'ai-share-kit') . '</p>';
        $providers = [];
        foreach (['drive' => 'Google Drive', 'onedrive' => 'OneDrive', 'dropbox' => 'Dropbox'] as $provider => $label) {
            $providers[$label] = !aiesk_feature_enabled($provider) ? __('Disabled', 'ai-share-kit') : (AIESK_Cloud_Auth::configured($provider) ? __('Configured; live account check required', 'ai-share-kit') : __('Setup required', 'ai-share-kit'));
        }
        self::section(__('Cloud application setup', 'ai-share-kit'), $providers);
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=ai-share-kit&tab=health')) . '">' . esc_html__('Refresh checks', 'ai-share-kit') . '</a></p>';
    }

    private static function when(int $timestamp, bool $past = false): string {
        if ($timestamp <= 0) { return $past ? __('Not yet recorded', 'ai-share-kit') : __('Not scheduled', 'ai-share-kit'); }
        $value = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
        if (!$past && $timestamp < time() - 300) { $value .= ' — ' . __('Overdue', 'ai-share-kit'); }
        return $value;
    }

    private static function section(string $heading, array $rows): void {
        echo '<section style="max-width:900px;margin:24px 0"><h3>' . esc_html($heading) . '</h3><table class="widefat striped"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th scope="row" style="width:45%;padding:12px">' . esc_html($label) . '</th><td style="padding:12px">' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }
}
