<?php

namespace EvolutionCMS\AiAssistant\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $table = 'ai_assistant_conversations';

    protected $fillable = [
        'session_id',
        'user_id',
        'role',
        'content',
        'metadata',
    ];

    protected $casts = [
        'user_id' => 'int',
        'metadata' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get conversations for a session
     */
    public static function forSession(string $sessionId)
    {
        return static::where('session_id', $sessionId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Clear old conversations
     */
    public static function cleanup(int $days = 7): int
    {
        return static::where('created_at', '<', now()->subDays($days))
            ->delete();
    }
}
