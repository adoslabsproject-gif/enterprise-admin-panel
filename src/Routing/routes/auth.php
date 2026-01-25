<?php

/**
 * Authentication Routes
 *
 * Handles:
 * - Login/Logout
 * - 2FA verification
 * - Emergency recovery (master admin only, bypasses 2FA)
 *
 * These routes do NOT require authentication (except logout).
 *
 * @version 1.0.0
 */

use AdosLabs\AdminPanel\Routing\RouteDefinition;

return function (RouteDefinition $routes): void {
    // ========================================================================
    // Login
    // ========================================================================
    $routes->get('/login', 'AuthController@loginForm')
        ->name('auth.login');

    $routes->post('/login', 'AuthController@login')
        ->name('auth.login.submit');

    // ========================================================================
    // Logout (requires authentication)
    // ========================================================================
    $routes->post('/logout', 'AuthController@logout')
        ->name('auth.logout')
        ->middleware('auth');

    // ========================================================================
    // Two-Factor Authentication
    // ========================================================================
    $routes->get('/2fa', 'AuthController@twoFactorForm')
        ->name('auth.2fa');

    $routes->post('/2fa/verify', 'AuthController@verifyTwoFactor')
        ->name('auth.2fa.verify');

    // ========================================================================
    // Emergency Recovery (Master Admin Only)
    //
    // Allows master admin to bypass 2FA using a one-time recovery token.
    // Token is generated via CLI: php setup/generate-recovery-token.php
    // Token is sent via email/telegram/discord/slack and expires in 24h.
    // ========================================================================
    $routes->get('/recovery', 'AuthController@recoveryForm')
        ->name('auth.recovery');

    $routes->post('/recovery', 'AuthController@verifyRecovery')
        ->name('auth.recovery.verify');
};
