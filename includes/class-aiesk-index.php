<?php
/** Bounded, rebuildable summary index. WordPress posts and metadata remain authoritative. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Index {
    public const VERSION = 1;
    public const BATCH_SIZE = 50;
    public const CRON_HOOK = 'aiesk_build_report_index';
    private const STATE = 'aiesk_report_index_state';
    private const KEYS = ['aiesk_creator', 'aiesk_token', 'aiesk_created_at', 'aiesk_expires_at', 'aiesk_revoked_at', 'aiesk_link_format', 'aiesk_retention_days', 'aiesk_delete_after'];
    private static $deleting = [];

    public function __construct() {
        add_action('init', [$this, 'initialize'], 2);
        add_action(self::CRON_HOOK, [$this, 'migrate']);
        add_action('save_post_aiesk_link', [$this, 'post_changed'], 20, 3);
        add_action('post_updated', [$this, 'type_changed'], 20, 3);
        add_action('added_post_meta', [$this, 'meta_changed'], 20, 4);
        add_action('updated_post_meta', [$this, 'meta_changed'], 20, 4);
        add_action('deleted_post_meta', [$this, 'meta_changed'], 20, 4);
        add_action('before_delete_post', [$this, 'before_delete'], 1, 2);
        add_action('deleted_post', [$this, 'post_deleted'], 20);
    }

    public static function table(): string { global $wpdb; return $wpdb->prefix . 'aiesk_reports'; }

    public function initialize(): void {
        if ((int) get_option('aiesk_report_index_schema', 0) !== self::VERSION) {
            // Never repeat expensive schema work on every request after a DB failure.
            if (get_transient('aiesk_report_index_install_retry')) { return; }
            set_transient('aiesk_report_index_install_retry', 1, 300);
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            global $wpdb;
            $table = self::table();
            $charset = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE $table (
                post_id bigint(20) unsigned NOT NULL,
                owner_id bigint(20) NOT NULL DEFAULT 0,
                token_hash varchar(64) NOT NULL DEFAULT '',
                post_status varchar(20) NOT NULL DEFAULT '',
                created_at bigint(20) NOT NULL DEFAULT 0,
                expires_at bigint(20) NOT NULL DEFAULT 0,
                revoked_at bigint(20) NOT NULL DEFAULT 0,
                delete_after bigint(20) NOT NULL DEFAULT 0,
                retention_days int(11) NOT NULL DEFAULT 0,
                payload_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (post_id),
                KEY token_lookup (token_hash,post_status),
                KEY owner_created (owner_id,post_status,created_at,post_id),
                KEY owner_usage (owner_id,payload_bytes),
                KEY retention_due (delete_after,post_id),
                KEY expiry (expires_at,revoked_at)
            ) $charset;");
            // A real read proves that the table exists, including SQLite adapters.
            $wpdb->get_var("SELECT post_id FROM $table LIMIT 1");
            if ($wpdb->last_error) { return; }
            update_option('aiesk_report_index_schema', self::VERSION, false);
            self::invalidate();
            delete_transient('aiesk_report_index_install_retry');
        }
        if (!self::ready()) { self::schedule(); }
    }

    public static function ready(): bool {
        if ((int) get_option('aiesk_report_index_schema', 0) !== self::VERSION) { return false; }
        // A long-running request must observe another worker invalidating the
        // index; request-local/persistent option caches are not a validity lock.
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::STATE));
        if ($wpdb->last_error) { return false; }
        $state = maybe_unserialize($raw);
        return is_array($state) && !empty($state['ready']);
    }

    private static function schedule(int $delay = 10): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) { wp_schedule_single_event(time() + $delay, self::CRON_HOOK); }
    }

    /** Invalidation fails back to source queries; it never grants access or quota. */
    public static function invalidate(): void {
        update_option(self::STATE, ['ready' => false, 'cursor' => 0, 'generation' => wp_generate_uuid4()], false);
        self::schedule(30);
    }

    /** Compare-and-swap progress prevents a concurrent failed write being marked ready. */
    private static function advance(array $before, array $after): bool {
        global $wpdb;
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",
            maybe_serialize($after), self::STATE, maybe_serialize($before)
        ));
        wp_cache_delete(self::STATE, 'options');
        wp_cache_delete('notoptions', 'options');
        return $changed === 1;
    }

    public function migrate(): void {
        if ((int) get_option('aiesk_report_index_schema', 0) !== self::VERSION) { return; }
        $state = get_option(self::STATE, []);
        if (!is_array($state) || !$state) { self::invalidate(); return; }
        if (!empty($state['ready'])) { return; }
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='aiesk_link' AND ID>%d ORDER BY ID ASC LIMIT %d", (int) ($state['cursor'] ?? 0), self::BATCH_SIZE));
        if ($wpdb->last_error) { self::schedule(60); return; }
        $cursor = (int) ($state['cursor'] ?? 0);
        $started = microtime(true);
        foreach ($ids as $id) {
            if (!self::sync((int) $id)) { self::schedule(60); return; }
            $cursor = (int) $id;
            if (microtime(true) - $started > 3) { break; }
        }
        $finished = count($ids) < self::BATCH_SIZE && (!$ids || $cursor === (int) end($ids));
        // Remove orphans without scanning report bodies. Source deletion hooks are
        // normally sufficient; this also repairs interrupted historic deletions.
        if ($finished) {
            $table = self::table();
            $orphans = $wpdb->get_col("SELECT i.post_id FROM $table i LEFT JOIN {$wpdb->posts} p ON p.ID=i.post_id WHERE p.ID IS NULL OR p.post_type<>'aiesk_link' ORDER BY i.post_id ASC LIMIT " . self::BATCH_SIZE);
            if ($wpdb->last_error) { self::schedule(60); return; }
            if ($orphans) {
                $in = implode(',', array_fill(0, count($orphans), '%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE post_id IN ($in)", array_map('intval', $orphans)));
                if ($wpdb->last_error) { self::schedule(60); return; }
                if (count($orphans) >= self::BATCH_SIZE) { $finished = false; }
            }
        }
        $next = array_merge($state, ['cursor' => $cursor, 'ready' => $finished, 'updated_at' => time()]);
        self::advance($state, $next);
        if (!self::ready()) { self::schedule(); }
    }

    public function post_changed(int $id, $post = null, $update = false): void {
        if ($post && $post->post_type === 'aiesk_link') { self::sync($id); }
    }
    public function type_changed(int $id, $after, $before): void {
        if ($before->post_type === 'aiesk_link' && $after->post_type !== 'aiesk_link') { self::remove($id); }
    }
    public function meta_changed($meta_id, int $id, string $key, $value = null): void {
        if (!isset(self::$deleting[$id]) && (in_array($key, self::KEYS, true) || $key === 'aiesk_payload_html')) { self::sync($id); }
    }
    public function before_delete(int $id, $post = null): void {
        $post = $post ?: get_post($id);
        if ($post && $post->post_type === 'aiesk_link') { self::$deleting[$id] = true; }
    }
    public function post_deleted(int $id): void {
        if (isset(self::$deleting[$id])) { self::remove($id); unset(self::$deleting[$id]); }
    }
    private static function remove(int $id): void {
        if ((int) get_option('aiesk_report_index_schema', 0) !== self::VERSION) { return; }
        global $wpdb;
        $table = self::table();
        if ($wpdb->query($wpdb->prepare("DELETE FROM $table WHERE post_id=%d AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID=%d AND p.post_type='aiesk_link')", $id, $id)) === false) { self::invalidate(); }
    }

    /** Only named scalar metadata is read. Never prime WordPress's full meta cache. */
    public static function summaries(array $ids) {
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        if (!$ids) { return []; }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $posts = $wpdb->get_results($wpdb->prepare("SELECT ID,post_author,post_title,post_status,post_date_gmt,post_date FROM {$wpdb->posts} WHERE post_type='aiesk_link' AND ID IN ($placeholders)", $ids));
        if ($wpdb->last_error) { return self::error(); }
        $keys = implode(',', array_fill(0, count(self::KEYS), '%s'));
        $meta = $wpdb->get_results($wpdb->prepare("SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key IN ($keys) ORDER BY meta_id ASC", array_merge($ids, self::KEYS)));
        if ($wpdb->last_error) { return self::error(); }
        $values = [];
        foreach ($meta as $m) {
            // Matches WordPress get_post_meta(..., true) for historic duplicates.
            if (!isset($values[(int) $m->post_id][$m->meta_key])) {
                $value = maybe_unserialize($m->meta_value);
                if (!is_scalar($value) && $value !== null) { return self::error(); }
                $values[(int) $m->post_id][$m->meta_key] = (string) $value;
            }
        }
        $rows = [];
        foreach ($posts as $p) {
            $m = $values[(int) $p->ID] ?? [];
            $created = (int) ($m['aiesk_created_at'] ?? 0);
            if (!$created) { $created = (int) strtotime(($p->post_date_gmt === '0000-00-00 00:00:00' ? $p->post_date : $p->post_date_gmt) . ' UTC'); }
            $rows[(int) $p->ID] = [
                'post_id' => (int) $p->ID, 'owner_id' => (int) ($m['aiesk_creator'] ?? 0) ?: (int) $p->post_author,
                'title' => (string) $p->post_title, 'post_status' => (string) $p->post_status,
                'token' => (string) ($m['aiesk_token'] ?? ''), 'created_at' => $created,
                'expires_at' => (int) ($m['aiesk_expires_at'] ?? 0), 'revoked_at' => (int) ($m['aiesk_revoked_at'] ?? 0),
                'delete_after' => (int) ($m['aiesk_delete_after'] ?? 0), 'retention_days' => (int) ($m['aiesk_retention_days'] ?? 0),
                'link_format' => (string) ($m['aiesk_link_format'] ?? ''),
            ];
        }
        return $rows;
    }

    public static function sync(int $id): bool {
        if ((int) get_option('aiesk_report_index_schema', 0) !== self::VERSION) { return false; }
        global $wpdb;
        $name = 'aiesk_report_lock_' . $id;
        $lease = (time() + 30) . ':' . wp_generate_uuid4();
        $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name));
        if (is_string($old) && (int) $old <= time()) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, $old));
        }
        if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'off')", $name, $lease)) !== 1) {
            // A concurrent source edit may otherwise overwrite a newer summary.
            self::invalidate(); return false;
        }
        try {
            $ok = self::sync_locked($id, $name, $lease);
            $held = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name));
            if ($held !== $lease || (int) $lease <= time()) { self::invalidate(); return false; }
            return $ok;
        } finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, $lease));
            wp_cache_delete($name, 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    private static function sync_locked(int $id, string $name, string $lease): bool {
        if ((int) get_option('aiesk_report_index_schema', 0) !== self::VERSION) { return false; }
        $rows = self::summaries([$id]);
        if (is_wp_error($rows)) { self::invalidate(); return false; }
        if (!isset($rows[$id])) {
            global $wpdb;
            $table = self::table();
            $result = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE post_id=%d AND EXISTS (SELECT 1 FROM {$wpdb->options} gate WHERE gate.option_name=%s AND gate.option_value=%s AND CAST(gate.option_value AS UNSIGNED)>UNIX_TIMESTAMP()) AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID=%d AND p.post_type='aiesk_link')", $id, $name, $lease, $id));
            if ($result === false) { self::invalidate(); return false; }
            return true;
        }
        $row = $rows[$id];
        global $wpdb;
        $bytes = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(OCTET_LENGTH(meta_value)),0) FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='aiesk_payload_html'", $id));
        if ($wpdb->last_error || $bytes === null) { self::invalidate(); return false; }
        $data = array_intersect_key($row, array_flip(['post_id', 'owner_id', 'post_status', 'created_at', 'expires_at', 'revoked_at', 'delete_after', 'retention_days']));
        $data['token_hash'] = $row['token'] !== '' ? hash('sha256', $row['token']) : '';
        $data['payload_bytes'] = max(0, (int) $bytes);
        $table = self::table();
        $columns = array_keys($data);
        $formats = array_map(static fn($key) => in_array($key, ['post_status', 'token_hash'], true) ? '%s' : '%d', $columns);
        $updates = array_map(static fn($key) => "$key=VALUES($key)", $columns);
        $sql = "INSERT INTO $table (" . implode(',', $columns) . ') SELECT ' . implode(',', $formats)
            . " FROM {$wpdb->options} gate WHERE gate.option_name=%s AND gate.option_value=%s AND CAST(gate.option_value AS UNSIGNED)>UNIX_TIMESTAMP()"
            . ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
        $written = $wpdb->query($wpdb->prepare($sql, array_merge(array_values($data), [$name, $lease])));
        if ($written === false) { self::invalidate(); return false; }
        // MySQL returns zero for a legitimate unchanged upsert and for a missing
        // gate. The outer exact lease check plus this scalar row comparison must
        // both succeed before an unchanged write can count as synchronized.
        if ($written === 0) {
            $stored = $wpdb->get_row($wpdb->prepare("SELECT " . implode(',', $columns) . " FROM $table WHERE post_id=%d", $id), ARRAY_A);
            if ($wpdb->last_error || !$stored || array_map('strval', $stored) !== array_map('strval', $data)) { self::invalidate(); return false; }
        }
        return true;
    }

    /** null permits the legacy lookup; zero is an authoritative indexed miss. */
    public static function find_token(string $token): ?int {
        if (!self::ready()) { return null; }
        global $wpdb;
        $table = self::table();
        $id = $wpdb->get_var($wpdb->prepare("SELECT i.post_id FROM $table i INNER JOIN {$wpdb->posts} p ON p.ID=i.post_id WHERE i.token_hash=%s AND i.post_status='publish' AND p.post_type='aiesk_link' AND p.post_status='publish' ORDER BY i.post_id ASC LIMIT 1", hash('sha256', $token)));
        if ($wpdb->last_error) { self::invalidate(); return null; }
        // The public reader must still compare the source token and lifecycle.
        return (int) $id;
    }

    public static function usage(int $owner) {
        if (!self::ready()) { return null; }
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS records, COALESCE(SUM(payload_bytes),0) AS bytes, (SELECT option_value FROM {$wpdb->options} WHERE option_name=%s) AS index_state FROM $table WHERE owner_id=%d", self::STATE, $owner), ARRAY_A);
        if (!$row || $wpdb->last_error) { self::invalidate(); return self::error(); }
        $state = maybe_unserialize($row['index_state'] ?? null);
        if (!is_array($state) || empty($state['ready'])) { return null; }
        return ['records' => (int) $row['records'], 'bytes' => (int) $row['bytes']];
    }

    public static function list_rows(int $owner, string $status, string $search, int $page, int $per_page, int $now) {
        if (!self::ready()) { return null; }
        global $wpdb;
        $table = self::table();
        $where = "i.post_status='publish' AND p.post_type='aiesk_link' AND p.post_status='publish'";
        if ($owner > 0) { $where .= $wpdb->prepare(' AND i.owner_id=%d', $owner); }
        if ($status === 'revoked') { $where .= ' AND i.revoked_at<>0'; }
        elseif ($status === 'expired') { $where .= $wpdb->prepare(' AND i.revoked_at=0 AND i.expires_at<>0 AND i.expires_at<=%d', $now); }
        elseif ($status === 'active') { $where .= $wpdb->prepare(' AND i.revoked_at=0 AND (i.expires_at=0 OR i.expires_at>%d)', $now); }
        if ($search !== '') { $where .= $wpdb->prepare(' AND p.post_title LIKE %s', '%' . $wpdb->esc_like($search) . '%'); }
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table i INNER JOIN {$wpdb->posts} p ON p.ID=i.post_id WHERE $where");
        if ($wpdb->last_error || $total === null) { self::invalidate(); return self::error(); }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT i.* FROM $table i INNER JOIN {$wpdb->posts} p ON p.ID=i.post_id WHERE $where ORDER BY i.created_at DESC,i.post_id DESC LIMIT %d OFFSET %d", $per_page, ($page - 1) * $per_page), ARRAY_A);
        if ($wpdb->last_error) { self::invalidate(); return self::error(); }
        return ['rows' => $rows, 'total' => (int) $total];
    }

    public static function due_ids(int $after, int $now, int $limit): ?array {
        if (!self::ready()) { return null; }
        global $wpdb;
        $table = self::table();
        $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM $table WHERE post_id>%d AND delete_after>0 AND delete_after<=%d AND retention_days>0 ORDER BY post_id ASC LIMIT %d", $after, $now, $limit));
        if ($wpdb->last_error) { self::invalidate(); return null; }
        return array_map('intval', $ids);
    }

    /** Admin-only caller receives aggregates, never report titles, tokens or HTML. */
    public static function health(): array {
        $state = get_option(self::STATE, []);
        $result = ['ready' => self::ready(), 'cursor' => (int) ($state['cursor'] ?? 0), 'updated_at' => (int) ($state['updated_at'] ?? 0), 'next_build' => (int) wp_next_scheduled(self::CRON_HOOK)];
        if (!$result['ready']) { return $result; }
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS records,COALESCE(SUM(payload_bytes),0) AS bytes,COALESCE(SUM(CASE WHEN retention_days>0 AND delete_after>0 AND delete_after<=%d THEN 1 ELSE 0 END),0) AS overdue FROM $table", time()), ARRAY_A);
        if (!$row || $wpdb->last_error) { self::invalidate(); $result['ready'] = false; return $result; }
        return array_merge($result, array_map('intval', $row));
    }

    private static function error(): WP_Error {
        return new WP_Error('aiesk_index_unavailable', __('Shared report information could not be checked. Please try again shortly.', 'ai-share-kit'), ['status' => 503]);
    }
}
