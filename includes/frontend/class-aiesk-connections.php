<?php
/** Connection controls remain available when sharing or an application is disabled. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Connections {
    private static int $instance = 0;
    private static bool $loaded = false;

    public static function enqueue(): void {
        if (self::$loaded || !is_user_logged_in()) { return; }
        self::$loaded = true;
        AIESK_I18n::enqueue();
        wp_enqueue_style('aiesk-connections-css', AIESK_URL . 'public/css/aiesk-connections.css', [], AIESK_VERSION);
        wp_enqueue_script('aiesk-connections-js', AIESK_URL . 'public/js/aiesk-connections.js', ['aiesk-i18n'], AIESK_VERSION, true);
        wp_localize_script('aiesk-connections-js', 'aieskConnections', [
            'rest' => esc_url_raw(rest_url('aiesk/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'cloudCallbackOrigin' => AIESK_Cloud_Auth::callback_origin(),
            'canConnect' => aiesk_user_can_share(),
            'settingsUrl' => current_user_can('manage_options') ? esc_url_raw(admin_url('admin.php?page=ai-share-kit&tab=cloud')) : '',
        ]);
    }

    public static function render(): string {
        if (!is_user_logged_in()) { return ''; }
        self::enqueue();
        $id = 'aiesk-connections-' . (++self::$instance);
        ob_start();
        if (did_action('wp_head') && !wp_style_is('aiesk-connections-css', 'done')) { wp_print_styles(['aiesk-connections-css']); }
        ?>
        <section class="aiesk-connections" data-aiesk-connections aria-labelledby="<?php echo esc_attr($id); ?>">
            <header class="aiesk-connections-heading">
                <div><h2 id="<?php echo esc_attr($id); ?>"><?php echo esc_html__('Connected accounts', 'ai-share-kit'); ?></h2>
                    <p><?php echo esc_html__('Manage your own cloud accounts, even when there is no report to share.', 'ai-share-kit'); ?></p></div>
                <button type="button" data-connections-refresh><?php echo esc_html__('Refresh status', 'ai-share-kit'); ?></button>
            </header>
            <p class="aiesk-connections-summary" data-connections-summary role="status" aria-live="polite" aria-atomic="true"></p>
            <div class="aiesk-connections-grid">
                <?php foreach (['drive' => ['Google Drive', __('Files saved to your Drive', 'ai-share-kit')], 'onedrive' => ['OneDrive', __('Files saved to Apps / application folder', 'ai-share-kit')], 'dropbox' => ['Dropbox', __('Files saved to Apps / application folder', 'ai-share-kit')]] as $provider => $details): ?>
                    <article class="aiesk-connection" data-connection="<?php echo esc_attr($provider); ?>" aria-busy="true" aria-labelledby="<?php echo esc_attr($id . '-' . $provider); ?>">
                        <h3 id="<?php echo esc_attr($id . '-' . $provider); ?>"><?php echo esc_html($details[0]); ?></h3>
                        <p class="aiesk-connection-destination"><?php echo esc_html($details[1]); ?></p>
                        <p class="aiesk-connection-status" data-connection-status role="status" aria-live="polite"><?php echo esc_html__('Checking connection…', 'ai-share-kit'); ?></p>
                        <div class="aiesk-connection-actions" data-connection-actions></div>
                        <p class="aiesk-connection-feedback" data-connection-feedback role="status" aria-live="polite"></p>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="aiesk-connections-note"><?php echo esc_html__('Connecting an account does not upload a report. Choose Save from a report when you are ready. Disconnecting removes this site’s stored connection; files already saved in your cloud account remain there.', 'ai-share-kit'); ?></p>
            <noscript><p><?php echo esc_html__('Enable JavaScript to manage connected accounts.', 'ai-share-kit'); ?></p></noscript>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
