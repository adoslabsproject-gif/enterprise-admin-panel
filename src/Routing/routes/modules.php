<?php

/**
 * Module Routes Loader
 *
 * This file serves as an extension point for module-provided routes.
 * Modules can register their routes via the ModuleRegistry, and those
 * routes are automatically loaded here.
 *
 * Module routes are prefixed with their module identifier, e.g.:
 * - /security/dashboard (SecurityShieldModule)
 * - /database-pool/connections (DatabasePoolModule)
 * - /logger/viewer (Psr3LoggerModule)
 *
 * All module routes require authentication by default.
 *
 * @version 1.0.0
 */

use AdosLabs\AdminPanel\Routing\RouteDefinition;

return function (RouteDefinition $routes): void {
    // ========================================================================
    // Module routes are dynamically registered via ModuleRegistry
    // ========================================================================
    // Each module registers its routes in the getRoutes() method
    // See: src/Modules/SecurityShieldModule.php for example
    //
    // The RouteLoader will call this file, but the actual module routes
    // are registered separately by the ModuleRegistry::getRoutes() method
    // in public/index.php
    //
    // This file can be used for common module middleware or prefixes
    // if needed in the future.

    // Example: Security Shield routes (handled by SecurityShieldModule)
    // $routes->group('/security', function (RouteDefinition $routes): void {
    //     $routes->get('/dashboard', 'SecurityShieldController@dashboard')
    //         ->name('security.dashboard');
    // }, ['auth']);
};
