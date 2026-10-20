<?php
/**
 * AI Share Kit shared validation and private-download helpers.
 */

if (!defined('ABSPATH')) { exit; }

if (!defined('AIESK_MAX_SHARE_HTML_BYTES')) {
    define('AIESK_MAX_SHARE_HTML_BYTES', 1048576);
}

if (!function_exists('aiesk_feature_enabled')) {
    function aiesk_feature_enabled(string $feature): bool {
        // Retired in RC3. An old saved setting must not restore email drafts.
        if ($feature === 'eml') { return false; }
        $defaults = [
            'email' => false,
            'link' => true,
            'qr' => true,
            'whatsapp' => true,
            'viber' => true,
            'docx' => true,
            'pdf' => true,
            'dropbox' => false,
            'drive' => false,
            'onedrive' => false,
        ];

        $option = 'aiesk_enable_' . sanitize_key($feature);
        return (bool) get_option($option, $defaults[$feature] ?? false);
    }
}

if (!function_exists('aiesk_user_can_share')) {
    function aiesk_user_can_share(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        return (bool) apply_filters('aiesk_can_share', true, get_current_user_id());
    }
}

if (!function_exists('aiesk_rest_permission')) {
    function aiesk_rest_permission(string $feature = '') {
        if (!aiesk_user_can_share()) {
            return new WP_Error('aiesk_login_required', __('Login required to use AI Share Kit.', 'ai-share-kit'), ['status' => 401]);
        }

        if ($feature !== '' && !aiesk_feature_enabled($feature)) {
            return new WP_Error('aiesk_feature_disabled', __('This sharing option is disabled.', 'ai-share-kit'), ['status' => 403]);
        }

        return true;
    }
}

if (!function_exists('aiesk_request_data')) {
    function aiesk_request_data(WP_REST_Request $request): array {
        $data = method_exists($request, 'get_json_params') ? $request->get_json_params() : null;
        if (!is_array($data)) {
            $data = json_decode((string) $request->get_body(), true);
        }

        return is_array($data) ? $data : [];
    }
}

if (!function_exists('aiesk_validate_share_html')) {
    function aiesk_validate_share_html($html) {
        if (!is_string($html)) {
            return new WP_Error('aiesk_invalid_html', __('The shared report must contain text.', 'ai-share-kit'), ['status' => 400]);
        }
        if (strlen($html) > AIESK_MAX_SHARE_HTML_BYTES) {
            return new WP_Error('aiesk_html_too_large', __('The shared report is too large.', 'ai-share-kit'), ['status' => 413]);
        }
        $html = wp_kses_post($html);
        if ($html === '') {
            return new WP_Error('aiesk_no_html', __('No shareable content was provided.', 'ai-share-kit'), ['status' => 400]);
        }

        if (strlen($html) > AIESK_MAX_SHARE_HTML_BYTES) {
            return new WP_Error('aiesk_html_too_large', __('The shared report is too large.', 'ai-share-kit'), ['status' => 413]);
        }

        return $html;
    }
}

if (!function_exists('aiesk_normalize_title')) {
    function aiesk_normalize_title($title): string {
        $title = sanitize_text_field((string) $title);
        if ($title === '') {
            return __('Shared AI report', 'ai-share-kit');
        }
        return function_exists('mb_substr') ? mb_substr($title, 0, 180) : substr($title, 0, 180);
    }
}

if (!function_exists('aiesk_parse_ttl')) {
    function aiesk_parse_ttl($amount = null, $unit = null) {
        $default_amount = max(1, absint(get_option('aiesk_ttl_default_amount', 7)));
        $default_unit = sanitize_key((string) get_option('aiesk_ttl_default_unit', 'days'));
        if (!in_array($default_unit, ['hours', 'days', 'weeks', 'months'], true)) {
            $default_unit = 'days';
        }

        $using_defaults = ($amount === null || $amount === '') && ($unit === null || $unit === '');
        $amount = ($amount === null || $amount === '') ? $default_amount : absint($amount);
        $unit = ($unit === null || $unit === '') ? $default_unit : sanitize_key((string) $unit);
        if ($amount < 1 || !in_array($unit, ['hours', 'days', 'weeks', 'months'], true)) {
            return new WP_Error('aiesk_invalid_ttl', __('Choose a valid positive TTL.', 'ai-share-kit'), ['status' => 400]);
        }

        $multipliers = ['hours' => 1, 'days' => 24, 'weeks' => 168, 'months' => 720];
        $hours = $amount * $multipliers[$unit];
        $max_hours = absint(get_option('aiesk_ttl_max_hours', 720));
        $max_hours = max(1, min(720, $max_hours));
        if ($hours > $max_hours) {
            if ($using_defaults) {
                $amount = $max_hours;
                $unit = 'hours';
                $hours = $max_hours;
            } else {
            return new WP_Error(
                'aiesk_ttl_too_long',
                sprintf(__('The maximum link lifetime is %d hours.', 'ai-share-kit'), $max_hours),
                ['status' => 400, 'max_hours' => $max_hours]
            );
            }
        }

        return [
            'amount' => $amount,
            'unit' => $unit,
            'hours' => $hours,
            'expires_at' => time() + ($hours * HOUR_IN_SECONDS),
        ];
    }
}

