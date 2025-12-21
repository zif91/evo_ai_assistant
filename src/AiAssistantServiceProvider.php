<?php

namespace EvolutionCMS\AiAssistant;

use EvolutionCMS\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;

class AiAssistantServiceProvider extends ServiceProvider
{
    /**
     * Namespace for the package
     */
    protected string $namespace = 'AiAssistant';

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../views', 'ai-assistant');

        // Load translations
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'ai-assistant');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');

        // Publish assets
        $this->publishes([
            __DIR__ . '/../public' => public_path('assets/ai-assistant'),
            __DIR__ . '/../config/ai-assistant.php' => config_path('ai-assistant.php'),
        ], 'ai-assistant');

        // Auto-install on first run
        $this->autoInstall();
    }

    /**
     * Auto-install plugin and assets if not installed
     */
    protected function autoInstall(): void
    {
        try {
            // Check if plugin exists
            $pluginName = 'AI Assistant';
            $existingPlugin = DB::table('site_plugins')->where('name', $pluginName)->first();

            if (!$existingPlugin) {
                $this->installPlugin();
            }

            // Check if module exists
            $this->installModule();

            // Check if settings exist
            $this->installSettings();

            // Check and copy assets
            $this->copyAssets();

        } catch (\Exception $e) {
            // Silently fail if DB not ready (e.g., during install)
            \Log::debug('AI Assistant auto-install skipped: ' . $e->getMessage());
        }
    }

    /**
     * Install plugin to database
     */
    protected function installPlugin(): void
    {
        $pluginName = 'AI Assistant';
        $pluginFile = __DIR__ . '/../assets/plugins/ai_assistant.php';

        if (!file_exists($pluginFile)) {
            return;
        }

        $pluginCode = file_get_contents($pluginFile);

        // Create plugin
        $pluginId = DB::table('site_plugins')->insertGetId([
            'name' => $pluginName,
            'description' => 'AI Assistant for Evolution CMS - Shopify Sidekick-like assistant',
            'plugincode' => $pluginCode,
            'disabled' => 0,
            'moduleguid' => '',
            'createdon' => time(),
            'editedon' => time(),
        ]);

        // Bind to events
        $events = ['OnManagerFrameLoader', 'OnManagerTopPrerender'];

        foreach ($events as $eventName) {
            $event = DB::table('system_eventnames')->where('name', $eventName)->first();
            if ($event) {
                DB::table('site_plugin_events')->insert([
                    'pluginid' => $pluginId,
                    'evtid' => $event->id,
                    'priority' => 0,
                ]);
            }
        }

        \Log::info('AI Assistant plugin installed successfully');
    }

    /**
     * Install settings module
     */
    protected function installModule(): void
    {
        $moduleName = 'AI Assistant Settings';
        $existingModule = DB::table('site_modules')->where('name', $moduleName)->first();

        if ($existingModule) {
            return;
        }

        $moduleCode = 'include MODX_BASE_PATH . "core/custom/packages/ai-assistant/assets/modules/ai_assistant_settings.php";';

        DB::table('site_modules')->insert([
            'name' => $moduleName,
            'description' => 'Configure AI Assistant API settings',
            'modulecode' => $moduleCode,
            'disabled' => 0,
            'icon' => 'fa fa-robot',
            'createdon' => time(),
            'editedon' => time(),
        ]);

        \Log::info('AI Assistant settings module installed');
    }

    /**
     * Install system settings
     */
    protected function installSettings(): void
    {
        $settings = [
            'ai_assistant_provider' => 'openai',
            'ai_assistant_api_key' => '',
            'ai_assistant_api_url' => 'https://api.openai.com/v1',
            'ai_assistant_model' => 'gpt-4',
        ];

        foreach ($settings as $key => $value) {
            $exists = DB::table('system_settings')->where('setting_name', $key)->exists();
            if (!$exists) {
                DB::table('system_settings')->insert([
                    'setting_name' => $key,
                    'setting_value' => $value,
                ]);
            }
        }
    }

    /**
     * Copy assets to public directory
     */
    protected function copyAssets(): void
    {
        $sourceDir = __DIR__ . '/../public';
        $targetDir = public_path('assets/ai-assistant');

        // Skip if source doesn't exist
        if (!is_dir($sourceDir)) {
            return;
        }

        // Check if assets need to be copied (by comparing modification time)
        $cssSource = $sourceDir . '/css/sidebar.css';
        $cssTarget = $targetDir . '/css/sidebar.css';

        if (file_exists($cssTarget) && filemtime($cssTarget) >= filemtime($cssSource)) {
            return; // Assets are up to date
        }

        // Create directories
        if (!is_dir($targetDir . '/css')) {
            @mkdir($targetDir . '/css', 0755, true);
        }
        if (!is_dir($targetDir . '/js')) {
            @mkdir($targetDir . '/js', 0755, true);
        }

        // Copy CSS files
        foreach (glob($sourceDir . '/css/*.css') as $file) {
            @copy($file, $targetDir . '/css/' . basename($file));
        }

        // Copy JS files
        foreach (glob($sourceDir . '/js/*.js') as $file) {
            @copy($file, $targetDir . '/js/' . basename($file));
        }

        \Log::info('AI Assistant assets copied to ' . $targetDir);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(__DIR__ . '/../config/ai-assistant.php', 'ai-assistant');

        // Register services
        $this->app->singleton(Services\AiService::class, function ($app) {
            return new Services\AiService(config('ai-assistant'));
        });

        $this->app->singleton(Services\CheckpointService::class, function ($app) {
            return new Services\CheckpointService();
        });

        $this->app->singleton(Services\ResourceService::class, function ($app) {
            return new Services\ResourceService(
                $app->make(Services\CheckpointService::class)
            );
        });

        $this->app->singleton(Services\TvService::class, function ($app) {
            return new Services\TvService(
                $app->make(Services\CheckpointService::class)
            );
        });

        $this->app->singleton(Services\TemplateService::class, function ($app) {
            return new Services\TemplateService(
                $app->make(Services\CheckpointService::class)
            );
        });

        $this->app->singleton(Services\SeoService::class, function ($app) {
            return new Services\SeoService(
                $app->make(Services\AiService::class),
                $app->make(Services\ResourceService::class)
            );
        });
    }
}
