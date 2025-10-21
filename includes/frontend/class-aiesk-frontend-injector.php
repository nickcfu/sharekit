<?php
/**
 * Frontend — Injector
 * Enqueues assets and mounts the portal that gets injected after the last Meow AI reply.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Frontend_Injector {

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('wp_footer',          [$this, 'mount_container'], 5);
    }

    /** Enqueue CSS + Core JS and expose settings */
    public function assets(): void {
        wp_enqueue_style('aiesk-css', AIESK_URL . 'public/css/aiesk.css', [], AIESK_VERSION);

        wp_enqueue_script('aiesk-core', AIESK_URL . 'public/js/aiesk-core.js', [], AIESK_VERSION, true);
		
		wp_enqueue_script('aiesk-qr', AIESK_URL . 'public/js/aiesk-qr.js', ['aiesk-core'], AIESK_VERSION, true);

		wp_enqueue_script('aiesk-drive', AIESK_URL . 'public/js/aiesk-drive.js', ['aiesk-core'], AIESK_VERSION, true);

		wp_enqueue_script('aiesk-dropbox', AIESK_URL . 'public/js/aiesk-dropbox.js', ['aiesk-core'], AIESK_VERSION, true);

		wp_enqueue_script('aiesk-email', AIESK_URL . 'public/js/aiesk-email.js', ['aiesk-core'], AIESK_VERSION, true);

		wp_enqueue_script('aiesk-onedrive', AIESK_URL . 'public/js/aiesk-onedrive.js', ['aiesk-core'], AIESK_VERSION, true);

        wp_localize_script('aiesk-core', 'aieskCore', [
            'rest'   => esc_url_raw(rest_url('aiesk/v1/')),
            'nonce'  => wp_create_nonce('wp_rest'),
            'labels' => [
                'share'   => __('share', 'ai-share-kit'),
                'expired' => __('this link expired', 'ai-share-kit'),
            ],
        ]);
    }

    /** Mount a single portal; JS will inject it under the last Meow reply */
    public function mount_container(): void {
        if (!is_user_logged_in()) {
            return; // guests must not see share controls
        }
        echo '<div id="aiesk-portal-root" class="aiesk-scope" style="display:none"></div>';
    }
}
