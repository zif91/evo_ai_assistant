<?php

namespace EvolutionCMS\AiAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use EvolutionCMS\Models\SiteContent;
use EvolutionCMS\Models\SiteTmplvar;
use EvolutionCMS\Models\SiteTemplate;

class Checkpoint extends Model
{
    protected $table = 'ai_assistant_checkpoints';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'field_name',
        'old_value',
        'new_value',
        'user_id',
        'session_id',
        'description',
        'is_rolled_back',
    ];

    protected $casts = [
        'entity_id' => 'int',
        'user_id' => 'int',
        'is_rolled_back' => 'bool',
        'old_value' => 'json',
        'new_value' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Entity types constants
     */
    const TYPE_RESOURCE = 'resource';
    const TYPE_TV_VALUE = 'tv_value';
    const TYPE_TV = 'tv';
    const TYPE_TEMPLATE = 'template';

    /**
     * Get the related entity based on type
     */
    public function getEntity()
    {
        return match ($this->entity_type) {
            self::TYPE_RESOURCE => SiteContent::find($this->entity_id),
            self::TYPE_TV => SiteTmplvar::find($this->entity_id),
            self::TYPE_TEMPLATE => SiteTemplate::find($this->entity_id),
            default => null,
        };
    }

    /**
     * Get checkpoints for a specific entity
     */
    public static function forEntity(string $type, int $id)
    {
        return static::where('entity_type', $type)
            ->where('entity_id', $id)
            ->where('is_rolled_back', false)
            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Get checkpoints for current session
     */
    public static function forSession(string $sessionId)
    {
        return static::where('session_id', $sessionId)
            ->where('is_rolled_back', false)
            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Mark this checkpoint as rolled back
     */
    public function markAsRolledBack(): bool
    {
        $this->is_rolled_back = true;
        return $this->save();
    }

    /**
     * Get human-readable description
     */
    public function getDescription(): string
    {
        if ($this->description) {
            return $this->description;
        }

        $entityName = match ($this->entity_type) {
            self::TYPE_RESOURCE => __('ai-assistant::messages.entity.resource'),
            self::TYPE_TV_VALUE => __('ai-assistant::messages.entity.tv_value'),
            self::TYPE_TV => __('ai-assistant::messages.entity.tv'),
            self::TYPE_TEMPLATE => __('ai-assistant::messages.entity.template'),
            default => $this->entity_type,
        };

        return sprintf(
            '%s #%d - %s',
            $entityName,
            $this->entity_id,
            $this->field_name ?? 'multiple fields'
        );
    }
}
