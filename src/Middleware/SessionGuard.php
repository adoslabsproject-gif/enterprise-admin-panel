<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Middleware;

use AdosLabs\AdminPanel\Services\SessionService;
use AdosLabs\AdminPanel\Services\ConfigService;
use AdosLabs\AdminPanel\Http\Response;

/**
 * Session Guard Middleware
 *
 * Enterprise-grade session protection:
 * - Validates session on every request
 * - Tracks activity for heartbeat mechanism
 * - 60 minute max session duration
 * - Auto-extends session if activity in last 5 minutes before expiry
 * - Provides session info for frontend countdown/warning
 *
 * @version 1.0.0
 */
final class SessionGuard
{
    private const SESSION_MAX_LIFETIME_MINUTES = 60;
    private const SESSION_WARNING_BEFORE_EXPIRY_MINUTES = 5;

    public function __construct(
        private SessionService $sessionService,
        private ConfigService $configService
    ) {
    }

    /**
     * Validate session and return session info
     *
     * @param string|null $sessionId Session ID from cookie
     * @return array{valid: bool, session: ?array, expires_in_seconds: ?int, should_warn: bool, login_url: string}
     */
    public function validate(?string $sessionId): array
    {
        $loginUrl = $this->configService->buildAdminUrl('login');

        if ($sessionId === null) {
            return [
                'valid' => false,
                'session' => null,
                'expires_in_seconds' => null,
                'should_warn' => false,
                'login_url' => $loginUrl,
            ];
        }

        $session = $this->sessionService->validate($sessionId);

        if ($session === null) {
            return [
                'valid' => false,
                'session' => null,
                'expires_in_seconds' => null,
                'should_warn' => false,
                'login_url' => $loginUrl,
            ];
        }

        // Calculate time remaining
        $expiresAt = new \DateTimeImmutable($session['expires_at']);
        $now = new \DateTimeImmutable();
        $expiresInSeconds = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());

        // Should warn if less than 5 minutes remaining
        $warningThreshold = self::SESSION_WARNING_BEFORE_EXPIRY_MINUTES * 60;
        $shouldWarn = $expiresInSeconds <= $warningThreshold && $expiresInSeconds > 0;

        return [
            'valid' => true,
            'session' => $session,
            'expires_in_seconds' => $expiresInSeconds,
            'should_warn' => $shouldWarn,
            'login_url' => $loginUrl,
        ];
    }

    /**
     * Create redirect response to login page
     */
    public function redirectToLogin(string $returnUrl = ''): Response
    {
        $loginUrl = $this->configService->buildAdminUrl('login');

        if ($returnUrl !== '') {
            $loginUrl .= '?return=' . urlencode($returnUrl);
        }

        return Response::redirect($loginUrl);
    }

    /**
     * Get session status info for API endpoint (heartbeat)
     *
     * Called by frontend JavaScript to update session countdown
     *
     * @param string|null $sessionId Session ID
     * @return array{active: bool, expires_in: int, should_warn: bool, extension_count: int}
     */
    public function getStatus(?string $sessionId): array
    {
        if ($sessionId === null) {
            return [
                'active' => false,
                'expires_in' => 0,
                'should_warn' => false,
                'extension_count' => 0,
            ];
        }

        $session = $this->sessionService->get($sessionId);

        if ($session === null) {
            return [
                'active' => false,
                'expires_in' => 0,
                'should_warn' => false,
                'extension_count' => 0,
            ];
        }

        $expiresAt = new \DateTimeImmutable($session['expires_at']);
        $now = new \DateTimeImmutable();
        $expiresInSeconds = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());

        $warningThreshold = self::SESSION_WARNING_BEFORE_EXPIRY_MINUTES * 60;

        return [
            'active' => true,
            'expires_in' => $expiresInSeconds,
            'should_warn' => $expiresInSeconds <= $warningThreshold && $expiresInSeconds > 0,
            'extension_count' => $session['payload']['extension_count'] ?? 0,
        ];
    }
}
