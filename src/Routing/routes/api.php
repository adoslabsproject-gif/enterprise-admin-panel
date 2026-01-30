<?php

/**
 * API Routes
 *
 * Internal API endpoints for AJAX requests.
 * All routes require authentication and return JSON.
 *
 * NOTE: Most API routes (notifications, search, audit, modules)
 * will be added in the enterprise-user-management package.
 *
 * @version 1.0.0
 */

use AdosLabs\AdminPanel\Routing\RouteDefinition;

return function (RouteDefinition $routes): void {
    // ========================================================================
    // API Group (all routes prefixed with /api)
    // ========================================================================

    $routes->group('/api', function (RouteDefinition $routes): void {

        // ====================================================================
        // Infrastructure Metrics (implemented in DashboardController)
        // ====================================================================

        // Database pool metrics
        $routes->get('/dbpool', 'DashboardController@dbPoolMetrics')
            ->name('api.dbpool');

        // Redis metrics
        $routes->get('/redis', 'DashboardController@redisMetrics')
            ->name('api.redis');

    }, ['auth']);
};
