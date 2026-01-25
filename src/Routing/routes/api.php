<?php

/**
 * API Routes
 *
 * Internal API endpoints for AJAX requests.
 * All routes require authentication and return JSON.
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
        // Session & Auth
        // ====================================================================

        // Check session validity (heartbeat)
        $routes->get('/session/check', 'ApiController@checkSession')
            ->name('api.session.check');

        // Refresh CSRF token
        $routes->get('/csrf/refresh', 'ApiController@refreshCsrf')
            ->name('api.csrf.refresh');

        // ====================================================================
        // Dashboard Stats
        // ====================================================================

        $routes->get('/stats/overview', 'ApiController@statsOverview')
            ->name('api.stats.overview');

        $routes->get('/stats/chart/{type}', 'ApiController@chartData')
            ->name('api.stats.chart');

        // ====================================================================
        // Modules
        // ====================================================================

        // Get active modules
        $routes->get('/modules', 'ApiController@modules')
            ->name('api.modules');

        // Enable/disable module
        $routes->post('/modules/{module}/toggle', 'ApiController@toggleModule')
            ->name('api.modules.toggle');

        // ====================================================================
        // Notifications
        // ====================================================================

        // Get user notifications
        $routes->get('/notifications', 'ApiController@notifications')
            ->name('api.notifications');

        // Mark notification as read
        $routes->post('/notifications/{id}/read', 'ApiController@markNotificationRead')
            ->name('api.notifications.read');

        // Mark all as read
        $routes->post('/notifications/read-all', 'ApiController@markAllNotificationsRead')
            ->name('api.notifications.read-all');

        // ====================================================================
        // Search
        // ====================================================================

        // Global search
        $routes->get('/search', 'ApiController@search')
            ->name('api.search');

        // ====================================================================
        // Audit
        // ====================================================================

        // Get recent audit events
        $routes->get('/audit/recent', 'ApiController@recentAudit')
            ->name('api.audit.recent');

        // ====================================================================
        // Infrastructure Metrics
        // ====================================================================

        // Database pool metrics
        $routes->get('/dbpool', 'DashboardController@dbPoolMetrics')
            ->name('api.dbpool');

        // Redis metrics
        $routes->get('/redis', 'DashboardController@redisMetrics')
            ->name('api.redis');

        // Health check endpoint
        $routes->get('/health', 'ApiController@health')
            ->name('api.health');
    }, ['auth']);
};
