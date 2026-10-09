<?php

namespace EvolutionCMS\AiAssistant\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $table = 'ai_assistant_jobs';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['state' => 'array', 'user_id' => 'integer', 'hidden' => 'boolean'];

    public function terminal(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'needs_review', 'cancelled'], true);
    }
}
