<?php

/**
 * Dashboard Routes
 *
 * Main dashboard and common authenticated routes.
 * All routes in this file require authentication.
 *
 * NOTE: Profile, Settings, Users, and Audit routes will be added
 * in the enterprise-user-management package.
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
};
