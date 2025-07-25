---
layout: default
---
[← Back to Table of Contents](index.md)

# 11. Extending KMP

The Kingdom Management Portal is designed to be extensible through plugins. This document explains how developers can extend and customize the application using the plugin system, with practical examples from the Officers plugin.

## 11.1 Creating Plugins

Plugins are the primary mechanism for extending KMP. They allow you to add new functionality while keeping your code separate from the core application.


### Plugin Structure

KMP plugins follow a standard structure. For consistency, the main plugin class should be named `Plugin.php` and placed in the `src/` directory. Example structure:

```
plugins/Officers/
├── config/             # Plugin configuration
│   ├── routes.php      # Plugin routes
│   ├── bootstrap.php   # Plugin bootstrap code
│   └── Migrations/     # Database migrations
├── src/                # Plugin source code
│   ├── Plugin.php      # Main plugin class (recommended name)
│   ├── Controller/     # Controllers
│   ├── Model/          # Models
│   │   ├── Entity/     # Entities
│   │   └── Table/      # Tables
│   ├── View/           # View classes
│   │   ├── Cell/       # Cell classes
│   │   └── Helper/     # View helpers
│   ├── Event/          # Event listeners
│   │   └── CallForCellsHandler.php # Cell registration
│   ├── Services/       # Business services
│   │   └── PluginNameNavigationProvider.php # Navigation provider
├── templates/          # Template files
│   ├── layout/         # Layout templates
│   ├── cell/           # Cell templates
│   └── element/        # UI elements
├── assets/             # Source asset files
│   ├── css/            # CSS source files
│   ├── js/             # JavaScript source files
│   │   └── controllers/ # Stimulus controllers
│   └── img/            # Images
└── tests/              # Test cases
```

> **Note:** Some legacy plugins may use a different main class name (e.g., `OfficersPlugin.php`). For new plugins, always use `Plugin.php` for clarity and consistency.

### Plugin Class

Every plugin needs a main Plugin class:

```php
namespace Officers;

use Cake\Core\BasePlugin;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;

class Plugin extends BasePlugin
{
    /**
     * Plugin name
     *
     * @var string
     */
    protected $name = 'Officers';

    /**
     * Enable middleware
     *
     * @var bool
     */
    protected $middlewareEnabled = false;

    /**
     * Load routes or not
     *
     * @var bool
     */
    protected $routesEnabled = true;

    /**
     * Console middleware
     *
     * @var bool
     */
    protected $consoleEnabled = false;

    /**
     * Bootstrap hook
     *
     * @param \Cake\Core\PluginApplicationInterface $app Application instance
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);
        
        // Register event listeners for cells
        $eventManager = \Cake\Event\EventManager::instance();
        $eventManager->on(new \Officers\Event\CallForCellsHandler());
        
        // Register navigation provider
        \App\Services\NavigationRegistry::register(
            'Officers',
            [],
            [\Officers\Services\OfficersNavigationProvider::class, 'getNavigationItems']
        );
    }

    /**
     * Routes hook
     *
     * @param \Cake\Routing\RouteBuilder $routes Routes builder
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        parent::routes($routes);
        
        $routes->plugin('Officers', function (RouteBuilder $builder) {
            $builder->connect('/', ['controller' => 'Officers', 'action' => 'index']);
            // Other routes...
        });
    }
}
```

### Plugin Registration

Register your plugin in `config/plugins.php`:

```php
return [
    // ... other plugins
    'Officers' => [
        'routes' => true,
        'bootstrap' => true,
        'migrationOrder' => 4, // After core plugins
    ],
];
```

## 11.2 Navigation and Event System

### Navigation Registration with NavigationProvider

KMP uses a service-based navigation registry system for performance and maintainability. Plugins register their navigation items through NavigationProvider classes rather than event handlers.


Here's how the Officers plugin implements navigation registration (example includes all keys as used in production):

