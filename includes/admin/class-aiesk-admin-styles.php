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
            'type' => 'string', 'default' => 'light', 'sanitize_callback' => function ($value) {
                return in_array($value, ['light', 'dark'], true) ? $value : 'light';
            }
        ]);
        register_setting('aiesk_styles', 'aiesk_style_layout', [
            'type' => 'string', 'default' => 'dropdown', 'sanitize_callback' => function ($value) {
                return in_array($value, ['dropdown', 'inline'], true) ? $value : 'dropdown';
            }
        ]);
        register_setting('aiesk_styles', 'aiesk_color_primary', [
            'type' => 'string', 'default' => '#2563eb', 'sanitize_callback' => function ($value) { return sanitize_hex_color($value) ?: '#2563eb'; }
        ]);
        register_setting('aiesk_styles', 'aiesk_color_accent', [
            'type' => 'string', 'default' => '#9333ea', 'sanitize_callback' => function ($value) { return sanitize_hex_color($value) ?: '#9333ea'; }
        ]);
        register_setting('aiesk_styles', 'aiesk_color_button_text', [
            'type' => 'string', 'default' => '#ffffff', 'sanitize_callback' => function ($value) { return sanitize_hex_color($value) ?: '#ffffff'; }
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
        $primary = sanitize_hex_color(get_option('aiesk_color_primary', '#2563eb')) ?: '#2563eb';
        $accent  = sanitize_hex_color(get_option('aiesk_color_accent', '#9333ea')) ?: '#9333ea';
        $btntext = sanitize_hex_color(get_option('aiesk_color_button_text', '#ffffff')) ?: '#ffffff';
        $theme   = get_option('aiesk_style_theme', 'light');
        if (self::contrast($primary, $btntext) < 4.5) {
            $btntext = self::contrast($primary, '#ffffff') >= self::contrast($primary, '#111827') ? '#ffffff' : '#111827';
        }
        $surface = $theme === 'dark' ? '#0f172a' : '#ffffff';
        $link = self::contrast($primary, $surface) >= 4.5 ? $primary : ($theme === 'dark' ? '#a9c3ff' : '#1d4ed8');

        echo '<style id="aiesk-styles">';
        echo '.aiesk-scope {';
        echo '--aiesk-primary:' . esc_attr($primary) . ';';
        echo '--aiesk-accent:' . esc_attr($accent) . ';';
        echo '--aiesk-btn-text:' . esc_attr($btntext) . ';';
        echo '--aiesk-link:' . esc_attr($link) . ';';
        echo ($theme === 'dark'
            ? '--aiesk-bg:#0f172a;--aiesk-fg:#e2e8f0;'
            : '--aiesk-bg:#ffffff;--aiesk-fg:#0f172a;');
        echo '}';
        echo '</style>';
    }

    private static function contrast(string $first, string $second): float {
        $luminance = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
            $rgb = array_map(static function ($part): float {
                $v = hexdec($part) / 255;
                return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split($hex, 2));
            return $rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722;
        };
        $a = $luminance($first); $b = $luminance($second);
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
