<?php

namespace EvolutionCMS\AiAssistant\Services;

use EvolutionCMS\Models\SiteContent;
use EvolutionCMS\Models\SiteTmplvarContentvalue;
use EvolutionCMS\Models\SiteTmplvar;
use Illuminate\Support\Collection;

class ResourceService
{
    public function __construct(
        protected CheckpointService $checkpointService
    ) {}

    /**
     * Search resources by various criteria
     */
    public function search(array $criteria): Collection
    {
        $query = SiteContent::query();

        // Search by keyword in title, longtitle, description, content
        if (!empty($criteria['keyword'])) {
            $keyword = $criteria['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('pagetitle', 'LIKE', "%{$keyword}%")
                    ->orWhere('longtitle', 'LIKE', "%{$keyword}%")
                    ->orWhere('description', 'LIKE', "%{$keyword}%")
                    ->orWhere('introtext', 'LIKE', "%{$keyword}%")
                    ->orWhere('content', 'LIKE', "%{$keyword}%")
                    ->orWhere('alias', 'LIKE', "%{$keyword}%")
                    ->orWhere('menutitle', 'LIKE', "%{$keyword}%");
            });
        }

        // Search by ID
        if (!empty($criteria['id'])) {
            $query->where('id', $criteria['id']);
        }

        // Search by parent
        if (isset($criteria['parent'])) {
            $query->where('parent', $criteria['parent']);
        }

        // Search by template
        if (!empty($criteria['template'])) {
            $query->where('template', $criteria['template']);
        }

        // Filter by published status
        if (isset($criteria['published'])) {
            $query->where('published', $criteria['published'] ? 1 : 0);
        }

        // Filter by deleted status
        if (isset($criteria['deleted'])) {
            if ($criteria['deleted']) {
                $query->onlyTrashed();
            } else {
                $query->withoutTrashed();
            }
        } else {
            $query->withoutTrashed();
        }

        // Search in TV values
        if (!empty($criteria['tv'])) {
            foreach ($criteria['tv'] as $tvName => $tvValue) {
                $tv = SiteTmplvar::where('name', $tvName)->first();
                if ($tv) {
                    $query->whereHas('templateValues', function ($q) use ($tv, $tvValue) {
                        $q->where('tmplvarid', $tv->id)
                            ->where('value', 'LIKE', "%{$tvValue}%");
                    });
                }
            }
        }

        // Limit results
        $limit = max(1, min(100, (int) ($criteria['limit'] ?? 50)));
        $query->limit($limit);

        // Order
        $orderBy = $criteria['orderBy'] ?? 'id';
        $orderDir = $criteria['orderDir'] ?? 'desc';
        $orderBy = in_array($orderBy, ['id', 'pagetitle', 'menuindex', 'createdon', 'editedon'], true) ? $orderBy : 'id';
        $orderDir = strtolower($orderDir) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($orderBy, $orderDir);

