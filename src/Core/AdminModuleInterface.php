<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Core;

/**
 * Admin Module Interface
 *
 * ENTERPRISE GALAXY: Contract for modular admin panel extensions
 *
 * Every module MUST implement this interface to:
 * - Register tabs in admin sidebar
 * - Register routes for module pages
 * - Provide installation/uninstallation logic
 * - Declare module metadata (name, version, description)
 *
 * EXAMPLE IMPLEMENTATION:
 * =======================
 * ```php
 * class SecurityShieldModule implements AdminModuleInterface
 * {
 *     public function getName(): string {
 *         return 'Security Shield';
 *     }
 *
 *     public function getTabs(): array {
 *         return [
 *             [
 *                 'label' => 'Security',
 *                 'url' => '/admin/security',
 *                 'icon' => 'shield',
 *                 'priority' => 10,
 *             ],
 *         ];
 *     }
 *
 *     public function getRoutes(): array {
 *         return [
 *             [
 *                 'method' => 'GET',
 *                 'path' => '/admin/security',
 *                 'handler' => [SecurityController::class, 'dashboard'],
 *             ],
 *         ];
 *     }
 *
 *     public function install(): void {
 *         // Run migrations, seed data
 *     }
 *
 *     public function uninstall(): void {
 *         // Clean up database tables
 *     }
 * }
 * ```
 *
 * @version 1.0.0
 * @since 2026-01-24
 */
interface AdminModuleInterface
{
    /**
     * Get module name
     *
     * @return string Human-readable module name (e.g., "Security Shield")
     */
    public function getName(): string;

    /**
     * Get module description
     *
     * @return string Short description of module functionality
     */
    public function getDescription(): string;

    /**
     * Get module version
     *
     * @return string Semantic version (e.g., "1.0.0")
     */
    public function getVersion(): string;

    /**
     * Get tabs for admin sidebar navigation
     *
     * Each tab definition:
     * - label: Display text (e.g., "Security")
     * - url: Route path (e.g., "/admin/security")
     * - icon: Icon name (e.g., "shield", "chart", "users")
     * - badge: Optional badge text (e.g., "3 alerts")
     * - priority: Sort order (lower = earlier, default: 50)
     *
     * @return array<array{label: string, url: string, icon: string, badge?: string, priority?: int}>
     */
    public function getTabs(): array;

    /**
     * Get routes for this module
     *
     * Each route definition:
     * - method: HTTP method (GET, POST, PUT, DELETE)
     * - path: Route path (e.g., "/admin/security/ban-ip")
     * - handler: Callable (e.g., [Controller::class, 'method'])
     * - middleware: Optional middleware array
     *
     * @return array<array{method: string, path: string, handler: callable, middleware?: array}>
     */
    public function getRoutes(): array;

    /**
     * Install module
     *
     * Called when module is first installed.
     * Should:
     * - Run database migrations
     * - Seed initial data
     * - Create config entries
     * - Register assets
     *
     * @throws \Exception If installation fails
     * @return void
     */
    public function install(): void;

    /**
     * Uninstall module
     *
     * Called when module is uninstalled.
     * Should:
     * - Drop database tables
     * - Remove config entries
     * - Clean up files
     *
     * IMPORTANT: Be careful with data deletion!
     *
     * @throws \Exception If uninstallation fails
     * @return void
     */
    public function uninstall(): void;

    /**
     * Get module configuration schema
     *
     * Defines configurable options for this module.
     * Used to auto-generate settings UI.
     *
     * Each config field:
     * - key: Config key (e.g., "max_ban_duration")
     * - label: Display label
     * - type: Field type (text, number, boolean, select)
     * - default: Default value
     * - options: For select type (array of values)
     * - description: Help text
     *
     * @return array<array{key: string, label: string, type: string, default: mixed, options?: array, description?: string}>
     */
    public function getConfigSchema(): array;

    /**
     * Get module dependencies
     *
     * List of required packages/modules.
     * Installation will fail if dependencies not met.
     *
     * @return array<string> Package names (e.g., ["psr/log", "adoslabs/database-pool"])
     */
    public function getDependencies(): array;

    /**
     * Get module permissions
     *
     * Defines permissions required to access this module.
     * Used for role-based access control (RBAC).
     *
     * @return array<string> Permission names (e.g., ["view_security", "ban_ips"])
     */
    public function getPermissions(): array;

    /**
     * Get views path for this module
     *
     * Each module provides its own views in its own package.
     * The admin panel core does NOT contain module-specific views.
     *
     * ARCHITECTURE:
     * - admin-panel/src/Views/ = Core views (layout, auth, dashboard)
     * - security-shield/src/Views/ = Security module views
     * - psr3-logger/src/Views/ = Logger module views
     *
     * @return string|null Absolute path to views directory, or null if none
     */
    public function getViewsPath(): ?string;

    /**
     * Get assets path for this module (CSS, JS)
     *
     * Modules can provide their own static assets.
     *
     * @return string|null Absolute path to assets directory, or null if none
     */
    public function getAssetsPath(): ?string;
}
