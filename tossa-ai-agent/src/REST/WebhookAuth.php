<?php
/**
 * Webhook Authentication.
 *
 * Validates incoming requests from the VPS agent using HMAC signatures,
 * timestamps, nonces, and IP allowlisting.
 *
 * @package Tossa\Agent\REST
 */

declare(strict_types=1);

namespace Tossa\Agent\REST;

final class WebhookAuth {

    private const MAX_AGE_SECONDS = 300; // 5 minutes.
    private const NONCE_TTL       = 600; // 10 minutes.

    /**
     * Validate an incoming webhook request.
     *
     * @param \WP_REST_Request $request The incoming request.
     * @return true|\WP_Error True if valid, WP_Error otherwise.
     */
    public static function validate(\WP_REST_Request $request): true|\WP_Error {
        // 1. Check kill switch.
        if (get_option('tossa_agent_kill_switch', false)) {
            return new \WP_Error(
                'tossa_agent_killed',
                'Emergency kill switch is active.',
                ['status' => 503]
            );
        }

        $secret = get_option('tossa_agent_webhook_secret', '');
        if (empty($secret)) {
            return new \WP_Error(
                'tossa_agent_not_configured',
                'Webhook secret not configured.',
                ['status' => 500]
            );
        }

        // 2. Timestamp freshness.
        $timestamp = (int) $request->get_header('X-Tossa-Timestamp');
        if (abs(time() - $timestamp) > self::MAX_AGE_SECONDS) {
            return new \WP_Error(
                'tossa_agent_stale_request',
                'Request timestamp is too old or too far in the future.',
                ['status' => 401]
            );
        }

        // 3. Nonce replay protection.
        $nonce = $request->get_header('X-Tossa-Nonce');
        if (empty($nonce)) {
            return new \WP_Error(
                'tossa_agent_missing_nonce',
                'Missing request nonce.',
                ['status' => 401]
            );
        }

        $nonce_key = 'tossa_nonce_' . md5($nonce);
        if (get_transient($nonce_key)) {
            return new \WP_Error(
                'tossa_agent_replay',
                'Duplicate request nonce detected.',
                ['status' => 401]
            );
        }
        set_transient($nonce_key, 1, self::NONCE_TTL);

        // 4. HMAC signature verification.
        $signature = $request->get_header('X-Tossa-Signature');
        $body      = $request->get_body();
        $expected  = hash_hmac('sha256', $timestamp . $nonce . $body, $secret);

        if (! hash_equals($expected, (string) $signature)) {
            return new \WP_Error(
                'tossa_agent_bad_signature',
                'HMAC signature verification failed.',
                ['status' => 401]
            );
        }

        // 5. IP allowlist (optional).
        $allowed_ips = get_option('tossa_agent_allowed_ips', '');
        if (! empty($allowed_ips)) {
            $allowed = array_map('trim', explode(',', $allowed_ips));
            $remote  = $_SERVER['REMOTE_ADDR'] ?? '';

            if (! in_array($remote, $allowed, true)) {
                return new \WP_Error(
                    'tossa_agent_ip_denied',
                    'Request IP not in allowlist.',
                    ['status' => 403]
                );
            }
        }

        // 6. Rate limiting.
        $rate_key = 'tossa_agent_webhook_rate';
        $count    = (int) get_transient($rate_key);
        $max      = (int) get_option('tossa_agent_webhook_rate_limit', 60);

        if ($count >= $max) {
            return new \WP_Error(
                'tossa_agent_rate_limited',
                'Webhook rate limit exceeded.',
                ['status' => 429]
            );
        }
        set_transient($rate_key, $count + 1, MINUTE_IN_SECONDS);

        return true;
    }
}