```php
namespace Officers\Services;

use App\KMP\StaticHelpers;

class OfficersNavigationProvider
{
    public static function getNavigationItems($user, array $params = []): array
    {
        if (StaticHelpers::pluginEnabled('Officers') == false) {
            return [];
        }
        return [
            [
                "type" => "link",
                "mergePath" => ["Reports"],
                "label" => "Officers",
                "order" => 29,
                "url" => [
                    "plugin" => "Officers",
                    "controller" => "Officers",
                    "action" => "index",
                    "model" => "Officers.Officers",
                ],
                "icon" => "bi-building",
                "activePaths" => [
                    "officers/Officers/view/*",
                ]
            ],
            [
                "type" => "link",
                "mergePath" => ["Config"],
                "label" => "Departments",
                "order" => 40,
                "url" => [
                    "plugin" => "Officers",
                    "controller" => "Departments",
                    "action" => "index",
                    "model" => "Officers.Departments",
                ],
                "icon" => "bi-building",
                "activePaths" => [
                    "officers/departments/view/*",
                ]
            ],
            // Additional navigation items...
        ];
    }
}
```

Key aspects of the navigation provider:
1. It's a static class with a static `getNavigationItems()` method
2. It accepts the current user and parameters for context-sensitive navigation
3. It checks if the plugin is enabled before returning items
4. It returns an array of navigation items with properties:
   - `type`: Usually "link" for navigation links
   - `mergePath`: Where in the navigation hierarchy this item belongs
   - `label`: Display text
   - `order`: Sort order within its section
   - `url`: CakePHP URL array
   - `icon`: Bootstrap icon class
   - `activePaths`: URL patterns that should highlight this item when active

### Registration in Plugin Bootstrap

Register the navigation provider in your plugin's `bootstrap()` method:

```php
public function bootstrap(PluginApplicationInterface $app): void
{
    parent::bootstrap($app);
    
    // Register navigation provider
    \App\Services\NavigationRegistry::register(
        'Officers',                                                      // Source identifier
        [],                                                             // Static navigation items (optional)
        [\Officers\Services\OfficersNavigationProvider::class, 'getNavigationItems'] // Dynamic callback
    );
}
```

The `NavigationRegistry::register()` method accepts:
- **Source**: A unique identifier for the navigation source (typically the plugin name)
- **Static Items**: An array of navigation items that don't require dynamic generation
- **Callback**: A callable that generates dynamic navigation items

### Event System for UI Components

The `CallForCellsHandler` allows a plugin to register view cells that appear in various parts of the application. For example, the Officers plugin registers cells that display officer information on branch pages.

Here's how the Officers plugin implements this handler:

```php
namespace Officers\Event;

use Cake\Event\EventListenerInterface;
use App\Event\CallForCellsHandlerBase;

class CallForCellsHandler extends CallForCellsHandlerBase
{
    protected string $pluginName = 'Officers';
    protected array $viewsToTest = [
        "\Officers\View\Cell\BranchOfficersCell",
        "\Officers\View\Cell\BranchRequiredOfficersCell",
        "\Officers\View\Cell\MemberOfficersCell",
    ];
}
```

This handler extends the `CallForCellsHandlerBase` class and specifies:
1. The plugin name (`Officers`)
2. An array of cell classes that the plugin wants to register

The `BranchOfficersCell` implements details about where and how the cell should appear:

```php
class BranchOfficersCell extends BasePluginCell
{
    static protected array $validRoutes = [
        ['controller' => 'Branches', 'action' => 'view', 'plugin' => null],
    ];
    static protected array $pluginData = [
        'type' => BasePluginCell::PLUGIN_TYPE_TAB,
        'label' => 'Officers',
        'id' => 'branch-officers',
        'order' => 1,
        'tabBtnBadge' => null,
        'cell' => 'Officers.BranchOfficers'
    ];
    
    public static function getViewConfigForRoute($route, $currentUser)
    {
        return parent::getRouteEventResponse($route, self::$pluginData, self::$validRoutes);
    }
    
    // Cell implementation...
}
```

