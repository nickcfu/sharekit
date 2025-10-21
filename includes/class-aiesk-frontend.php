<?php
/**
 * Frontend class for AI Share Kit — auto-inject under last Meow AI reply
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('AIESK_Frontend')):

final class AIESK_Frontend
{
    public const REST_NAMESPACE = 'aiesk/v1';

    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('rest_api_init',     [$this, 'register_rest_routes']);

        // Auto-mount container in the footer (once) for logged-in users.
        add_action('wp_footer',         [$this, 'print_mountpoint'], 5);
    }

    public function enqueue_scripts(): void
    {
        $ver = defined('AIESK_VERSION') ? AIESK_VERSION : '1.0.0';

        // Styles + core JS (handles injection & actions)
        wp_enqueue_style('aiesk-css', plugins_url('../public/css/aiesk.css', __FILE__), [], $ver);
        wp_enqueue_script('aiesk-js',  plugins_url('../public/js/aiesk.js',  __FILE__), ['jquery'], $ver, true);

        $login_url = wp_login_url($this->current_url());
        wp_localize_script('aiesk-js', 'aieskVars', [
            'restUrl'   => esc_url_raw(rest_url(self::REST_NAMESPACE . '/')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'loginUrl'  => $login_url,
            // UI prefs (will add admin-controlled choices later)
            'triggerText' => 'share',
            'injectSelector' => '.mwai-reply.mwai-ai.mwai-rendered',
        ]);
    }

    /**
     * Print the mount point for the share bar. JS will move it under the LAST Meow AI reply.
     * Only for logged-in users (guests should not see share controls).
     */
    public function print_mountpoint(): void
    {
        if (!is_user_logged_in()) return;
        echo '<div id="aiesk-portal-root" class="aiesk-scope" data-inject=".mwai-reply.mwai-ai.mwai-rendered" style="display:none"></div>';
    }

    public function register_rest_routes(): void
    {
        // Render bar HTML on demand (for auto-injection)
        register_rest_route(self::REST_NAMESPACE, '/render-bar', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_render_bar'],
            'permission_callback' => function(){ return is_user_logged_in(); },
        ]);

        // Exports
        register_rest_route(self::REST_NAMESPACE, '/export-pdf', [
            'methods' => 'POST', 'callback' => [$this, 'rest_export_pdf'], 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::REST_NAMESPACE, '/export-docx', [
            'methods' => 'POST', 'callback' => [$this, 'rest_export_docx'], 'permission_callback' => '__return_true',
        ]);

        // Providers (require login if setting demands it)
        register_rest_route(self::REST_NAMESPACE, '/save-drive', [
            'methods' => 'POST', 'callback' => [$this, 'rest_save_drive'], 'permission_callback' => fn() => $this->can_use_sharing(),
        ]);
        register_rest_route(self::REST_NAMESPACE, '/save-dropbox', [
            'methods' => 'POST', 'callback' => [$this, 'rest_save_dropbox'], 'permission_callback' => fn() => $this->can_use_sharing(),
        ]);
        register_rest_route(self::REST_NAMESPACE, '/save-onedrive', [
            'methods' => 'POST', 'callback' => [$this, 'rest_save_onedrive'], 'permission_callback' => fn() => $this->can_use_sharing(),
        ]);
    }

    /**
     * Returns the HTML of the share bar (dropdown trigger labeled "share").
     * Buttons are gated by your enable/disable options + login requirement.
     */
    public function rest_render_bar(\WP_REST_Request $req)
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('aiesk_guest', __('Not allowed', 'ai-share-kit'), ['status' => 401]);
        }

        $enabled_pdf      = (bool) get_option('aiesk_enable_pdf', true);
        $enabled_docx     = (bool) get_option('aiesk_enable_docx', true);
        $enabled_drive    = (bool) get_option('aiesk_enable_drive', false);
        $enabled_dropbox  = (bool) get_option('aiesk_enable_dropbox', false);
        $enabled_onedrive = (bool) get_option('aiesk_enable_onedrive', false);

        $require_login = (bool) get_option('aiesk_require_login_sharing', true);
        $can_share     = $require_login ? is_user_logged_in() : true;

        ob_start();
        ?>
        <div class="aiesk-share-bar aiesk-dropdown" data-aiesk>
            <button type="button" class="aiesk-trigger"><?php echo esc_html__('share','ai-share-kit'); ?></button>
            <div class="aiesk-menu">
                <?php if ($enabled_pdf)  echo $this->render_pdf_button(); ?>
                <?php if ($enabled_docx) echo $this->render_docx_button(); ?>
                <?php if ($enabled_drive   && $can_share) echo $this->render_drive_button(); ?>
                <?php if ($enabled_dropbox && $can_share) echo $this->render_dropbox_button(); ?>
                <?php if ($enabled_onedrive&& $can_share) echo $this->render_onedrive_button(); ?>
            </div>
        </div>
        <?php
        return rest_ensure_response(['html' => (string) ob_get_clean()]);
    }

    private function render_pdf_button(): string { return '<button type="button" class="aiesk-btn aiesk-btn-pdf" data-action="export-pdf">' . esc_html__('PDF', 'ai-share-kit') . '</button>'; }
    private function render_docx_button(): string { return '<button type="button" class="aiesk-btn aiesk-btn-docx" data-action="export-docx">' . esc_html__('DOCX', 'ai-share-kit') . '</button>'; }
    private function render_drive_button(): string { return '<button type="button" class="aiesk-btn aiesk-btn-drive" data-action="save-drive">' . esc_html__('Google Drive', 'ai-share-kit') . '</button>'; }
    private function render_dropbox_button(): string { return '<button type="button" class="aiesk-btn aiesk-btn-dropbox" data-action="save-dropbox">' . esc_html__('Dropbox', 'ai-share-kit') . '</button>'; }
    private function render_onedrive_button(): string { return '<button type="button" class="aiesk-btn aiesk-btn-onedrive" data-action="save-onedrive">' . esc_html__('OneDrive', 'ai-share-kit') . '</button>'; }

    private function can_use_sharing() {
        $require_login = (bool) get_option('aiesk_require_login_sharing', true);
        if ($require_login && !is_user_logged_in()) {
            return new \WP_Error('aiesk_login_required', __('Login required', 'ai-share-kit'), ['status' => 401]);
        }
        return true;
    }

    public function rest_export_pdf(\WP_REST_Request $req) { return $this->respond_file_download(apply_filters('aiesk_generate_pdf', wp_kses_post((string) $req->get_param('html'))), 'export-' . time() . '.pdf', 'application/pdf'); }
    public function rest_export_docx(\WP_REST_Request $req) { return $this->respond_file_download(apply_filters('aiesk_generate_docx', wp_kses_post((string) $req->get_param('html'))), 'export-' . time() . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'); }

    public function rest_save_drive(\WP_REST_Request $req) { $this->assert_nonce_header($req); $payload = $this->sanitize_payload($req); $r = apply_filters('aiesk_save_to_drive', $payload); return $this->normalize_provider_result($r); }
    public function rest_save_dropbox(\WP_REST_Request $req) { $this->assert_nonce_header($req); $payload = $this->sanitize_payload($req); $r = apply_filters('aiesk_save_to_dropbox', $payload); return $this->normalize_provider_result($r); }
    public function rest_save_onedrive(\WP_REST_Request $req) { $this->assert_nonce_header($req); $payload = $this->sanitize_payload($req); $r = apply_filters('aiesk_save_to_onedrive', $payload); return $this->normalize_provider_result($r); }

    private function sanitize_payload(\WP_REST_Request $req): array { return ['title' => sanitize_text_field((string) $req->get_param('title')), 'html' => wp_kses_post((string) $req->get_param('html')), 'path' => sanitize_text_field((string) $req->get_param('path')), 'format' => sanitize_key((string) $req->get_param('format'))]; }

    private function respond_file_download($bytes, string $filename, string $mime) {
        if (empty($bytes)) return new \WP_Error('aiesk_export_error', __('Failed to generate file', 'ai-share-kit'), ['status' => 500]);
        $upload_dir = wp_upload_dir();
        $file_path  = trailingslashit($upload_dir['basedir']) . $filename;
        file_put_contents($file_path, $bytes);
        $url = trailingslashit($upload_dir['baseurl']) . $filename;
        return rest_ensure_response(['url' => esc_url_raw($url), 'filename' => $filename, 'mime' => $mime]);
    }

    private function assert_nonce_header(\WP_REST_Request $req) {
        $nonce = $req->get_header('X-WP-Nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_Error('aiesk_bad_nonce', __('Invalid nonce', 'ai-share-kit'), ['status' => 403]);
        }
        return true;
    }

    private function normalize_provider_result($result) {
        if (is_wp_error($result)) {
            $status = (int) ($result->get_error_data()['status'] ?? 400);
            return new \WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => $status]);
        }
        return rest_ensure_response(['success' => true, 'data' => $result]);
    }

    private function current_url(): string { $scheme = is_ssl() ? 'https' : 'http'; $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'; $uri = $_SERVER['REQUEST_URI'] ?? '/'; return esc_url_raw($scheme . '://' . $host . $uri); }
}

endif;
