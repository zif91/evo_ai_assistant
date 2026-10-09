<?php

namespace EvolutionCMS\AiAssistant\Http\Middleware;

use Closure;
use EvolutionCMS\AiAssistant\Support\ManagerSecurity;
use Illuminate\Http\Request;

class AiAssistantAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (empty($_SESSION['mgrValidated']) || empty($_SESSION['mgrInternalKey'])) {
            return response()->json(['success' => false, 'error' => 'Log in to the manager.'], 401);
        }
        if (!ManagerSecurity::allowed()) {
            return response()->json(['success' => false, 'error' => 'Administrator access required.'], 403);
        }
        $method = $request->route() ? $request->route()->getActionMethod() : null;
        $action = match ($method) {
            'searchResources', 'getResourceTree', 'getResource', 'getResourceTv' => 'get_resource',
            'updateResource', 'updateResourceTv' => 'update_resource',
            'publishResource' => 'publish_resource', 'unpublishResource' => 'unpublish_resource',
            'listTv', 'getTv', 'createTv', 'updateTv', 'bindTvToTemplates' => 'list_tv',
            'listTemplates', 'getTemplate', 'updateTemplate', 'updateBladeTemplate' => 'list_templates',
            'analyzeSeo', 'optimizeSeo', 'getSeoSuggestions' => 'analyze_seo',
            'listCheckpoints', 'rollbackCheckpoint', 'rollbackSession' => 'list_checkpoints',
            default => null,
        };
        if ($action && !\EvolutionCMS\AiAssistant\Support\ActionPolicy::allowed($action, config('ai-assistant.actions', []))) {
            return response()->json(['success' => false, 'error' => 'Action disabled by configuration'], 403);
        }
        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && !ManagerSecurity::validToken($request->header('X-AI-CSRF-Token') ?? $request->input('_ai_token'))) {
            return response()->json(['success' => false, 'error' => 'CSRF token mismatch. Reload the panel.'], 403);
        }
        return $next($request)->header('Cache-Control', 'no-store');
    }
}
