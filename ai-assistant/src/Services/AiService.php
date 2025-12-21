<?php

namespace EvolutionCMS\AiAssistant\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    protected array $config;
    protected string $provider;
    protected array $conversationHistory = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->provider = $config['provider'] ?? 'openai';
    }

    /**
     * Execute a tool and return result
     */
    protected function executeTool(string $name, array $args): array
    {
        // This will be set by the controller
        if ($this->toolExecutor) {
            return call_user_func($this->toolExecutor, $name, $args);
        }
        return ['error' => 'No tool executor set'];
    }

    protected $toolExecutor = null;

    public function setToolExecutor(callable $executor): void
    {
        $this->toolExecutor = $executor;
    }

    /**
     * Debug log helper
     */
    protected function debugLog(string $msg, array $data = []): void
    {
        $logFile = storage_path('logs/ai_assistant_debug.log');
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] $msg\n";
        if (!empty($data)) {
            $entry .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        $entry .= "---\n";
        file_put_contents($logFile, $entry, FILE_APPEND);
    }

    /**
     * Send a message to the AI and get a response with multi-turn tool execution
     */
    public function chat(string $message, array $context = []): array
    {
        $systemPrompt = $this->config['system_prompt'] ?? '';
        $maxIterations = 15; // Allow complex multi-step tasks

        $this->debugLog("=== NEW CHAT REQUEST ===", ['message' => $message]);

        // Build messages array
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Add context if provided
        if (!empty($context)) {
            $contextMessage = "Current context:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $messages[] = ['role' => 'system', 'content' => $contextMessage];
        }

        // Add conversation history (limit to last 10 messages to save context)
        $history = array_slice($this->conversationHistory, -10);
        foreach ($history as $historyItem) {
            $messages[] = $historyItem;
        }

        // Add current user message
        $messages[] = ['role' => 'user', 'content' => $message];

        $allActions = [];
        $finalContent = '';

        try {
            for ($i = 0; $i < $maxIterations; $i++) {
                $this->debugLog("ITERATION $i - Calling API", ['messages_count' => count($messages)]);

                $response = match ($this->provider) {
                    'openai' => $this->callOpenAI($messages),
                    'anthropic' => $this->callAnthropic($messages),
                    default => throw new \Exception("Unsupported AI provider: {$this->provider}"),
                };

                $finalContent = $response['content'] ?? '';
                $actionsCount = count($response['actions'] ?? []);

                $this->debugLog("ITERATION $i - API Response", [
                    'content' => substr($finalContent, 0, 200),
                    'actions_count' => $actionsCount,
                    'actions' => array_map(fn($a) => $a['name'] ?? 'unknown', $response['actions'] ?? []),
                    'finish_reason' => $response['finish_reason'] ?? 'unknown',
                ]);

                // If no tool calls, we're done
                if (empty($response['actions'])) {
                    $this->debugLog("ITERATION $i - No more tool calls, finishing loop");
                    break;
                }

                // Execute each tool and collect results
                $toolResults = [];
                foreach ($response['actions'] as $action) {
                    $toolName = $action['name'] ?? '';
                    $toolArgs = $action['arguments'] ?? [];
                    $toolId = $action['id'] ?? $toolName;

                    // Skip if no tool name
                    if (empty($toolName)) {
                        $this->debugLog("SKIPPING empty tool name", ['action' => $action]);
                        continue;
                    }

                    $this->debugLog("Executing tool: $toolName", ['args' => $toolArgs]);

                    // Execute tool
                    $result = $this->executeTool($toolName, $toolArgs);
                    $allActions[] = array_merge(['name' => $toolName], $result);

                    $this->debugLog("Tool result: $toolName", [
                        'success' => $result['success'] ?? false,
                        'data_preview' => isset($result['data']) ? (is_array($result['data']) ? 'array('.count($result['data']).')' : substr(json_encode($result['data']), 0, 100)) : 'null',
                    ]);

                    $toolResults[] = [
                        'tool_call_id' => $toolId,
                        'name' => $toolName,
                        'result' => $result,
                    ];
                }

                // Add assistant message with tool calls to conversation
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $finalContent,
                    'tool_calls' => array_map(function($a) {
                        return [
                            'id' => $a['id'] ?? $a['name'],
                            'type' => 'function',
                            'function' => [
                                'name' => $a['name'],
                                'arguments' => json_encode($a['arguments'] ?? []),
                            ],
                        ];
                    }, $response['actions']),
                ];

                // Add tool results
                foreach ($toolResults as $tr) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tr['tool_call_id'],
                        'content' => json_encode($tr['result'], JSON_UNESCAPED_UNICODE),
                    ];
                }

                $this->debugLog("Added tool results to messages, continuing loop");
            }

            $this->debugLog("=== CHAT COMPLETE ===", [
                'iterations' => $i + 1,
                'total_actions' => count($allActions),
                'final_content_length' => strlen($finalContent),
            ]);

            // Save to history
            $this->conversationHistory[] = ['role' => 'user', 'content' => $message];
            $this->conversationHistory[] = ['role' => 'assistant', 'content' => $finalContent];

            return [
                'success' => true,
                'content' => $finalContent,
                'actions' => $allActions,
            ];
        } catch (\Exception $e) {
            $this->debugLog("ERROR", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            Log::error('AI Service error: ' . $e->getMessage());

            return [
                'success' => false,
                'content' => '',
                'error' => $e->getMessage(),
                'actions' => $allActions,
            ];
        }
    }

    /**
     * Call OpenAI API (also works with OpenRouter and other OpenAI-compatible APIs)
     */
    protected function callOpenAI(array $messages): array
    {
        $providerConfig = $this->config['providers']['openai'];
        $endpoint = $providerConfig['endpoint'];

        // Headers for OpenAI-compatible APIs
        $headers = [
            'Authorization' => 'Bearer ' . $providerConfig['api_key'],
            'Content-Type' => 'application/json',
        ];

        // Add OpenRouter-specific headers if using OpenRouter
        if (str_contains($endpoint, 'openrouter.ai')) {
            $headers['HTTP-Referer'] = defined('MODX_SITE_URL') ? MODX_SITE_URL : 'http://localhost';
            $headers['X-Title'] = 'Evolution CMS AI Assistant';
        }

        // Build request body with tools for function calling
        $requestBody = [
            'model' => $providerConfig['model'],
            'messages' => $messages,
            'max_tokens' => $providerConfig['max_tokens'],
            'temperature' => $providerConfig['temperature'],
            'tools' => $this->getOpenAIToolDefinitions(),
            'tool_choice' => 'auto',
        ];

        $response = Http::withHeaders($headers)
            ->timeout(120)
            ->post($endpoint, $requestBody);

        if (!$response->successful()) {
            throw new \Exception('AI API error: ' . $response->body());
        }

        $data = $response->json();

        // Debug raw response
        $this->debugLog("RAW API RESPONSE", [
            'model' => $data['model'] ?? 'unknown',
            'choices_count' => count($data['choices'] ?? []),
            'first_choice' => $data['choices'][0] ?? null,
        ]);

        $choice = $data['choices'][0] ?? [];
        $assistantMessage = $choice['message'] ?? [];

        $content = $assistantMessage['content'] ?? '';
        $actions = [];

        // Parse function/tool calls if present
        if (isset($assistantMessage['function_call'])) {
            $functionCall = $assistantMessage['function_call'];
            $actions[] = [
                'type' => 'function',
                'name' => $functionCall['name'],
                'arguments' => json_decode($functionCall['arguments'], true) ?? [],
            ];
        }

        // Also handle tool_calls format (newer OpenAI API)
        if (isset($assistantMessage['tool_calls'])) {
            $this->debugLog("TOOL_CALLS found", ['tool_calls' => $assistantMessage['tool_calls']]);

            foreach ($assistantMessage['tool_calls'] as $toolCall) {
                if (($toolCall['type'] ?? '') === 'function' && !empty($toolCall['function']['name'])) {
                    $actions[] = [
                        'type' => 'function',
                        'id' => $toolCall['id'] ?? uniqid('tool_'),
                        'name' => $toolCall['function']['name'],
                        'arguments' => json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
                    ];
                }
            }
        }

        return [
            'success' => true,
            'content' => $content,
            'actions' => $actions,
            'raw' => $data,
        ];
    }

    /**
     * Call Anthropic API
     */
    protected function callAnthropic(array $messages): array
    {
        $providerConfig = $this->config['providers']['anthropic'];

        // Convert messages format for Anthropic
        $systemContent = '';
        $anthropicMessages = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemContent .= $msg['content'] . "\n\n";
            } else {
                $anthropicMessages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                ];
            }
        }

        $response = Http::withHeaders([
            'x-api-key' => $providerConfig['api_key'],
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ])->timeout(60)->post($providerConfig['endpoint'], [
            'model' => $providerConfig['model'],
            'max_tokens' => $providerConfig['max_tokens'],
            'system' => trim($systemContent),
            'messages' => $anthropicMessages,
            'tools' => $this->getAnthropicToolDefinitions(),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Anthropic API error: ' . $response->body());
        }

        $data = $response->json();
        $content = '';
        $actions = [];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $actions[] = [
                    'type' => 'function',
                    'name' => $block['name'],
                    'arguments' => $block['input'] ?? [],
                    'id' => $block['id'],
                ];
            }
        }

        return [
            'success' => true,
            'content' => $content,
            'actions' => $actions,
            'raw' => $data,
        ];
    }

    /**
     * Get tool definitions in OpenAI tools format
     */
    protected function getOpenAIToolDefinitions(): array
    {
        $functions = $this->getToolDefinitions();
        $tools = [];
        foreach ($functions as $func) {
            $tools[] = [
                'type' => 'function',
                'function' => $func,
            ];
        }
        return $tools;
    }

    /**
     * Get tool definitions for OpenAI
     */
    protected function getToolDefinitions(): array
    {
        return [
            [
                'name' => 'search_resources',
                'description' => 'Search for resources (pages) in the CMS',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword' => [
                            'type' => 'string',
                            'description' => 'Search keyword',
                        ],
                        'template' => [
                            'type' => 'integer',
                            'description' => 'Filter by template ID',
                        ],
                        'published' => [
                            'type' => 'boolean',
                            'description' => 'Filter by published status',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'get_resource',
                'description' => 'Get detailed information about a resource',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => 'Resource ID',
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'update_resource',
                'description' => 'Update resource fields',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => 'Resource ID',
                        ],
                        'pagetitle' => ['type' => 'string'],
                        'longtitle' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'introtext' => ['type' => 'string'],
                        'alias' => ['type' => 'string'],
                        'menutitle' => ['type' => 'string'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'create_resource',
                'description' => 'Create a new resource (page) in the CMS',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'pagetitle' => [
                            'type' => 'string',
                            'description' => 'Page title',
                        ],
                        'parent' => [
                            'type' => 'integer',
                            'description' => 'Parent resource ID (0 for root)',
                        ],
                        'template' => [
                            'type' => 'integer',
                            'description' => 'Template ID to use',
                        ],
                        'alias' => ['type' => 'string', 'description' => 'URL alias'],
                        'content' => ['type' => 'string', 'description' => 'Page content'],
                        'published' => ['type' => 'boolean', 'description' => 'Publish immediately'],
                        'isfolder' => ['type' => 'boolean', 'description' => 'Is this a folder/container'],
                    ],
                    'required' => ['pagetitle'],
                ],
            ],
            [
                'name' => 'update_tv',
                'description' => 'Update template variable value for a resource',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'resource_id' => [
                            'type' => 'integer',
                            'description' => 'Resource ID',
                        ],
                        'tv_name' => [
                            'type' => 'string',
                            'description' => 'TV name',
                        ],
                        'value' => [
                            'type' => 'string',
                            'description' => 'New value',
                        ],
                    ],
                    'required' => ['resource_id', 'tv_name', 'value'],
                ],
            ],
            [
                'name' => 'publish_resource',
                'description' => 'Publish a resource',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => 'Resource ID',
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'unpublish_resource',
                'description' => 'Unpublish a resource',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => 'Resource ID',
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'create_tv',
                'description' => 'Create a new template variable',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'TV name (no spaces)'],
                        'caption' => ['type' => 'string', 'description' => 'Display caption'],
                        'description' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'description' => 'TV type (text, textarea, image, etc.)'],
                        'default_text' => ['type' => 'string', 'description' => 'Default value'],
                        'templates' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Template IDs to bind to',
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'optimize_seo',
                'description' => 'Generate SEO-optimized content for a resource',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'resource_id' => [
                            'type' => 'integer',
                            'description' => 'Resource ID',
                        ],
                        'focus_keyword' => [
                            'type' => 'string',
                            'description' => 'Main keyword to optimize for',
                        ],
                    ],
                    'required' => ['resource_id'],
                ],
            ],
            [
                'name' => 'rollback_checkpoint',
                'description' => 'Rollback to a previous checkpoint',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'checkpoint_id' => [
                            'type' => 'integer',
                            'description' => 'Checkpoint ID to rollback to',
                        ],
                    ],
                    'required' => ['checkpoint_id'],
                ],
            ],
            [
                'name' => 'list_checkpoints',
                'description' => 'List available checkpoints for rollback',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'entity_type' => [
                            'type' => 'string',
                            'description' => 'Filter by entity type (resource, tv, template)',
                        ],
                        'entity_id' => [
                            'type' => 'integer',
                            'description' => 'Filter by entity ID',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'get_resource_tv',
                'description' => 'Get all TV (template variable) values for a resource',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'resource_id' => [
                            'type' => 'integer',
                            'description' => 'Resource ID',
                        ],
                    ],
                    'required' => ['resource_id'],
                ],
            ],
            [
                'name' => 'list_templates',
                'description' => 'List all available templates in the CMS',
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object)[],
                ],
            ],
            [
                'name' => 'get_template',
                'description' => 'Get template details by ID',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => 'Template ID',
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'list_tv',
                'description' => 'List all template variables (TV) in the CMS',
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object)[],
                ],
            ],
            [
                'name' => 'bind_tv_to_templates',
                'description' => 'Bind a TV to one or more templates',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tv_id' => [
                            'type' => 'integer',
                            'description' => 'TV ID',
                        ],
                        'template_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Array of template IDs to bind',
                        ],
                    ],
                    'required' => ['tv_id', 'template_ids'],
                ],
            ],
            [
                'name' => 'analyze_seo',
                'description' => 'Analyze SEO for a resource and get improvement suggestions',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'resource_id' => [
                            'type' => 'integer',
                            'description' => 'Resource ID to analyze',
                        ],
                    ],
                    'required' => ['resource_id'],
                ],
            ],
            [
                'name' => 'update_template',
                'description' => 'Update template properties (name, description, content for inline templates)',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => 'Template ID',
                        ],
                        'templatename' => ['type' => 'string', 'description' => 'Template name'],
                        'description' => ['type' => 'string', 'description' => 'Template description'],
                        'content' => ['type' => 'string', 'description' => 'Template content (for inline templates)'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'update_blade_template',
                'description' => 'Update a Blade template file content',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => 'Template ID',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'New Blade template content',
                        ],
                    ],
                    'required' => ['id', 'content'],
                ],
            ],
            [
                'name' => 'create_template',
                'description' => 'Create a new template',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'templatename' => [
                            'type' => 'string',
                            'description' => 'Template name',
                        ],
                        'description' => ['type' => 'string', 'description' => 'Template description'],
                        'content' => ['type' => 'string', 'description' => 'Template content'],
                    ],
                    'required' => ['templatename'],
                ],
            ],
        ];
    }

    /**
     * Get tool definitions for Anthropic
     */
    protected function getAnthropicToolDefinitions(): array
    {
        $openAiTools = $this->getToolDefinitions();
        $anthropicTools = [];

        foreach ($openAiTools as $tool) {
            $anthropicTools[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'],
            ];
        }

        return $anthropicTools;
    }

    /**
     * Clear conversation history
     */
    public function clearHistory(): void
    {
        $this->conversationHistory = [];
    }

    /**
     * Set conversation history
     */
    public function setHistory(array $history): void
    {
        $this->conversationHistory = $history;
    }

    /**
     * Get conversation history
     */
    public function getHistory(): array
    {
        return $this->conversationHistory;
    }

    /**
     * Check if AI is configured
     */
    public function isConfigured(): bool
    {
        $providerConfig = $this->config['providers'][$this->provider] ?? [];
        return !empty($providerConfig['api_key']);
    }
}
