<?php
/** Per-account limits. Never applied to viewing, revocation or deletion. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Limits {
    public function __construct() {
        add_filter('rest_pre_dispatch', [$this, 'body_limit'], 5, 3);
        add_action('admin_init', [$this, 'settings']);
        add_action('deleted_user', [$this, 'forget_user']);
    }

    public function body_limit($result, $server, $request) {
        if ($result !== null || $request->get_method() !== 'POST' || strpos($request->get_route(), '/aiesk/v1/') !== 0) { return $result; }
        // Permit JSON escaping overhead; the raw HTML is separately capped at 1 MiB.
        return strlen((string) $request->get_body()) > 8 * 1048576
            ? new WP_Error('aiesk_request_too_large', __('The sharing request is too large.', 'ai-share-kit'), ['status' => 413]) : $result;
    }

    public function settings(): void {
        foreach (['aiesk_max_stored_links' => [500, 10000], 'aiesk_max_storage_mb' => [50, 1024]] as $key => $bounds) {
            register_setting('aiesk_privacy', $key, ['type' => 'integer', 'default' => $bounds[0], 'sanitize_callback' => static function ($value) use ($bounds) {
                return max(1, min($bounds[1], absint($value)));
            }]);
        }
        add_settings_section('aiesk_limits', __('Account limits', 'ai-share-kit'), static function () {
            echo '<p>' . esc_html__('Limits apply to new shares. Teachers can always view, revoke or delete their existing links. Creation is limited to 20 links per five minutes, downloads to 20 per five minutes, cloud saves to 10 per five minutes, and email to 10 per ten minutes.', 'ai-share-kit') . '</p>';
        }, 'aiesk_privacy');
        foreach (['aiesk_max_stored_links' => [__('Maximum stored reports per account', 'ai-share-kit'), 500, 10000], 'aiesk_max_storage_mb' => [__('Maximum report storage per account (MiB)', 'ai-share-kit'), 50, 1024]] as $key => $field) {
            add_settings_field($key, $field[0], static function () use ($key, $field) {
                echo '<input type="number" min="1" max="' . (int) $field[2] . '" name="' . esc_attr($key) . '" value="' . (int) get_option($key, $field[1]) . '">';
            }, 'aiesk_privacy', 'aiesk_limits');
        }
    }

    /** Hold this lease until the associated report write has finished. */
    public static function begin(string $kind, int $incoming_bytes = 0) {
        global $wpdb;
        $user = get_current_user_id();
        if (!$user || !in_array($kind, ['link', 'email', 'download', 'cloud'], true)) {
            return new WP_Error('aiesk_login_required', __('Please sign in to share a report.', 'ai-share-kit'), ['status' => 401]);
        }
        $key = 'aiesk_lock_' . $user . '_' . $kind;
        try { $value = (time() + 120) . ':' . bin2hex(random_bytes(12)); }
        catch (Throwable $error) { return self::storage_error(); }
        $inserted = self::insert_lock($key, $value);
        if ($inserted === false) { return self::storage_error(); }
        if ($inserted !== 1) {
            $old = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
            if ($wpdb->last_error) { return self::storage_error(); }
            if ($old !== '' && (int) explode(':', $old, 2)[0] < time()) { self::remove_exact($key, $old); }
            $inserted = self::insert_lock($key, $value);
            if ($inserted === false) { return self::storage_error(); }
            if ($inserted !== 1) {
                return new WP_Error('aiesk_busy', __('Another sharing request is still running. Wait a moment and try again.', 'ai-share-kit'), ['status' => 429, 'retry_after' => 2]);
            }
        }
        $lease = ['name' => $key, 'value' => $value];
        try {
            if ($kind === 'link') {
                $quota = self::quota($user, $incoming_bytes);
                if (is_wp_error($quota)) { self::finish($lease); return $quota; }
            }
            $defaults = ['link' => ['limit' => 20, 'window' => 300], 'email' => ['limit' => 10, 'window' => 600], 'download' => ['limit' => 20, 'window' => 300], 'cloud' => ['limit' => 10, 'window' => 300]];
            $rule = (array) apply_filters('aiesk_rate_limit', $defaults[$kind], $kind, $user);
            $limit = max(1, min(100000, absint($rule['limit'] ?? $defaults[$kind]['limit'])));
            $window = max(1, min(DAY_IN_SECONDS, absint($rule['window'] ?? $defaults[$kind]['window'])));
            $counter_key = 'aiesk_usage_' . $user . '_' . $kind;
            $counter = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $counter_key)));
            if ($wpdb->last_error) { self::finish($lease); return self::storage_error(); }
            $now = time();
            if (!is_array($counter) || empty($counter['until']) || (int) $counter['until'] <= $now) { $counter = ['until' => $now + $window, 'count' => 0]; }
            if ((int) ($counter['count'] ?? 0) >= $limit) {
                self::finish($lease);
                return new WP_Error('aiesk_rate_limited', __('You have reached the sharing limit. Please wait a few minutes before trying again.', 'ai-share-kit'), ['status' => 429, 'retry_after' => max(1, (int) $counter['until'] - $now)]);
            }
            $counter['count'] = (int) ($counter['count'] ?? 0) + 1;
            // Fence the counter write inside the SQL statement. A paused request
            // must not overwrite a replacement holder's newer usage counter.
            $saved = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                 SELECT %s, %s, 'off' FROM {$wpdb->options} AS gate
                 WHERE gate.option_name=%s AND gate.option_value=%s
                 AND CAST(gate.option_value AS UNSIGNED)>UNIX_TIMESTAMP()
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'off'",
                $counter_key, maybe_serialize($counter), $lease['name'], $lease['value']
            ));
            wp_cache_delete($counter_key, 'options');
            wp_cache_delete('notoptions', 'options');
            $stored = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $counter_key)));
            if ($saved === false || $saved < 1 || $wpdb->last_error || $stored !== $counter || !self::valid($lease)) { self::finish($lease); return self::storage_error(); }
            return $lease;
        } catch (Throwable $error) { self::finish($lease); return self::storage_error(); }
    }

    public static function rate(string $kind) {
        $lease = self::begin($kind);
        if (is_wp_error($lease)) { return $lease; }
        $valid = self::valid($lease);
        self::finish($lease);
        return $valid ? true : self::storage_error();
    }

    public static function finish(array $lease): void { self::remove_exact($lease['name'], $lease['value']); }

    public static function valid(array $lease): bool {
        global $wpdb;
        if ((int) explode(':', $lease['value'], 2)[0] <= time()) { return false; }
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lease['name']));
        return is_string($value) && hash_equals($lease['value'], $value);
    }

    private static function insert_lock(string $name, string $value) {
        global $wpdb;
        // add_option() uses an upsert and can overwrite a concurrent lock.
        // INSERT IGNORE acquires the unique key only when one row is inserted.
        return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value));
    }

    private static function remove_exact(string $name, string $value): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value));
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private static function quota(int $user, int $incoming_bytes) {
        global $wpdb;
        $indexed = class_exists('AIESK_Index') ? AIESK_Index::usage($user) : null;
        if (is_wp_error($indexed)) { return $indexed; }
        $row = is_array($indexed) ? (object) $indexed : $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS records, COALESCE(SUM((SELECT COALESCE(SUM(OCTET_LENGTH(payload.meta_value)),0) FROM {$wpdb->postmeta} payload WHERE payload.post_id = p.ID AND payload.meta_key = 'aiesk_payload_html')),0) AS bytes FROM {$wpdb->posts} p WHERE p.post_type = 'aiesk_link' AND (EXISTS (SELECT 1 FROM {$wpdb->postmeta} owner WHERE owner.post_id = p.ID AND owner.meta_key = 'aiesk_creator' AND CAST(owner.meta_value AS SIGNED) = %d) OR (p.post_author = %d AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} creator WHERE creator.post_id = p.ID AND creator.meta_key = 'aiesk_creator' AND CAST(creator.meta_value AS SIGNED) <> 0)))",
            $user, $user
        ));
        if (!$row || $wpdb->last_error) { return self::storage_error(); }
        $max_count = max(1, min(10000, (int) get_option('aiesk_max_stored_links', 500)));
        $max_bytes = max(1, min(1024, (int) get_option('aiesk_max_storage_mb', 50))) * 1048576;
        if ((int) $row->records >= $max_count || (int) $row->bytes + max(0, $incoming_bytes) > $max_bytes) {
            return new WP_Error('aiesk_storage_limit', __('Your saved reports have reached the account storage limit. Delete shared reports you no longer need, then try again.', 'ai-share-kit'), ['status' => 409]);
        }
        return true;
    }

    private static function storage_error(): WP_Error {
        return new WP_Error('aiesk_limit_unavailable', __('Sharing limits could not be checked. Please try again shortly.', 'ai-share-kit'), ['status' => 503]);
    }

    public function forget_user(int $user): void {
        foreach (['link', 'email', 'download', 'cloud'] as $kind) {
            delete_option('aiesk_usage_' . $user . '_' . $kind);
            delete_option('aiesk_lock_' . $user . '_' . $kind);
        }
    }
}
