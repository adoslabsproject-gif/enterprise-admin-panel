<?php

/**
 * Dashboard Routes
 *
 * Main dashboard and common authenticated routes.
 * All routes in this file require authentication.
 *
 * @version 1.0.0
 */

use AdosLabs\AdminPanel\Routing\RouteDefinition;

return function (RouteDefinition $routes): void {
    // ========================================================================
    // Dashboard
    // ========================================================================

    // Main dashboard
    $routes->get('/dashboard', 'DashboardController@index')
        ->name('dashboard')
        ->middleware('auth');

    // Dashboard home (redirect to dashboard)
    $routes->get('/', 'DashboardController@index')
        ->name('home')
        ->middleware('auth');

    // ========================================================================
    // Profile & Settings
    // ========================================================================

    $routes->group('/profile', function (RouteDefinition $routes): void {
        // View profile
        $routes->get('', 'ProfileController@index')
            ->name('profile');

        // Update profile
        $routes->post('', 'ProfileController@update')
            ->name('profile.update');

        // Change password
        $routes->get('/password', 'ProfileController@passwordForm')
            ->name('profile.password');

        $routes->post('/password', 'ProfileController@changePassword')
            ->name('profile.password.update');

        // 2FA settings
        $routes->get('/2fa', 'ProfileController@twoFactorSettings')
            ->name('profile.2fa');

        $routes->post('/2fa/enable', 'ProfileController@enableTwoFactor')
            ->name('profile.2fa.enable');

        $routes->post('/2fa/disable', 'ProfileController@disableTwoFactor')
            ->name('profile.2fa.disable');

        // Notification preferences
        $routes->get('/notifications', 'ProfileController@notificationSettings')
            ->name('profile.notifications');

        $routes->post('/notifications', 'ProfileController@updateNotifications')
            ->name('profile.notifications.update');
    }, ['auth']);

    // ========================================================================
    // System Settings (admin only)
    // ========================================================================

    $routes->group('/settings', function (RouteDefinition $routes): void {
        // General settings
        $routes->get('', 'SettingsController@index')
            ->name('settings');

        $routes->post('', 'SettingsController@update')
            ->name('settings.update');

        // Security settings
        $routes->get('/security', 'SettingsController@security')
            ->name('settings.security');

        $routes->post('/security', 'SettingsController@updateSecurity')
            ->name('settings.security.update');

        // Regenerate admin URL
        $routes->post('/regenerate-url', 'SettingsController@regenerateUrl')
            ->name('settings.regenerate-url');
    }, ['auth']);

    // ========================================================================
    // User Management (admin only)
    // ========================================================================

    $routes->group('/users', function (RouteDefinition $routes): void {
        // List users
        $routes->get('', 'UsersController@index')
            ->name('users');

        // Create user
        $routes->get('/create', 'UsersController@create')
            ->name('users.create');

        $routes->post('', 'UsersController@store')
            ->name('users.store');

        // Edit user
        $routes->get('/{id}', 'UsersController@edit')
            ->name('users.edit');

        $routes->post('/{id}', 'UsersController@update')
            ->name('users.update');

        // Delete user
        $routes->delete('/{id}', 'UsersController@destroy')
            ->name('users.destroy');

        // Toggle user status
        $routes->post('/{id}/toggle', 'UsersController@toggle')
            ->name('users.toggle');

        // Reset user password
        $routes->post('/{id}/reset-password', 'UsersController@resetPassword')
            ->name('users.reset-password');
    }, ['auth']);

    // ========================================================================
    // Audit Log
    // ========================================================================

    $routes->group('/audit', function (RouteDefinition $routes): void {
        $routes->get('', 'AuditController@index')
            ->name('audit');

        $routes->get('/export', 'AuditController@export')
            ->name('audit.export');
    }, ['auth']);
};