if (!function_exists('aiesk_new_token')) {
    function aiesk_new_token(): string {
        try {
            return bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            return wp_generate_password(64, false, false);
        }
    }
}

if (!function_exists('aiesk_token_hash')) {
    function aiesk_token_hash(string $token): string {
        return hash('sha256', $token);
    }
}

if (!function_exists('aiesk_store_private_download')) {
    function aiesk_store_private_download(string $bytes, string $filename, string $mime, int $ttl = 3600) {
        $reservation = AIESK_Download_Budget::begin(strlen($bytes));
        if (is_wp_error($reservation)) { return $reservation; }
        try { return AIESK_Download_Budget::store($reservation, $bytes, $filename, $mime, $ttl); }
        finally { AIESK_Download_Budget::release($reservation); }
    }
}

if (!function_exists('aiesk_remove_private_download_record')) {
    function aiesk_remove_private_download_record(string $key): bool {
        if (!preg_match('/^aiesk_download_[a-f0-9]{64}$/', $key)) {
            return false;
        }
        global $wpdb;
        $value_key = '_transient_' . $key;
        if ($wpdb->delete($wpdb->options, ['option_name' => $value_key], ['%s']) === false) {
            // Keep the timeout, so a future cleanup can retry a failed delete.
            return false;
        }
        wp_cache_delete($value_key, 'options');
        delete_option('_transient_timeout_' . $key);
        return true;
    }
}

/** Consume once before emitting bytes; concurrent requests cannot both claim. */
if (!function_exists('aiesk_consume_private_download')) {
    function aiesk_consume_private_download(string $token) {
        $unavailable = static function () {
            return new WP_Error('aiesk_download_not_found', __('This download is no longer available. Create a new download.', 'ai-share-kit'), ['status' => 404]);
        };
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)) {
            return $unavailable();
        }
        // New owned records are independent of WordPress transient garbage collection.
        $current = AIESK_Download_Budget::consume($token);
        if ($current !== null) { return $current; }
        global $wpdb;
        $key = 'aiesk_download_' . aiesk_token_hash($token);
        $value_key = '_transient_' . $key;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $value_key
        ));
        if ($raw === null) {
            if ($wpdb->last_error !== '') {
                return new WP_Error('aiesk_download_read_failed', __('The download could not be opened. Please try again.', 'ai-share-kit'), ['status' => 503]);
            }
            // Never serve the old transient's filesystem path. Old download
            // buttons must create a fresh DB-backed download after this update.
            delete_transient('aiesk_file_' . $token);
            return $unavailable();
        }
        $file = maybe_unserialize($raw);
        if (!is_array($file) || ($file['version'] ?? 0) !== 2
            || !is_int($file['expires_at'] ?? null) || $file['expires_at'] <= time()
            || !is_string($file['bytes'] ?? null) || strlen($file['bytes']) > 13981016
            || !is_string($file['filename'] ?? null) || !is_string($file['mime'] ?? null)) {
            aiesk_remove_private_download_record($key);
            return $unavailable();
        }
        $bytes = base64_decode($file['bytes'], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > 10 * 1024 * 1024) {
            aiesk_remove_private_download_record($key);
            return $unavailable();
        }
        // Do not use get_transient() + delete_transient(): that pair is not an
        // atomic redemption when two PHP requests arrive together.
        $claimed = $wpdb->delete($wpdb->options, ['option_name' => $value_key], ['%s']);
        if ($claimed !== 1) {
            return $claimed === false
                ? new WP_Error('aiesk_download_claim_failed', __('The download could not be opened. Please try again.', 'ai-share-kit'), ['status' => 503])
                : $unavailable();
        }
        wp_cache_delete($value_key, 'options');
        delete_option('_transient_timeout_' . $key);
        return [
            'bytes' => $bytes,
            'filename' => sanitize_file_name($file['filename']) ?: 'download.bin',
            'mime' => sanitize_mime_type($file['mime']) ?: 'application/octet-stream',
        ];
    }
}

