<?php
/**
 * Sharing — TTL links for guest viewers.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Share_Link {
    private const REUSE_SECONDS = 900;
    private const RECENT_LIMIT = 20;

    public function __construct() {
        add_filter('aiesk_create_ttl_link', [$this, 'create_link'], 10, 3);
        add_action('deleted_user', [self::class, 'forget_user']);
        add_action('wpmu_delete_user', [self::class, 'forget_user']);
    }

    /**
     * Compatibility filter for integrations that already call this hook.
     */
    public function create_link(string $title, string $html, array $args = []) {
        $result = self::create_record($title, $html, $args);
        return is_wp_error($result) ? $result : $result['url'];
    }

    /**
     * Create a share record after validating the payload and lifetime.
     */
    public static function create_record($title, $html, array $args = []) {
        if (!aiesk_user_can_share()) {
            return new WP_Error('aiesk_not_logged_in', __('Only registered users can create private links.', 'ai-share-kit'), ['status' => 401]);
        }

        $html = aiesk_validate_share_html($html);
        if (is_wp_error($html)) {
            return $html;
        }

        $ttl = aiesk_parse_ttl($args['amount'] ?? null, $args['unit'] ?? null);
        if (is_wp_error($ttl)) {
            return $ttl;
        }

        if (array_key_exists('short_url', $args) && !is_bool($args['short_url'])) {
            return new WP_Error('aiesk_invalid_short_url', __('Choose whether to use a short URL.', 'ai-share-kit'), ['status' => 400]);
        }
        $language = class_exists('AIESK_Report_Context') ? AIESK_Report_Context::language($args['report_lang'] ?? '') : '';
        if (is_wp_error($language)) { return $language; }
        // Existing integrations retain their URL format unless they explicitly opt in.
        $short_url = $args['short_url'] ?? false;
        $title = aiesk_normalize_title($title);
        $owner = get_current_user_id();
        $format = $short_url ? 'short' : 'standard';
        $fingerprint = hash('sha256', serialize([$owner, $title, $html, (int) $ttl['hours'], $format, $language]));
        // Revalidate the source on every request. This is deliberately not a
        // browser URL cache: another tab may have revoked or deleted the link.
        $recent = self::reusable($owner, $fingerprint, $title, $html, (int) $ttl['hours'], $format, $language);
        if (is_array($recent)) { return $recent; }
        $lease = class_exists('AIESK_Limits') ? AIESK_Limits::begin('link', strlen($html)) : null;
        if (is_wp_error($lease)) {
            // A preceding request may have completed between lookup and lease.
            $recent = self::reusable($owner, $fingerprint, $title, $html, (int) $ttl['hours'], $format, $language);
            return is_array($recent) ? $recent : $lease;
        }
        try {
        // The owner lease serializes lookup, creation and the recent-share map.
        $recent = self::reusable($owner, $fingerprint, $title, $html, (int) $ttl['hours'], $format, $language);
        if (is_array($recent)) { return $recent; }
        try {
            $token = $short_url ? aiesk_new_share_token() : aiesk_new_token();
        } catch (Throwable $e) {
            return new WP_Error('aiesk_random_failed', __('Could not create a secure link. Please try again.', 'ai-share-kit'), ['status' => 500]);
        }
        $post_id = wp_insert_post([
            'post_type' => 'aiesk_link',
            'post_status' => 'draft',
            // wp_insert_post() unslashes input; preserve the normalized title
            // exactly so a later reuse check compares the same snapshot.
            'post_title' => wp_slash($title),
            'post_author' => $owner,
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            return new WP_Error('aiesk_link_create_failed', __('Could not create the private link.', 'ai-share-kit'), ['status' => 500]);
        }

        $post_id = (int) $post_id;
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'draft') {
            return self::creation_failed($post_id);
        }

        // A partially written record must never be publicly resolvable. WordPress
        // unslashes metadata, so compensate before verifying the exact payload.
        $metadata = array_merge([
            'aiesk_token' => $token,
            'aiesk_token_hash' => aiesk_token_hash($token),
            'aiesk_expires_at' => (int) $ttl['expires_at'],
            'aiesk_payload_html' => $html,
            'aiesk_creator' => $owner,
            'aiesk_created_at' => time(),
            'aiesk_status' => 'active',
            'aiesk_link_format' => $format,
            'aiesk_ttl_hours' => (int) $ttl['hours'],
            'aiesk_report_lang' => $language,
        ], class_exists('AIESK_Privacy') ? AIESK_Privacy::creation_metadata((int) $ttl['expires_at']) : []);
        foreach ($metadata as $key => $value) {
            update_post_meta($post_id, $key, wp_slash($value));
            if ((string) get_post_meta($post_id, $key, true) !== (string) $value) {
                return self::creation_failed($post_id);
            }
        }

        if (is_array($lease) && !AIESK_Limits::valid($lease)) { return self::creation_failed($post_id); }
        // Store the pointer while the new record is still an inaccessible draft.
        if (!self::remember($owner, $fingerprint, $post_id, (int) $ttl['expires_at'], $lease)) {
            return self::creation_failed($post_id);
        }
        $published = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
        $post = get_post($post_id);
        if (is_wp_error($published) || !$published || !$post || $post->post_status !== 'publish') {
            return self::creation_failed($post_id);
        }
        // Also catch metadata modified by an integration during publication.
        foreach ($metadata as $key => $value) {
            if ((string) get_post_meta($post_id, $key, true) !== (string) $value) {
                return self::creation_failed($post_id);
            }
        }

        if (is_array($lease) && !AIESK_Limits::valid($lease)) { return self::creation_failed($post_id); }
        $url = aiesk_link_url($post_id);
        do_action('aiesk_link_created', $post_id, $url, $ttl['expires_at']);

        return [
            'url' => esc_url_raw($url),
            'expires_at' => (int) $ttl['expires_at'],
            'post_id' => $post_id,
            'ttl_hours' => (int) $ttl['hours'],
            'delete_after' => (int) ($metadata['aiesk_delete_after'] ?? 0),
            'reused' => false,
        ];
        } finally {
            if (is_array($lease)) { AIESK_Limits::finish($lease); }
        }
    }

    /** A bounded lookup by owner; no report payload or postmeta scan is needed. */
    private static function recent_entries(int $owner): array {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::recent_key($owner)));
        if ($wpdb->last_error) { return []; }
        $entries = maybe_unserialize($raw);
        if (!is_array($entries)) { return []; }
        $now = time();
        $clean = [];
        foreach (array_slice($entries, -self::RECENT_LIMIT, null, true) as $hash => $entry) {
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || !is_array($entry)
                || empty($entry['id']) || (int) ($entry['until'] ?? 0) <= $now) { continue; }
            $clean[$hash] = ['id' => (int) $entry['id'], 'until' => (int) $entry['until']];
        }
        return $clean;
    }

    private static function reusable(int $owner, string $fingerprint, string $title, string $html, int $hours, string $format, string $language): ?array {
        $entry = self::recent_entries($owner)[$fingerprint] ?? null;
        if (!$entry) { return null; }
        $id = (int) $entry['id'];
        // Evict this request's source cache before checking the current record.
        // The recent-map pointer itself is only a hint, never authorization.
        clean_post_cache($id);
        $post = get_post($id);
        $now = time();
        if (!$post || $post->post_type !== 'aiesk_link' || $post->post_status !== 'publish'
            || aiesk_link_owner($post) !== $owner || (string) $post->post_title !== $title
            || (string) get_post_meta($id, 'aiesk_status', true) !== 'active'
            || (int) get_post_meta($id, 'aiesk_revoked_at', true)
            || (int) get_post_meta($id, 'aiesk_expires_at', true) <= $now
            || (int) get_post_meta($id, 'aiesk_ttl_hours', true) !== $hours
            || (string) get_post_meta($id, 'aiesk_link_format', true) !== $format
            || (string) get_post_meta($id, 'aiesk_report_lang', true) !== $language
            || (string) get_post_meta($id, 'aiesk_payload_html', true) !== $html) { return null; }
        $token = (string) get_post_meta($id, 'aiesk_token', true);
        $hash = (string) get_post_meta($id, 'aiesk_token_hash', true);
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/D', $token) || !hash_equals(aiesk_token_hash($token), $hash)) { return null; }
        $url = aiesk_link_url($id);
        if (!$url) { return null; }
        return [
            'url' => esc_url_raw($url), 'post_id' => $id,
            'expires_at' => (int) get_post_meta($id, 'aiesk_expires_at', true),
            'ttl_hours' => $hours, 'delete_after' => (int) get_post_meta($id, 'aiesk_delete_after', true),
            'reused' => true,
        ];
    }

    private static function remember(int $owner, string $fingerprint, int $post_id, int $expires_at, $lease): bool {
        global $wpdb;
        $entries = self::recent_entries($owner);
        unset($entries[$fingerprint]);
        $entries[$fingerprint] = ['id' => $post_id, 'until' => min(time() + self::REUSE_SECONDS, $expires_at)];
        $entries = array_slice($entries, -self::RECENT_LIMIT, null, true);
        $name = self::recent_key($owner);
        $value = maybe_serialize($entries);
        if (is_array($lease)) {
            // A paused request must never overwrite a newer lease holder's map.
            $saved = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 SELECT %s, %s, 'off' FROM {$wpdb->options} AS gate
                 WHERE gate.option_name=%s AND gate.option_value=%s
                 AND CAST(gate.option_value AS UNSIGNED)>UNIX_TIMESTAMP()
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'off'",
                $name, $value, $lease['name'], $lease['value']
            ));
            wp_cache_delete($name, 'options');
            wp_cache_delete('notoptions', 'options');
            if ($saved === false || $saved < 1 || !AIESK_Limits::valid($lease)) { return false; }
        } else {
            // Retain the standalone service/filter integration contract.
            update_option($name, $entries, false);
        }
        return self::recent_entries($owner) === $entries;
    }

    private static function recent_key(int $owner): string { return 'aiesk_recent_links_' . $owner; }

    public static function forget_user(int $owner): void { delete_option(self::recent_key($owner)); }

    private static function creation_failed(int $post_id) {
        // Only this just-created record is removed; existing shares are untouched.
        wp_delete_post($post_id, true);
        return new WP_Error('aiesk_link_create_failed', __('Could not securely save the private link. Please try again.', 'ai-share-kit'), ['status' => 500]);
    }
}
