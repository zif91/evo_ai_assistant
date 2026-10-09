<?php

namespace EvolutionCMS\AiAssistant;

use EvolutionCMS\ServiceProvider;

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
