<?php
/** Administrator-owned OAuth application settings; teacher tokens stay private. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Admin_Cloud {
    private const PROVIDERS = ['drive' => 'Google Drive', 'onedrive' => 'OneDrive', 'dropbox' => 'Dropbox'];

    public function __construct() {
        add_action('admin_post_aiesk_cloud_settings', [$this, 'save']);
        add_action('admin_init', [$this, 'privacy_policy']);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) { return; }
        $notice = isset($_GET['aiesk_cloud_notice']) && is_string($_GET['aiesk_cloud_notice']) ? sanitize_key(wp_unslash($_GET['aiesk_cloud_notice'])) : '';
        if ($notice === 'saved') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Cloud application settings saved. Each teacher connects their own account from Share or Connected accounts in their dashboard.', 'ai-share-kit') . '</p></div>';
        } elseif ($notice === 'failed') {
            echo '<div class="notice notice-error"><p>' . esc_html__('The application settings could not be saved securely. Check the values and your server encryption support, then try again.', 'ai-share-kit') . '</p></div>';
        }
        echo '<h2>' . esc_html__('Cloud storage', 'ai-share-kit') . '</h2>';
        echo '<p>' . esc_html__('Register one application with each service you want to offer. Enter its client ID and secret here, then enable that destination. Teachers authorise their own cloud accounts from Share or Connected accounts in their dashboard; they do not share a school account.', 'ai-share-kit') . '</p>';
        echo '<p>' . esc_html__('Saved cloud files are independent copies. Expiring, revoking or deleting a share link does not delete those files. Disconnecting stops this site from using the stored connection; existing cloud files stay in place.', 'ai-share-kit') . '</p>';
        echo '<p><strong>' . esc_html__('Upgrading from 2.4.0-rc.1? Replace each registered callback with the query-free URL shown below, then reconnect existing accounts. Do not append action or provider parameters.', 'ai-share-kit') . '</strong></p>';
        $guides = [
            'drive' => ['https://console.cloud.google.com/apis/credentials', __('Enable the Google Drive API. Create a Web application OAuth client, configure its consent screen, and add the exact callback below. Access is limited to files created or selected through this application (drive.file).', 'ai-share-kit')],
            'onedrive' => ['https://entra.microsoft.com/', __('Register a Web application supporting work, school and personal Microsoft accounts. Add the callback below and delegated Files.ReadWrite.AppFolder plus offline_access permissions. Files are saved in the application folder under Apps.', 'ai-share-kit')],
            'dropbox' => ['https://www.dropbox.com/developers/apps', __('Create a Scoped access application with App folder access. Enable files.content.write, add the exact callback below, and use its App key and App secret. Files are saved in the application folder under Apps.', 'ai-share-kit')],
        ];
        foreach (self::PROVIDERS as $provider => $label) {
            $client_id = (string) get_option('aiesk_cloud_' . $provider . '_client_id', '');
            $configured = AIESK_Cloud_Auth::configured($provider);
            $enabled = aiesk_feature_enabled($provider);
            $has_secret = (bool) get_option('aiesk_cloud_' . $provider . '_client_secret', '');
            echo '<section id="aiesk-cloud-' . esc_attr($provider) . '" style="max-width:850px;margin:20px 0;padding:20px;background:#fff;border:1px solid #dcdcde;border-radius:8px">';
            echo '<h3 style="margin-top:0">' . esc_html($label) . ' · ' . esc_html($configured ? ($enabled ? __('Enabled', 'ai-share-kit') : __('Disabled', 'ai-share-kit')) : __('Setup required', 'ai-share-kit')) . '</h3>';
            echo '<p>' . esc_html($guides[$provider][1]) . '</p>';
            echo '<p><a href="' . esc_url($guides[$provider][0]) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Open application console', 'ai-share-kit') . '</a></p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="aiesk_cloud_settings"><input type="hidden" name="provider" value="' . esc_attr($provider) . '">';
            wp_nonce_field('aiesk_cloud_settings_' . $provider);
            echo '<p><label for="aiesk-' . esc_attr($provider) . '-callback"><strong>' . esc_html__('Callback URL (copy exactly)', 'ai-share-kit') . '</strong></label><br><input id="aiesk-' . esc_attr($provider) . '-callback" class="large-text code" readonly value="' . esc_attr(AIESK_Cloud_Auth::redirect_uri($provider)) . '"></p>';
            echo '<p><label for="aiesk-' . esc_attr($provider) . '-client">' . esc_html__('Client ID / App key', 'ai-share-kit') . '</label><br><input id="aiesk-' . esc_attr($provider) . '-client" name="client_id" type="text" class="large-text" maxlength="512" autocomplete="off" value="' . esc_attr($client_id) . '"></p>';
            echo '<p><label for="aiesk-' . esc_attr($provider) . '-secret">' . esc_html__('Client secret / App secret', 'ai-share-kit') . '</label><br><input id="aiesk-' . esc_attr($provider) . '-secret" name="client_secret" type="password" class="large-text" maxlength="4096" autocomplete="new-password" value="" placeholder="' . esc_attr($has_secret ? __('Stored securely; leave blank to keep', 'ai-share-kit') : __('Enter the application secret', 'ai-share-kit')) . '"></p>';
            echo '<p><label><input type="checkbox" name="clear_secret" value="1"> ' . esc_html__('Remove the stored application secret', 'ai-share-kit') . '</label></p>';
            echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked($enabled, true, false) . '> ' . esc_html__('Enable this cloud destination', 'ai-share-kit') . '</label></p>';
            submit_button(__('Save application settings', 'ai-share-kit'), 'secondary', 'submit', false);
            echo '</form></section>';
        }
        echo '<p>' . esc_html__('For a public launch, complete each provider’s production-access or consent-review requirements. Credentials and account authorisations cannot be validated until the application is configured and a real account completes connection.', 'ai-share-kit') . '</p>';
    }

    public function save(): void {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('You are not allowed to change cloud settings.', 'ai-share-kit'), '', ['response' => 403]); }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die(esc_html__('Use the settings form to save changes.', 'ai-share-kit'), '', ['response' => 405]); }
        $provider = isset($_POST['provider']) && is_string($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
        if (!isset(self::PROVIDERS[$provider])) { wp_die(esc_html__('Unknown cloud service.', 'ai-share-kit'), '', ['response' => 400]); }
        check_admin_referer('aiesk_cloud_settings_' . $provider);
        $client_id = isset($_POST['client_id']) && is_string($_POST['client_id']) ? trim(wp_unslash($_POST['client_id'])) : '';
        $secret = isset($_POST['client_secret']) && is_string($_POST['client_secret']) ? trim(wp_unslash($_POST['client_secret'])) : '';
        $clear = isset($_POST['clear_secret']) && $_POST['clear_secret'] === '1';
        $keys = ['aiesk_cloud_' . $provider . '_client_secret', 'aiesk_cloud_' . $provider . '_client_id', 'aiesk_enable_' . $provider];
        global $wpdb;
        $before = [];
        $success = ($client_id === '' || preg_match('/^[A-Za-z0-9_.:\-]{1,512}$/D', $client_id))
            && strlen($secret) <= 4096 && !preg_match('/[\x00-\x1f\x7f]/', $secret) && !($clear && $secret !== '');
        foreach ($keys as $key) {
            $before[$key] = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
            if ($wpdb->last_error) { $success = false; }
        }
        $started = $success;
        if ($success && ($clear || $secret !== '')) {
            $saved = AIESK_Cloud_Auth::save_app_secret($provider, $clear ? '' : $secret);
            $success = $saved === true;
        }
        if ($success) {
            update_option($keys[1], $client_id, false);
            $success = self::stored($keys[1], $client_id);
            $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1' && AIESK_Cloud_Auth::configured($provider);
            if ($success) {
                update_option($keys[2], $enabled, false);
                $success = self::stored($keys[2], (string) $enabled) || (!$enabled && self::stored($keys[2], null));
            }
        }
        if (!$success && $started) {
            // Restore the previous encrypted settings if any later write fails.
            // If storage cannot confirm rollback, disable the destination.
            $restored = true;
            foreach ($before as $key => $raw) {
                wp_cache_delete($key, 'options');
                wp_cache_delete('notoptions', 'options');
                if ($raw === null) { delete_option($key); }
                else { update_option($key, maybe_unserialize($raw), false); }
                $restored = self::stored($key, $raw) && $restored;
            }
            if (!$restored) { update_option($keys[2], false, false); }
        }
        // Only fixed status codes reach the URL; secrets never leave the POST.
        wp_safe_redirect(admin_url('admin.php?page=ai-share-kit&tab=cloud&aiesk_cloud_notice=' . ($success ? 'saved' : 'failed')));
        exit;
    }

    private static function stored(string $key, ?string $expected): bool {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
        return !$wpdb->last_error && $raw === $expected;
    }

    public function privacy_policy(): void {
        if (!function_exists('wp_add_privacy_policy_content')) { return; }
        wp_add_privacy_policy_content('AI Share Kit cloud connections', wp_kses_post('<p>' . __('When a teacher connects a cloud account and chooses Save, AI Share Kit sends the selected report, its title and chosen file format to that provider. Account tokens and application secrets are stored encrypted on this site. Connections can be disconnected and are removed through account erasure. Cloud files and messages are independent copies controlled by the selected service and recipient; the site’s link expiry and deletion rules do not remove them. Opening WhatsApp or Viber shares the chosen title/link with that service so the teacher can select a recipient and send.', 'ai-share-kit') . '</p>'));
    }
}
