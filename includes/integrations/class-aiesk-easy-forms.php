<?php
/**
 * Reuse Easy Forms' Word exporter for the selected response only.
 *
 * Delegate to its public route so its form-signature and permission checks remain
 * authoritative. Do not instantiate another router or call its private helpers.
 */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Easy_Forms {
    private const HOOK = 'aiesk_generate_docx';

    public function __construct() {
        // Easy Forms creates its router at plugins_loaded:20. Feature discovery
        // runs later, when frontend assets and their template are rendered.
        add_action('plugins_loaded', [self::class, 'register'], 99);
        // Easy Forms' original whole-conversation Word button serializes its
        // message list, including our sibling controls. Keep those controls out
        // of that export without changing the report or Easy Forms source.
        // This hook runs after the route's permission callback, unlike
        // rest_request_before_callbacks (which runs before that permission).
        add_filter('rest_dispatch_request', [self::class, 'clean_native_word_request'], 90, 4);
    }

    public static function clean_native_word_request($response, $request, $route, $handler) {
        if ($response !== null || $request->get_route() !== '/mwaief/v1/word'
            || $request->get_method() !== 'POST' || !class_exists('DOMDocument')) { return $response; }
        $callback = $handler['callback'] ?? null;
        if (!is_array($callback) || !($callback[0] instanceof MWAIEF_Router) || ($callback[1] ?? '') !== 'rest_word') { return $response; }
        $params = $request->get_json_params();
        $html = is_array($params) ? ($params['html'] ?? null) : null;
        // Match the native route's input ceiling before allocating a DOM. No
        // toolbar markers means no parsing or changes to the original HTML.
        if (!is_string($html) || strlen($html) > 1500000 || strpos($html, 'aiesk-share-bar') === false
            || strpos($html, 'data-aiesk') === false) { return $response; }

        $previous_libxml = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            $id = 'aiesk-word-root-' . bin2hex(random_bytes(12));
            $dom->loadHTML('<?xml encoding="utf-8" ?><div id="' . $id . '">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            $root = $dom->getElementById($id);
            if (!$root) { return $response; }
            $xpath = new DOMXPath($dom);
            $controls = $xpath->query('.//div[@data-aiesk and contains(concat(" ", normalize-space(@class), " "), " aiesk-share-bar ") and contains(concat(" ", normalize-space(@class), " "), " aiesk-scope ")]', $root);
            $removed = false;
            foreach (iterator_to_array($controls) as $control) {
                // Generated bars are siblings of message articles. A matching
                // fragment inside user/report content must remain report data.
                $inside_message = false;
                for ($parent = $control->parentNode; $parent && $parent !== $root; $parent = $parent->parentNode) {
                    if ($parent instanceof DOMElement
                        && preg_match('/(?:^|\s)(?:mwaief-message(?:-assistant|-user)?|mwaief-assistant-content)(?:\s|$)/', $parent->getAttribute('class'))) {
                        $inside_message = true;
                        break;
                    }
                }
                if (!$inside_message) { $control->parentNode->removeChild($control); $removed = true; }
            }
            if ($removed) {
                $clean = '';
                foreach ($root->childNodes as $node) { $clean .= $dom->saveHTML($node); }
                $request->set_param('html', $clean);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous_libxml);
        }
        return $response;
    }

    public static function register(): void {
        if (class_exists('MWAIEF_Router') && class_exists('MWAIEF_Storage')
            && !has_filter(self::HOOK, [self::class, 'generate'])) {
            add_filter(self::HOOK, [self::class, 'generate'], PHP_INT_MAX, 3);
        }
    }

    /** True only when Word is supplied by this form-dependent fallback. */
    public static function requires_form_context(): bool {
        return false !== has_filter(self::HOOK, [self::class, 'generate']) && !self::has_custom_converter();
    }

    /** A site's explicit converter takes precedence, including its failures. */
    private static function has_custom_converter(): bool {
        global $wp_filter;
        if (empty($wp_filter[self::HOOK])) { return false; }
        foreach ($wp_filter[self::HOOK]->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                if (($callback['function'] ?? null) !== [self::class, 'generate']) { return true; }
            }
        }
        return false;
    }

    /** Filter receives validated HTML, a title and the originating request data. */
    public static function generate($value, $title = '', $context = []) {
        if (self::has_custom_converter() || is_wp_error($value)) { return $value; }
        if (!aiesk_user_can_share()) {
            return new WP_Error('aiesk_docx_access', __('You do not have permission to share reports.', 'ai-share-kit'), ['status' => 403]);
        }
        $form = is_array($context) && is_array($context['easy_forms'] ?? null) ? $context['easy_forms'] : [];
        $id = $form['form_id'] ?? null;
        $signature = $form['signature'] ?? null;
        if ((!is_int($id) && (!is_string($id) || !ctype_digit($id))) || (int) $id < 1
            || !is_string($signature) || $signature === '' || strlen($signature) > 256) {
            return new WP_Error('aiesk_docx_context', __('This Word export needs its assessment form. Reload the page and try again.', 'ai-share-kit'), ['status' => 400]);
        }
        $html = aiesk_validate_share_html($value);
        if (is_wp_error($html)) { return $html; }

        // The outer Share Kit endpoint has already checked its authenticated
        // user's permission and nonce. The internal request stays in that same
        // user/session; Easy Forms still applies its own permission callback.
        $request = new WP_REST_Request('POST', '/mwaief/v1/word');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $request->set_body(wp_json_encode([
            'formId' => (int) $id,
            'signature' => $signature,
            'html' => $html,
            'title' => aiesk_normalize_title($title),
            'meta' => '',
        ]));
        $previous_libxml = function_exists('libxml_use_internal_errors') ? libxml_use_internal_errors() : null;
        try {
            $response = rest_do_request($request);
        } finally {
            if ($previous_libxml !== null) { libxml_use_internal_errors($previous_libxml); }
        }
        if (is_wp_error($response)) { return $response; }
        $data = $response->get_data();
        if ($response->get_status() >= 400 || !is_array($data) || empty($data['success'])) {
            $message = is_array($data) && is_string($data['message'] ?? null)
                ? sanitize_text_field($data['message']) : __('The file could not be generated. Please try again.', 'ai-share-kit');
            return new WP_Error('aiesk_docx_easy_forms', $message, ['status' => max(400, $response->get_status())]);
        }
        $bytes = is_string($data['data'] ?? null) ? base64_decode($data['data'], true) : false;
        if (!is_string($bytes) || strncmp($bytes, "PK\x03\x04", 4) !== 0) {
            return new WP_Error('aiesk_docx_error', __('DOCX generator did not return a DOCX file', 'ai-share-kit'), ['status' => 500]);
        }
        return $bytes;
    }
}
