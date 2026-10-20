<?php
/** Per-account cloud authorisation. Credentials never leave the WordPress server. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Cloud_Auth {
    private const PROVIDERS = [
        'drive' => [
            'label' => 'Google Drive',
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'scope' => 'https://www.googleapis.com/auth/drive.file',
        ],
        'onedrive' => [
            'label' => 'OneDrive',
            'authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scope' => 'offline_access https://graph.microsoft.com/Files.ReadWrite.AppFolder',
        ],
        'dropbox' => [
            'label' => 'Dropbox',
            'authorize' => 'https://www.dropbox.com/oauth2/authorize',
            'token' => 'https://api.dropboxapi.com/oauth2/token',
            'scope' => 'files.content.write',
        ],
    ];

    public function __construct() {
        // Query-free registration supports personal Microsoft accounts and plain permalinks.
        // Unrelated admin-post requests are ignored unless their state uses our prefix.
        add_action('admin_post', [$this, 'callback'], 0);
        add_action('admin_post_nopriv', [$this, 'callback'], 0);
        add_action('aiesk_cleanup_private_downloads', [$this, 'cleanup']);
        add_action('deleted_user', [__CLASS__, 'forget_user']);
        add_action('wpmu_delete_user', [__CLASS__, 'forget_user']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_eraser']);
    }

    public static function providers(): array { return array_keys(self::PROVIDERS); }

    public static function redirect_uri(string $provider): string {
        if (!isset(self::PROVIDERS[$provider])) { return ''; }
        return admin_url('admin-post.php');
    }

    public static function callback_origin(): string { return self::secure_origin(admin_url('admin-post.php')); }

    /** Server-only configuration. Never localise or return this array in a REST response. */
    public static function config(string $provider) {
        if (!isset(self::PROVIDERS[$provider])) { return self::error('provider', 400); }
        $client = get_option('aiesk_cloud_' . $provider . '_client_id', '');
        $cipher = get_option('aiesk_cloud_' . $provider . '_client_secret', '');
        if (!is_string($client) || !preg_match('/^[A-Za-z0-9_.:\-]{1,512}$/D', $client) || !is_string($cipher) || $cipher === '') {
            return self::error('configuration', 503);
        }
        $secret = self::decrypt($cipher, 'app:' . $provider);
        if (is_wp_error($secret) || $secret === '' || !self::secure_origin(self::redirect_uri($provider))) { return self::error('configuration', 503); }
        return self::PROVIDERS[$provider] + ['client_id' => $client, 'client_secret' => $secret, 'redirect_uri' => self::redirect_uri($provider)];
    }

    public static function configured(string $provider): bool { return !is_wp_error(self::config($provider)); }

    public static function save_app_secret(string $provider, string $secret) {
        if (!current_user_can('manage_options')) { return self::error('permission', 403); }
        if (!isset(self::PROVIDERS[$provider])) { return self::error('provider', 400); }
        if (strlen($secret) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $secret)) { return self::error('configuration', 400); }
        $key = 'aiesk_cloud_' . $provider . '_client_secret';
        if ($secret === '') { return self::remove($key) ? true : self::error('storage', 503); }
        $cipher = self::encrypt($secret, 'app:' . $provider);
        return is_wp_error($cipher) ? $cipher : self::write($key, $cipher);
    }

    /** AES-GCM authenticates both ciphertext and its intended provider/user purpose. */
    public static function encrypt(string $plain, string $context) {
        if (!self::crypto_available()) { return self::error('encryption', 503); }
        try {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'aiesk:v1:' . $context, 16);
            if ($cipher === false || strlen($tag) !== 16) { return self::error('encryption', 503); }
            return 'v1.' . base64_encode($iv . $tag . $cipher);
        } catch (Throwable $error) { return self::error('encryption', 503); }
    }

    public static function decrypt(string $cipher, string $context) {
        if (!self::crypto_available() || substr($cipher, 0, 3) !== 'v1.' || strlen($cipher) > 131072) { return self::error('encryption', 503); }
        $bytes = base64_decode(substr($cipher, 3), true);
        if (!is_string($bytes) || strlen($bytes) < 28) { return self::error('encryption', 503); }
        try {
            $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'aiesk:v1:' . $context);
            return is_string($plain) ? $plain : self::error('encryption', 503);
        } catch (Throwable $error) { return self::error('encryption', 503); }
    }

    private static function crypto_available(): bool {
        return function_exists('openssl_encrypt') && function_exists('openssl_decrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }
    private static function key(): string { return hash('sha256', wp_salt('auth') . "\0" . wp_salt('secure_auth') . '\0AI Share Kit cloud v1', true); }
    private static function random(int $bytes = 32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
    private static function name(string $kind, string $provider, int $user): string { return 'aiesk_cloud_' . $kind . '_' . $user . '_' . $provider; }
    private static function fingerprint(array $config): string { return hash('sha256', $config['client_id'] . "\0" . $config['redirect_uri']); }
    private static function session_hash(): string { return hash_hmac('sha256', wp_get_session_token(), self::key()); }

    public static function start(string $provider, WP_REST_Request $request) {
        $user = get_current_user_id();
        if (!$user || !get_userdata($user) || wp_get_session_token() === '') { return self::error('session', 401); }
        $config = self::config($provider);
        if (is_wp_error($config)) { return $config; }
        if (!aiesk_feature_enabled($provider)) { return self::error('disabled', 403); }
        $lease = self::lock($provider, $user);
        if (is_wp_error($lease)) { return $lease; }
        try {
            $name = self::name('pending', $provider, $user);
            $old = self::record($name);
            if (is_wp_error($old)) { return $old; }
            if (is_array($old) && (int) ($old['created'] ?? 0) > time() - 5) { return self::error('busy', 429); }
            $state = 'aiesk1_' . self::random();
            $verifier = self::random(48);
            $origin = self::secure_origin($request->get_header('origin')) ?: self::secure_origin($request->get_header('referer'));
            $allowed = array_filter([self::secure_origin(home_url('/')), self::secure_origin(admin_url('/'))]);
            if (!$origin || !in_array($origin, $allowed, true)) { $origin = self::secure_origin(home_url('/')); }
            if (!$origin) { return self::error('configuration', 503); }
            $pending = ['hash' => hash('sha256', $state), 'verifier' => $verifier, 'user' => $user, 'provider' => $provider, 'session' => self::session_hash(), 'created' => time(), 'expires' => time() + 600, 'origin' => $origin, 'app' => self::fingerprint($config)];
            $saved = self::write_record($name, $pending, $lease);
            if (is_wp_error($saved)) { return $saved; }
            $args = [
                'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri'], 'response_type' => 'code',
                'scope' => $config['scope'], 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            ];
            if ($provider === 'drive') { $args += ['access_type' => 'offline', 'prompt' => 'consent select_account']; }
            if ($provider === 'onedrive') { $args += ['response_mode' => 'query', 'prompt' => 'select_account']; }
            if ($provider === 'dropbox') { $args += ['token_access_type' => 'offline', 'force_reapprove' => 'true']; }
            return ['auth_url' => $config['authorize'] . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986)];
        } catch (Throwable $error) { return self::error('storage', 503); }
        finally { self::unlock($lease); }
    }

    /** Used by admin-post after WordPress has authenticated its ordinary login cookie. */
    public static function finish(string $provider, string $state, string $code, string $provider_error = '') {
        $user = get_current_user_id();
        if (!$user || !get_userdata($user) || wp_get_session_token() === '') { return self::error('session', 401); }
        if (!isset(self::PROVIDERS[$provider]) || !preg_match('/^aiesk1_[A-Za-z0-9_-]{43}$/D', $state)) { return self::error('state', 400); }
        $lease = self::lock($provider, $user);
        if (is_wp_error($lease)) { return $lease; }
        try {
            $name = self::name('pending', $provider, $user);
            $cipher = self::read($name);
            if (is_wp_error($cipher)) { return $cipher; }
            $pending = self::decode_record($cipher, $name);
            if (!is_array($pending) || (int) ($pending['user'] ?? 0) !== $user || ($pending['provider'] ?? '') !== $provider || (int) ($pending['expires'] ?? 0) <= time()
                || !hash_equals((string) ($pending['hash'] ?? ''), hash('sha256', $state)) || !hash_equals((string) ($pending['session'] ?? ''), self::session_hash())) { return self::error('state', 400); }
            // Compare-and-delete is the one-use claim, independent of WP's object cache.
            if (!self::remove($name, $cipher)) { return self::error('state', 400); }
            if (!aiesk_feature_enabled($provider)) { return self::error('disabled', 403); }
            if ($provider_error !== '') { return self::error('cancelled', 400); }
            if ($code === '' || strlen($code) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $code)) { return self::error('state', 400); }
            $config = self::config($provider);
            if (is_wp_error($config) || !hash_equals((string) $pending['app'], self::fingerprint($config))) { return self::error('configuration', 503); }
            $tokens = self::exchange($provider, $config, ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $config['redirect_uri'], 'code_verifier' => $pending['verifier']]);
            if (is_wp_error($tokens)) { return $tokens; }
            // A new authorisation must supply its own refresh token: never mix two cloud accounts.
            if (empty($tokens['refresh_token'])) { return self::error('reconnect', 409); }
            $tokens['app'] = self::fingerprint($config);
            $saved = self::write_record(self::name('tokens', $provider, $user), $tokens, $lease);
            if (is_wp_error($saved)) { return $saved; }
            return ['connected' => true, 'origin' => $pending['origin']];
        } catch (Throwable $error) { return self::error('storage', 503); }
        finally { self::unlock($lease); }
    }

    public static function status(string $provider): array {
        $config = self::config($provider);
        $name = self::name('tokens', $provider, get_current_user_id());
        $raw = get_current_user_id() && isset(self::PROVIDERS[$provider]) ? self::read($name) : null;
        $record = is_string($raw) && !is_wp_error($config) ? self::decode_record($raw, $name) : null;
        return [
            'configured' => !is_wp_error($config), 'enabled' => isset(self::PROVIDERS[$provider]) && aiesk_feature_enabled($provider),
            // A removed app secret or rotated WordPress salt must not hide Disconnect.
            'stored_connection' => is_string($raw) && $raw !== '',
            'connected' => !is_wp_error($config) && is_array($record) && self::usable($record, $config),
            'label' => self::PROVIDERS[$provider]['label'] ?? '',
        ];
    }

    public static function disconnect(string $provider) {
        $user = get_current_user_id();
        if (!$user) { return self::error('session', 401); }
        if (!isset(self::PROVIDERS[$provider])) { return self::error('provider', 400); }
        $lease = self::lock($provider, $user);
        if (is_wp_error($lease)) { return $lease; }
        try {
            if (!self::remove(self::name('tokens', $provider, $user)) || !self::remove(self::name('pending', $provider, $user))) { return self::error('storage', 503); }
            return ['disconnected' => true];
        } finally { self::unlock($lease); }
    }

    /** The optional revision identifies this exact encrypted credential, never just its token text. */
    public static function access_token(string $provider, ?string &$revision = null) {
        $revision = null;
        $user = get_current_user_id();
        if (!$user || !get_userdata($user)) { return self::error('session', 401); }
        $config = self::config($provider);
        if (is_wp_error($config)) { return $config; }
        $name = self::name('tokens', $provider, $user);
        $record = self::record($name, $revision);
        if (is_wp_error($record)) { return $record; }
        if (!is_array($record) || !self::usable($record, $config)) { return self::error('reconnect', 409); }
        if ((int) $record['expires'] > time() + 60) { return $record['access_token']; }
        $lease = self::lock($provider, $user);
        if (is_wp_error($lease)) { return $lease; }
        try {
            // Read again under the same lock used for disconnect and callback completion.
            $record = self::record($name, $revision);
            if (is_wp_error($record)) { return $record; }
            if (!is_array($record) || !self::usable($record, $config)) { return self::error('reconnect', 409); }
            if ((int) $record['expires'] > time() + 60) { return $record['access_token']; }
            $tokens = self::exchange($provider, $config, ['grant_type' => 'refresh_token', 'refresh_token' => $record['refresh_token']]);
            if (is_wp_error($tokens)) {
                if ($tokens->get_error_code() === 'aiesk_cloud_reconnect' && self::valid_lock($lease)) { self::remove($name); }
                return $tokens;
            }
            if (empty($tokens['refresh_token'])) { $tokens['refresh_token'] = $record['refresh_token']; }
            $tokens['app'] = self::fingerprint($config);
            $saved = self::write_record($name, $tokens, $lease, $revision);
            return is_wp_error($saved) ? $saved : $tokens['access_token'];
        } catch (Throwable $error) { return self::error('storage', 503); }
        finally { self::unlock($lease); }
    }

    /**
     * A provider rejected the credential used by one request. Keep it removable,
     * but stop reporting readiness. The conditional UPDATE cannot overwrite a
     * concurrent reconnect, refresh, account switch, disconnect or erasure.
     */
    public static function reject_access_token(string $provider, string $revision) {
        global $wpdb;
        $user = get_current_user_id();
        if (!$user || !get_userdata($user)) { return self::error('session', 401); }
        if (!isset(self::PROVIDERS[$provider]) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) { return self::error('provider', 400); }
        $name = self::name('tokens', $provider, $user);
        $raw = self::read($name);
        if (is_wp_error($raw)) { return $raw; }
        if (!is_string($raw) || !hash_equals($revision, hash('sha256', $raw))) { return false; }
        $record = self::decode_record($raw, $name);
        if (is_wp_error($record)) { return $record; }
        if (!is_array($record)) { return false; }
        $record['reconnect_required'] = true;
        $cipher = self::encrypt(wp_json_encode($record), $name);
        if (is_wp_error($cipher)) { return $cipher; }
        $updated = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $cipher, $name, $raw));
        self::clear_cache($name);
        return $updated === false ? self::error('storage', 503) : $updated === 1;
    }

    private static function usable(array $record, array $config): bool {
        return empty($record['reconnect_required']) && isset($record['access_token'], $record['refresh_token'], $record['expires'], $record['app']) && is_string($record['access_token']) && $record['access_token'] !== '' && is_string($record['refresh_token']) && $record['refresh_token'] !== '' && hash_equals(self::fingerprint($config), (string) $record['app']);
    }

    private static function exchange(string $provider, array $config, array $fields) {
        $fields += ['client_id' => $config['client_id'], 'client_secret' => $config['client_secret']];
        if ($provider === 'onedrive') { $fields['scope'] = $config['scope']; }
        $response = wp_remote_post($config['token'], ['timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true, 'limit_response_size' => 65536, 'headers' => ['Accept' => 'application/json'], 'body' => $fields]);
        if (is_wp_error($response)) { return self::error('upstream', 502); }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($data)) {
            if (is_array($data) && in_array($data['error'] ?? '', ['invalid_grant', 'invalid_token', 'interaction_required', 'consent_required'], true)) { return self::error('reconnect', 409); }
            return self::error('upstream', 502);
        }
        $token = $data['access_token'] ?? null;
        $refresh = $data['refresh_token'] ?? '';
        $ttl = $data['expires_in'] ?? 0;
        if (!is_string($token) || $token === '' || strlen($token) > 32768 || preg_match('/[\x00-\x20\x7f]/', $token) || !is_string($refresh) || strlen($refresh) > 32768 || preg_match('/[\x00-\x1f\x7f]/', $refresh)
            || !is_scalar($ttl) || !ctype_digit((string) $ttl) || (int) $ttl < 1 || (int) $ttl > 31536000 || strtolower((string) ($data['token_type'] ?? '')) !== 'bearer') { return self::error('upstream', 502); }
        // Google can return granular consent; reject explicitly reduced scopes.
        if (isset($data['scope'])) {
            if (!is_string($data['scope'])) { return self::error('upstream', 502); }
            $granted = preg_split('/\s+/', trim($data['scope']));
            $required = $provider === 'onedrive' ? ['Files.ReadWrite.AppFolder', 'https://graph.microsoft.com/Files.ReadWrite.AppFolder'] : [$config['scope']];
            if (!array_intersect($required, $granted)) { return self::error('reconnect', 409); }
        }
        return ['access_token' => $token, 'refresh_token' => $refresh, 'expires' => time() + (int) $ttl];
    }

    public function callback(): void {
        $state = self::query_string('state', 128);
        if (!preg_match('/^aiesk1_[A-Za-z0-9_-]{43}$/D', $state)) { return; }
        $context = self::callback_context($state);
        $provider = $context['provider'] ?? '';
        $result = self::finish($provider, $state, self::query_string('code', 8192), self::query_string('error', 512));
        $ok = !is_wp_error($result);
        $origin = $context['origin'] ?? self::secure_origin(home_url('/'));
        try { $nonce = self::random(18); } catch (Throwable $error) { $nonce = ''; }
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        $message = $ok ? __('Cloud account connected. Close this window and return to the page where you started.', 'ai-share-kit') : __('Connection was not completed. Return to the page where you started and connect again. Make sure you are still signed in.', 'ai-share-kit');
        echo '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html__('Cloud connection', 'ai-share-kit') . '</title></head><body style="font:16px/1.6 system-ui,sans-serif;padding:3rem;max-width:36rem;margin:auto"><h1>' . esc_html__('Cloud connection', 'ai-share-kit') . '</h1><p>' . esc_html($message) . '</p>';
        if ($nonce !== '' && $origin) {
            $payload = wp_json_encode(['type' => 'aiesk-cloud-connected', 'provider' => isset(self::PROVIDERS[$provider]) ? $provider : '', 'success' => $ok], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            echo '<script nonce="' . esc_attr($nonce) . '">history.replaceState(null,"",location.pathname);if(window.opener){window.opener.postMessage(' . $payload . ',' . wp_json_encode($origin) . ');}if(' . ($ok ? 'true' : 'false') . '){window.close();}</script>';
        }
        echo '</body></html>';
        exit;
    }

    /** Resolve only the current user's pending state; never trust a provider query argument. */
    private static function callback_context(string $state): array {
        $user = get_current_user_id();
        if (!$user || wp_get_session_token() === '') { return []; }
        foreach (self::providers() as $provider) {
            $pending = self::record(self::name('pending', $provider, $user));
            if (is_array($pending) && (int) ($pending['user'] ?? 0) === $user && ($pending['provider'] ?? '') === $provider
                && hash_equals((string) ($pending['hash'] ?? ''), hash('sha256', $state))
                && hash_equals((string) ($pending['session'] ?? ''), self::session_hash())) {
                return ['provider' => $provider, 'origin' => self::secure_origin((string) ($pending['origin'] ?? ''))];
            }
        }
        return [];
    }

    private static function query_string(string $name, int $max): string {
        $value = $_GET[$name] ?? '';
        return is_string($value) && strlen($value) <= $max ? wp_unslash($value) : '';
    }
    private static function secure_origin(string $url): string {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) { return ''; }
        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) && (int) $parts['port'] !== 443 ? ':' . (int) $parts['port'] : '');
    }

    private static function record(string $name, ?string &$revision = null) {
        $raw = self::read($name);
        $record = self::decode_record($raw, $name);
        $revision = is_array($record) && is_string($raw) ? hash('sha256', $raw) : null;
        return $record;
    }
    private static function decode_record($value, string $name) {
        if (is_wp_error($value) || $value === null) { return $value; }
        $plain = self::decrypt($value, $name);
        if (is_wp_error($plain)) { return $plain; }
        $record = json_decode($plain, true);
        return is_array($record) ? $record : self::error('storage', 503);
    }
    private static function write_record(string $name, array $record, array $lease, ?string &$revision = null) {
        if (!self::valid_lock($lease)) { return self::error('busy', 409); }
        $cipher = self::encrypt(wp_json_encode($record), $name);
        if (is_wp_error($cipher)) { return $cipher; }
        $saved = self::write($name, $cipher, $lease);
        if (!is_wp_error($saved)) { $revision = hash('sha256', $cipher); }
        return $saved;
    }
    private static function read(string $name) {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        return $wpdb->last_error ? self::error('storage', 503) : $value;
    }
    private static function write(string $name, string $value, ?array $lease = null) {
        global $wpdb;
        // The INSERT itself verifies the lease, so erasure cannot race a stale writer.
        $sql = $lease === null
            ? $wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'off'", $name, $value)
            : $wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'off' FROM {$wpdb->options} AS aiesk_cloud_lease WHERE option_name = %s AND option_value = %s ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'off'", $name, $value, $lease['name'], $lease['value']);
        $result = $wpdb->query($sql);
        self::clear_cache($name);
        return $result !== false && self::read($name) === $value ? true : self::error('storage', 503);
    }
    private static function remove(string $name, ?string $exact = null): bool {
        global $wpdb;
        $sql = $exact === null ? $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $name) : $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $exact);
        $result = $wpdb->query($sql);
        self::clear_cache($name);
        return $exact === null ? $result !== false : $result === 1;
    }
    private static function clear_cache(string $name): void { wp_cache_delete($name, 'options'); wp_cache_delete('notoptions', 'options'); }

    /** Unique option key is the atomic mutex; cache-backed add_option is insufficient. */
    private static function lock(string $provider, int $user) {
        global $wpdb;
        $name = self::name('lock', $provider, $user);
        $old = self::read($name);
        if (is_wp_error($old)) { return $old; }
        if (is_string($old) && (int) explode(':', $old, 2)[0] <= time()) { self::remove($name, $old); }
        try { $value = (time() + 90) . ':' . self::random(16); } catch (Throwable $error) { return self::error('encryption', 503); }
        $count = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value));
        self::clear_cache($name);
        return $count === 1 ? ['name' => $name, 'value' => $value] : self::error('busy', 409);
    }
    private static function valid_lock(array $lease): bool {
        $current = self::read($lease['name']);
        return (int) explode(':', $lease['value'], 2)[0] > time() && is_string($current) && hash_equals($lease['value'], $current);
    }
    private static function unlock(array $lease): void { self::remove($lease['name'], $lease['value']); }

    public function cleanup(): void {
        global $wpdb;
        foreach (['pending', 'lock'] as $kind) {
            $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 100", $wpdb->esc_like('aiesk_cloud_' . $kind . '_') . '%'));
            foreach ((array) $names as $name) {
                $value = self::read($name);
                if (!is_string($value)) { continue; }
                $record = $kind === 'pending' ? self::decode_record($value, $name) : null;
                $expired = $kind === 'lock' ? (int) explode(':', $value, 2)[0] <= time() : !is_array($record) || (int) ($record['expires'] ?? 0) <= time();
                if ($expired) { self::remove($name, $value); }
            }
        }
    }

    /** Forget local authorisations only; previously exported cloud files remain with their owner. */
    public static function forget_user(int $user): bool {
        if ($user < 1) { return false; }
        $removed = true;
        foreach (self::providers() as $provider) {
            // Cancel in-flight authorisation before erasing its account's credentials.
            // Atomic guarded writes ensure the old lease cannot recreate them.
            if (!self::remove(self::name('lock', $provider, $user))) { $removed = false; continue; }
            foreach (['tokens', 'pending'] as $kind) { if (!self::remove(self::name($kind, $provider, $user))) { $removed = false; } }
        }
        return $removed;
    }

    public function register_eraser(array $erasers): array {
        $erasers['aiesk-cloud-connections'] = ['eraser_friendly_name' => __('AI Share Kit cloud connections', 'ai-share-kit'), 'callback' => [__CLASS__, 'erase_personal_data']];
        return $erasers;
    }
    public static function erase_personal_data(string $email, int $page = 1): array {
        $user = get_user_by('email', $email);
        $ok = !$user || self::forget_user((int) $user->ID);
        return ['items_removed' => (bool) $user && $ok, 'items_retained' => !$ok, 'messages' => $ok ? [] : [__('A cloud connection is busy. Retry erasure in a few minutes.', 'ai-share-kit')], 'done' => true];
    }

    private static function error(string $kind, int $status): WP_Error {
        $messages = [
            'disabled' => __('This cloud destination is disabled by the administrator.', 'ai-share-kit'),
            'provider' => __('Unknown cloud destination.', 'ai-share-kit'),
            'configuration' => __('This cloud destination needs administrator setup.', 'ai-share-kit'),
            'permission' => __('You do not have permission to change cloud settings.', 'ai-share-kit'),
            'encryption' => __('The server could not securely read or store the cloud connection. Ask the administrator to check encryption and WordPress salts.', 'ai-share-kit'),
            'session' => __('Your sign-in session has expired. Reload the page and sign in again.', 'ai-share-kit'),
            'state' => __('This connection request has expired or was already used. Connect again from Share or Connected accounts.', 'ai-share-kit'),
            'cancelled' => __('Cloud connection was cancelled.', 'ai-share-kit'),
            'reconnect' => __('Connect this cloud account again before saving.', 'ai-share-kit'),
            'busy' => __('Another cloud connection request is running. Try again shortly.', 'ai-share-kit'),
            'storage' => __('The cloud connection could not be saved securely. Try again shortly.', 'ai-share-kit'),
            'upstream' => __('The cloud service did not complete authorisation. Try connecting again.', 'ai-share-kit'),
        ];
        $data = ['status' => $status];
        if ($kind === 'reconnect') { $data['connection_required'] = true; }
        return new WP_Error('aiesk_cloud_' . $kind, $messages[$kind] ?? $messages['upstream'], $data);
    }
}