Key attributes:
- `$validRoutes`: Defines which routes this cell should appear on (in this case, the Branch view page)
- `$pluginData`: Configures how the cell appears, including:
  - `type`: Whether it's a tab, panel, or other UI element
  - `label`: The display name
  - `id`: HTML element ID
  - `order`: Display order among other cells
  - `cell`: Cell reference for rendering

The cell then implements its `display()` method to fetch and display the relevant data.

### Navigation Registration with CallForNavHandler (Deprecated)


> **⚠️ DEPRECATED**: The `CallForNavHandler` event-based navigation system has been replaced with the NavigationProvider service-based system for better performance and maintainability. All new plugins must use the NavigationProvider pattern. See the "Navigation Registration with NavigationProvider" section above for the current implementation approach.

For legacy reference, the old event-based navigation system worked as follows:

```php
namespace Officers\Event;

use App\KMP\StaticHelpers;
use Cake\Event\EventListenerInterface;

class CallForNavHandler implements EventListenerInterface
{
    public function implementedEvents(): array
    {
        return [
            \App\View\Cell\NavigationCell::VIEW_CALL_EVENT => 'callForNav',
        ];
    }

    public function callForNav($event)
    {
        if (StaticHelpers::pluginEnabled('Officers') == false) {
            return null;
        }
        
        $user = $event->getData('user');
        $results = [];
        if ($event->getResult() && is_array($event->getResult())) {
            $results = $event->getResult();
        }
        
        $appNav = [
            [
                "type" => "link",
                "mergePath" => ["Reports"],
                "label" => "Officers",
                "order" => 29,
                "url" => [
                    "plugin" => "Officers",
                    "controller" => "Officers",
                    "action" => "index",
                ],
                "icon" => "bi-building",
                "activePaths" => [
                    "officers/Officers/view/*",
                ]
            ],
            [
                "type" => "link",
                "mergePath" => ["Config"],
                "label" => "Departments",
                "order" => 40,
                "url" => [
                    "plugin" => "Officers",
                    "controller" => "Departments",
                    "action" => "index",
                ],
                "icon" => "bi-building",
                "activePaths" => [
                    "officers/departments/view/*",
                ]
            },
            // Additional navigation items...
        ];

        $results = array_merge($results, $appNav);
        return $results;
    }
}
```

Key aspects of the navigation handler:
1. It implements `EventListenerInterface` and listens for the `NavigationCell::VIEW_CALL_EVENT`
2. It checks if the plugin is enabled before proceeding
3. It defines an array of navigation items with properties:
   - `type`: Usually "link" for navigation links
   - `mergePath`: Where in the navigation hierarchy this item belongs
   - `label`: Display text
   - `order`: Sort order within its section
   - `url`: CakePHP URL array
   - `icon`: Bootstrap icon class
   - `activePaths`: URL patterns that should highlight this item when active

The navigation items are merged with existing items and returned as the event result.

## 11.2.1 Migration Guide: From CallForNavHandler to NavigationProvider

If you have an existing plugin using the deprecated `CallForNavHandler` system, follow these steps to migrate to the new NavigationProvider system:

### Step 1: Create NavigationProvider Class

Create a new service class in your plugin's `Services` directory:

```php
namespace YourPlugin\Services;

use App\KMP\StaticHelpers;

class YourPluginNavigationProvider
{
    public static function getNavigationItems($user, array $params = []): array
    {
        if (StaticHelpers::pluginEnabled('YourPlugin') == false) {
            return [];
        }
        
        // Move your navigation items array from the old callForNav method here
        return [
            // Your navigation items...
        ];
    }
}
```

### Step 2: Update Plugin Bootstrap

Replace the event handler registration with NavigationRegistry registration:

