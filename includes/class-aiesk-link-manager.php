<?php
/**
 * Authenticated ownership-scoped management of existing shared reports.
 */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Link_Manager {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('rest_post_dispatch', [$this, 'private_headers'], 10, 3);
    }

    public function register_routes(): void {
        register_rest_route('aiesk/v1', '/links', [
            'methods' => 'GET',
            'callback' => [$this, 'list_links'],
            'permission_callback' => 'aiesk_manage_links_permission',
            'args' => [
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
                'status' => ['type' => 'string', 'enum' => ['all', 'active', 'expired', 'revoked'], 'default' => 'all'],
                'scope' => ['type' => 'string', 'enum' => ['mine', 'all'], 'default' => 'mine'],
                'search' => ['type' => 'string', 'maxLength' => 180, 'default' => ''],
            ],
        ]);
        // WordPress merges this GET endpoint with the existing DELETE method.
        register_rest_route('aiesk/v1', '/link/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_link'],
            'permission_callback' => 'aiesk_manage_links_permission',
            'args' => ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
        ]);
        register_rest_route('aiesk/v1', '/link/(?P<id>\d+)/renew', [
            'methods' => 'POST',
            'callback' => [$this, 'renew_link'],
            'permission_callback' => 'aiesk_manage_links_permission',
            'args' => ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
        ]);
    }

    /** Include failures, so intermediary caches cannot save account-specific errors. */
    public function private_headers($response, $server, $request) {
        if (preg_match('~^/aiesk/v1/(?:links|link(?:/\d+)(?:/renew)?)/?$~', $request->get_route())) {
            return aiesk_private_rest_response($response);
        }
        return $response;
    }

    public function list_links(\WP_REST_Request $request) {
        $permission = aiesk_manage_links_permission();
        if (is_wp_error($permission)) {
            return $permission;
        }
        $admin = current_user_can('manage_options');
        $scope = (string) ($request['scope'] ?? 'mine');
        $status = (string) ($request['status'] ?? 'all');
        if (!in_array($scope, ['mine', 'all'], true) || !in_array($status, ['all', 'active', 'expired', 'revoked'], true)) {
            return new WP_Error('aiesk_invalid_filter', __('Choose a valid link filter.', 'ai-share-kit'), ['status' => 400]);
        }
        if ($scope === 'all' && !$admin) {
            return new WP_Error('aiesk_forbidden', __('Only administrators can view all shared links.', 'ai-share-kit'), ['status' => 403]);
        }
        $page = max(1, min(1000000, absint($request['page'] ?? 1)));
        $per_page = max(1, min(50, absint($request['per_page'] ?? 20)));
        $search = sanitize_text_field((string) ($request['search'] ?? ''));
        $search = function_exists('mb_substr') ? mb_substr($search, 0, 180) : substr($search, 0, 180);
        $owner = $scope === 'all' ? 0 : get_current_user_id();
        $now = time();
        if (class_exists('AIESK_Index')) {
            $indexed = AIESK_Index::list_rows($owner, $status, $search, $page, $per_page, $now);
            if (is_wp_error($indexed)) { return $indexed; }
            if (is_array($indexed)) {
                $source = AIESK_Index::summaries(array_column($indexed['rows'], 'post_id'));
                if (is_wp_error($source)) { return $source; }
                $items = [];
                foreach ($indexed['rows'] as $row) {
                    $id = (int) $row['post_id'];
                    $current = $source[$id] ?? null;
                    // Never trust an indexed owner, token or status for disclosure.
                    // Fail closed on drift; the asynchronous rebuild repairs it.
                    if (!$current || $current['post_status'] !== 'publish'
                        || (int) $row['owner_id'] !== $current['owner_id']
                        || (int) $row['expires_at'] !== $current['expires_at']
                        || (int) $row['revoked_at'] !== $current['revoked_at']) {
                        AIESK_Index::invalidate();
                        return new WP_Error('aiesk_index_changed', __('Shared reports changed while loading. Please refresh the list.', 'ai-share-kit'), ['status' => 503]);
                    }
                    if ((!$admin && $current['owner_id'] !== get_current_user_id())
                        || ($scope === 'mine' && $current['owner_id'] !== get_current_user_id())) { continue; }
                    $items[] = $this->summary_item($current, $now, $admin);
                }
                return $this->list_response($items, $page, $per_page, $indexed['total'], $admin, $scope, $now);
            }
        }
        $marker = spl_object_hash($this) . ':links';

        // Restrict rows in the database, before pagination and total calculations.
        // A fallback author is used only for old records with no nonzero creator.
        $where_filter = static function ($where, $query) use ($marker, $owner, $status, $search, $now) {
            if ($query->get('aiesk_manager_query') !== $marker) {
                return $where;
            }
            global $wpdb;
            if ($owner) {
                $where .= $wpdb->prepare(
                    " AND (EXISTS (SELECT 1 FROM {$wpdb->postmeta} aiesk_owner WHERE aiesk_owner.post_id = {$wpdb->posts}.ID AND aiesk_owner.meta_key = 'aiesk_creator' AND CAST(aiesk_owner.meta_value AS SIGNED) = %d)"
                    . " OR ({$wpdb->posts}.post_author = %d AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} aiesk_creator WHERE aiesk_creator.post_id = {$wpdb->posts}.ID AND aiesk_creator.meta_key = 'aiesk_creator' AND CAST(aiesk_creator.meta_value AS SIGNED) <> 0)))",
                    $owner, $owner
                );
            }
            $revoked = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} aiesk_revoked WHERE aiesk_revoked.post_id = {$wpdb->posts}.ID AND aiesk_revoked.meta_key = 'aiesk_revoked_at' AND CAST(aiesk_revoked.meta_value AS SIGNED) <> 0)";
            $expired = $wpdb->prepare("EXISTS (SELECT 1 FROM {$wpdb->postmeta} aiesk_expiry WHERE aiesk_expiry.post_id = {$wpdb->posts}.ID AND aiesk_expiry.meta_key = 'aiesk_expires_at' AND CAST(aiesk_expiry.meta_value AS SIGNED) <> 0 AND CAST(aiesk_expiry.meta_value AS SIGNED) <= %d)", $now);
            if ($status === 'revoked') {
                $where .= ' AND ' . $revoked;
            } elseif ($status === 'expired') {
                $where .= ' AND NOT ' . $revoked . ' AND ' . $expired;
            } elseif ($status === 'active') {
                $where .= ' AND NOT ' . $revoked . ' AND NOT ' . $expired;
            }
            if ($search !== '') {
                $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like($search) . '%');
            }
            return $where;
        };
        add_filter('posts_where', $where_filter, 10, 2);
        try {
            $query = new WP_Query([
                'post_type' => 'aiesk_link',
                'post_status' => 'publish',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
                'ignore_sticky_posts' => true,
                'no_found_rows' => false,
                'suppress_filters' => false,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
                'aiesk_manager_query' => $marker,
            ]);
        } finally {
            remove_filter('posts_where', $where_filter, 10);
        }
        $items = [];
        if (class_exists('AIESK_Index')) {
            $summaries = AIESK_Index::summaries(array_map(static fn($post) => (int) $post->ID, $query->posts));
            if (is_wp_error($summaries)) { return $summaries; }
            foreach ($query->posts as $post) {
                $row = $summaries[(int) $post->ID] ?? null;
                if (!$row || $row['post_status'] !== 'publish'
                    || (!$admin && $row['owner_id'] !== get_current_user_id())
                    || ($scope === 'mine' && $row['owner_id'] !== get_current_user_id())) { continue; }
                $items[] = $this->summary_item($row, $now, $admin);
            }
        } else {
            // Compatibility for integrations that load this service by itself.
            foreach ($query->posts as $post) {
                if (!aiesk_can_manage_link($post) || ($scope === 'mine' && aiesk_link_owner($post) !== get_current_user_id())) { continue; }
                $items[] = $this->item($post, $now, $admin);
            }
        }
        return $this->list_response($items, $page, $per_page, (int) $query->found_posts, $admin, $scope, $now);
    }

    private function list_response(array $items, int $page, int $per_page, int $total, bool $admin, string $scope, int $now) {
        // Defensive against query filters that return the same row repeatedly.
        // Different stored records remain visible even when their titles match.
        $unique = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0 && !isset($unique[$id])) { $unique[$id] = $item; }
        }
        return aiesk_private_rest_response([
            'items' => array_values($unique), 'page' => $page, 'per_page' => $per_page,
            'total' => $total, 'total_pages' => (int) ceil($total / $per_page),
            'can_manage_all' => $admin, 'scope' => $scope, 'server_time' => $now,
        ]);
    }

    private function summary_item(array $row, int $now, bool $admin): array {
        $status = $row['revoked_at'] ? 'revoked' : ($row['expires_at'] && $row['expires_at'] <= $now ? 'expired' : 'active');
        $url = '';
        if (preg_match('/^[A-Za-z0-9_-]{20,128}$/', $row['token'])) {
            $prefix = $row['link_format'] === 'short' ? '/s/' : '/shared/';
            $url = esc_url_raw(get_option('permalink_structure')
                ? home_url(user_trailingslashit($prefix . rawurlencode($row['token'])))
                : add_query_arg('aiesk_token', $row['token'], home_url('/')));
        }
        $item = [
            'id' => $row['post_id'], 'title' => $row['title'], 'url' => $url,
            'created_at' => $row['created_at'], 'expires_at' => $row['expires_at'],
            'revoked_at' => $row['revoked_at'], 'status' => $status,
            'can_revoke' => $status === 'active', 'can_delete' => true, 'delete_after' => $row['delete_after'],
        ];
        if ($admin) {
            $user = get_userdata($row['owner_id']);
            $item['owner_name'] = $user ? (string) $user->display_name : __('Deleted account', 'ai-share-kit');
        }
        return $item;
    }

    public function get_link(\WP_REST_Request $request) {
        $permission = aiesk_manage_links_permission();
        if (is_wp_error($permission)) {
            return $permission;
        }
        $id = absint($request['id']);
        $post = get_post($id);
        $allowed = false;
        if ($post && $post->post_type === 'aiesk_link' && $post->post_status === 'publish') {
            if (class_exists('AIESK_Index')) {
                $source = AIESK_Index::summaries([$id]);
                if (is_wp_error($source)) { return $source; }
                $allowed = isset($source[$id]) && ($source[$id]['owner_id'] === get_current_user_id() || current_user_can('manage_options'));
            } else { $allowed = aiesk_can_manage_link($post); }
        }
        if (!$allowed) {
            return new WP_Error('aiesk_link_not_found', __('Share link not found.', 'ai-share-kit'), ['status' => 404]);
        }
        $item = $this->item($post, time(), current_user_can('manage_options'));
        // Owner previews remain available after expiry/revocation; public access does not.
        $item['html'] = wp_kses_post((string) get_post_meta((int) $post->ID, 'aiesk_payload_html', true));
        return aiesk_private_rest_response($item);
    }

    public function renew_link(\WP_REST_Request $request) {
        $permission = aiesk_manage_links_permission();
        if (is_wp_error($permission)) return $permission;
        $id = absint($request['id']); $post = get_post($id);
        if (!$post || $post->post_type !== 'aiesk_link' || $post->post_status !== 'publish' || !aiesk_can_manage_link($post)) {
            return new WP_Error('aiesk_link_not_found', __('Share link not found.', 'ai-share-kit'), ['status' => 404]);
        }
        if ((int)get_post_meta($id, 'aiesk_revoked_at', true)) {
            return new WP_Error('aiesk_link_revoked', __('A revoked link cannot be renewed. Create a new link from the report.', 'ai-share-kit'), ['status' => 409]);
        }
        $hours = max(1, (int)get_post_meta($id, 'aiesk_ttl_hours', true));
        $max = aiesk_parse_ttl(null, null);
        if (is_wp_error($max)) return $max;
        $hours = min($hours, max(1, (int)$max['hours']));
        $expires = time() + ($hours * HOUR_IN_SECONDS);
        update_post_meta($id, 'aiesk_expires_at', $expires);
        if ((int)get_post_meta($id, 'aiesk_expires_at', true) !== $expires) return new WP_Error('aiesk_renew_failed', __('The link could not be renewed. Try again.', 'ai-share-kit'), ['status' => 500]);
        update_post_meta($id, 'aiesk_ttl_hours', $hours);
        return aiesk_private_rest_response(['success' => true, 'id' => $id, 'expires_at' => $expires, 'status' => 'active']);
    }

    private function item($post, int $now, bool $admin): array {
        $post_id = (int) $post->ID;
        $created = (int) get_post_meta($post_id, 'aiesk_created_at', true);
        if (!$created) {
            $created = (int) get_post_time('U', true, $post);
        }
        $status = aiesk_link_status($post_id, $now);
        $item = [
            'id' => $post_id,
            'title' => (string) $post->post_title,
            'url' => aiesk_link_url($post_id),
            'created_at' => $created,
            'expires_at' => (int) get_post_meta($post_id, 'aiesk_expires_at', true),
            'revoked_at' => (int) get_post_meta($post_id, 'aiesk_revoked_at', true),
            'status' => $status,
            'can_revoke' => $status === 'active',
        ];
        if ($admin) {
            $user = get_userdata(aiesk_link_owner($post));
            $item['owner_name'] = $user ? (string) $user->display_name : __('Deleted account', 'ai-share-kit');
        }
        return array_merge($item, class_exists('AIESK_Privacy') ? AIESK_Privacy::management_fields($post) : []);
    }
}