        return $query->get();
    }

    /**
     * Get resource by ID with TV values
     */
    public function get(int $id, bool $withTv = true): ?array
    {
        $resource = SiteContent::withTrashed()->find($id);

        if (!$resource) {
            return null;
        }

        $data = $resource->toArray();

        if ($withTv) {
            $data['tv'] = $this->getTvValues($id);
        }

        return $data;
    }

    /**
     * Get TV values for a resource
     */
    public function getTvValues(int $resourceId): array
    {
        $resource = SiteContent::find($resourceId);
        if (!$resource) {
            return [];
        }

        $tvValues = [];
        $templateTvs = $resource->tpl?->tvs ?? collect();

        foreach ($templateTvs as $tv) {
            $value = SiteTmplvarContentvalue::where('contentid', $resourceId)
                ->where('tmplvarid', $tv->id)
                ->first();

            $tvValues[$tv->name] = [
                'id' => $tv->id,
                'name' => $tv->name,
                'caption' => $tv->caption,
                'type' => $tv->type,
                'value' => $value?->value ?? $tv->default_text,
                'default' => $tv->default_text,
            ];
        }

        return $tvValues;
    }

    /**
     * Update resource fields
     */
    public function update(int $id, array $data, bool $createCheckpoint = true): ?SiteContent
    {
        $resource = SiteContent::find($id);

        if (!$resource) {
            return null;
        }

        // Filter allowed fields
        $allowedFields = [
            'pagetitle', 'longtitle', 'description', 'alias', 'link_attributes',
            'introtext', 'content', 'menutitle', 'template', 'parent',
            'menuindex', 'searchable', 'cacheable', 'richtext', 'hidemenu',
        ];

        $changes = array_intersect_key($data, array_flip($allowedFields));

        if (empty($changes)) {
            return $resource;
        }

        // Create checkpoint before changes
        if ($createCheckpoint) {
            $this->checkpointService->createForResource(
                $resource,
                $changes,
                "Update resource #{$id}: " . implode(', ', array_keys($changes))
            );
        }

        // Apply changes
        $resource->fill($changes);
        $resource->save();
        evo()->clearCache('full');

        return $resource->fresh();
    }

    /**
     * Update TV value for a resource
     */
    public function updateTv(int $resourceId, string $tvName, $value, bool $createCheckpoint = true): bool
    {
        $resource = SiteContent::find($resourceId);
        if (!$resource) {
            return false;
        }

        $tv = SiteTmplvar::where('name', $tvName)->first();
        if (!$tv) {
            return false;
        }

        // Get current value
        $currentValue = SiteTmplvarContentvalue::where('contentid', $resourceId)
            ->where('tmplvarid', $tv->id)
            ->first();

        $oldValue = $currentValue?->value;

        // Create checkpoint
        if ($createCheckpoint) {
            $this->checkpointService->createForTvValue(
                $resourceId,
                $tv->id,
                $oldValue,
                $value,
                "Update TV '{$tvName}' for resource #{$resourceId}"
            );
        }

        // Update or create TV value
        if ($currentValue) {
            $currentValue->value = $value;
            $currentValue->save();
        } else {
            SiteTmplvarContentvalue::create([
                'contentid' => $resourceId,
                'tmplvarid' => $tv->id,
                'value' => $value,
            ]);
        }

        evo()->clearCache('full');
        return true;
    }

    /**
     * Update multiple TV values
     */
    public function updateTvs(int $resourceId, array $tvData, bool $createCheckpoint = true): array
    {
        $results = [];

        foreach ($tvData as $tvName => $value) {
            $results[$tvName] = $this->updateTv($resourceId, $tvName, $value, $createCheckpoint);
        }

        return $results;
    }

    /**
     * Publish a resource
     */
    public function publish(int $id, bool $createCheckpoint = true): ?SiteContent
    {
        $resource = SiteContent::find($id);

        if (!$resource) {
            return null;
        }

        if ($resource->published) {
            return $resource;
        }

        if ($createCheckpoint) {
            $this->checkpointService->createForResource(
                $resource,
                ['published' => 1, 'publishedon' => time()],
                "Publish resource #{$id}"
            );
        }

        $resource->published = 1;
        $resource->publishedon = time();
        $resource->publishedby = evo()->getLoginUserID('mgr');
        $resource->save();
        evo()->clearCache('full');

        return $resource->fresh();
    }

    /**
     * Unpublish a resource
     */
    public function unpublish(int $id, bool $createCheckpoint = true): ?SiteContent
    {
        $resource = SiteContent::find($id);

        if (!$resource) {
            return null;
        }

        if (!$resource->published) {
            return $resource;
        }

        if ($createCheckpoint) {
            $this->checkpointService->createForResource(
                $resource,
                ['published' => 0],
                "Unpublish resource #{$id}"
            );
        }

        $resource->published = 0;
        $resource->save();
        evo()->clearCache('full');

        return $resource->fresh();
    }

    /**
     * Create a new resource
     */
    public function create(array $data): ?SiteContent
    {
        // Set defaults
        $defaults = [
            'type' => 'document',
            'contentType' => 'text/html',
            'published' => 0,
            'parent' => 0,
            'isfolder' => 0,
            'richtext' => 1,
            'template' => config('global.default_template', 0),
            'menuindex' => 0,
            'searchable' => 1,
            'cacheable' => 1,
            'createdby' => evo()->getLoginUserID('mgr'),
            'createdon' => time(),
        ];

        $data = array_merge($defaults, $data);

        // Required field
        if (empty($data['pagetitle'])) {
            return null;
        }

        $resource = new SiteContent($data);
        $resource->save();
        evo()->clearCache('full');

        return $resource->fresh();
    }

    /**
     * Get resource tree structure
     */
    public function getTree(int $parentId = 0, int $depth = 2): array
    {
        return $this->buildTree($parentId, $depth, 0);
    }

    /**
     * Build tree recursively
     */
    protected function buildTree(int $parentId, int $maxDepth, int $currentDepth): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $resources = SiteContent::where('parent', $parentId)
            ->withoutTrashed()
            ->orderBy('menuindex')
            ->get(['id', 'pagetitle', 'alias', 'parent', 'isfolder', 'published', 'template']);

        $tree = [];

        foreach ($resources as $resource) {
            $item = [
                'id' => $resource->id,
                'pagetitle' => $resource->pagetitle,
                'alias' => $resource->alias,
                'isfolder' => (bool) $resource->isfolder,
                'published' => (bool) $resource->published,
                'children' => [],
            ];

            if ($resource->isfolder) {
                $item['children'] = $this->buildTree($resource->id, $maxDepth, $currentDepth + 1);
            }

            $tree[] = $item;
        }

        return $tree;
    }
}
