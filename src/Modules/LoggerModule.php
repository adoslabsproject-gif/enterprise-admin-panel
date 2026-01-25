<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Modules;

use AdosLabs\AdminPanel\Controllers\LoggerController;

/**
 * Logger Module
 *
 * Integrates PSR-3 Logger management into the admin panel.
 *
 * Features:
 * - Channel configuration (min level, enabled/disabled)
 * - Log viewer with filters
 * - Database log management
 * - Telegram notifications
 * - PHP error log viewer
 *
 * This module is PART OF enterprise-admin-panel, not a separate package.
 * It works with enterprise-psr3-logger for logging and enterprise-bootstrap
 * for the should_log() integration.
 *
 * @package adoslabs/enterprise-admin-panel
 */
class LoggerModule extends BaseModule
{
    /**
     * Get module name
     */
    public function getName(): string
    {
        return 'Logger';
    }

    /**
     * Get module description
     */
    public function getDescription(): string
    {
        return 'PSR-3 Logger configuration and log viewer';
    }

    /**
     * Get module version
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Get tabs for admin sidebar
     *
     * @return array<array{label: string, url: string, icon: string, badge?: string|null, priority?: int}>
     */
    public function getTabs(): array
    {
        return [
            [
                'label' => 'Logger',
                'url' => '/logger',
                'icon' => 'file-text',
                'priority' => 30,
                'children' => [
                    [
                        'label' => 'Dashboard',
                        'url' => '/logger',
                        'icon' => 'layout-dashboard',
                    ],
                    [
                        'label' => 'Channels',
                        'url' => '/logger/channels',
                        'icon' => 'layers',
                    ],
                    [
                        'label' => 'Database Logs',
                        'url' => '/logger/database',
                        'icon' => 'database',
                    ],
                    [
                        'label' => 'Telegram',
                        'url' => '/logger/telegram',
                        'icon' => 'send',
                    ],
                    [
                        'label' => 'PHP Errors',
                        'url' => '/logger/php-errors',
                        'icon' => 'alert-triangle',
                    ],
                ],
            ],
        ];
    }

    /**
     * Get routes for this module
     *
     * @return array<array{method: string, path: string, handler: callable|array}>
     */
    public function getRoutes(): array
    {
        return [
            // Dashboard
            [
                'method' => 'GET',
                'path' => '/logger',
                'handler' => [LoggerController::class, 'index'],
            ],

            // Channels
            [
                'method' => 'GET',
                'path' => '/logger/channels',
                'handler' => [LoggerController::class, 'channels'],
            ],
            [
                'method' => 'POST',
                'path' => '/logger/channels/update',
                'handler' => [LoggerController::class, 'updateChannel'],
            ],
            [
                'method' => 'POST',
                'path' => '/logger/channels/delete',
                'handler' => [LoggerController::class, 'deleteChannel'],
            ],

            // Telegram
            [
                'method' => 'GET',
                'path' => '/logger/telegram',
                'handler' => [LoggerController::class, 'telegram'],
            ],
            [
                'method' => 'POST',
                'path' => '/logger/telegram/save',
                'handler' => [LoggerController::class, 'saveTelegram'],
            ],
            [
                'method' => 'POST',
                'path' => '/logger/telegram/test',
                'handler' => [LoggerController::class, 'testTelegram'],
            ],

            // Database Logs
            [
                'method' => 'GET',
                'path' => '/logger/database',
                'handler' => [LoggerController::class, 'database'],
            ],
            [
                'method' => 'POST',
                'path' => '/logger/database/clear',
                'handler' => [LoggerController::class, 'clearDatabaseLogs'],
            ],

            // File viewer
            [
                'method' => 'GET',
                'path' => '/logger/file',
                'handler' => [LoggerController::class, 'fileView'],
            ],
            [
                'method' => 'GET',
                'path' => '/logger/file/download',
                'handler' => [LoggerController::class, 'fileDownload'],
            ],

            // PHP Errors
            [
                'method' => 'GET',
                'path' => '/logger/php-errors',
                'handler' => [LoggerController::class, 'phpErrors'],
            ],
            [
                'method' => 'POST',
                'path' => '/logger/php-errors/clear',
                'handler' => [LoggerController::class, 'clearPhpErrors'],
            ],

            // API
            [
                'method' => 'GET',
                'path' => '/logger/api/logs',
                'handler' => [LoggerController::class, 'apiLogs'],
            ],
        ];
    }

    /**
     * Get module permissions
     *
     * @return array<string>
     */
    public function getPermissions(): array
    {
        return [
            'logger.view',
            'logger.channels.manage',
            'logger.telegram.manage',
            'logger.database.view',
            'logger.database.clear',
            'logger.files.view',
            'logger.files.download',
            'logger.php-errors.view',
            'logger.php-errors.clear',
        ];
    }

    /**
     * Get views path
     *
     * Logger views are in the admin-panel package, not external.
     */
    public function getViewsPath(): ?string
    {
        return dirname(__DIR__) . '/Views';
    }

    /**
     * Get configuration schema
     *
     * @return array<array{key: string, label: string, type: string, default: mixed, description?: string}>
     */
    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'log_dir',
                'label' => 'Log Directory',
                'type' => 'string',
                'default' => '/var/log/app',
                'description' => 'Directory where log files are stored',
            ],
            [
                'key' => 'max_log_age_days',
                'label' => 'Max Log Age (days)',
                'type' => 'integer',
                'default' => 30,
                'description' => 'Auto-delete database logs older than this many days',
            ],
            [
                'key' => 'enable_php_error_log',
                'label' => 'Enable PHP Error Log Viewer',
                'type' => 'boolean',
                'default' => true,
                'description' => 'Allow viewing PHP error log from admin panel',
            ],
        ];
    }

    /**
     * Get module dependencies
     *
     * @return array<string>
     */
    public function getDependencies(): array
    {
        return [
            // Optional but recommended
            'adoslabs/enterprise-psr3-logger',
            'adoslabs/enterprise-bootstrap',
        ];
    }

    /**
     * Install module
     *
     * Creates log_channels and log_telegram_config tables.
     */
    public function install(): void
    {
        $this->logger->info('Installing Logger module');

        // The migrations are in admin-panel's migrations folder
        // They are run by admin-panel's install script
        // This method is called for additional setup if needed

        parent::install();

        $this->logger->info('Logger module installed');
    }
}