```php
// OLD - Remove this:
$eventManager->on(new \YourPlugin\Event\CallForNavHandler());

// NEW - Add this:
\App\Services\NavigationRegistry::register(
    'YourPlugin',
    [],
    [\YourPlugin\Services\YourPluginNavigationProvider::class, 'getNavigationItems']
);
```

### Step 3: Remove Old Event Handler (Optional)

You can now safely delete the old `CallForNavHandler.php` file from your plugin's `Event` directory, though you may want to keep it temporarily for backward compatibility.

### Benefits of Migration

- **Performance**: Eliminates event overhead on every navigation render
- **Maintainability**: Cleaner, more explicit registration pattern
- **Debugging**: Better visibility into navigation sources through the registry
- **Flexibility**: Support for both static and dynamic navigation items

## 11.3 Creating UI Components with Cells

View cells are a key way to extend the KMP interface. The Officers plugin demonstrates how to create cells that can be integrated into existing pages.

### Cell Implementation

The `BranchOfficersCell` from the Officers plugin is a good example:

```php
class BranchOfficersCell extends BasePluginCell
{
    // Cell configuration...
    
    public function display($id)
    {
        // Fetch the branch
        $branch = $this->fetchTable("Branches")
            ->find()->select(['id', 'parent_id', 'type', 'domain'])
            ->where(['id' => $id])->first();
            
        // Fetch relevant offices
        $officesTbl = $this->fetchTable("Officers.Offices");
        $officeQuery = $officesTbl->find("all")
            ->contain(["Departments"])
            ->select(["id", "Offices.name", "deputy_to_id", "reports_to_id", 
                       "applicable_branch_types", "default_contact_address"])
            ->orderBY(["Offices.name" => "ASC"]);
            
        // Filter offices by branch type
        $officeSet = $officeQuery
            ->where(['applicable_branch_types like' => '%"' . $branch->type . '"%'])
            ->toArray();
            
        // Check user permissions
        $user = $this->request->getAttribute("identity");
        // Permission checks...
        
        // Build office tree structure
        $offices = $this->buildOfficeTree($officeSet, $branch, $hireAll, 
                                          $myOffices, $canHireOffices, null);
                                          
        // Pass data to the template
        $this->set(compact('id', 'offices', 'newOfficer'));
    }
    
    // Helper methods...
}
```

Each cell typically:
1. Fetches and processes necessary data
2. Checks permissions
3. Prepares data for the template
4. Passes data to the template using `$this->set()`

### Cell Templates

The cell template renders the UI component. For example, the Officers plugin might have a template at `templates/cell/BranchOfficers/display.php` that renders the officers for a branch.

## 11.4 Database Models

Plugins often need their own database tables. The Officers plugin includes models for offices, departments, and officer assignments.

### Table Classes

Create table classes for your plugin's database tables:

```php
namespace Officers\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class OfficesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        
        $this->setTable('offices');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        
        $this->belongsTo('Departments', [
            'foreignKey' => 'department_id',
            'className' => 'Officers.Departments',
        ]);
        
        $this->hasMany('Officers', [
            'foreignKey' => 'office_id',
            'className' => 'Officers.Officers',
        ]);
    }
    
    // Validation, finder methods, etc.
}
```

### Entity Classes

Entity classes represent individual database records:

```php
namespace Officers\Model\Entity;

use Cake\ORM\Entity;

class Office extends Entity
{
    protected array $_accessible = [
        'name' => true,
        'description' => true,
        'department_id' => true,
        'deputy_to_id' => true,
        'reports_to_id' => true,
        'applicable_branch_types' => true,
        'default_contact_address' => true,
        'department' => true,
        'officers' => true,
    ];
}
```

### Database Migrations

Create migrations for your plugin's database tables:

```php
use Migrations\AbstractMigration;

class CreateOfficersTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('officers');
        $table->addColumn('member_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('office_id', 'integer', [
            'null' => false,
        ]);
        $table->addColumn('start_date', 'date', [
            'null' => false,
        ]);
        $table->addColumn('end_date', 'date', [
            'null' => true,
        ]);
        $table->addColumn('active', 'boolean', [
            'default' => true,
        ]);
        $table->addColumn('created', 'datetime');
        $table->addColumn('modified', 'datetime');
        $table->addForeignKey('member_id', 'members', 'id');
        $table->addForeignKey('office_id', 'offices', 'id');
        $table->create();
    }
}
```