if (!function_exists('aiesk_cleanup_private_downloads')) {
    function aiesk_cleanup_private_downloads(): void {
        AIESK_Download_Budget::cleanup();
        global $wpdb;
        $prefix = '_transient_timeout_aiesk_download_';
        $timeouts = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) <= %d ORDER BY option_id ASC LIMIT 100",
            $wpdb->esc_like($prefix) . '%', time()
        ));
        if (count($timeouts ?: []) >= 100) { AIESK_Download_Budget::schedule_cleanup(); }
        $timeout_started = microtime(true);
        foreach ($timeouts ?: [] as $timeout_key) {
            if (microtime(true) - $timeout_started >= 2) { AIESK_Download_Budget::schedule_cleanup(); break; }
            if (preg_match('/^_transient_timeout_(aiesk_download_[a-f0-9]{64})$/', $timeout_key, $matches)) {
                if (!aiesk_remove_private_download_record($matches[1])) { AIESK_Download_Budget::schedule_cleanup(); }
            }
        }

        // Retire only recognised files created by earlier plugin releases.
        // Nothing in a stored transient path is followed, and symlinks or
        // unrelated uploads are never removed. Existing share links/QR assets
        // are separate and deliberately untouched by this cleanup.
        $uploads = wp_upload_dir();
        $directory = trailingslashit($uploads['basedir']) . 'aiesk-private';
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $real_directory = realpath($directory);
        $removed = $scanned = 0;
        $started = microtime(true);
        $offset = max(0, (int) get_option('aiesk_legacy_download_scan_offset', 0));
        try {
            $files = new DirectoryIterator($directory);
            if ($offset) { $files->seek($offset); }
        } catch (UnexpectedValueException $error) { return; }
        catch (OutOfBoundsException $error) {
            delete_option('aiesk_legacy_download_scan_offset');
            AIESK_Download_Budget::schedule_cleanup();
            return;
        }
        while ($files->valid() && $removed < 100 && $scanned < 500 && microtime(true) - $started < 2) {
            $entry = $files->current();
            $path = $entry->getPathname();
            if (!is_link($path) && preg_match('/^[A-Za-z0-9]{64}-.+\.(?:eml|pdf|docx)$/i', basename($path))) {
                $real_path = realpath($path);
                if ($real_path && dirname($real_path) === $real_directory && is_file($path) && @unlink($path)) { $removed++; }
            }
            $files->next();
            $scanned++;
        }
        if ($files->valid()) {
            // Account for entries removed during this pass. Any directory changes
            // are revisited from the start after the cursor reaches the end.
            update_option('aiesk_legacy_download_scan_offset', max(0, $offset + $scanned - $removed), false);
            AIESK_Download_Budget::schedule_cleanup();
        } else { delete_option('aiesk_legacy_download_scan_offset'); }

    }
}


/** Share tokens keep 128 bits of entropy in a compact, case-sensitive URL. */
if (!function_exists('aiesk_new_share_token')) {
    function aiesk_new_share_token(): string {
        // Do not fall back to a weaker source if the operating system RNG fails.
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}

if (!function_exists('aiesk_link_owner')) {
    function aiesk_link_owner($post): int {
        return (int) get_post_meta((int) $post->ID, 'aiesk_creator', true) ?: (int) $post->post_author;
    }
}

if (!function_exists('aiesk_can_manage_link')) {
    function aiesk_can_manage_link($post): bool {
        return is_user_logged_in() && $post && $post->post_type === 'aiesk_link'
            && (aiesk_link_owner($post) === get_current_user_id() || current_user_can('manage_options'));
    }
}

/** A teacher must be able to revoke an existing share after creation is disabled. */
if (!function_exists('aiesk_manage_links_permission')) {
    function aiesk_manage_links_permission() {
        return is_user_logged_in() ? true : new WP_Error(
            'aiesk_login_required', __('Log in to manage your shared links.', 'ai-share-kit'), ['status' => 401]
        );
    }
}

if (!function_exists('aiesk_link_status')) {
    function aiesk_link_status(int $post_id, ?int $now = null): string {
        if ((int) get_post_meta($post_id, 'aiesk_revoked_at', true)) {
            return 'revoked';
        }
        $expires = (int) get_post_meta($post_id, 'aiesk_expires_at', true);
        return $expires && $expires <= ($now ?? time()) ? 'expired' : 'active';
    }
}

if (!function_exists('aiesk_link_url')) {
    function aiesk_link_url(int $post_id): string {
        $token = (string) get_post_meta($post_id, 'aiesk_token', true);
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)) {
            return '';
        }
        $prefix = get_post_meta($post_id, 'aiesk_link_format', true) === 'short' ? '/s/' : '/shared/';
        // The query-variable endpoint also works when pretty permalinks are disabled.
        return esc_url_raw(get_option('permalink_structure')
            ? home_url(user_trailingslashit($prefix . rawurlencode($token)))
            : add_query_arg('aiesk_token', $token, home_url('/')));
    }
}

if (!function_exists('aiesk_private_rest_response')) {
    function aiesk_private_rest_response($data) {
        $response = rest_ensure_response($data);
        if (!is_wp_error($response)) {
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Vary', 'Cookie, Authorization');
        }
        return $response;
    }
}
