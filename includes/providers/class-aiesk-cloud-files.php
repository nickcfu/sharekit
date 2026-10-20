<?php
/** Private per-account cloud uploads. No public permissions or share links are created. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Cloud_Files {
    const MAX_BYTES = 10485760;

    /** Shared route registration keeps all providers on the same permission model. */
    public static function register(string $provider): void {
        if (!in_array($provider, ['drive', 'onedrive', 'dropbox'], true)) { return; }
        add_filter('rest_post_dispatch', [self::class, 'private_headers'], 10, 3);
        register_rest_route('aiesk/v1', '/' . $provider . '/oauth/start', [
            'methods' => ['GET', 'POST'],
            'permission_callback' => static function ($request) use ($provider) {
                $allowed = aiesk_rest_permission($provider);
                if (is_wp_error($allowed)) { return $allowed; }
                // Retain legacy GET callers only with an explicit REST nonce.
                if ($request->get_method() === 'GET') {
                    $nonce = $request->get_header('X-WP-Nonce') ?: $request->get_param('_wpnonce');
                    if (!is_string($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
                        return new WP_Error('rest_cookie_invalid_nonce', __('Your session has expired. Reload the page and sign in again.', 'ai-share-kit'), ['status' => 403]);
                    }
                }
                return true;
            },
            'callback' => static function ($request) use ($provider) { return aiesk_private_rest_response(AIESK_Cloud_Auth::start($provider, $request)); },
        ]);
        register_rest_route('aiesk/v1', '/' . $provider . '/status', [
            'methods' => 'GET', 'permission_callback' => 'aiesk_manage_links_permission',
            'callback' => static function () use ($provider) { return aiesk_private_rest_response(AIESK_Cloud_Auth::status($provider)); },
        ]);
        register_rest_route('aiesk/v1', '/' . $provider . '/connection', [
            'methods' => 'DELETE', 'permission_callback' => 'aiesk_manage_links_permission',
            'callback' => static function () use ($provider) { return aiesk_private_rest_response(AIESK_Cloud_Auth::disconnect($provider)); },
        ]);
        register_rest_route('aiesk/v1', '/' . $provider . '/save', [
            'methods' => 'POST',
            'permission_callback' => static function () use ($provider) { return aiesk_rest_permission($provider); },
            'callback' => static function ($request) use ($provider) { return self::save($provider, $request); },
        ]);
    }

    /** REST converts WP_Error after callbacks, so include permission and upload failures. */
    public static function private_headers($response, $server, $request) {
        return preg_match('~^/aiesk/v1/(?:drive|onedrive|dropbox)/(?:oauth/start|status|connection|save)/?$~', $request->get_route())
            ? aiesk_private_rest_response($response) : $response;
    }

    public static function save(string $provider, WP_REST_Request $request) {
        if (!in_array($provider, ['drive', 'onedrive', 'dropbox'], true)) {
            return new WP_Error('aiesk_cloud_provider', __('Choose a supported cloud provider.', 'ai-share-kit'), ['status' => 400]);
        }
        // Defence in depth for integrations calling this method outside REST.
        $allowed = aiesk_rest_permission($provider);
        if (is_wp_error($allowed)) { return $allowed; }
        $body = (string) $request->get_body();
        if (strlen($body) > 8 * 1048576) {
            return new WP_Error('aiesk_request_too_large', __('The sharing request is too large.', 'ai-share-kit'), ['status' => 413]);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || substr(ltrim($body), 0, 1) !== '{') {
            return new WP_Error('aiesk_bad_json', __('Send a valid report request.', 'ai-share-kit'), ['status' => 400]);
        }
        if ((array_key_exists('title', $data) && !is_string($data['title'])) || (array_key_exists('format', $data) && !is_string($data['format']))) {
            return new WP_Error('aiesk_cloud_fields', __('The report title and format must be text.', 'ai-share-kit'), ['status' => 400]);
        }
        $format = $data['format'] ?? 'html';
        if (!in_array($format, ['html', 'pdf', 'docx'], true)) {
            return new WP_Error('aiesk_cloud_format', __('Choose HTML, PDF or Word format.', 'ai-share-kit'), ['status' => 400]);
        }
        $language = AIESK_Report_Context::language($data['report_lang'] ?? '');
        if (is_wp_error($language)) { return $language; }
        $html = aiesk_validate_share_html($data['html'] ?? null);
        if (is_wp_error($html)) { return $html; }
        if (!AIESK_Cloud_Auth::configured($provider)) {
            return new WP_Error('aiesk_cloud_not_configured', __('This cloud connection has not been set up by the site administrator.', 'ai-share-kit'), ['status' => 503]);
        }
        $allowed = AIESK_Limits::rate('cloud');
        if (is_wp_error($allowed)) { return $allowed; }
        // Prepare potentially slow conversions before reading credentials, so a
        // disconnect during conversion prevents a later upload from starting.
        $file = self::file($html, aiesk_normalize_title($data['title'] ?? ''), $format, $language, $data);
        if (is_wp_error($file)) { return $file; }
        $revision = null;
        $token = AIESK_Cloud_Auth::access_token($provider, $revision);
        if (is_wp_error($token)) { return $token; }
        if (!is_string($token) || $token === '' || preg_match('/[\r\n]/', $token)) {
            return self::reconnect();
        }
        try {
            $response = self::{$provider}($token, $file);
            if (is_wp_error($response) && $response->get_error_code() === 'aiesk_cloud_reconnect') {
                $marked = AIESK_Cloud_Auth::reject_access_token($provider, $revision);
                if (is_wp_error($marked)) { return $marked; }
            }
        } catch (Throwable $error) {
            // A provider may already have accepted bytes. Do not claim failure or retry.
            return self::unknown();
        }
        return is_wp_error($response) ? $response : aiesk_private_rest_response($response);
    }

    private static function file(string $html, string $title, string $format, string $language = '', array $context = []) {
        $mime = 'text/html';
        if ($format === 'html') {
            // The standalone file requires no remote font, script or image requests.
            $bytes = '<!doctype html><html' . ($language !== '' ? ' lang="' . esc_attr($language) . '"' : '') . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; base-uri \'none\'; form-action \'none\'">'
                . '<meta name="referrer" content="no-referrer"><title>' . esc_html($title) . '</title>'
                . '<style>body{font:16px/1.65 system-ui,sans-serif;color:#182438;background:#fff;max-width:900px;margin:40px auto;padding:0 24px;overflow-wrap:anywhere}h1,h2,h3{line-height:1.25}header{border-bottom:1px solid #dce3ed;margin-bottom:28px;padding-bottom:16px}header p{color:#64748b;font-size:13px}table{border-collapse:collapse;width:100%;margin:20px 0}th,td{border:1px solid #dce3ed;padding:10px;text-align:start;vertical-align:top}th{background:#f1f5f9}pre{white-space:pre-wrap}img{max-width:100%;height:auto}blockquote{border-inline-start:3px solid #64748b;margin:20px 0;padding-inline-start:20px}@media print{body{margin:0;max-width:none;font-size:11pt}thead{display:table-header-group}tr{break-inside:avoid}}</style></head><body>'
                . '<header><h1>' . esc_html($title) . '</h1><p>' . esc_html__('Saved report', 'ai-share-kit') . ' · ' . esc_html(gmdate('Y-m-d H:i') . ' UTC') . '</p></header><main dir="auto">' . $html . '</main></body></html>';
        } else {
            $hook = 'aiesk_generate_' . $format;
            if (!has_filter($hook)) {
                return new WP_Error('aiesk_cloud_converter', __('This file converter is not available. Choose HTML instead.', 'ai-share-kit'), ['status' => 501]);
            }
            try { $bytes = apply_filters($hook, $html, $title, $context); }
            catch (Throwable $error) { $bytes = false; }
            $signature = $format === 'pdf' ? '%PDF-' : "PK\x03\x04";
            if (!is_string($bytes) || strncmp($bytes, $signature, strlen($signature)) !== 0) {
                return new WP_Error('aiesk_cloud_conversion', __('The report could not be converted. Choose HTML or try again later.', 'ai-share-kit'), ['status' => 500]);
            }
            $mime = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return new WP_Error('aiesk_cloud_size', __('Cloud files must be no larger than 10 MiB. Choose HTML or a smaller report.', 'ai-share-kit'), ['status' => 413]);
        }
        $stem = sanitize_file_name($title);
        // Keep a compact Unicode stem, plus 128 random bits to avoid replacing names.
        $stem = function_exists('mb_substr') ? mb_substr($stem, 0, 70, 'UTF-8') : preg_replace('/[^\x20-\x7e]/', '', substr($stem, 0, 70));
        if (function_exists('mb_strcut')) { $stem = mb_strcut($stem, 0, 140, 'UTF-8'); }
        $stem = trim(preg_replace('/[<>:"\/\\\\|?*#%\x00-\x1f]/u', '-', $stem), " .-");
        if ($stem === '') { $stem = 'AI-report'; }
        try { $suffix = bin2hex(random_bytes(16)); }
        catch (Throwable $error) { return new WP_Error('aiesk_cloud_filename', __('Could not prepare a unique filename. Please try again.', 'ai-share-kit'), ['status' => 503]); }
        return ['bytes' => $bytes, 'mime' => $mime, 'name' => $stem . '-' . gmdate('Ymd-His') . '-' . $suffix . '.' . $format];
    }

    private static function drive(string $token, array $file) {
        $metadata = wp_json_encode(['name' => $file['name'], 'mimeType' => $file['mime']]);
        $base = 'https://www.googleapis.com/upload/drive/v3/files?fields=id%2Cname%2CwebViewLink&uploadType=';
        if (strlen($file['bytes']) <= 5 * 1000 * 1000) {
            $boundary = 'aiesk_' . bin2hex(random_bytes(24));
            $body = '--' . $boundary . "\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . $metadata
                . "\r\n--" . $boundary . "\r\nContent-Type: " . $file['mime'] . "\r\n\r\n" . $file['bytes'] . "\r\n--" . $boundary . "--\r\n";
            $response = self::request('drive', $base . 'multipart', 'POST', $token, $body, ['Content-Type' => 'multipart/related; boundary=' . $boundary]);
        } else {
            $start = self::request('drive', $base . 'resumable', 'POST', $token, $metadata, [
                'Content-Type' => 'application/json; charset=UTF-8', 'X-Upload-Content-Type' => $file['mime'], 'X-Upload-Content-Length' => strlen($file['bytes']),
            ], false);
            if (is_wp_error($start)) { return $start; }
            $location = wp_remote_retrieve_header($start, 'location');
            $parts = is_string($location) ? wp_parse_url($location) : false;
            // Session URLs are bearer credentials: only Google's documented endpoint.
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'www.googleapis.com'
                || ($parts['path'] ?? '') !== '/upload/drive/v3/files' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
                return new WP_Error('aiesk_cloud_session', __('The cloud upload could not be started securely. Please try again later.', 'ai-share-kit'), ['status' => 502]);
            }
            $response = self::request('drive', $location, 'PUT', $token, $file['bytes'], ['Content-Type' => $file['mime']]);
        }
        return self::result('drive', $response, $file);
    }

    private static function onedrive(string $token, array $file) {
        $folder = self::request('onedrive', 'https://graph.microsoft.com/v1.0/me/drive/special/approot?$select=id', 'GET', $token, null, [], false);
        if (is_wp_error($folder)) { return $folder; }
        $json = json_decode(wp_remote_retrieve_body($folder), true);
        if (!is_array($json) || !is_string($json['id'] ?? null) || !preg_match('/^[A-Za-z0-9!_-]{1,512}$/', $json['id'])) {
            return new WP_Error('aiesk_cloud_folder', __('The OneDrive app folder could not be opened. Please reconnect OneDrive.', 'ai-share-kit'), ['status' => 502]);
        }
        $url = 'https://graph.microsoft.com/v1.0/me/drive/items/' . rawurlencode($json['id']) . ':/' . rawurlencode($file['name']) . ':/content?%40microsoft.graph.conflictBehavior=fail';
        return self::result('onedrive', self::request('onedrive', $url, 'PUT', $token, $file['bytes'], ['Content-Type' => $file['mime']]), $file);
    }

    private static function dropbox(string $token, array $file) {
        // HTTP header values must be ASCII, including filenames with Greek text.
        $arg = wp_json_encode(['path' => '/' . $file['name'], 'mode' => 'add', 'autorename' => true, 'strict_conflict' => true]);
        $response = self::request('dropbox', 'https://content.dropboxapi.com/2/files/upload', 'POST', $token, $file['bytes'], [
            'Content-Type' => 'application/octet-stream', 'Dropbox-API-Arg' => $arg,
        ]);
        return self::result('dropbox', $response, $file);
    }

    /** No redirects and no automatic retries, including on transport errors. */
    private static function request(string $provider, string $url, string $method, string $token, $body, array $headers, bool $upload = true) {
        $headers['Authorization'] = 'Bearer ' . $token;
        $headers['Accept'] = 'application/json';
        $args = ['method' => $method, 'timeout' => 35, 'redirection' => 0, 'sslverify' => true,
            'reject_unsafe_urls' => true, 'limit_response_size' => 65536, 'headers' => $headers];
        if ($body !== null) { $args['body'] = $body; $args['headers']['Content-Length'] = strlen($body); }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) { return $upload ? self::unknown() : self::unavailable(); }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) { return $response; }
        if ($status === 401) { return self::reconnect(); }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        $reason = is_array($json) && is_array($json['error'] ?? null) && is_array($json['error']['errors'] ?? null)
            ? ($json['error']['errors'][0]['reason'] ?? '') : '';
        if ($status === 429 || ($provider === 'drive' && $status === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true))) {
            $retry = wp_remote_retrieve_header($response, 'retry-after');
            return new WP_Error('aiesk_cloud_rate_limited', __('The cloud provider is busy. Wait a few minutes before trying again.', 'ai-share-kit'), ['status' => 429, 'retry_after' => is_numeric($retry) ? max(1, min(3600, (int) $retry)) : 60]);
        }
        if ($status === 403) {
            return new WP_Error('aiesk_cloud_permission', __('The cloud provider refused this upload. Check storage space and your account permissions, or reconnect the account.', 'ai-share-kit'), ['status' => 403]);
        }
        if ($status === 409 || $status === 507) {
            return new WP_Error('aiesk_cloud_conflict', __('The cloud provider could not save this file. Check available storage and the destination folder before trying again.', 'ai-share-kit'), ['status' => 409]);
        }
        if ($status >= 500 || ($status >= 200 && $status < 400)) { return $upload ? self::unknown() : self::unavailable(); }
        return new WP_Error('aiesk_cloud_rejected', __('The cloud provider rejected the upload. Check your connection and account settings before trying again.', 'ai-share-kit'), ['status' => 502]);
    }

    private static function result(string $provider, $response, array $file) {
        if (is_wp_error($response)) { return $response; }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !is_string($data['id'] ?? null) || $data['id'] === '' || strlen($data['id']) > 1024
            || !is_string($data['name'] ?? null) || $data['name'] === '') { return self::unknown(); }
        $result = ['success' => true, 'provider' => $provider, 'file_name' => sanitize_text_field($data['name']), 'file_id' => sanitize_text_field($data['id'])];
        $url = self::safe_url($provider, $data['webViewLink'] ?? $data['webUrl'] ?? null);
        if ($url !== '') { $result['url'] = $url; }
        return $result;
    }

    private static function safe_url(string $provider, $url): string {
        if (!is_string($url) || strlen($url) > 4096) { return ''; }
        $p = wp_parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port'])) { return ''; }
        $host = strtolower($p['host'] ?? '');
        $allowed = $provider === 'drive' ? in_array($host, ['drive.google.com', 'docs.google.com'], true)
            : ($provider === 'onedrive' && (in_array($host, ['onedrive.live.com', '1drv.ms'], true) || preg_match('/^[a-z0-9-]+\.sharepoint\.com$/D', $host)));
        return $allowed ? esc_url_raw($url) : '';
    }

    private static function reconnect(): WP_Error {
        return new WP_Error('aiesk_cloud_reconnect', __('Your cloud connection has expired. Reconnect your account and try again.', 'ai-share-kit'), ['status' => 409, 'connection_required' => true]);
    }
    private static function unknown(): WP_Error {
        return new WP_Error('aiesk_cloud_outcome_unknown', __('The upload result could not be confirmed. Check your cloud folder before trying again to avoid a duplicate file.', 'ai-share-kit'), ['status' => 502, 'outcome_unknown' => true]);
    }
    private static function unavailable(): WP_Error {
        return new WP_Error('aiesk_cloud_unavailable', __('The cloud provider could not be reached. Please try again later.', 'ai-share-kit'), ['status' => 502]);
    }
}