## 11.5 Best Practices for Plugin Development

Based on the Officers plugin example, here are some best practices for developing KMP plugins:

1. **Use Event Handlers**: Implement `CallForCellsHandler` to integrate with the core UI for cells
2. **Use NavigationProvider**: Create a NavigationProvider service for adding navigation items
3. **Extend Base Classes**: Use base classes like `BasePluginCell` to ensure consistent behavior
3. **Follow Naming Conventions**: Use consistent naming for controllers, models, and templates
4. **Check Permissions**: Always check user permissions before displaying sensitive data or actions
5. **Modular Design**: Keep plugin functionality self-contained but integrated through events
6. **Test Thoroughly**: Include comprehensive tests for your plugin functionality

### Plugin Integration Checklist

When developing a new plugin:

1. [ ] Create the basic plugin structure
2. [ ] Implement the main Plugin class
3. [ ] Add database migrations if needed
4. [ ] Create model and entity classes
5. [ ] Implement controllers and templates
6. [ ] Create cell classes for UI integration
7. [ ] Implement CallForCellsHandler to register cells
8. [ ] Create NavigationProvider service to add navigation items
9. [ ] Register the plugin in config/plugins.php
10. [ ] Test plugin functionality thoroughly

## 11.6 Managing Plugin Configuration with AppSettings

Plugins often need their own configuration settings that administrators can modify. KMP's AppSettings system provides a centralized way to manage application settings, and plugins can leverage this system to store and retrieve their configuration.

### Registering Default AppSettings

The Officers plugin demonstrates a robust approach to registering and maintaining default AppSettings during the plugin's bootstrap process. This ensures that necessary configuration values exist when the plugin is first installed or updated, and handles versioning to track configuration changes.

Here's how the Officers plugin implements this in its `Plugin.php` file:

```php
public function bootstrap(PluginApplicationInterface $app): void
{
    // Register event listeners for cells
    $handler = new CallForCellsHandler();
    EventManager::instance()->on($handler);

    // Register navigation provider
    \App\Services\NavigationRegistry::register(
        'Officers',
        [],
        [\Officers\Services\OfficersNavigationProvider::class, 'getNavigationItems']
    );

    $currentConfigVersion = "25.01.11.a"; // update this each time you change the config

    // Use a consistent key: Plugin.{PluginName}.configVersion
    $configVersion = StaticHelpers::getAppSetting("Plugin.Officers.configVersion", "0.0.0", null, true);
    if ($configVersion != $currentConfigVersion) {
        StaticHelpers::setAppSetting("Plugin.Officers.configVersion", $currentConfigVersion, null, true);
        StaticHelpers::getAppSetting("Plugin.Officers.NextStatusCheck", DateTime::now()->subDays(1)->toDateString(), null, true);
        StaticHelpers::getAppSetting("Plugin.Officers.Active", "yes", null, true);
    }
}
```

This approach offers several advantages:

1. **Version Tracking**: Using a configuration version (`Officer.configVersion`) allows the plugin to detect when its settings structure has been updated
2. **Update Management**: Settings are only initialized or updated when the configuration version changes, preventing unnecessary database operations
3. **Default Values**: Each setting is created with a sensible default value
4. **Systematic Updates**: When introducing new settings, you simply update the version number and add the new settings to the version check block

The `StaticHelpers::getAppSetting()` method is used with these parameters:
- First parameter: The setting key (e.g., "Officer.configVersion")
- Second parameter: Default value if the setting doesn't exist
- Third parameter: Description (null in this case but can be used for documentation)
- Fourth parameter: Create if missing (true to ensure the setting exists)

