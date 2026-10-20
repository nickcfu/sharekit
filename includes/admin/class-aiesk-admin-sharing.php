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
            'aiesk_enable_email'    => ['label' => __('Email (send message)', 'ai-share-kit'), 'default' => false],
            'aiesk_enable_link'     => ['label' => __('Share Link (TTL for guests)', 'ai-share-kit'), 'default' => true],
            'aiesk_enable_qr'       => ['label' => __('QR Code (TTL for guests)', 'ai-share-kit'), 'default' => true],
            'aiesk_enable_whatsapp' => ['label' => __('Share to WhatsApp', 'ai-share-kit'), 'default' => true],
            'aiesk_enable_viber'    => ['label' => __('Share to Viber', 'ai-share-kit'), 'default' => true],
            'aiesk_enable_docx'     => ['label' => __('Save as .DOCX', 'ai-share-kit'), 'default' => true],
            'aiesk_enable_pdf'      => ['label' => __('Save as native .PDF', 'ai-share-kit'), 'default' => true],
            'aiesk_enable_dropbox'  => ['label' => __('Save to Dropbox', 'ai-share-kit'), 'default' => false],
            'aiesk_enable_drive'    => ['label' => __('Save to Google Drive', 'ai-share-kit'), 'default' => false],
            'aiesk_enable_onedrive' => ['label' => __('Save to OneDrive', 'ai-share-kit'), 'default' => false],
        ];

        // Register all as checkboxes
        foreach ($fields as $key => $field) {
            register_setting('aiesk_sharing', $key, [
                'type' => 'boolean',
                'default' => $field['default'],
                'sanitize_callback' => $bool,
            ]);
        }

        add_settings_section(
            'aiesk_sharing_section',
            __('Enabled Features', 'ai-share-kit'),
            fn() => print('<p>' . esc_html__('Toggle which sharing methods are available to registered users. Set up cloud providers in the Cloud storage tab before enabling them.', 'ai-share-kit') . '</p>'),
            'aiesk_sharing'
        );

        foreach ($fields as $key => $field) {
            add_settings_field(
                $key,
                $field['label'],
                function () use ($key, $field) {
                    $val = (bool) get_option($key, $field['default']);
                    echo '<input type="hidden" name="' . esc_attr($key) . '" value="0">';
                    echo '<label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($val, true, false) . '> ' . esc_html__('Enabled', 'ai-share-kit') . '</label>';
                },
                'aiesk_sharing',
                'aiesk_sharing_section'
            );
        }
    }
}
