<?php
/** Retention and explicit, verified deletion of stored shared-report snapshots. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Privacy {
    public const BATCH_SIZE = 50;
    public const CRON_HOOK = 'aiesk_purge_shared_links';
    public const CONTINUE_HOOK = 'aiesk_purge_shared_links_continue';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('rest_post_dispatch', [$this, 'private_headers'], 10, 3);
        add_action(self::CRON_HOOK, [$this, 'purge_expired']);
        add_action(self::CONTINUE_HOOK, [$this, 'purge_expired']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'privacy_policy']);
        add_action('aiesk_privacy_after_settings', [$this, 'legacy_qr_tools']);
        add_action('admin_post_aiesk_cleanup_legacy_qr', [$this, 'cleanup_legacy_qr']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
    }

    public static function retention_days($value): int {
        return max(0, min(365, (int) $value));
    }

    /** Merge into the caller's metadata write-and-verify transaction. */
    public static function creation_metadata(int $expires): array {
        $days = self::retention_days(get_option('aiesk_retention_days', 30));
        return [
            'aiesk_retention_days' => $days,
            'aiesk_delete_after' => $days > 0 && $expires > 0 ? $expires + $days * DAY_IN_SECONDS : 0,
        ];
    }

    /** Revocation closes access first. A retention failure must not undo it. */
    public static function revoked(int $post_id, int $at) {
        if (!metadata_exists('post', $post_id, 'aiesk_retention_days')) { return true; }
        $days = self::retention_days(get_post_meta($post_id, 'aiesk_retention_days', true));
        if (!$days) { return true; }
        $current = (int) get_post_meta($post_id, 'aiesk_delete_after', true);
        $delete_after = $at + $days * DAY_IN_SECONDS;
        if ($current > 0) { $delete_after = min($current, $delete_after); }
        update_post_meta($post_id, 'aiesk_delete_after', $delete_after);
        if ((int) get_post_meta($post_id, 'aiesk_delete_after', true) !== $delete_after) {
            return new WP_Error('aiesk_retention_failed', __('Access is revoked, but the automatic deletion date could not be saved. You can delete this report manually.', 'ai-share-kit'), ['status' => 500]);
        }
        return true;
    }

    public static function management_fields($post): array {
        return [
            'can_delete' => (bool) aiesk_can_manage_link($post),
            'delete_after' => (int) get_post_meta((int) $post->ID, 'aiesk_delete_after', true),
        ];
    }

    public function register_routes(): void {
        register_rest_route('aiesk/v1', '/link/(?P<id>\d+)/permanent', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_link'],
            'permission_callback' => 'aiesk_manage_links_permission',
            'args' => ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
        ]);
    }

    public function private_headers($response, $server, $request) {
        return preg_match('~^/aiesk/v1/link/\d+/permanent/?$~', $request->get_route())
            ? aiesk_private_rest_response($response) : $response;
    }

    public function delete_link(WP_REST_Request $request) {
        $permission = aiesk_manage_links_permission();
        if (is_wp_error($permission)) { return $permission; }
        $post_id = absint($request['id']);
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'aiesk_link' || !aiesk_can_manage_link($post)) {
            return new WP_Error('aiesk_link_not_found', __('Share link not found.', 'ai-share-kit'), ['status' => 404]);
        }
        $body = $request->get_json_params();
        if (!is_array($body) || ($body['confirm'] ?? null) !== true) {
            return new WP_Error('aiesk_delete_confirmation_required', __('Confirm permanent deletion of this shared report.', 'ai-share-kit'), ['status' => 400]);
        }
        $deleted = self::delete_record($post_id);
        return is_wp_error($deleted) ? $deleted : aiesk_private_rest_response([
            'success' => true, 'id' => $post_id, 'post_id' => $post_id, 'deleted' => true,
        ]);
    }

    /** Called only after explicit owner/admin authorization or a scoped purge. */
    private static function delete_record(int $post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'aiesk_link') {
            return new WP_Error('aiesk_delete_failed', __('The shared report could not be found for deletion.', 'ai-share-kit'), ['status' => 404]);
        }
        // A failed file cleanup must not leave an explicitly deleted report
        // publicly accessible. Close access first and verify the persisted state.
        if ($post->post_status === 'publish' && aiesk_link_status($post_id) === 'active') {
            $revoked_at = time();
            update_post_meta($post_id, 'aiesk_revoked_at', $revoked_at);
            if (aiesk_link_status($post_id) === 'active') {
                return new WP_Error('aiesk_revoke_failed', __('Access could not be revoked. Permanent deletion was not performed. Please try again or contact an administrator.', 'ai-share-kit'), ['status' => 500]);
            }
            // Retention remains a fallback if subsequent permanent deletion fails.
            // Public revocation must not be undone if its retention update fails.
            self::revoked($post_id, (int) get_post_meta($post_id, 'aiesk_revoked_at', true) ?: $revoked_at);
        }
        $qr = self::delete_legacy_qr($post_id);
        if (is_wp_error($qr)) { return self::deletion_failure($post_id, $qr->get_error_code(), $qr->get_error_message()); }
        wp_delete_post($post_id, true);
        // Some integrations veto deletion or leave metadata behind. Never show
        // success until both the record and all its snapshot metadata are gone.
        if (get_post($post_id) || get_post_meta($post_id)) {
            return self::deletion_failure($post_id, 'aiesk_delete_failed', __('Permanent deletion could not be completed. Please try again or contact an administrator.', 'ai-share-kit'));
        }
        return true;
    }

    private static function deletion_failure(int $post_id, string $code, string $detail): WP_Error {
        $post = get_post($post_id);
        $closed = !$post || $post->post_status !== 'publish' || aiesk_link_status($post_id) !== 'active';
        $message = $closed ? __('Public access is closed, but permanent deletion could not be completed.', 'ai-share-kit') . ' ' . $detail : $detail;
        return new WP_Error($code, $message, ['status' => 500, 'access_closed' => $closed]);
    }

    /** Delete only this plugin's verified local legacy QR image, never a URL fetch. */
    private static function delete_legacy_qr(int $post_id) {
        $url = (string) get_post_meta($post_id, 'aiesk_qr_url', true);
        if ($url === '') { return true; }
        $uploads = wp_upload_dir();
        $base = wp_parse_url((string) ($uploads['baseurl'] ?? ''));
        $target = wp_parse_url($url);
        $base_path = rtrim((string) ($base['path'] ?? ''), '/') . '/';
        $url_path = rawurldecode((string) ($target['path'] ?? ''));
        $valid = !empty($uploads['basedir']) && !empty($base['host']) && !empty($target['host'])
            && strtolower($base['host']) === strtolower($target['host'])
            && ($base['port'] ?? null) === ($target['port'] ?? null)
            && str_starts_with($url_path, $base_path)
            && !str_contains($url_path, "\0")
            && preg_match('/^aiesk-qr-' . $post_id . '(?:-\d+)?\.png$/', basename($url_path));
        if (!$valid) { return self::qr_cleanup_error(); }
        $relative = substr($url_path, strlen($base_path));
        if (preg_match('~(?:^|/)\.\.(?:/|$)~', $relative)) { return self::qr_cleanup_error(); }
        $path = rtrim($uploads['basedir'], '/\\') . '/' . $relative;
        if (!file_exists($path) && !is_link($path)) { return true; }
        $real_base = realpath($uploads['basedir']);
        $real_path = realpath($path);
        if (!$real_base || !$real_path || is_link($path) || !is_file($real_path)
            || !str_starts_with($real_path, rtrim($real_base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return self::qr_cleanup_error();
        }
        wp_delete_file($real_path);
        clearstatcache(true, $real_path);
        return file_exists($real_path) ? self::qr_cleanup_error() : true;
    }

    private static function qr_cleanup_error(): WP_Error {
        return new WP_Error('aiesk_qr_cleanup_failed', __('The saved QR image could not be safely removed. Ask an administrator to check the image before deleting this report.', 'ai-share-kit'), ['status' => 500]);
    }

    /** Bounded batches continue promptly; one hourly tick can drain a backlog. */
    public function purge_expired(): void {
        // WordPress cron can be launched by several workers. Serialize a batch
        // so their cursors and deletion results cannot overwrite one another.
        if (!class_exists('AIESK_Index')) { $this->purge_batch(); return; }
        global $wpdb;
        $name = 'aiesk_retention_lock';
        $lease = (time() + 60) . ':' . wp_generate_uuid4();
        $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name));
        if (is_string($old) && (int) $old <= time()) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, $old));
        }
        if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'off')", $name, $lease)) !== 1) {
            $this->schedule_continuation(60); return;
        }
        try { $this->purge_batch($lease); }
        finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, $lease));
            wp_cache_delete($name, 'options'); wp_cache_delete('notoptions', 'options');
        }
    }

    private function purge_batch(string $lease = ''): void {
        $cursor = max(0, (int) get_option('aiesk_purge_cursor', 0));
        $now = time();
        $ids = class_exists('AIESK_Index') ? AIESK_Index::due_ids($cursor, $now, self::BATCH_SIZE) : null;
        if ($ids === null) {
            $ids = $this->query_ids(0, $cursor, 1, [
                'relation' => 'AND',
                ['key' => 'aiesk_retention_days', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC'],
                ['key' => 'aiesk_delete_after', 'value' => [1, $now], 'compare' => 'BETWEEN', 'type' => 'NUMERIC'],
            ]);
        }
        $summaries = class_exists('AIESK_Index') ? AIESK_Index::summaries($ids) : null;
        if (is_wp_error($summaries)) {
            $this->schedule_continuation(60);
            return;
        }
        $started = microtime(true);
        $processed = $removed = $failed = 0;
        foreach ($ids as $id) {
            $cursor = max($cursor, (int) $id);
            $processed++;
            // Read only scalar source metadata before deletion. No report body is
            // needed to decide whether a record qualifies for retention.
            if (is_array($summaries)) {
                $row = $summaries[(int) $id] ?? null;
                if (!$row) { continue; }
                $days = $row['retention_days']; $due = $row['delete_after'];
                $inactive = $row['post_status'] !== 'publish' || $row['revoked_at'] !== 0 || ($row['expires_at'] !== 0 && $row['expires_at'] <= $now);
            } else {
                if (!metadata_exists('post', $id, 'aiesk_retention_days')) { continue; }
                $days = (int) get_post_meta($id, 'aiesk_retention_days', true);
                $due = (int) get_post_meta($id, 'aiesk_delete_after', true);
                $inactive = aiesk_link_status($id, $now) !== 'active';
            }
            if ($days > 0 && $due > 0 && $due <= $now && $inactive) {
                $result = self::delete_record((int) $id);
                if (is_wp_error($result)) { $failed++; } else { $removed++; }
            }
            if (microtime(true) - $started >= 3) { break; }
        }
        $more = $processed < count($ids) || count($ids) >= self::BATCH_SIZE;
        // Failed records are retried on the next complete cycle, without starving
        // later IDs or spinning a continuation forever around a vetoed deletion.
        if ($lease !== '') {
            global $wpdb;
            $held = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='aiesk_retention_lock'");
            if ($held !== $lease || (int) $lease <= time()) { $this->schedule_continuation(60); return; }
        }
        update_option('aiesk_purge_cursor', $more ? $cursor : 0, false);
        update_option('aiesk_retention_health', ['last_run' => $now, 'processed' => $processed, 'removed' => $removed, 'failed' => $failed, 'continuing' => $more], false);
        if ($more) { $this->schedule_continuation(); }
    }

    private function schedule_continuation(int $delay = 10): void {
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled(self::CONTINUE_HOOK)) {
            wp_schedule_single_event(time() + $delay, self::CONTINUE_HOOK);
        }
    }

    public static function health(): array {
        $state = get_option('aiesk_retention_health', []);
        return array_merge(is_array($state) ? $state : [], [
            'next_run' => (int) wp_next_scheduled(self::CRON_HOOK),
            'next_continuation' => (int) wp_next_scheduled(self::CONTINUE_HOOK),
            'cursor' => max(0, (int) get_option('aiesk_purge_cursor', 0)),
        ]);
    }

    /** Creator precedence matches management; post_author is legacy fallback only. */
    private function query_ids(int $owner, int $after = 0, int $page = 1, array $meta = []): array {
        $marker = spl_object_hash($this) . ':privacy';
        $where = static function ($where, $query) use ($marker, $owner, $after) {
            if ($query->get('aiesk_privacy_query') !== $marker) { return $where; }
            global $wpdb;
            if ($owner > 0) {
                $where .= $wpdb->prepare(
                    " AND (EXISTS (SELECT 1 FROM {$wpdb->postmeta} ap_owner WHERE ap_owner.post_id={$wpdb->posts}.ID AND ap_owner.meta_key='aiesk_creator' AND CAST(ap_owner.meta_value AS SIGNED)=%d)"
                    . " OR ({$wpdb->posts}.post_author=%d AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} ap_creator WHERE ap_creator.post_id={$wpdb->posts}.ID AND ap_creator.meta_key='aiesk_creator' AND CAST(ap_creator.meta_value AS SIGNED)<>0)))",
                    $owner, $owner
                );
            }
            if ($after > 0) { $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after); }
            return $where;
        };
        add_filter('posts_where', $where, 10, 2);
        try {
            return array_map('intval', get_posts([
                'post_type' => 'aiesk_link', 'post_status' => array_keys(get_post_stati()),
                'fields' => 'ids', 'posts_per_page' => self::BATCH_SIZE,
                'paged' => max(1, $page), 'orderby' => 'ID', 'order' => 'ASC',
                'no_found_rows' => true, 'suppress_filters' => false,
                'meta_query' => $meta, 'aiesk_privacy_query' => $marker,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
            ]));
        } finally { remove_filter('posts_where', $where, 10); }
    }

    public function register_exporter(array $exporters): array {
        $exporters['ai-share-kit'] = ['exporter_friendly_name' => __('AI Share Kit reports', 'ai-share-kit'), 'callback' => [$this, 'export_personal_data']];
        return $exporters;
    }

    public function register_eraser(array $erasers): array {
        $erasers['ai-share-kit'] = ['eraser_friendly_name' => __('AI Share Kit reports', 'ai-share-kit'), 'callback' => [$this, 'erase_personal_data']];
        return $erasers;
    }

    public function export_personal_data(string $email, int $page = 1): array {
        $user = get_user_by('email', $email);
        if (!$user) { return ['data' => [], 'done' => true]; }
        $ids = $this->query_ids((int) $user->ID, 0, max(1, $page));
        $data = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || aiesk_link_owner($post) !== (int) $user->ID) { continue; }
            $data[] = [
                'group_id' => 'aiesk-shared-reports', 'group_label' => __('Shared reports', 'ai-share-kit'),
                'item_id' => 'aiesk-report-' . $id,
                'data' => [
                    ['name' => __('Title', 'ai-share-kit'), 'value' => (string) $post->post_title],
                    ['name' => __('Report content', 'ai-share-kit'), 'value' => wp_kses_post((string) get_post_meta($id, 'aiesk_payload_html', true))],
                    ['name' => __('Share URL', 'ai-share-kit'), 'value' => aiesk_link_url($id)],
                    ['name' => __('Status', 'ai-share-kit'), 'value' => aiesk_link_status($id)],
                    ['name' => __('Created (UTC)', 'ai-share-kit'), 'value' => self::export_date((int) get_post_meta($id, 'aiesk_created_at', true))],
                    ['name' => __('Expires (UTC)', 'ai-share-kit'), 'value' => self::export_date((int) get_post_meta($id, 'aiesk_expires_at', true))],
                    ['name' => __('Revoked (UTC)', 'ai-share-kit'), 'value' => self::export_date((int) get_post_meta($id, 'aiesk_revoked_at', true))],
                    ['name' => __('Automatic deletion after (UTC)', 'ai-share-kit'), 'value' => self::export_date((int) get_post_meta($id, 'aiesk_delete_after', true))],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($ids) < self::BATCH_SIZE];
    }

    private static function export_date(int $timestamp): string {
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : __('Not set', 'ai-share-kit');
    }

    public function erase_personal_data(string $email, int $page = 1): array {
        $result = ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        $user = get_user_by('email', $email);
        if (!$user) { return $result; }
        $key = 'aiesk_erase_' . hash('sha256', (int) $user->ID . ':' . strtolower($email));
        $page = max(1, $page);
        if ($page === 1 && class_exists('AIESK_Share_Link')) { AIESK_Share_Link::forget_user((int) $user->ID); }
        if ($page === 1 && class_exists('AIESK_Download_Budget')) {
            $downloads = AIESK_Download_Budget::forget_user((int) $user->ID);
            if (is_wp_error($downloads)) {
                $result['items_retained'] = true;
                $result['messages'][] = __('Temporary report downloads could not be erased. Please retry the erasure request.', 'ai-share-kit');
            } elseif ($downloads > 0) { $result['items_removed'] = true; }
        }
        $state = $page === 1 ? false : get_transient($key);
        if ($page > 1 && (!is_array($state) || !isset($state['last'], $state['page']))) {
            $result['items_retained'] = true;
            $result['messages'][] = __('Report erasure paused because its progress expired. Start a new erasure request to finish.', 'ai-share-kit');
            return $result;
        }
        if ($state && (int) $state['page'] === $page) { return $state['result']; }
        $ids = $this->query_ids((int) $user->ID, $state ? (int) $state['last'] : 0);
        $last = $state ? (int) $state['last'] : 0;
        foreach ($ids as $id) {
            $last = max($last, $id);
            $post = get_post($id);
            if (!$post || aiesk_link_owner($post) !== (int) $user->ID) { continue; }
            $deleted = self::delete_record($id);
            if (is_wp_error($deleted)) {
                $result['items_retained'] = true;
                $result['messages'][] = sprintf(__('Shared report %d could not be deleted. Review it in AI Share Kit and retry erasure.', 'ai-share-kit'), $id);
            } else { $result['items_removed'] = true; }
        }
        $result['done'] = count($ids) < self::BATCH_SIZE;
        // Keyset pagination remains correct after earlier rows have been deleted.
        // Retain the final result briefly so a retried AJAX page is idempotent.
        set_transient($key, ['page' => $page, 'last' => $last, 'result' => $result], HOUR_IN_SECONDS);
        return $result;
    }

    public function register_settings(): void {
        register_setting('aiesk_privacy', 'aiesk_retention_days', [
            'type' => 'integer', 'default' => 30, 'sanitize_callback' => [self::class, 'retention_days'],
        ]);
        add_settings_section('aiesk_privacy_retention', __('Stored shared reports', 'ai-share-kit'), [$this, 'settings_description'], 'aiesk_privacy');
        add_settings_field('aiesk_retention_days', __('Delete inactive reports after', 'ai-share-kit'), [$this, 'retention_field'], 'aiesk_privacy', 'aiesk_privacy_retention');
    }

    public function settings_description(): void {
        echo '<p>' . esc_html__('Sharing stores a separate copy of the selected report on this site. Expiry and revocation stop public access; permanent deletion also removes that stored copy and its link.', 'ai-share-kit') . '</p>';
        echo '<p>' . esc_html__('This setting is saved with each new share. Changing it does not alter existing shares. Older shares without a saved retention policy remain available to their owner until manually deleted.', 'ai-share-kit') . '</p>';
        echo '<p>' . esc_html__('Use Tools → Export Personal Data or Erase Personal Data for a teacher account’s shared reports. Run erasure before deleting or reassigning the account. Reports are matched to the creator’s account email, not to names or emails written inside the report.', 'ai-share-kit') . '</p>';
        echo '<p>' . esc_html__('Automatic cleanup starts hourly and continues in small scheduled batches while a backlog remains. It depends on WordPress scheduled tasks running. It does not remove the original AI Engine conversation, recipient downloads, or backup copies.', 'ai-share-kit') . '</p>';
    }

    public function retention_field(): void {
        $days = self::retention_days(get_option('aiesk_retention_days', 30));
        echo '<label><input type="number" class="small-text" min="0" max="365" step="1" name="aiesk_retention_days" value="' . esc_attr($days) . '"> ' . esc_html__('days after expiry or revocation', 'ai-share-kit') . '</label>';
        echo '<p class="description">' . esc_html__('Default: 30 days. Enter 0 for manual deletion only, or 1–365 for automatic cleanup. Only new shares use this setting.', 'ai-share-kit') . '</p>';
    }

    /** Inventory is read-only; upgrades never revoke existing links automatically. */
    public function legacy_qr_tools(): void {
        if (!current_user_can('manage_options')) { return; }
        $query = new WP_Query([
            'post_type' => 'aiesk_link', 'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => false,
            'update_post_meta_cache' => false, 'update_post_term_cache' => false,
            'meta_query' => [['key' => 'aiesk_qr_url', 'value' => '', 'compare' => '!=']],
        ]);
        $count = (int) $query->found_posts;
        echo '<hr><h2>' . esc_html__('Legacy QR images', 'ai-share-kit') . '</h2>';
        echo '<p>' . esc_html(sprintf(__('Shared reports with a saved legacy QR image: %d.', 'ai-share-kit'), $count)) . '</p>';
        echo '<p>' . esc_html__('Older QR integrations stored images in public uploads. Someone who obtained an image could read its share URL while that link was active. Current sharing controls generate QR images in the browser.', 'ai-share-kit') . '</p>';
        $audit = get_option('aiesk_legacy_qr_cleanup_result', []);
        if (is_array($audit) && isset($audit['processed'], $audit['revoked'], $audit['cleaned'], $audit['failed'], $audit['at'])) {
            echo '<p><strong>' . esc_html__('Last cleanup', 'ai-share-kit') . ':</strong> ' . esc_html(sprintf(
                __('%1$d checked; %2$d links verified revoked; %3$d QR records cleared; %4$d need review. Run at %5$s UTC.', 'ai-share-kit'),
                (int) $audit['processed'], (int) $audit['revoked'], (int) $audit['cleaned'], (int) $audit['failed'], gmdate('Y-m-d H:i:s', (int) $audit['at'])
            )) . '</p>';
            if (!empty($audit['failures']) && is_array($audit['failures'])) {
                echo '<p>' . esc_html__('Reports needing review (IDs only):', 'ai-share-kit') . ' ' . esc_html(implode(', ', array_map('intval', array_keys($audit['failures'])))) . '</p>';
            }
        }
        if (!$count) { return; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="aiesk_cleanup_legacy_qr">';
        wp_nonce_field('aiesk_cleanup_legacy_qr');
        echo '<p>' . esc_html__('Each click processes up to 50 reports. It revokes their links before removing verified local QR images. Report snapshots remain in the dashboard and keep their recorded retention policy. Images at an unverified location are left for an administrator to review.', 'ai-share-kit') . '</p>';
        echo '<p><label><input type="checkbox" name="confirm_legacy_qr" value="1" required> ' . esc_html__('I understand that access through these links and QR codes will stop.', 'ai-share-kit') . '</label></p>';
        echo '<button type="submit" class="button button-secondary">' . esc_html__('Revoke links and remove legacy QR images', 'ai-share-kit') . '</button></form>';
    }

    public function cleanup_legacy_qr(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You are not allowed to manage legacy QR images.', 'ai-share-kit'));
        }
        check_admin_referer('aiesk_cleanup_legacy_qr');
        if (($_POST['confirm_legacy_qr'] ?? null) !== '1') {
            wp_die(__('Confirm that these shared links should be revoked before running cleanup.', 'ai-share-kit'));
        }
        $cursor = max(0, (int) get_option('aiesk_legacy_qr_cursor', 0));
        $ids = $this->query_ids(0, $cursor, 1, [['key' => 'aiesk_qr_url', 'value' => '', 'compare' => '!=']]);
        $result = ['at' => time(), 'processed' => 0, 'revoked' => 0, 'cleaned' => 0, 'failed' => 0, 'failures' => []];
        foreach ($ids as $id) {
            $cursor = max($cursor, $id);
            $post = get_post($id);
            if (!$post || $post->post_type !== 'aiesk_link' || get_post_meta($id, 'aiesk_qr_url', true) === '') { continue; }
            $result['processed']++;
            $errors = [];
            $revoked_at = (int) get_post_meta($id, 'aiesk_revoked_at', true);
            if (!$revoked_at) {
                update_post_meta($id, 'aiesk_revoked_at', time());
                $revoked_at = (int) get_post_meta($id, 'aiesk_revoked_at', true);
            }
            if (!$revoked_at) {
                $errors[] = 'aiesk_revoke_failed';
            } else {
                $result['revoked']++;
                $retention = self::revoked($id, $revoked_at);
                if (is_wp_error($retention)) { $errors[] = $retention->get_error_code(); }
                $removed = self::delete_legacy_qr($id);
                if (is_wp_error($removed)) { $errors[] = $removed->get_error_code(); }
                else {
                    delete_post_meta($id, 'aiesk_qr_url');
                    if (get_post_meta($id, 'aiesk_qr_url', true) !== '') { $errors[] = 'aiesk_qr_metadata_failed'; }
                    else { $result['cleaned']++; }
                }
            }
            if ($errors) {
                $result['failed']++;
                $result['failures'][$id] = $errors;
            }
        }
        update_option('aiesk_legacy_qr_cursor', count($ids) < self::BATCH_SIZE ? 0 : $cursor, false);
        // Only IDs, error codes, counts and time; no report content or bearer URLs.
        update_option('aiesk_legacy_qr_cleanup_result', $result, false);
        wp_safe_redirect(admin_url('admin.php?page=ai-share-kit&tab=privacy'));
        exit;
    }

    public function privacy_policy(): void {
        if (!function_exists('wp_add_privacy_policy_content')) { return; }
        $text = '<p>' . esc_html__('When you share a report, AI Share Kit stores its title, a copy of its content, your account ID, creation and expiry dates, an access token, and any revocation or scheduled deletion date. Anyone with an active share URL or QR code can view that copy.', 'ai-share-kit') . '</p>';
        $text .= '<p>' . esc_html__('Revoking or expiring a link stops public access but does not immediately delete the stored report. New shares use the retention policy recorded when they are created: the default is deletion 30 days after expiry or revocation. Administrators can choose 0 for manual deletion or 1–365 days for new shares. Existing shares keep their recorded policy; older shares without one require manual deletion. Cleanup depends on the site’s scheduled tasks.', 'ai-share-kit') . '</p>';
        $text .= '<p>' . esc_html__('Teachers may permanently delete their own shared reports; administrators may delete any shared report. The site’s personal-data export and erasure tools include reports belonging to the creator account matched by its current email. They do not locate people named within another teacher’s report. Erase shared reports before deleting or reassigning a teacher account.', 'ai-share-kit') . '</p>';
        $text .= '<p>' . esc_html__('Deleting a shared copy does not delete its original AI Engine conversation, recipient downloads, or copies in site backups. Current sharing controls generate QR codes in the browser. Older integrations using the server QR endpoint may send the share URL to an external QR service and store a QR image in uploads.', 'ai-share-kit') . '</p>';
        wp_add_privacy_policy_content('AI Share Kit', $text);
    }
}
