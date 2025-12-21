<?php

namespace EvolutionCMS\AiAssistant\Services;

use EvolutionCMS\Models\SiteTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class TemplateService
{
    public function __construct(
        protected CheckpointService $checkpointService
    ) {}

    /**
     * Get all templates
     */
    public function getAll(): Collection
    {
        return SiteTemplate::orderBy('templatename')->get();
    }

    /**
     * Get template by ID or name
     */
    public function get(int|string $identifier): ?array
    {
        if (is_numeric($identifier)) {
            $template = SiteTemplate::find($identifier);
        } else {
            $template = SiteTemplate::where('templatename', $identifier)->first();
        }

        if (!$template) {
            return null;
        }

        $data = $template->toArray();

        // Check if it's a Blade template
        $data['is_blade'] = $this->isBladeTemplate($template);
        $data['blade_path'] = $this->getBladeTemplatePath($template);
        $data['blade_content'] = $this->getBladeTemplateContent($template);

        return $data;
    }

    /**
     * Search templates
     */
    public function search(string $keyword): Collection
    {
        return SiteTemplate::where('templatename', 'LIKE', "%{$keyword}%")
            ->orWhere('description', 'LIKE', "%{$keyword}%")
            ->orWhere('content', 'LIKE', "%{$keyword}%")
            ->get();
    }

    /**
     * Update template (inline content in database)
     */
    public function update(int $id, array $data, bool $createCheckpoint = true): ?SiteTemplate
    {
        $template = SiteTemplate::find($id);

        if (!$template) {
            return null;
        }

        $allowedFields = [
            'templatename', 'description', 'content', 'category', 'locked', 'selectable', 'icon',
        ];

        $changes = array_intersect_key($data, array_flip($allowedFields));

        if (empty($changes)) {
            return $template;
        }

        // Create checkpoint
        if ($createCheckpoint) {
            $this->checkpointService->createForTemplate(
                $template,
                $changes,
                "Update template #{$id} ({$template->templatename})"
            );
        }

        $template->fill($changes);
        $template->save();

        return $template->fresh();
    }

    /**
     * Check if template uses Blade view
     */
    public function isBladeTemplate(SiteTemplate $template): bool
    {
        // Check if content references a Blade view
        $content = trim($template->content);

        // Check for @extends directive
        if (str_starts_with($content, '@extends')) {
            return true;
        }

        // Check for Blade view reference pattern
        if (preg_match('/^\[!\[view:([^\]]+)\]!\]/', $content)) {
            return true;
        }

        // Check templatealias which might reference a blade view
        if (!empty($template->templatealias)) {
            $viewPath = $this->resolveViewPath($template->templatealias);
            if ($viewPath && File::exists($viewPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get Blade template file path
     */
    public function getBladeTemplatePath(SiteTemplate $template): ?string
    {
        // Try to find by templatealias
        if (!empty($template->templatealias)) {
            $viewPath = $this->resolveViewPath($template->templatealias);
            if ($viewPath && File::exists($viewPath)) {
                return $viewPath;
            }
        }

        // Try to find by template name
        $viewPath = $this->resolveViewPath($template->templatename);
        if ($viewPath && File::exists($viewPath)) {
            return $viewPath;
        }

        return null;
    }

    /**
     * Get Blade template content
     */
    public function getBladeTemplateContent(SiteTemplate $template): ?string
    {
        $path = $this->getBladeTemplatePath($template);

        if ($path && File::exists($path)) {
            return File::get($path);
        }

        return null;
    }

    /**
     * Update Blade template file
     */
    public function updateBladeTemplate(int $id, string $content, bool $createCheckpoint = true): bool
    {
        $template = SiteTemplate::find($id);

        if (!$template) {
            return false;
        }

        $path = $this->getBladeTemplatePath($template);

        if (!$path) {
            return false;
        }

        // Create checkpoint for blade template content
        if ($createCheckpoint && File::exists($path)) {
            $oldContent = File::get($path);
            $this->checkpointService->create(
                'blade_template',
                $id,
                ['path' => $path, 'content' => $oldContent],
                ['path' => $path, 'content' => $content],
                'content',
                "Update Blade template for #{$id} ({$template->templatename})"
            );
        }

        return File::put($path, $content) !== false;
    }

    /**
     * Resolve view path from view name
     */
    protected function resolveViewPath(string $viewName): ?string
    {
        // Convert dot notation to path
        $viewName = str_replace('.', '/', $viewName);

        // Check in views directory
        $paths = [
            base_path('views/' . $viewName . '.blade.php'),
            resource_path('views/' . $viewName . '.blade.php'),
            MODX_BASE_PATH . 'views/' . $viewName . '.blade.php',
        ];

        foreach ($paths as $path) {
            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Create a new template
     */
    public function create(array $data): ?SiteTemplate
    {
        if (empty($data['templatename'])) {
            return null;
        }

        // Check if template with this name exists
        if (SiteTemplate::where('templatename', $data['templatename'])->exists()) {
            return null;
        }

        $defaults = [
            'description' => '',
            'content' => '',
            'category' => 0,
            'icon' => '',
            'template_type' => 0,
            'locked' => 0,
            'selectable' => 1,
        ];

        $data = array_merge($defaults, $data);

        $template = new SiteTemplate($data);
        $template->save();

        return $template->fresh();
    }

    /**
     * Get template usage statistics
     */
    public function getUsageStats(int $templateId): array
    {
        $template = SiteTemplate::find($templateId);

        if (!$template) {
            return [];
        }

        return [
            'id' => $template->id,
            'name' => $template->templatename,
            'resources_count' => \EvolutionCMS\Models\SiteContent::where('template', $templateId)->count(),
            'tvs_count' => $template->tvs->count(),
        ];
    }

    /**
     * List available Blade view files
     */
    public function listBladeViews(): array
    {
        $views = [];
        $viewsPath = MODX_BASE_PATH . 'views';

        if (File::isDirectory($viewsPath)) {
            $files = File::allFiles($viewsPath);

            foreach ($files as $file) {
                if ($file->getExtension() === 'php' && str_ends_with($file->getFilename(), '.blade.php')) {
                    $relativePath = str_replace($viewsPath . '/', '', $file->getPathname());
                    $viewName = str_replace(['.blade.php', '/'], ['', '.'], $relativePath);

                    $views[] = [
                        'path' => $file->getPathname(),
                        'name' => $viewName,
                        'size' => $file->getSize(),
                        'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                    ];
                }
            }
        }

        return $views;
    }
}
