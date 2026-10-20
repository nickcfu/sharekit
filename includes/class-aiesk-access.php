<?php
/** Keep share records outside WordPress's ordinary post/editor permissions. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Access {
    public function __construct() {
        add_filter('map_meta_cap', [$this, 'map_meta_cap'], 100, 4);
        add_filter('is_protected_meta', [$this, 'protect_meta'], 100, 3);
    }

    /**
     * Generic WordPress interfaces are administrative only. Teacher operations
     * use the plugin's explicit owner-checked services instead. This applies to
     * existing records too; no role edits or metadata migration are necessary.
     */
    public function map_meta_cap(array $caps, string $cap, int $user_id, array $args): array {
        if (in_array($cap, ['add_post_meta', 'edit_post_meta', 'delete_post_meta', 'read_post_meta'], true)
            && isset($args[1]) && $this->is_share_key($args[1])) {
            // Even administrators manage these through the checked plugin
            // services, never generic custom-field/XML-RPC mutation. In core,
            // edit_post_meta also controls XML-RPC custom-field disclosure.
            return ['do_not_allow'];
        }
        if (!in_array($cap, ['read_post', 'edit_post', 'delete_post', 'read_page', 'edit_page', 'delete_page'], true)
            || empty($args[0])) {
            return $caps;
        }
        $post = get_post((int) $args[0]);
        return $post && $post->post_type === 'aiesk_link' ? ['manage_options'] : $caps;
    }

    /** Hide bearer tokens, report bodies and control fields from custom-field APIs. */
    public function protect_meta(bool $protected, string $key, string $type): bool {
        return $protected || ($type === 'post' && $this->is_share_key($key));
    }

    private function is_share_key($key): bool {
        // Database meta-key comparisons can be case-insensitive.
        return is_string($key) && strncasecmp($key, 'aiesk_', 6) === 0;
    }
}
