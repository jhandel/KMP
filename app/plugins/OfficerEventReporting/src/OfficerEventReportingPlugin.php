<?php

declare(strict_types=1);

namespace OfficerEventReporting;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;
use App\KMP\KMPPluginInterface;
use App\Services\NavigationRegistry;
use OfficerEventReporting\Services\OfficerEventReportingNavigationProvider;
use App\KMP\StaticHelpers;
use Cake\I18n\DateTime;

/**
 * Plugin for Officer and Event Reporting
 */
class OfficerEventReportingPlugin extends BasePlugin implements KMPPluginInterface
{
    protected int $_migrationOrder = 0;
    
    public function getMigrationOrder(): int
    {
        return $this->_migrationOrder;
    }

    public function __construct($config = [])
    {
        if (!isset($config['migrationOrder'])) {
            $config['migrationOrder'] = 0;
        }
        $this->_migrationOrder = $config['migrationOrder'];
    }

    /**
     * Load all the plugin configuration and bootstrap logic.
     *
     * @param \Cake\Core\PluginApplicationInterface $app The host application
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        // Register navigation items
        NavigationRegistry::register(
            'OfficerEventReporting',
            [], // Static items (none for now)
            function ($user, $params) {
                return OfficerEventReportingNavigationProvider::getNavigationItems($user, $params);
            }
        );

        $currentConfigVersion = "25.07.13.a"; // update this each time you change the config

        $configVersion = StaticHelpers::getAppSetting("OfficerEventReporting.configVersion", "0.0.0", null, true);
        if ($configVersion != $currentConfigVersion) {
            StaticHelpers::setAppSetting("OfficerEventReporting.configVersion", $currentConfigVersion, null, true);
            StaticHelpers::getAppSetting("Plugin.OfficerEventReporting.Active", "yes", null, true);
            StaticHelpers::getAppSetting("OfficerEventReporting.MaxFileUploadSize", "10485760", null, true); // 10MB
            StaticHelpers::getAppSetting("OfficerEventReporting.AllowedFileTypes", "pdf,doc,docx,jpg,jpeg,png,gif", null, true);
        }
    }

    /**
     * Add routes for the plugin.
     *
     * @param \Cake\Routing\RouteBuilder $routes The route builder to update.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->plugin(
            'OfficerEventReporting',
            ['path' => '/officer-event-reporting'],
            function (RouteBuilder $builder) {
                // Custom routes
                $builder->connect('/forms', ['controller' => 'Forms', 'action' => 'index']);
                $builder->connect('/forms/create', ['controller' => 'Forms', 'action' => 'add']);
                $builder->connect('/forms/view/{id}', ['controller' => 'Forms', 'action' => 'view'])
                    ->setPass(['id'])
                    ->setPatterns(['id' => '\d+']);
                $builder->connect('/forms/edit/{id}', ['controller' => 'Forms', 'action' => 'edit'])
                    ->setPass(['id'])
                    ->setPatterns(['id' => '\d+']);
                $builder->connect('/forms/delete/{id}', ['controller' => 'Forms', 'action' => 'delete'])
                    ->setPass(['id'])
                    ->setPatterns(['id' => '\d+']);
                
                $builder->connect('/submissions', ['controller' => 'Submissions', 'action' => 'index']);
                $builder->connect('/submissions/submit/{form_id}', ['controller' => 'Submissions', 'action' => 'add'])
                    ->setPass(['form_id'])
                    ->setPatterns(['form_id' => '\d+']);
                $builder->connect('/submissions/view/{id}', ['controller' => 'Submissions', 'action' => 'view'])
                    ->setPass(['id'])
                    ->setPatterns(['id' => '\d+']);

                $builder->fallbacks();
            }
        );
        parent::routes($routes);
    }

    /**
     * Add middleware for the plugin.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to update.
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue;
    }

    /**
     * Add commands for the plugin.
     *
     * @param \Cake\Console\CommandCollection $commands The command collection to update.
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands = parent::console($commands);
        return $commands;
    }

    /**
     * Register application container services.
     *
     * @param \Cake\Core\ContainerInterface $container The Container to update.
     * @return void
     */
    public function services(ContainerInterface $container): void
    {
        // Add your services here if needed
    }
}