<?php

namespace EvolutionCMS\AiAssistant\Controllers;

use EvolutionCMS\AiAssistant\Models\Checkpoint;
use EvolutionCMS\AiAssistant\Models\Job;
use EvolutionCMS\AiAssistant\Services\JobService;
use EvolutionCMS\AiAssistant\Services\AiService;
use EvolutionCMS\AiAssistant\Services\CheckpointService;
use EvolutionCMS\AiAssistant\Services\ResourceService;
use EvolutionCMS\AiAssistant\Services\SeoService;
use EvolutionCMS\AiAssistant\Services\TemplateService;
use EvolutionCMS\AiAssistant\Services\TvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ApiController extends Controller
{
    protected $aiService = null;
    protected $resourceService = null;
    protected $tvService = null;
    protected $templateService = null;
    protected $seoService = null;
    protected $checkpointService = null;
    protected $initialized = false;

    /**
     * Lazy initialization of services
     */
    protected function initServices(): void
    {
        if ($this->initialized) {
            return;
        }

        $runtime = include __DIR__ . '/../../config/ai-assistant.php';
        $config = array_replace_recursive($runtime, config('ai-assistant', []));
        // Resolve manager settings after Evolution loads them, preserving custom
        // action flags, system prompt, iteration and token limits.
        $config['provider'] = $runtime['provider'];
        foreach ($runtime['providers'] as $name => $provider) {
            foreach (['api_key', 'model', 'endpoint'] as $key) {
                $config['providers'][$name][$key] = $provider[$key];
            }
        }

        $this->checkpointService = new CheckpointService();
        $this->aiService = new AiService($config);
        $this->resourceService = new ResourceService($this->checkpointService);
        $this->tvService = new TvService($this->checkpointService);
        $this->templateService = new TemplateService($this->checkpointService);
        $this->seoService = new SeoService($this->aiService, $this->resourceService);

        $this->initialized = true;
    }

    /**
     * Chat with AI
     */
    public function chat(Request $request): JsonResponse
    {
        $this->initServices();
        $message = $request->input('message', '');
        $context = $request->input('context', []);
        $requestId = $request->input('request_id', '');
        if (!is_string($message) || !trim($message) || strlen($message) > 32000 || !is_array($context)
            || !is_string($requestId) || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $requestId)) {
            return response()->json(['success' => false, 'error' => 'Invalid message, context or request ID.'], 422);
        }
        if (!$this->aiService->isConfigured()) {
            return response()->json(['success' => false, 'error' => 'AI Assistant is not configured'], 422);
        }
        $jobs = new JobService();
        $user = (int) $_SESSION['mgrInternalKey'];
        $history = $jobs->history($user);
        $this->aiService->setHistory($history ?: ($_SESSION['ai_assistant_history'] ?? []));
        $job = $jobs->enqueue($user, session_id(), $requestId, $message, $this->aiService->beginJob($message, $context));
        return response()->json(['success' => true, 'job' => $jobs->summary($job)], 202);
    }

    private function ownedJob(string $id): ?Job
    {
        return Job::where('user_id', (int) $_SESSION['mgrInternalKey'])->find($id);
    }

    public function activeJob(): JsonResponse
    {
        $jobs = new JobService();
        $job = $jobs->active((int) $_SESSION['mgrInternalKey']);
        return response()->json(['success' => true, 'job' => $job ? $jobs->summary($job) : null]);
    }

    public function getJob(string $id): JsonResponse
    {
        $job = $this->ownedJob($id);
        return $job ? response()->json(['success' => true, 'job' => (new JobService())->summary($job)])
            : response()->json(['success' => false, 'error' => 'Job not found'], 404);
    }

    public function stepJob(string $id): JsonResponse
    {
        $job = $this->ownedJob($id);
        if (!$job) return response()->json(['success' => false, 'error' => 'Job not found'], 404);
        if ($job->mode !== 'browser') return response()->json(['success' => false, 'error' => 'This job belongs to the worker'], 409);
        if (function_exists('set_time_limit')) {
            @set_time_limit(max(30, min(900, (int) config('ai-assistant.request_time_limit', 300))));
        }
        // Polling and other manager requests must not wait on this native session lock.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $job = $this->processJob($job);
        return response()->json(['success' => true, 'job' => (new JobService())->summary($job)]);
    }

    /** Trusted CLI entry; HTTP routes always enforce ownership above. */
    public function processJob(Job $job): Job
    {
        $this->initServices();
        $this->checkpointService->setJobSession($job->session_id);
        return (new JobService())->advance($job, $this->aiService,
            fn($name, $args) => $this->executeAction(['name' => $name, 'arguments' => $args]));
    }

    public function retryJob(string $id): JsonResponse
    {
        $job = $this->ownedJob($id);
        if (!$job) return response()->json(['success' => false, 'error' => 'Job not found'], 404);
        try {
            $jobs = new JobService();
            return response()->json(['success' => true, 'job' => $jobs->summary($jobs->retry($job))]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    public function cancelJob(string $id): JsonResponse
    {
        $job = $this->ownedJob($id);
        if (!$job) return response()->json(['success' => false, 'error' => 'Job not found'], 404);
        try {
            $jobs = new JobService();
            return response()->json(['success' => true, 'job' => $jobs->summary($jobs->cancel($job))]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 409);
        }
    }

    /**
     * Execute a specific action
     */
    public function execute(Request $request): JsonResponse
    {
        $this->initServices();
        $action = $request->input('action', '');
        $params = $request->input('params', []);

        if (empty($action)) {
            return response()->json([
                'success' => false,
                'error' => 'Action is required',
            ], 400);
        }

        $result = $this->executeAction([
            'name' => $action,
            'arguments' => $params,
        ]);

        return response()->json($result);
    }

    /**
     * Execute an action from AI response
     */
    protected function executeAction(array $action): array
    {
        $name = $action['name'] ?? '';
        $args = $action['arguments'] ?? [];

        if (!\EvolutionCMS\AiAssistant\Support\ActionPolicy::allowed($name, config('ai-assistant.actions', []))) {
            return ['action' => $name, 'success' => false, 'error' => 'Action disabled by configuration'];
        }
        try {
            $result = match ($name) {
                'search_resources' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->resourceService->search($args)->toArray(),
                ],
                'get_resource' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->resourceService->get($args['id'] ?? 0),
                ],
                'update_resource' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->resourceService->update($args['id'] ?? 0, $args)?->toArray(),
                ],
                'update_tv' => [
                    'action' => $name,
                    'success' => $this->resourceService->updateTv(
                        $args['resource_id'] ?? 0,
                        $args['tv_name'] ?? '',
                        $args['value'] ?? ''
                    ),
                ],
                'publish_resource' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->resourceService->publish($args['id'] ?? 0)?->toArray(),
                ],
                'unpublish_resource' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->resourceService->unpublish($args['id'] ?? 0)?->toArray(),
                ],
                'create_resource' => $this->handleCreateResource($args),
                'create_tv' => $this->handleCreateTv($args),
                'optimize_seo' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->seoService->generateSuggestions(
                        $args['resource_id'] ?? 0,
                        $args['focus_keyword'] ?? null
                    ),
                ],
                'rollback_checkpoint' => [
                    'action' => $name,
                    'success' => $this->rollbackCheckpointById($args['checkpoint_id'] ?? 0),
                ],
                'list_checkpoints' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->getCheckpointsList($args),
                ],
                'get_resource_tv' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->resourceService->getTvValues($args['resource_id'] ?? 0),
                ],
                'list_templates' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->templateService->getAll()->toArray(),
                ],
                'get_template' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->templateService->get($args['id'] ?? 0),
                ],
                'list_tv' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->tvService->getAll()->toArray(),
                ],
                'bind_tv_to_templates' => [
                    'action' => $name,
                    'success' => $this->tvService->bindToTemplates($args['tv_id'] ?? 0, $args['template_ids'] ?? []),
                ],
                'analyze_seo' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->seoService->analyze($args['resource_id'] ?? 0),
                ],
                'update_template' => [
                    'action' => $name,
                    'success' => true,
                    'data' => $this->templateService->update($args['id'] ?? 0, $args)?->toArray(),
                ],
                'update_blade_template' => [
                    'action' => $name,
                    'success' => $this->templateService->updateBladeTemplate($args['id'] ?? 0, $args['content'] ?? ''),
                ],
                'create_template' => $this->handleCreateTemplate($args),
                default => [
                    'action' => $name,
                    'success' => false,
                    'error' => "Unknown action: {$name}",
                ],
            };

            if (array_key_exists('data', $result) && $result['data'] === null) {
                $result['success'] = false;
                $result['error'] = 'Entity not found or operation failed';
            }
            return $result;
        } catch (\Throwable $e) {
            return [
                'action' => $name,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ========== Resources ==========

    /**
     * Search resources
     */
    public function searchResources(Request $request): JsonResponse
    {
        $this->initServices();
        $criteria = $request->only(['keyword', 'id', 'parent', 'template', 'published', 'deleted', 'limit', 'orderBy', 'orderDir']);

        return response()->json([
            'success' => true,
            'data' => $this->resourceService->search($criteria)->toArray(),
        ]);
    }

    /**
     * Get resource tree
     */
    public function getResourceTree(Request $request): JsonResponse
    {
        $this->initServices();
        $parentId = (int) $request->input('parent', 0);
        $depth = (int) $request->input('depth', 2);

        return response()->json([
            'success' => true,
            'data' => $this->resourceService->getTree($parentId, $depth),
        ]);
    }

    /**
     * Get single resource
     */
    public function getResource(int $id): JsonResponse
    {
        $this->initServices();
        $resource = $this->resourceService->get($id);

        if (!$resource) {
            return response()->json([
                'success' => false,
                'error' => 'Resource not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $resource,
        ]);
    }

    /**
     * Update resource
     */
    public function updateResource(Request $request, int $id): JsonResponse
    {
        $this->initServices();
        if (!evo()->hasPermission('save_document', 'mgr')) {
            return response()->json([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $resource = $this->resourceService->update($id, $request->all());

        if (!$resource) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update resource',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $resource->toArray(),
        ]);
    }

    /**
     * Publish resource
     */
    public function publishResource(int $id): JsonResponse
    {
        $this->initServices();
        if (!evo()->hasPermission('publish_document', 'mgr')) {
            return response()->json([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $resource = $this->resourceService->publish($id);

        if (!$resource) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to publish resource',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $resource->toArray(),
        ]);
    }

    /**
     * Unpublish resource
     */
    public function unpublishResource(int $id): JsonResponse
    {
        $this->initServices();
        if (!evo()->hasPermission('publish_document', 'mgr')) {
            return response()->json([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $resource = $this->resourceService->unpublish($id);

        if (!$resource) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to unpublish resource',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $resource->toArray(),
        ]);
    }

    /**
     * Get resource TV values
     */
    public function getResourceTv(int $id): JsonResponse
    {
        $this->initServices();
        return response()->json([
            'success' => true,
            'data' => $this->resourceService->getTvValues($id),
        ]);
    }

    /**
     * Update resource TV values
     */
    public function updateResourceTv(Request $request, int $id): JsonResponse
    {
        $this->initServices();
        if (!evo()->hasPermission('save_document', 'mgr')) {
            return response()->json([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $tvData = $request->input('tv', []);
        $results = $this->resourceService->updateTvs($id, $tvData);

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    // ========== TV ==========

    /**
     * List all TVs
     */
    public function listTv(): JsonResponse
    {
        $this->initServices();
        return response()->json([
            'success' => true,
            'data' => $this->tvService->getAll()->toArray(),
        ]);
    }

    /**
     * Create TV
     */
    public function createTv(Request $request): JsonResponse
    {
        $this->initServices();
        if (!evo()->hasPermission('new_template', 'mgr') && !evo()->hasPermission('edit_template', 'mgr')) {
            return response()->json([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $tv = $this->tvService->create($request->all());

        if (!$tv) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create TV',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $tv->toArray(),
        ]);
    }

    /**
     * Get TV
     */
    public function getTv(int $id): JsonResponse
    {
        $this->initServices();
        $tv = $this->tvService->get($id);

        if (!$tv) {
            return response()->json([
                'success' => false,
                'error' => 'TV not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $tv->toArray(),
        ]);
    }

    /**
     * Update TV
     */
    public function updateTv(Request $request, int $id): JsonResponse
    {
        $this->initServices();
        if (!evo()->hasPermission('edit_template', 'mgr')) {
            return response()->json([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $tv = $this->tvService->update($id, $request->all());

        if (!$tv) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update TV',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $tv->toArray(),
        ]);
    }

    /**
     * Bind TV to templates
     */
    public function bindTvToTemplates(Request $request, int $id): JsonResponse
    {
        $this->initServices();
        if (!evo()->hasPermission('edit_template', 'mgr')) {
            return response()->json([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $templateIds = $request->input('templates', []);
        $success = $this->tvService->bindToTemplates($id, $templateIds);

        return response()->json([
            'success' => $success,
        ]);
    }

    // ========== Templates ==========

    /**
     * List templates
     */
    public function listTemplates(): JsonResponse
    {
        $this->initServices();
        return response()->json([
            'success' => true,
            'data' => $this->templateService->getAll()->toArray(),
        ]);
    }

    /**
     * Get template
     */
    public function getTemplate(int $id): JsonResponse
    {
        $this->initServices();
        $template = $this->templateService->get($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'error' => 'Template not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $template,
        ]);
    }

    /**
     * Update template (inline content)
     */
    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $this->initServices();
        if (!evo()->hasPermission('save_template', 'mgr')) {
            return response()->json([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $template = $this->templateService->update($id, $request->all());

        if (!$template) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update template',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $template->toArray(),
        ]);
    }

    /**
     * Update Blade template file
     */
    public function updateBladeTemplate(Request $request, int $id): JsonResponse
    {
        $this->initServices();
        if (!evo()->hasPermission('save_template', 'mgr')) {
            return response()->json([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $content = $request->input('content', '');
        $success = $this->templateService->updateBladeTemplate($id, $content);

        return response()->json([
            'success' => $success,
        ]);
    }

    // ========== SEO ==========

    /**
     * Analyze SEO
     */
    public function analyzeSeo(int $id): JsonResponse
    {
        $this->initServices();
        return response()->json([
            'success' => true,
            'data' => $this->seoService->analyze($id),
        ]);
    }

    /**
     * Optimize SEO
     */
    public function optimizeSeo(Request $request, int $id): JsonResponse
    {
        $this->initServices();
        if (!evo()->hasPermission('save_document', 'mgr')) {
            return response()->json([
                'success' => false,
                'error' => 'Permission denied',
            ], 403);
        }

        $optimizations = $request->all();

        return response()->json([
            'success' => true,
            'data' => $this->seoService->optimize($id, $optimizations),
        ]);
    }

    /**
     * Get SEO suggestions
     */
    public function getSeoSuggestions(Request $request, int $id): JsonResponse
    {
        $this->initServices();
        $focusKeyword = $request->input('focus_keyword');

        return response()->json([
            'success' => true,
            'data' => $this->seoService->generateSuggestions($id, $focusKeyword),
        ]);
    }

    // ========== Checkpoints ==========

    /**
     * List checkpoints
     */
    public function listCheckpoints(Request $request): JsonResponse
    {
        $this->initServices();
        $entityType = $request->input('entity_type');
        $entityId = $request->input('entity_id');

        if ($entityType && $entityId) {
            $checkpoints = $this->checkpointService->getEntityCheckpoints($entityType, $entityId);
        } else {
            $checkpoints = $this->checkpointService->getSessionCheckpoints();
        }

        return response()->json([
            'success' => true,
            'data' => $checkpoints->map(function ($cp) {
                return [
                    'id' => $cp->id,
                    'entity_type' => $cp->entity_type,
                    'entity_id' => $cp->entity_id,
                    'field_name' => $cp->field_name,
                    'description' => $cp->getDescription(),
                    'created_at' => $cp->created_at?->toDateTimeString(),
                ];
            })->toArray(),
        ]);
    }

    /**
     * Rollback checkpoint
     */
    public function rollbackCheckpoint(int $id): JsonResponse
    {
        $this->initServices();
        $checkpoint = Checkpoint::find($id);

        if (!$checkpoint) {
            return response()->json([
                'success' => false,
                'error' => 'Checkpoint not found',
            ], 404);
        }

        $success = $this->checkpointService->rollback($checkpoint);

        return response()->json([
            'success' => $success,
        ]);
    }

    /**
     * Rollback all session checkpoints
     */
    public function rollbackSession(): JsonResponse
    {
        $this->initServices();
        $count = $this->checkpointService->rollbackSession(session_id());

        return response()->json([
            'success' => true,
            'rolled_back' => $count,
        ]);
    }

    /**
     * Helper to rollback checkpoint by ID
     */
    protected function rollbackCheckpointById(int $id): bool
    {
        $checkpoint = Checkpoint::find($id);
        return $checkpoint ? $this->checkpointService->rollback($checkpoint) : false;
    }

    /**
     * Helper to get checkpoints list
     */
    protected function getCheckpointsList(array $filters): array
    {
        if (!empty($filters['entity_type']) && !empty($filters['entity_id'])) {
            $checkpoints = $this->checkpointService->getEntityCheckpoints(
                $filters['entity_type'],
                $filters['entity_id']
            );
        } else {
            $checkpoints = $this->checkpointService->getSessionCheckpoints();
        }

        return $checkpoints->map(function ($cp) {
            return [
                'id' => $cp->id,
                'entity_type' => $cp->entity_type,
                'entity_id' => $cp->entity_id,
                'description' => $cp->getDescription(),
                'created_at' => $cp->created_at?->toDateTimeString(),
            ];
        })->toArray();
    }

    // ========== Other ==========

    /**
     * Get conversation history
     */
    public function getHistory(): JsonResponse
    {
        $this->initServices();
        return response()->json([
            'success' => true,
            'data' => (new JobService())->history((int) $_SESSION['mgrInternalKey']) ?: ($_SESSION['ai_assistant_history'] ?? []),
        ]);
    }

    /**
     * Clear conversation history
     */
    public function clearHistory(): JsonResponse
    {
        $this->initServices();
        $jobs = new JobService();
        if ($jobs->active((int) $_SESSION['mgrInternalKey'])) {
            return response()->json(['success' => false, 'error' => 'Stop or finish the active job first'], 409);
        }
        Job::where('user_id', (int) $_SESSION['mgrInternalKey'])->update(['hidden' => true]);
        unset($_SESSION['ai_assistant_history']);

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Get settings
     */
    public function getSettings(): JsonResponse
    {
        $this->initServices();
        return response()->json([
            'success' => true,
            'data' => [
                'provider' => config('ai-assistant.provider'),
                'ui' => config('ai-assistant.ui'),
                'actions' => config('ai-assistant.actions'),
                'is_configured' => $this->aiService->isConfigured(),
            ],
        ]);
    }

    /**
     * Update settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $this->initServices();
        // For now, settings are managed via config file
        return response()->json([
            'success' => false,
            'error' => 'Settings can only be changed via configuration file',
        ], 400);
    }

    /**
     * Handle create resource with clear id response
     */
    protected function handleCreateResource(array $args): array
    {
        $resource = $this->resourceService->create($args);
        if ($resource) {
            return [
                'action' => 'create_resource',
                'success' => true,
                'id' => $resource->id,
                'message' => "Created resource with id={$resource->id}",
                'data' => $resource->toArray(),
            ];
        }
        return ['action' => 'create_resource', 'success' => false, 'error' => 'Failed to create resource'];
    }

    /**
     * Handle create TV with clear id response
     */
    protected function handleCreateTv(array $args): array
    {
        $name = $args['name'] ?? '';

        // Check if already exists
        $existing = \EvolutionCMS\Models\SiteTmplvar::where('name', $name)->first();
        if ($existing) {
            return [
                'action' => 'create_tv',
                'success' => true,
                'id' => $existing->id,
                'already_exists' => true,
                'message' => "TV '{$name}' already exists with id={$existing->id}",
                'data' => $existing->toArray(),
            ];
        }

        $tv = $this->tvService->create($args);
        if ($tv) {
            return [
                'action' => 'create_tv',
                'success' => true,
                'id' => $tv->id,
                'message' => "Created TV '{$tv->name}' with id={$tv->id}",
                'data' => $tv->toArray(),
            ];
        }
        return ['action' => 'create_tv', 'success' => false, 'error' => 'Failed to create TV'];
    }

    /**
     * Handle create template with clear id response
     */
    protected function handleCreateTemplate(array $args): array
    {
        $templatename = $args['templatename'] ?? '';

        // Check if already exists
        $existing = \EvolutionCMS\Models\SiteTemplate::where('templatename', $templatename)->first();
        if ($existing) {
            return [
                'action' => 'create_template',
                'success' => true,
                'id' => $existing->id,
                'already_exists' => true,
                'message' => "Template '{$templatename}' already exists with id={$existing->id}",
                'data' => $existing->toArray(),
            ];
        }

        $template = $this->templateService->create($args);
        if ($template) {
            return [
                'action' => 'create_template',
                'success' => true,
                'id' => $template->id,
                'message' => "Created template '{$template->templatename}' with id={$template->id}",
                'data' => $template->toArray(),
            ];
        }
        return ['action' => 'create_template', 'success' => false, 'error' => 'Failed to create template'];
    }

    /**
     * Status check
     */
    public function status(): JsonResponse
    {
        $this->initServices();
        return response()->json([
            'success' => true,
            'data' => [
                'version' => '1.1.0',
                'ai_configured' => $this->aiService->isConfigured(),
                'user' => [
                    'id' => evo()->getLoginUserID('mgr'),
                    'permissions' => [
                        'view_document' => evo()->hasPermission('view_document', 'mgr'),
                        'edit_document' => evo()->hasPermission('edit_document', 'mgr'),
                        'save_document' => evo()->hasPermission('save_document', 'mgr'),
                        'publish_document' => evo()->hasPermission('publish_document', 'mgr'),
                        'edit_template' => evo()->hasPermission('edit_template', 'mgr'),
                    ],
                ],
            ],
        ]);
    }
}
