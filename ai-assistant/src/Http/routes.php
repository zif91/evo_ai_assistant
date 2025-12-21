<?php

use EvolutionCMS\AiAssistant\Controllers\ApiController;
use EvolutionCMS\AiAssistant\Controllers\PanelController;
use EvolutionCMS\AiAssistant\Http\Middleware\AiAssistantAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI Assistant Routes
|--------------------------------------------------------------------------
|
| Routes for the AI Assistant package
|
*/

Route::prefix('ai-assistant')->middleware([AiAssistantAuth::class])->group(function () {

    // Panel routes (returns HTML for the sidebar)
    Route::get('/panel', [PanelController::class, 'index'])->name('ai-assistant.panel');

    // API routes
    Route::prefix('api')->group(function () {

        // Chat endpoint
        Route::post('/chat', [ApiController::class, 'chat'])->name('ai-assistant.api.chat');

        // Execute AI action
        Route::post('/execute', [ApiController::class, 'execute'])->name('ai-assistant.api.execute');

        // Resources
        Route::prefix('resources')->group(function () {
            Route::get('/search', [ApiController::class, 'searchResources'])->name('ai-assistant.api.resources.search');
            Route::get('/tree', [ApiController::class, 'getResourceTree'])->name('ai-assistant.api.resources.tree');
            Route::get('/{id}', [ApiController::class, 'getResource'])->name('ai-assistant.api.resources.get');
            Route::put('/{id}', [ApiController::class, 'updateResource'])->name('ai-assistant.api.resources.update');
            Route::post('/{id}/publish', [ApiController::class, 'publishResource'])->name('ai-assistant.api.resources.publish');
            Route::post('/{id}/unpublish', [ApiController::class, 'unpublishResource'])->name('ai-assistant.api.resources.unpublish');
            Route::get('/{id}/tv', [ApiController::class, 'getResourceTv'])->name('ai-assistant.api.resources.tv');
            Route::put('/{id}/tv', [ApiController::class, 'updateResourceTv'])->name('ai-assistant.api.resources.tv.update');
        });

        // TV (Template Variables)
        Route::prefix('tv')->group(function () {
            Route::get('/', [ApiController::class, 'listTv'])->name('ai-assistant.api.tv.list');
            Route::post('/', [ApiController::class, 'createTv'])->name('ai-assistant.api.tv.create');
            Route::get('/{id}', [ApiController::class, 'getTv'])->name('ai-assistant.api.tv.get');
            Route::put('/{id}', [ApiController::class, 'updateTv'])->name('ai-assistant.api.tv.update');
            Route::post('/{id}/bind', [ApiController::class, 'bindTvToTemplates'])->name('ai-assistant.api.tv.bind');
        });

        // Templates
        Route::prefix('templates')->group(function () {
            Route::get('/', [ApiController::class, 'listTemplates'])->name('ai-assistant.api.templates.list');
            Route::get('/{id}', [ApiController::class, 'getTemplate'])->name('ai-assistant.api.templates.get');
            Route::put('/{id}', [ApiController::class, 'updateTemplate'])->name('ai-assistant.api.templates.update');
            Route::put('/{id}/blade', [ApiController::class, 'updateBladeTemplate'])->name('ai-assistant.api.templates.blade.update');
        });

        // SEO
        Route::prefix('seo')->group(function () {
            Route::get('/analyze/{id}', [ApiController::class, 'analyzeSeo'])->name('ai-assistant.api.seo.analyze');
            Route::post('/optimize/{id}', [ApiController::class, 'optimizeSeo'])->name('ai-assistant.api.seo.optimize');
            Route::post('/suggestions/{id}', [ApiController::class, 'getSeoSuggestions'])->name('ai-assistant.api.seo.suggestions');
        });

        // Checkpoints
        Route::prefix('checkpoints')->group(function () {
            Route::get('/', [ApiController::class, 'listCheckpoints'])->name('ai-assistant.api.checkpoints.list');
            Route::post('/{id}/rollback', [ApiController::class, 'rollbackCheckpoint'])->name('ai-assistant.api.checkpoints.rollback');
            Route::post('/rollback-session', [ApiController::class, 'rollbackSession'])->name('ai-assistant.api.checkpoints.rollback-session');
        });

        // Conversation history
        Route::get('/history', [ApiController::class, 'getHistory'])->name('ai-assistant.api.history');
        Route::delete('/history', [ApiController::class, 'clearHistory'])->name('ai-assistant.api.history.clear');

        // Settings
        Route::get('/settings', [ApiController::class, 'getSettings'])->name('ai-assistant.api.settings');
        Route::put('/settings', [ApiController::class, 'updateSettings'])->name('ai-assistant.api.settings.update');

        // Status check
        Route::get('/status', [ApiController::class, 'status'])->name('ai-assistant.api.status');
    });
});
