# Module Development

## Overview

The admin panel uses a modular architecture. Each module can add:
- Navigation tabs
- Routes and controllers
- Configuration options
- Database migrations
- Assets (CSS, JS)

Modules are auto-discovered from installed Composer packages.

## Creating a Module

### Step 1: Create Module Class

```php
<?php

namespace YourVendor\YourPackage;

use AdosLabs\AdminPanel\Modules\BaseModule;

class YourModule extends BaseModule
{
    public function getName(): string
    {
        return 'your-module';
    }

    public function getDisplayName(): string
    {
        return 'Your Module';
    }

    public function getDescription(): string
    {
        return 'Description of what your module does';
    }

    public function getIcon(): string
    {
        return 'settings'; // Lucide icon name
    }

    public function getTabs(): array
    {
        return [
            [
                'id' => 'your-dashboard',
                'label' => 'Dashboard',
                'url' => '/admin/your-module',
                'icon' => 'layout-dashboard',
                'priority' => 10,
            ],
            [
                'id' => 'your-settings',
                'label' => 'Settings',
                'url' => '/admin/your-module/settings',
                'icon' => 'settings',
                'priority' => 20,
            ],
        ];
    }

    public function getRoutes(): array
    {
        return [
            'GET:/admin/your-module' => [YourController::class, 'index'],
            'GET:/admin/your-module/settings' => [YourController::class, 'settings'],
            'POST:/admin/your-module/settings' => [YourController::class, 'saveSettings'],
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'enable_feature',
                'label' => 'Enable Feature',
                'type' => 'boolean',
                'default' => true,
                'description' => 'Turn this feature on or off',
            ],
            [
                'key' => 'api_key',
                'label' => 'API Key',
                'type' => 'string',
                'default' => '',
                'description' => 'Your API key for external service',
            ],
            [
                'key' => 'max_items',
                'label' => 'Maximum Items',
                'type' => 'integer',
                'default' => 100,
                'min' => 1,
                'max' => 1000,
            ],
        ];
    }

    public function install(): void
    {
        // Run when module is first installed
        // e.g., create database tables
    }

    public function uninstall(): void
    {
        // Run when module is removed
        // e.g., drop database tables (optional)
    }
}
```

### Step 2: Register in composer.json

```json
{
    "name": "your-vendor/your-package",
    "extra": {
        "admin-panel": {
            "module": "YourVendor\\YourPackage\\YourModule",
            "priority": 50
        }
    }
}
```

Priority determines sidebar order (lower = higher in list).

### Step 3: Create Controller

```php
<?php

namespace YourVendor\YourPackage\Controllers;

use AdosLabs\AdminPanel\Controllers\BaseController;

class YourController extends BaseController
{
    public function index(): string
    {
        $data = [
            'title' => 'Your Module Dashboard',
            'items' => $this->getItems(),
        ];

        return $this->render('your-module/index', $data);
    }

    public function settings(): string
    {
        $config = $this->getModuleConfig('your-module');

        return $this->render('your-module/settings', [
            'config' => $config,
        ]);
    }

    public function saveSettings(): void
    {
        $this->validateCsrf();

        $this->setModuleConfig('your-module', [
            'enable_feature' => $_POST['enable_feature'] ?? false,
            'api_key' => $_POST['api_key'] ?? '',
            'max_items' => (int)($_POST['max_items'] ?? 100),
        ]);

        $this->redirect('/admin/your-module/settings', [
            'success' => 'Settings saved',
        ]);
    }
}
```

### Step 4: Create Views

Create view files in your package:

```
your-package/
├── resources/
│   └── views/
│       └── your-module/
│           ├── index.php
│           └── settings.php
```

Example view (`index.php`):

```php
<?php
/**
 * @var string $title
 * @var array $items
 * @var string $admin_base_path
 */
?>

<div class="eap-page-header">
    <h1 class="eap-page-title"><?= htmlspecialchars($title) ?></h1>
</div>

<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Items</span>
    </div>
    <div class="eap-card__body">
        <?php if (empty($items)): ?>
            <p class="eap-text-muted">No items found.</p>
        <?php else: ?>
            <ul class="eap-list">
                <?php foreach ($items as $item): ?>
                    <li class="eap-list__item">
                        <?= htmlspecialchars($item['name']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
```

## CSS Classes

Use the `.eap-` prefix for all CSS classes to ensure compatibility:

- `.eap-card` - Card container
- `.eap-card__header` - Card header
- `.eap-card__body` - Card body
- `.eap-btn` - Button
- `.eap-btn--primary` - Primary button
- `.eap-table` - Table
- `.eap-form__group` - Form group
- `.eap-form__input` - Input field
- `.eap-badge` - Badge/tag

See `public/css/admin.css` for all available classes.

## Database Migrations

If your module needs database tables:

```php
public function install(): void
{
    $pdo = $this->getPdo();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS your_module_items (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

public function uninstall(): void
{
    // Optional: drop tables on uninstall
    // $this->getPdo()->exec("DROP TABLE IF EXISTS your_module_items");
}
```

## Permissions

Define required permissions:

```php
public function getRequiredPermissions(): array
{
    return [
        'your-module.view',
        'your-module.edit',
        'your-module.delete',
    ];
}
```

Check permissions in controller:

```php
public function delete(): void
{
    $this->requirePermission('your-module.delete');

    // ... delete logic
}
```

## Events

Listen to admin panel events:

```php
public function boot(): void
{
    $this->on('user.login', function ($user) {
        // Log login event
    });

    $this->on('user.logout', function ($user) {
        // Cleanup
    });
}
```

## Assets

Register CSS/JS files:

```php
public function getAssets(): array
{
    return [
        'css' => [
            '/your-module/styles.css',
        ],
        'js' => [
            '/your-module/scripts.js',
        ],
    ];
}
```

Place assets in `resources/assets/` and they'll be published to `public/`.

## Testing

```php
use PHPUnit\Framework\TestCase;

class YourModuleTest extends TestCase
{
    public function testModuleRegistration(): void
    {
        $module = new YourModule();

        $this->assertEquals('your-module', $module->getName());
        $this->assertNotEmpty($module->getTabs());
        $this->assertNotEmpty($module->getRoutes());
    }
}
```

## Example: Complete Module

See these packages for complete examples:
- `ados-labs/enterprise-security-shield` - Security module
- `ados-labs/enterprise-psr3-logger` - Logger module
- `ados-labs/database-pool` - Database pool module
