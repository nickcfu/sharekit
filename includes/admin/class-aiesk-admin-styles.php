<?php
/**
 * Admin – Styles & Appearance Settings
 * Lets you set primary/accent/button colors, theme (light/dark), and layout (dropdown/inline)
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Admin_Styles {

    public function __construct() {
        add_action('admin_init', [$this, 'register']);
        add_action('wp_head', [$this, 'output_custom_css']);
    }

    public function register(): void {

        // Register options
        register_setting('aiesk_styles', 'aiesk_style_theme', [
            'type' => 'string', 'default' => 'light', 'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('aiesk_styles', 'aiesk_style_layout', [
            'type' => 'string', 'default' => 'dropdown', 'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('aiesk_styles', 'aiesk_color_primary', [
            'type' => 'string', 'default' => '#2563eb', 'sanitize_callback' => 'sanitize_hex_color'
        ]);
        register_setting('aiesk_styles', 'aiesk_color_accent', [
            'type' => 'string', 'default' => '#9333ea', 'sanitize_callback' => 'sanitize_hex_color'
        ]);
        register_setting('aiesk_styles', 'aiesk_color_button_text', [
            'type' => 'string', 'default' => '#ffffff', 'sanitize_callback' => 'sanitize_hex_color'
        ]);

        add_settings_section(
            'aiesk_styles_section',
            __('Appearance', 'ai-share-kit'),
            fn() => print('<p>' . esc_html__('Customize colors, theme and layout. All colors are applied to a custom CSS class to avoid overriding your theme defaults.', 'ai-share-kit') . '</p>'),
            'aiesk_styles'
        );

        // Theme: light or dark
        add_settings_field(
            'aiesk_style_theme',
            __('Theme', 'ai-share-kit'),
            function () {
                $val = get_option('aiesk_style_theme', 'light');
                echo '<select name="aiesk_style_theme">';
                echo '<option value="light"' . selected($val, 'light', false) . '>Light</option>';
                echo '<option value="dark"' . selected($val, 'dark', false) . '>Dark</option>';
                echo '</select>';
            },
            'aiesk_styles',
            'aiesk_styles_section'
        );

        // Layout: dropdown or inline
        add_settings_field(
            'aiesk_style_layout',
            __('Layout', 'ai-share-kit'),
            function () {
                $val = get_option('aiesk_style_layout', 'dropdown');
                echo '<select name="aiesk_style_layout">';
                echo '<option value="dropdown"' . selected($val, 'dropdown', false) . '>Dropdown (single “share” button)</option>';
                echo '<option value="inline"' . selected($val, 'inline', false) . '>Inline (all visible)</option>';
                echo '</select>';
            },
            'aiesk_styles',
            'aiesk_styles_section'
        );

        // Color pickers
        $colors = [
            'aiesk_color_primary'      => __('Primary Color', 'ai-share-kit'),
            'aiesk_color_accent'       => __('Accent Color', 'ai-share-kit'),
            'aiesk_color_button_text'  => __('Button Text Color', 'ai-share-kit'),
        ];

        foreach ($colors as $key => $label) {
            add_settings_field(
                $key,
                $label,
                function () use ($key) {
                    $val = get_option($key, '');
                    echo '<input type="color" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" />';
                },
                'aiesk_styles',
                'aiesk_styles_section'
            );
        }
    }

    /**
     * Output inline CSS variables to apply admin-defined colors and theme
     */
    public function output_custom_css(): void {
        $primary = get_option('aiesk_color_primary', '#2563eb');
        $accent  = get_option('aiesk_color_accent', '#9333ea');
        $btntext = get_option('aiesk_color_button_text', '#ffffff');
        $theme   = get_option('aiesk_style_theme', 'light');

        echo '<style id="aiesk-styles">';
        echo '.aiesk-scope {';
        echo '--aiesk-primary:' . esc_attr($primary) . ';';
        echo '--aiesk-accent:' . esc_attr($accent) . ';';
        echo '--aiesk-btn-text:' . esc_attr($btntext) . ';';
        echo ($theme === 'dark'
            ? '--aiesk-bg:#0f172a;--aiesk-fg:#e2e8f0;'
            : '--aiesk-bg:#ffffff;--aiesk-fg:#0f172a;');
        echo '}';
        echo '</style>';
    }
}
