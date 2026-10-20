<?php
/** Explicit per-response metadata. Never infer history labels from current form controls. */
if (!defined('ABSPATH')) { exit; }

final class AIESK_Report_Context {
    public static function language($value) {
        if ($value === null || $value === '') { return ''; }
        if (!is_string($value) || strlen($value) > 63
            || !preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $value)) {
            return new WP_Error('aiesk_invalid_report_language', __('Choose a valid report language.', 'ai-share-kit'), ['status' => 400]);
        }
        return $value;
    }
}