### Structure of AppSettings for Plugins


When creating AppSettings for your plugin, follow these conventions:

1. **Namespacing**: Prefix all settings with `Plugin.{PluginName}.` (e.g., `Plugin.Officers.Setting`)
2. **Categorization**: Group related settings together with similar prefixes
3. **Documentation**: Include a clear description as the third parameter of `setAppSettingDefault`
4. **Types**: Use appropriate types for values ('yes'/'no' for booleans, numeric strings for numbers)

### Accessing Plugin Settings

Once registered, your plugin can access these settings throughout its code:

```php
// Check if the plugin is enabled
if (StaticHelpers::getAppSetting('Officers.Enabled') !== 'yes') {
    return;
}

// Get a numeric setting and convert to integer
$termLength = (int)StaticHelpers::getAppSetting('Officers.DefaultTermLength', '12');

// Get a boolean setting
$sendNotifications = StaticHelpers::getAppSetting('Officers.SendNotifications') === 'yes';
```

The `getAppSetting` method accepts a default value as the second parameter, which is returned if the setting doesn't exist.

### Providing a Settings UI

For important plugin settings, you may want to provide a UI that allows administrators to modify these values. The Officers plugin includes a settings controller that displays and updates its AppSettings:

```php
namespace Officers\Controller;

use App\Controller\AppController;
use App\KMP\StaticHelpers;

class SettingsController extends AppController
{
    public function index()
    {
        $this->Authorization->authorize($this);
        
        if ($this->request->is('post')) {
            $settings = $this->request->getData();
            
            // Update each setting
            foreach ($settings as $key => $value) {
                if (strpos($key, 'Officers.') === 0) {
                    StaticHelpers::setAppSetting($key, $value);
                }
            }
            
            $this->Flash->success('Settings updated successfully.');
            return $this->redirect(['action' => 'index']);
        }
        
        // Load current settings
        $settings = [
            'Officers.Enabled' => StaticHelpers::getAppSetting('Officers.Enabled'),
            'Officers.DefaultTermLength' => StaticHelpers::getAppSetting('Officers.DefaultTermLength'),
            'Officers.RequireHeralds' => StaticHelpers::getAppSetting('Officers.RequireHeralds'),
            'Officers.RequireSeneschals' => StaticHelpers::getAppSetting('Officers.RequireSeneschals'),
            'Officers.SendNotifications' => StaticHelpers::getAppSetting('Officers.SendNotifications'),
            'Officers.NotifyDaysBeforeExpiration' => StaticHelpers::getAppSetting('Officers.NotifyDaysBeforeExpiration'),
        ];
        
        $this->set(compact('settings'));
    }
}
```

With a corresponding template that renders form fields for each setting.


### Checking Plugin Enabled Status

A common pattern is to check if the plugin is enabled before performing operations:

```php
public static function getNavigationItems($user, array $params = []): array
{
    // Early return if plugin is disabled
    if (StaticHelpers::pluginEnabled('Officers') == false) {
        return [];
    }
    // Continue with navigation building...
}
```

The `pluginEnabled` helper method checks for a setting with the pattern `Plugin.{PluginName}.Active` and returns true if it's set to 'yes'.


### Best Practices for Plugin AppSettings

1. **Register Early**: Set defaults during bootstrap to ensure settings exist
2. **Use Defaults Wisely**: Provide sensible defaults that work for most installations
3. **Clear Documentation**: Document each setting's purpose and valid values
4. **Respect Settings**: Always check relevant settings before performing operations
5. **Graceful Fallbacks**: Handle cases where settings might be missing or invalid
6. **User-Friendly UI**: Provide an admin interface for important settings

### Directory Path Conventions

- For main app JavaScript: use `app/assets/js/`
- For plugin JavaScript: use `plugins/PluginName/assets/js/`
- For main app controllers: use `app/assets/js/controllers/`
- For plugin controllers: use `plugins/PluginName/assets/js/controllers/`