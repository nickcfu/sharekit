<?php
/**
 * Admin – Sharing Options
 * Registers enable/disable switches for each share feature.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Admin_Sharing {

    public function __construct() {
        add_action('admin_init', [$this, 'register']);
    }

    public function register(): void {

        // Each option stored as boolean (checked = enabled)
        $bool = fn($v): bool => !empty($v);

        $fields = [
            'aiesk_enable_email'    => __('Email (send message)', 'ai-share-kit'),
            'aiesk_enable_eml'      => __('Email as .eml (download)', 'ai-share-kit'),
            'aiesk_enable_link'     => __('Share Link (TTL for guests)', 'ai-share-kit'),
            'aiesk_enable_qr'       => __('QR Code (TTL for guests)', 'ai-share-kit'),
            'aiesk_enable_docx'     => __('Save as .DOCX', 'ai-share-kit'),
            'aiesk_enable_pdf'      => __('Save as native .PDF', 'ai-share-kit'),
            'aiesk_enable_dropbox'  => __('Save to Dropbox', 'ai-share-kit'),
            'aiesk_enable_drive'    => __('Save to Google Drive', 'ai-share-kit'),
            'aiesk_enable_onedrive' => __('Save to OneDrive', 'ai-share-kit'),
        ];

        // Register all as checkboxes
        foreach ($fields as $key => $label) {
            register_setting('aiesk_sharing', $key, [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => $bool,
            ]);
        }

        add_settings_section(
            'aiesk_sharing_section',
            __('Enabled Features', 'ai-share-kit'),
            fn() => print('<p>' . esc_html__('Toggle which sharing methods are available to registered users.', 'ai-share-kit') . '</p>'),
            'aiesk_sharing'
        );

        foreach ($fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                function () use ($key) {
                    $val = (bool) get_option($key, false);
                    echo '<label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($val, true, false) . '> ' . esc_html__('Enabled', 'ai-share-kit') . '</label>';
                },
                'aiesk_sharing',
                'aiesk_sharing_section'
            );
        }
    }
}
