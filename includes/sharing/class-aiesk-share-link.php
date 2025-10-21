<?php
/**
 * Sharing — Link (TTL for guest viewers)
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Share_Link {

    public function __construct() {
        // REST route: /aiesk/v1/link handled by AIESK_REST, but we add helper filter
        add_filter('aiesk_create_ttl_link', [$this, 'create_link'], 10, 3);
    }

    /**
     * Create a TTL-based shared link and store the message HTML.
     *
     * @param string $title  Title for the shared message.
     * @param string $html   HTML content of the message.
     * @param array  $args   ['amount'=>int,'unit'=>'days|weeks|months']
     * @return string|WP_Error URL or error.
     */
    public function create_link(string $title, string $html, array $args = []) {
        if (!is_user_logged_in()) {
            return new WP_Error('aiesk_not_logged_in', __('Only registered users can create shared links.', 'ai-share-kit'));
        }

        $amount = absint($args['amount'] ?? get_option('aiesk_ttl_default_amount', 7));
        $unit   = sanitize_text_field($args['unit'] ?? get_option('aiesk_ttl_default_unit', 'days'));
        $expires = strtotime("+{$amount} {$unit}");
        $token   = wp_generate_password(20, false, false);

        $post_id = wp_insert_post([
            'post_type'   => 'aiesk_link',
            'post_status' => 'publish',
            'post_title'  => sanitize_text_field($title ?: 'Shared message'),
        ]);

        update_post_meta($post_id, 'aiesk_token', $token);
        update_post_meta($post_id, 'aiesk_expires_at', $expires);
        update_post_meta($post_id, 'aiesk_payload_html', wp_kses_post($html));

        return home_url('/shared/' . $token);
    }
}
