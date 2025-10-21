<?php
/**
 * Admin Menu — AI Share Kit
 * Creates a top-level menu with tabbed sections.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Admin_Menu {

    public const MENU_SLUG = 'ai-share-kit';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void {
        add_menu_page(
            __('AI Share Kit', 'ai-share-kit'),
            __('AI Share Kit', 'ai-share-kit'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-share',
            62
        );
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You are not allowed to view this page.', 'ai-share-kit'));
        }

        $active = $_GET['tab'] ?? 'sharing';
        $tabs = [
            'sharing' => __('Sharing', 'ai-share-kit'),
            'styles'  => __('Styles', 'ai-share-kit'),
            'ttl'     => __('TTL Links & QR', 'ai-share-kit'),
        ];

        echo '<div class="wrap aiesk-admin">';
        echo '<h1>' . esc_html__('AI Share Kit', 'ai-share-kit') . '</h1>';

        // Tab navigation
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $class = $active === $slug ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url = admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=' . $slug);
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '<form method="post" action="options.php" style="margin-top:1em">';
        switch ($active) {
            case 'styles':
                settings_fields('aiesk_styles');
                do_settings_sections('aiesk_styles');
                break;
            case 'ttl':
                settings_fields('aiesk_ttl');
                do_settings_sections('aiesk_ttl');
                break;
            default:
                settings_fields('aiesk_sharing');
                do_settings_sections('aiesk_sharing');
                break;
        }
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
