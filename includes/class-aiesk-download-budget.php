<?php
/** Owned, bounded temporary downloads. Payloads never enter public uploads or object caches. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Download_Budget {
    private const VERSION = '1';
    private const MAX_RAW = 10485760;
    private const OVERHEAD = 1024;
    private const BACKLOG_HOOK = 'aiesk_cleanup_download_backlog';

    public function __construct() {
        add_action('init', [self::class, 'maybe_install'], 0);
        add_action('admin_init', [$this, 'settings']);
        add_action(self::BACKLOG_HOOK, 'aiesk_cleanup_private_downloads');
        add_action('deleted_user', [self::class, 'forget_user']);
        add_action('wpmu_delete_user', [self::class, 'forget_user']);
    }

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'aiesk_downloads'; }

    public static function maybe_install(): void {
        if (get_option('aiesk_download_schema') !== self::VERSION) {
            if (get_transient('aiesk_download_install_retry')) { return; }
            set_transient('aiesk_download_install_retry', 1, 300);
            self::install();
        }
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta("CREATE TABLE {$table} (
            token_hash char(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            charged_bytes bigint(20) unsigned NOT NULL,
            raw_capacity int(10) unsigned NOT NULL,
            state varchar(12) NOT NULL,
            created_at bigint(20) unsigned NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            payload longtext NOT NULL,
            filename varchar(255) NOT NULL,
            mime varchar(128) NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY user_budget (user_id,charged_bytes),
            KEY expiry (expires_at)
        ) {$wpdb->get_charset_collate()};");
        $wpdb->get_row("SELECT token_hash,user_id,charged_bytes,raw_capacity,state,created_at,expires_at,payload,filename,mime FROM {$table} LIMIT 1");
        if (!$wpdb->last_error) {
            update_option('aiesk_download_schema', self::VERSION, false);
            delete_transient('aiesk_download_install_retry');
        }
    }

    public function settings(): void {
        foreach (['aiesk_download_user_mb' => [25, 1024], 'aiesk_download_site_mb' => [250, 10240]] as $key => $bounds) {
            register_setting('aiesk_privacy', $key, ['type' => 'integer', 'default' => $bounds[0], 'sanitize_callback' => static function ($value) use ($bounds) {
                return max(14, min($bounds[1], absint($value)));
            }]);
        }
        add_settings_section('aiesk_download_limits', __('Temporary download storage', 'ai-share-kit'), static function () {
            echo '<p>' . esc_html__('These limits include encoded file data and a small allowance per file. Generating PDF or Word reserves space for a 10 MiB file before conversion. Space is released after download or cleanup; downloads expire within one hour.', 'ai-share-kit') . '</p>';
        }, 'aiesk_privacy');
        foreach (['aiesk_download_user_mb' => [__('Temporary storage per account (MiB)', 'ai-share-kit'), 25, 1024], 'aiesk_download_site_mb' => [__('Temporary storage for this site (MiB)', 'ai-share-kit'), 250, 10240]] as $key => $field) {
            add_settings_field($key, $field[0], static function () use ($key, $field) {
                echo '<input type="number" min="14" max="' . (int) $field[2] . '" name="' . esc_attr($key) . '" value="' . max(14, min($field[2], (int) get_option($key, $field[1]))) . '">';
            }, 'aiesk_privacy', 'aiesk_download_limits');
        }
    }

    private static function limits(): array {
        return [
            'user_limit_bytes' => max(14, min(1024, (int) get_option('aiesk_download_user_mb', 25))) * 1048576,
            'site_limit_bytes' => max(14, min(10240, (int) get_option('aiesk_download_site_mb', 250))) * 1048576,
        ];
    }

    private static function charged(int $bytes): int { return 4 * (int) ceil($bytes / 3) + self::OVERHEAD; }
    private static function unavailable(): WP_Error {
        return new WP_Error('aiesk_download_storage_unavailable', __('Temporary download storage could not be checked. Please try again shortly.', 'ai-share-kit'), ['status' => 503]);
    }
    private static function not_found(): WP_Error {
        return new WP_Error('aiesk_download_not_found', __('This download is no longer available. Create a new download.', 'ai-share-kit'), ['status' => 404]);
    }

    /** Rate and capacity checks happen before invoking a potentially expensive converter. */
    public static function begin(int $raw_capacity = self::MAX_RAW) {
        global $wpdb;
        $user = get_current_user_id();
        if (!$user) { return new WP_Error('aiesk_login_required', __('Please sign in to share a report.', 'ai-share-kit'), ['status' => 401]); }
        if ($raw_capacity < 1 || $raw_capacity > self::MAX_RAW) { return new WP_Error('aiesk_download_size', __('Downloads must contain data and be no larger than 10 MB.', 'ai-share-kit'), ['status' => 413]); }
        $rate = AIESK_Limits::rate('download');
        if (is_wp_error($rate)) { return $rate; }
        // Clear one bounded batch before checking the real rows; no counters can drift.
        self::cleanup();
        $lease = self::lock();
        if (is_wp_error($lease)) { return $lease; }
        try {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $table = self::table();
            $charge = self::charged($raw_capacity);
            $limits = self::limits();
            $legacy_prefix = $wpdb->esc_like('_transient_aiesk_download_') . '%';
            $now = time();
            // The lock value is checked in the insertion itself. A request paused
            // beyond its lease cannot reserve after another request takes over.
            // All rows count, including expired rows until physically removed.
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (token_hash,user_id,charged_bytes,raw_capacity,state,created_at,expires_at,payload,filename,mime)
                 SELECT %s,%d,%d,%d,'reserved',%d,%d,'','','' FROM {$wpdb->options} AS gate
                 WHERE gate.option_name = %s AND gate.option_value = %s AND CAST(gate.option_value AS UNSIGNED) > UNIX_TIMESTAMP()
                 AND (SELECT COALESCE(SUM(charged_bytes),0) FROM {$table})
                     + (SELECT COALESCE(SUM(OCTET_LENGTH(option_value)),0) FROM {$wpdb->options} WHERE option_name LIKE %s) + %d <= %d
                 AND (SELECT COALESCE(SUM(charged_bytes),0) FROM {$table} WHERE user_id = %d) + %d <= %d",
                $hash, $user, $charge, $raw_capacity, $now, $now + 600, $lease['name'], $lease['value'],
                $legacy_prefix, $charge, $limits['site_limit_bytes'], $user, $charge, $limits['user_limit_bytes']
            ));
            if ($inserted === false || $wpdb->last_error) { return self::unavailable(); }
            if ($inserted !== 1) {
                return new WP_Error('aiesk_download_storage_limit', __('Temporary download storage is full. Download an existing file or wait for expired files to be cleaned up, then try again.', 'ai-share-kit'), ['status' => 429, 'retry_after' => 60]);
            }
            return ['token' => $token, 'hash' => $hash, 'user_id' => $user, 'raw_capacity' => $raw_capacity];
        } catch (Throwable $error) { return self::unavailable(); }
        finally { self::unlock($lease); }
    }

    /** Replace only this live reservation; shrinking charge and writing bytes are one SQL operation. */
    public static function store(array $reservation, string $bytes, string $filename, string $mime, int $ttl = 3600) {
        global $wpdb;
        if (!self::valid_reservation($reservation) || $reservation['user_id'] !== get_current_user_id()) { return self::unavailable(); }
        $length = strlen($bytes);
        if (!$length || $length > $reservation['raw_capacity'] || $length > self::MAX_RAW) { return new WP_Error('aiesk_download_size', __('Downloads must contain data and be no larger than 10 MB.', 'ai-share-kit'), ['status' => 413]); }
        $filename = sanitize_file_name($filename) ?: 'download.bin';
        // Keep metadata within the charged allowance and DB column limits.
        $filename = function_exists('mb_strcut') ? mb_strcut($filename, 0, 240, 'UTF-8') : substr($filename, 0, 240);
        $mime = substr(sanitize_mime_type($mime) ?: 'application/octet-stream', 0, 128);
        $payload = base64_encode($bytes);
        $expires = time() + max(60, min(HOUR_IN_SECONDS, $ttl));
        $table = self::table();
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET payload=%s,filename=%s,mime=%s,charged_bytes=%d,expires_at=%d,state='ready'
             WHERE token_hash=%s AND user_id=%d AND state='reserved' AND expires_at>UNIX_TIMESTAMP() AND raw_capacity=%d",
            $payload, $filename, $mime, self::charged($length), $expires, $reservation['hash'], $reservation['user_id'], $reservation['raw_capacity']
        ));
        if ($updated !== 1 || $wpdb->last_error) { return self::unavailable(); }
        $stored = $wpdb->get_row($wpdb->prepare("SELECT payload,filename,mime,expires_at FROM {$table} WHERE token_hash=%s AND state='ready'", $reservation['hash']));
        if (!$stored || $wpdb->last_error || $stored->payload !== $payload || $stored->filename !== $filename || $stored->mime !== $mime || (int) $stored->expires_at !== $expires) {
            $wpdb->delete($table, ['token_hash' => $reservation['hash']], ['%s']);
            return self::unavailable();
        }
        return ['url' => rest_url('aiesk/v1/download/' . rawurlencode($reservation['token'])), 'filename' => $filename, 'mime' => $mime];
    }

    private static function valid_reservation(array $reservation): bool {
        return isset($reservation['token'], $reservation['hash'], $reservation['user_id'], $reservation['raw_capacity'])
            && is_string($reservation['token']) && is_string($reservation['hash'])
            && preg_match('/^[a-f0-9]{64}$/', $reservation['hash'])
            && hash_equals($reservation['hash'], hash('sha256', $reservation['token']))
            && is_int($reservation['user_id']) && is_int($reservation['raw_capacity']);
    }

    public static function release(array $reservation): void {
        if (!self::valid_reservation($reservation)) { return; }
        global $wpdb;
        $wpdb->delete(self::table(), ['token_hash' => $reservation['hash'], 'user_id' => $reservation['user_id'], 'state' => 'reserved'], ['%s', '%d', '%s']);
    }

    /** Return null only when no v3 record exists, so legacy v2 can be checked. */
    public static function consume(string $token) {
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)) { return self::not_found(); }
        global $wpdb;
        $table = self::table();
        $hash = hash('sha256', $token);
        $row = $wpdb->get_row($wpdb->prepare("SELECT state,expires_at,payload,filename,mime FROM {$table} WHERE token_hash=%s", $hash));
        if ($wpdb->last_error) { return self::unavailable(); }
        if (!$row) { return null; }
        if ($row->state !== 'ready') { return self::not_found(); }
        if ((int) $row->expires_at <= time() || strlen($row->payload) > self::charged(self::MAX_RAW) - self::OVERHEAD) {
            $wpdb->delete($table, ['token_hash' => $hash], ['%s']); return self::not_found();
        }
        $bytes = base64_decode($row->payload, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_RAW) {
            $wpdb->delete($table, ['token_hash' => $hash], ['%s']); return self::not_found();
        }
        // Removing the payload row is both the one-use claim and quota release.
        $claimed = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE token_hash=%s AND state='ready' AND expires_at>UNIX_TIMESTAMP()", $hash));
        if ($claimed !== 1) { return $claimed === false ? self::unavailable() : self::not_found(); }
        return ['bytes' => $bytes, 'filename' => sanitize_file_name($row->filename) ?: 'download.bin', 'mime' => sanitize_mime_type($row->mime) ?: 'application/octet-stream'];
    }

    public static function cleanup(): void {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_col($wpdb->prepare("SELECT token_hash FROM {$table} WHERE expires_at<=%d ORDER BY expires_at ASC LIMIT 100", time()));
        if ($wpdb->last_error || !$rows) { return; }
        $started = microtime(true);
        $done = 0;
        $failed = false;
        foreach ($rows as $hash) {
            if (microtime(true) - $started >= 2) { break; }
            $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE token_hash=%s AND expires_at<=%d", $hash, time()));
            if ($deleted === false) { $failed = true; }
            $done++;
        }
        if ($failed || count($rows) === 100 || $done < count($rows)) { self::schedule_cleanup(); }
    }

    public static function schedule_cleanup(): void {
        if (!wp_next_scheduled(self::BACKLOG_HOOK)) { wp_schedule_single_event(time() + 30, self::BACKLOG_HOOK); }
    }

    /** Aggregates only: never return file contents, names, bearer tokens or user identities. */
    public static function health(): array {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS records,COALESCE(SUM(charged_bytes),0) AS bytes,COALESCE(SUM(CASE WHEN expires_at<=%d THEN 1 ELSE 0 END),0) AS expired_records,COALESCE(SUM(CASE WHEN state='reserved' THEN 1 ELSE 0 END),0) AS reserved_records,MIN(CASE WHEN expires_at<=%d THEN expires_at ELSE NULL END) AS oldest_expired_at FROM {$table}", time(), time()));
        $error = (bool) $wpdb->last_error;
        $legacy = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS records,COALESCE(SUM(OCTET_LENGTH(option_value)),0) AS bytes FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_aiesk_download_') . '%'));
        if ($error || $wpdb->last_error || !$row || !$legacy) { return array_merge(self::limits(), ['available' => false]); }
        return array_merge(self::limits(), ['available' => true, 'site_bytes' => (int) $row->bytes + (int) $legacy->bytes, 'records' => (int) $row->records, 'legacy_records' => (int) $legacy->records, 'expired_records' => (int) $row->expired_records, 'reserved_records' => (int) $row->reserved_records, 'oldest_expired_at' => (int) $row->oldest_expired_at]);
    }

    /** Number of removed rows, or an error that privacy tools can report honestly. */
    public static function forget_user(int $user) {
        global $wpdb;
        if ($user <= 0) { return 0; }
        $removed = $wpdb->delete(self::table(), ['user_id' => $user], ['%d']);
        return $removed === false ? self::unavailable() : (int) $removed;
    }

    private static function lock() {
        global $wpdb;
        $name = 'aiesk_download_budget_lock';
        try { $value = (time() + 120) . ':' . bin2hex(random_bytes(16)); }
        catch (Throwable $error) { return self::unavailable(); }
        $insert = static function () use ($wpdb, $name, $value) { return $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'off')", $name, $value)); };
        $inserted = $insert();
        if ($inserted === false) { return self::unavailable(); }
        if ($inserted !== 1) {
            $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name));
            if ($wpdb->last_error) { return self::unavailable(); }
            if (is_string($old) && (int) explode(':', $old, 2)[0] <= time()) { self::unlock(['name' => $name, 'value' => $old]); }
            $inserted = $insert();
            if ($inserted === false) { return self::unavailable(); }
            if ($inserted !== 1) { return new WP_Error('aiesk_download_busy', __('Another download is being prepared. Please try again in a moment.', 'ai-share-kit'), ['status' => 429, 'retry_after' => 2]); }
        }
        return ['name' => $name, 'value' => $value];
    }

    private static function unlock(array $lease): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lease['name'], $lease['value']));
        wp_cache_delete($lease['name'], 'options'); wp_cache_delete('notoptions', 'options');
    }
}
