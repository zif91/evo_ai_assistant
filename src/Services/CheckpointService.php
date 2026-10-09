<?php

namespace EvolutionCMS\AiAssistant\Services;

use EvolutionCMS\AiAssistant\Models\Checkpoint;
use EvolutionCMS\Models\SiteContent;
use EvolutionCMS\Models\SiteTmplvar;
use EvolutionCMS\Models\SiteTmplvarContentvalue;
use EvolutionCMS\Models\SiteTemplate;
use Illuminate\Support\Collection;

class CheckpointService
{
    /**
     * Create a checkpoint for an entity before modification
     */
    public function create(
        string $entityType,
        int $entityId,
        array|string|null $oldValue,
        array|string|null $newValue = null,
        ?string $fieldName = null,
        ?string $description = null
    ): Checkpoint {
        return Checkpoint::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field_name' => $fieldName,
            'old_value' => is_array($oldValue) ? $oldValue : ['value' => $oldValue],
            'new_value' => is_array($newValue) ? $newValue : ($newValue !== null ? ['value' => $newValue] : null),
            'user_id' => evo()->getLoginUserID('mgr') ?? null,
            'session_id' => session_id(),
            'description' => $description,
        ]);
    }

    /**
     * Create checkpoint for a resource
     */
    public function createForResource(SiteContent $resource, array $changes, ?string $description = null): Checkpoint
    {
        $oldValues = [];
        foreach (array_keys($changes) as $field) {
            $oldValues[$field] = $resource->getOriginal($field) ?? $resource->getAttribute($field);
        }

        return $this->create(
            Checkpoint::TYPE_RESOURCE,
            $resource->id,
            $oldValues,
            $changes,
            implode(', ', array_keys($changes)),
            $description
        );
    }

    /**
     * Create checkpoint for TV value
     */
    public function createForTvValue(
        int $resourceId,
        int $tvId,
        ?string $oldValue,
        ?string $newValue,
        ?string $description = null
    ): Checkpoint {
        return $this->create(
            Checkpoint::TYPE_TV_VALUE,
            $resourceId,
            ['tv_id' => $tvId, 'value' => $oldValue],
            ['tv_id' => $tvId, 'value' => $newValue],
            "tv_$tvId",
            $description
        );
    }

    /**
     * Create checkpoint for template
     */
    public function createForTemplate(SiteTemplate $template, array $changes, ?string $description = null): Checkpoint
    {
        $oldValues = [];
        foreach (array_keys($changes) as $field) {
            $oldValues[$field] = $template->getOriginal($field) ?? $template->getAttribute($field);
        }

        return $this->create(
            Checkpoint::TYPE_TEMPLATE,
            $template->id,
            $oldValues,
            $changes,
            implode(', ', array_keys($changes)),
            $description
        );
    }

    /**
     * Create checkpoint for TV definition
     */
    public function createForTv(SiteTmplvar $tv, array $changes, ?string $description = null): Checkpoint
    {
        $oldValues = [];
        foreach (array_keys($changes) as $field) {
            $oldValues[$field] = $tv->getOriginal($field) ?? $tv->getAttribute($field);
        }

        return $this->create(
            Checkpoint::TYPE_TV,
            $tv->id,
            $oldValues,
            $changes,
            implode(', ', array_keys($changes)),
            $description
        );
    }

    /**
     * Rollback a checkpoint
     */
    public function rollback(Checkpoint $checkpoint): bool
    {
        if ($checkpoint->is_rolled_back) {
            return false;
        }

        $oldValue = $checkpoint->old_value;
        if (!is_array($oldValue)) {
            return false;
        }

        switch ($checkpoint->entity_type) {
            case Checkpoint::TYPE_RESOURCE:
                $resource = SiteContent::find($checkpoint->entity_id);
                if ($resource && is_array($oldValue)) {
                    $resource->fill($oldValue);
                    $resource->save();
                } else {
                    return false;
                }
                break;

            case Checkpoint::TYPE_TV_VALUE:
                if (!isset($oldValue['tv_id']) || !array_key_exists('value', $oldValue)) {
                    return false;
                }
                if (isset($oldValue['tv_id'])) {
                    $tvValue = SiteTmplvarContentvalue::where('contentid', $checkpoint->entity_id)
                        ->where('tmplvarid', $oldValue['tv_id'])
                        ->first();

                    if ($tvValue) {
                        if ($oldValue['value'] === null) {
                            $tvValue->delete();
                        } else {
                            $tvValue->value = $oldValue['value'];
                            $tvValue->save();
                        }
                    } elseif ($oldValue['value'] !== null) {
                        SiteTmplvarContentvalue::create([
                            'contentid' => $checkpoint->entity_id,
                            'tmplvarid' => $oldValue['tv_id'],
                            'value' => $oldValue['value'],
                        ]);
                    }
                }
                break;

            case Checkpoint::TYPE_TEMPLATE:
                $template = SiteTemplate::find($checkpoint->entity_id);
                if ($template && is_array($oldValue)) {
                    $template->fill($oldValue);
                    $template->save();
                } else {
                    return false;
                }
                break;

            case Checkpoint::TYPE_TV:
                $tv = SiteTmplvar::find($checkpoint->entity_id);
                if ($tv && is_array($oldValue)) {
                    $tv->fill($oldValue);
                    $tv->save();
                } else {
                    return false;
                }
                break;

            case 'blade_template':
                $path = $oldValue['path'] ?? '';
                $real = realpath($path);
                $roots = array_filter([realpath(MODX_BASE_PATH . 'views'), realpath(resource_path('views'))]);
                $allowed = false;
                foreach ($roots as $root) {
                    $allowed = $allowed || ($real && str_starts_with($real, $root . DIRECTORY_SEPARATOR));
                }
                if (!$allowed || !str_ends_with($real, '.blade.php') || !isset($oldValue['content'])
                    || file_put_contents($real, $oldValue['content'], LOCK_EX) === false) {
                    return false;
                }
                break;
            default:
                return false;
        }

        evo()->clearCache('full');
        return $checkpoint->markAsRolledBack();
    }

    /**
     * Rollback all checkpoints for a session
     */
    public function rollbackSession(string $sessionId): int
    {
        $checkpoints = Checkpoint::forSession($sessionId);
        $count = 0;

        foreach ($checkpoints as $checkpoint) {
            if ($this->rollback($checkpoint)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get checkpoints for current session
     */
    public function getSessionCheckpoints(?string $sessionId = null): Collection
    {
        return Checkpoint::forSession($sessionId ?? session_id());
    }

    /**
     * Get checkpoints for entity
     */
    public function getEntityCheckpoints(string $entityType, int $entityId): Collection
    {
        return Checkpoint::forEntity($entityType, $entityId);
    }

    /**
     * Cleanup old checkpoints
     */
    public function cleanup(int $days = 30): int
    {
        return Checkpoint::where('created_at', '<', now()->subDays($days))
            ->where('is_rolled_back', true)
            ->delete();
    }
}
