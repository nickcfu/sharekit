<?php
/**
 * Send one selected report with server-derived teacher and school attribution.
 */

if (!defined('ABSPATH')) { exit; }

final class AIESK_Share_Email {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_route']);
    }

    public function register_route(): void {
        register_rest_route('aiesk/v1', '/email', [
            'methods'             => 'POST',
            'callback'            => [$this, 'send_email'],
            'permission_callback' => function () { return aiesk_rest_permission('email'); },
        ]);
    }

    /**
     * Send the shared AI message via email using wp_mail()
     * Expected JSON: { "to": "user@example.com", "title": "...", "html": "..." }
     */
    public function send_email(WP_REST_Request $req) {
        $allowed = aiesk_rest_permission('email');
        if (is_wp_error($allowed)) { return $allowed; }
        $data  = aiesk_request_data($req);
        if (!is_array($data)) {
            return new WP_Error('aiesk_bad_json', __('Invalid JSON', 'ai-share-kit'), ['status' => 400]);
        }

        if (!is_string($data['to'] ?? null) || !is_string($data['title'] ?? null)) {
            return new WP_Error('aiesk_email_fields', __('Enter a recipient email address and report title.', 'ai-share-kit'), ['status' => 400]);
        }
        // Do not silently turn a mistyped address into a different recipient.
        $to = trim($data['to']);
        if (preg_match('/[\x00-\x1F\x7F]/', $data['to']) || !is_email($to)) {
            return new WP_Error('aiesk_email_recipient', __('Enter one valid recipient email address.', 'ai-share-kit'), ['status' => 422]);
        }
        $title = self::text($data['title'], 180);
        $html  = aiesk_validate_share_html($data['html'] ?? '');
        if (is_wp_error($html)) {
            return $html;
        }
        $language = AIESK_Report_Context::language($data['report_lang'] ?? '');
        if (is_wp_error($language)) { return $language; }

        if ($title === '' || $html === '') {
            return new WP_Error('aiesk_missing_data', __('Missing required fields', 'ai-share-kit'), ['status' => 422]);
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        $lease = class_exists('AIESK_Limits') ? AIESK_Limits::begin('email') : null;
        if (is_wp_error($lease)) { return $lease; }
        try {
            // Submitted sender/user/school fields are deliberately never read.
            $user = wp_get_current_user();
            $name = self::text($user->display_name ?? '', 120);
            if ($name === '' || is_email($name)) {
                $name = self::text(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''), 120);
            }
            if ($name === '' || is_email($name)) { $name = __('An AI EdWiBo user', 'ai-share-kit'); }
            $school = self::school((int) $user->ID);
            /* translators: 1: sender's profile name, 2: report title. */
            $subject = sprintf(__('%1$s sent you a report via AI EdWiBo: %2$s', 'ai-share-kit'), $name, $title);
            $subject = self::text($subject, 400);
            $message = self::message($name, $school, $title, $html, $language);
            // Keep the site's authenticated From/transport settings. Never
            // expose a teacher's private address through From or Reply-To.
            $sent = wp_mail($to, $subject, $message, $headers);
        } catch (Throwable $error) {
            $sent = false;
        }
        finally { if (is_array($lease)) { AIESK_Limits::finish($lease); } }

        if (!$sent) {
            return new WP_Error('aiesk_email_failed', __('Email failed to send', 'ai-share-kit'), ['status' => 500]);
        }

        return rest_ensure_response(['success' => true, 'to' => $to]);
    }

    /** Profile labels are plain text, bounded without splitting UTF-8. */
    private static function text($value, int $limit): string {
        if (!is_string($value)) { return ''; }
        $value = sanitize_text_field($value);
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        if (!preg_match('/^.{0,' . $limit . '}/us', trim($value), $match)) { return ''; }
        return trim($match[0]);
    }

    /** Only the school plugin's current, unambiguous, active identity qualifies. */
    private static function school(int $user_id): string {
        if ($user_id < 1 || !is_callable(['EdWiBo_School_Credits_Plugin', 'instance'])) { return ''; }
        try {
            $plugin = EdWiBo_School_Credits_Plugin::instance();
            if (!is_callable([$plugin, 'accounts'])) { return ''; }
            $accounts = $plugin->accounts();
            if (!is_callable([$accounts, 'identity'])) { return ''; }
            $identity = $accounts->identity($user_id);
            if (is_wp_error($identity) || !is_object($identity)
                || ($identity->active ?? false) !== true
                || !in_array($identity->purpose ?? '', ['school', 'owner'], true)
                || (int) ($identity->school_id ?? 0) < 1) { return ''; }
            return self::text($identity->name ?? '', 190);
        } catch (Throwable $error) {
            // An unavailable or older optional integration must never invent
            // an affiliation or prevent a teacher-only report from being sent.
            return '';
        }
    }

    private static function message(string $name, string $school, string $title, string $html, string $language): string {
        $teacher = '<strong>' . esc_html($name) . '</strong>';
        if ($school !== '') {
            /* translators: 1: sender name, 2: verified school name; both are escaped HTML. */
            $intro = sprintf(__('%1$s from %2$s has sent you this report via AI EdWiBo.', 'ai-share-kit'), $teacher, '<strong>' . esc_html($school) . '</strong>');
        } else {
            /* translators: %s: sender name, escaped HTML. */
            $intro = sprintf(__('%s has sent you this report via AI EdWiBo.', 'ai-share-kit'), $teacher);
        }
        $lang = str_replace('_', '-', get_locale());
        return '<!doctype html><html lang="' . esc_attr($lang) . '" dir="' . (is_rtl() ? 'rtl' : 'ltr') . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;color:#172033;font-family:Arial,Helvetica,sans-serif;line-height:1.6">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse"><tr><td style="padding:24px 12px">'
            . '<table role="presentation" align="center" width="100%" cellspacing="0" cellpadding="0" style="width:100%;max-width:720px;margin:0 auto;background:#ffffff;border:1px solid #cbd5e1;border-collapse:collapse">'
            . '<tr><td style="padding:24px;background:#eff6ff;border-bottom:1px solid #cbd5e1">'
            . '<p style="margin:0 0 12px;color:#1d4ed8;font-size:15px;font-weight:bold">AI EdWiBo</p>'
            . '<p style="margin:0;font-size:17px;color:#172033">' . $intro . '</p></td></tr>'
            . '<tr><td style="padding:24px;overflow-wrap:anywhere;word-break:normal">'
            . '<div class="aiesk-email-report" dir="auto"' . ($language !== '' ? ' lang="' . esc_attr($language) . '"' : '') . '>' . $html . '</div></td></tr>'
            . '<tr><td style="padding:16px 24px;border-top:1px solid #cbd5e1;color:#526176;font-size:13px">'
            . esc_html__('This email contains the report selected by the sender.', 'ai-share-kit')
            . '</td></tr></table></td></tr></table></body></html>';
    }
}
