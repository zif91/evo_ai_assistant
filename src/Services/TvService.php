<?php

namespace EvolutionCMS\AiAssistant\Services;

use EvolutionCMS\Models\SiteTmplvar;
use EvolutionCMS\Models\SiteTmplvarTemplate;
use EvolutionCMS\Models\SiteTemplate;
use Illuminate\Support\Collection;

class TvService
{
    public function __construct(
        protected CheckpointService $checkpointService
    ) {}

    /**
     * Get all TV definitions
     */
    public function getAll(): Collection
    {
        return SiteTmplvar::orderBy('name')->get();
    }

    /**
     * Get TV by ID or name
     */
    public function get(int|string $identifier): ?SiteTmplvar
    {
        if (is_numeric($identifier)) {
            return SiteTmplvar::find($identifier);
        }

        return SiteTmplvar::where('name', $identifier)->first();
    }

    /**
     * Search TVs
     */
    public function search(string $keyword): Collection
    {
        return SiteTmplvar::where('name', 'LIKE', "%{$keyword}%")
            ->orWhere('caption', 'LIKE', "%{$keyword}%")
            ->orWhere('description', 'LIKE', "%{$keyword}%")
            ->get();
    }

    /**
     * Get TVs for a template
     */
    public function getForTemplate(int $templateId): Collection
    {
        $template = SiteTemplate::find($templateId);
        if (!$template) {
            return collect();
        }

        return $template->tvs;
    }

    /**
     * Create a new TV
     */
    public function create(array $data): ?SiteTmplvar
    {
        // Required fields
        if (empty($data['name'])) {
            return null;
        }

        // Check if TV with this name already exists
        if (SiteTmplvar::where('name', $data['name'])->exists()) {
            return null;
        }

        // Defaults
        $defaults = [
            'type' => 'text',
            'caption' => $data['name'],
            'description' => '',
            'editor_type' => 0,
            'category' => 0,
            'locked' => 0,
            'elements' => '',
            'rank' => 0,
            'display' => '',
            'display_params' => '',
            'default_text' => '',
        ];

        $data = array_merge($defaults, $data);

        $templates = $data['templates'] ?? [];
        unset($data['templates']);
        $tv = new SiteTmplvar($data);
        $tv->save();
        evo()->clearCache('full');

        // Bind to templates if specified
        if (!empty($templates)) {
            $this->bindToTemplates($tv->id, $templates);
        }

        return $tv->fresh();
    }

    /**
     * Update TV definition
     */
    public function update(int $id, array $data, bool $createCheckpoint = true): ?SiteTmplvar
    {
        $tv = SiteTmplvar::find($id);

        if (!$tv) {
            return null;
        }

        $allowedFields = [
            'name', 'caption', 'description', 'type', 'elements',
            'default_text', 'display', 'display_params', 'category', 'rank', 'locked',
        ];

        $changes = array_intersect_key($data, array_flip($allowedFields));

        if (empty($changes)) {
            return $tv;
        }

        // Create checkpoint
        if ($createCheckpoint) {
            $this->checkpointService->createForTv(
                $tv,
                $changes,
                "Update TV #{$id} ({$tv->name})"
            );
        }

        $tv->fill($changes);
        $tv->save();
        evo()->clearCache('full');

        // Update template bindings if specified
        if (isset($data['templates'])) {
            $this->bindToTemplates($id, $data['templates']);
        }

        return $tv->fresh();
    }

    /**
     * Bind TV to templates
     */
    public function bindToTemplates(int $tvId, array $templateIds): bool
    {
        $tv = SiteTmplvar::find($tvId);
        if (!$tv) {
            return false;
        }

        // Remove existing bindings
        SiteTmplvarTemplate::where('tmplvarid', $tvId)->delete();

        // Create new bindings
        $rank = 0;
        foreach ($templateIds as $templateId) {
            if (SiteTemplate::find($templateId)) {
                SiteTmplvarTemplate::create([
                    'tmplvarid' => $tvId,
                    'templateid' => $templateId,
                    'rank' => $rank++,
                ]);
            }
        }

        evo()->clearCache('full');
        return true;
    }

    /**
     * Get available TV types
     */
    public function getAvailableTypes(): array
    {
        return [
            'text' => 'Text',
            'textarea' => 'Textarea',
            'textareamini' => 'Textarea (Mini)',
            'richtext' => 'Rich Text',
            'dropdown' => 'Dropdown List',
            'listbox' => 'Listbox (Single Select)',
            'listbox-multiple' => 'Listbox (Multiple Select)',
            'checkbox' => 'Checkbox',
            'option' => 'Radio Options',
            'image' => 'Image',
            'file' => 'File',
            'url' => 'URL',
            'email' => 'Email',
            'number' => 'Number',
            'date' => 'Date',
        ];
    }

    /**
     * Delete TV (soft, keeps data)
     */
    public function delete(int $id): bool
    {
        $tv = SiteTmplvar::find($id);
        if (!$tv) {
            return false;
        }

        // Note: This will cascade delete related records
        return $tv->delete();
    }

    /**
     * Get all templates with their TV assignments
     */
    public function getTemplatesWithTvs(): Collection
    {
        return SiteTemplate::with('tvs')->get()->map(function ($template) {
            return [
                'id' => $template->id,
                'name' => $template->templatename,
                'tvs' => $template->tvs->map(function ($tv) {
                    return [
                        'id' => $tv->id,
                        'name' => $tv->name,
                        'caption' => $tv->caption,
                        'type' => $tv->type,
                    ];
                }),
            ];
        });
    }
}
