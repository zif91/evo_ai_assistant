<?php

namespace EvolutionCMS\AiAssistant\Services;

use EvolutionCMS\AiAssistant\Models\Job;
use Illuminate\Support\Facades\DB;

/** Durable steps shared by AJAX and the CLI worker. No credentials in job state. */
class JobService
{
    public const ACTIVE = ['queued', 'running', 'applying'];

    public static function lock(string $name)
    {
        $dir = storage_path('ai-assistant/locks');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create worker lock directory');
        }
        $handle = fopen($dir . '/' . hash('sha256', $name) . '.lock', 'c');
        if (!$handle) throw new \RuntimeException('Cannot open worker lock');
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    public static function unlock($handle): void
    {
        if ($handle) { flock($handle, LOCK_UN); fclose($handle); }
    }

    public static function heartbeat(?int $user = null): void
    {
        DB::table('ai_assistant_settings')->updateOrInsert(['key' => 'worker_heartbeat_' . ($user ?? (defined('EVO_CLI_USER') ? EVO_CLI_USER : ($_SESSION['mgrInternalKey'] ?? 1)))], ['value' => (string) time()]);
    }

    public static function workerStatus(?int $user = null): array
    {
        $last = (int) DB::table('ai_assistant_settings')->where('key', 'worker_heartbeat_' . ($user ?? ($_SESSION['mgrInternalKey'] ?? 1)))->value('value');
        return ['available' => $last > time() - 420, 'last_seen' => $last ?: null,
            'instructions_url' => 'https://github.com/zif91/evo_ai_assistant/blob/main/docs/worker.md'];
    }

    public function enqueue(int $user, string $session, string $request, string $prompt, array $state): Job
    {
        $lock = self::lock('enqueue-' . $user);
        if (!$lock) throw new \RuntimeException('Another request is being submitted. Retry with the same request ID.');
        try {
            $previous = Job::where('user_id', $user)->where('request_id', $request)->first();
            if ($previous) return $previous;
            // One active conversation per manager user, including other tabs.
            $active = $this->active($user);
            if ($active) return $active;
            return Job::create(['id' => bin2hex(random_bytes(24)), 'request_id' => $request,
                'user_id' => $user, 'session_id' => $session, 'prompt' => $prompt,
                'mode' => self::workerStatus($user)['available'] ? 'worker' : 'browser',
                'status' => 'queued', 'state' => $state]);
        } finally { self::unlock($lock); }
    }

    public function active(int $user): ?Job
    {
        return Job::where('user_id', $user)->whereIn('status', self::ACTIVE)->oldest()->first();
    }

    public function history(int $user): array
    {
        $history = [];
        $jobs = Job::where('user_id', $user)->where('hidden', false)->whereNotIn('status', self::ACTIVE)
            ->latest()->limit(5)->get()->reverse();
        foreach ($jobs as $job) {
            $history[] = ['role' => 'user', 'content' => $job->prompt];
            $history[] = ['role' => 'assistant', 'content' => $job->error ?: ($job->state['content'] ?? ''),
                'actions' => $job->state['actions'] ?? [], 'job_id' => $job->id, 'retryable' => $job->status === 'failed' && !empty($job->state['retryable'])];
        }
        return $history;
    }

    public function summary(Job $job): array
    {
        $state = $job->state;
        return ['id' => $job->id, 'status' => $job->status, 'mode' => $job->mode,
            'done' => $job->terminal(), 'success' => $job->status === 'completed',
            'prompt' => $job->prompt, 'message' => $state['content'] ?? '', 'error' => $job->error,
            'retryable' => $job->status === 'failed' && !empty($state['retryable']),
            'iterations' => $state['iterations'] ?? 0, 'actions' => $state['actions'] ?? [],
            'worker' => self::workerStatus($job->user_id)];
    }

    public function retry(Job $job): Job
    {
        $ownerLock = self::lock('enqueue-' . $job->user_id);
        if (!$ownerLock) throw new \RuntimeException('Another task is being submitted');
        $lock = null;
        try {
            $lock = self::lock('job-' . $job->id);
            if (!$lock) throw new \RuntimeException('Step still running');
            $job->refresh();
            if ($job->status !== 'failed' || empty($job->state['retryable']) || $this->active($job->user_id)) {
                throw new \RuntimeException('This step cannot be retried; review saved actions.');
            }
            $job->mode = self::workerStatus($job->user_id)['available'] ? 'worker' : 'browser';
            $job->status = 'queued'; $job->error = null; $job->save();
            return $job;
        } finally { self::unlock($lock); self::unlock($ownerLock); }
    }

    public function cancel(Job $job): Job
    {
        $lock = self::lock('job-' . $job->id);
        if (!$lock) throw new \RuntimeException('A step is still running. Try cancelling again after it finishes.');
        try {
            $job->refresh();
            if (!$job->terminal()) {
                $job->status = $job->status === 'applying' ? 'needs_review' : 'cancelled';
                $job->error = 'Stopped. Already completed actions remain saved; review them before starting another task.';
                $job->save();
            }
            return $job;
        } finally { self::unlock($lock); }
    }

    /** One inference OR one tool. A lost action acknowledgement must never cause a blind replay. */
    public function advance(Job $job, AiService $ai, callable $executor): Job
    {
        $lock = self::lock('job-' . $job->id);
        if (!$lock) return $job->refresh();
        try {
            $job->refresh();
            if ($job->terminal()) return $job;
            if ($job->status === 'applying') {
                $job->status = 'needs_review';
                $job->error = 'Execution stopped during a CMS action. Check the page and saved actions before continuing; this action was not repeated.';
                $job->save();
                return $job;
            }
            $state = $job->state;
            if (!empty($state['pending'])) {
                $action = $state['pending'][$state['cursor']];
                // Persist intent BEFORE mutation. A hard PHP/process kill leaves this marker.
                $job->status = 'applying';
                $job->save();
                $result = $executor($action['name'], $action['arguments']);
                $state['actions'][] = array_merge(['name' => $action['name']], $result);
                $state['messages'][] = ['role' => 'tool', 'tool_call_id' => $action['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                $state['cursor']++;
                if ($state['cursor'] >= count($state['pending'])) {
                    $state['pending'] = []; $state['cursor'] = 0;
                }
                $job->status = 'running';
            } else {
                if ($state['iterations'] >= $ai->iterationLimit()) {
                    throw new \RuntimeException('Step limit reached. Review saved actions before continuing.');
                }
                $job->status = 'running'; $job->save();
                $response = $ai->infer($state['messages']);
                $state['iterations']++;
                $state['content'] = $response['content'] ?? '';
                $state['pending'] = $response['actions'] ?? [];
                $state['cursor'] = 0;
                if (!$state['pending']) {
                    $job->status = 'completed';
                } else {
                    // Preserve provider metadata (e.g. reasoning_details) across PHP processes.
                    $state['messages'][] = $response['assistant_message'];
                }
            }
            $job->state = $state;
            $job->save();
        } catch (\Throwable $e) {
            // Do not store provider response bodies, prompts or secrets in errors/logs.
            $job->refresh();
            $job->error = $job->status === 'applying'
                ? 'CMS action interrupted. Check the result before continuing; it was not repeated.'
                : 'AI step failed. Check provider availability, model and limits. Completed actions are saved.';
            $state = $job->state;
            $state['retryable'] = $job->status !== 'applying' && $state['iterations'] < $ai->iterationLimit();
            $job->state = $state;
            if ($e->getCode() >= 400 && $e->getCode() <= 599) $job->error .= ' HTTP ' . $e->getCode() . '.';
            $job->status = $job->status === 'applying' ? 'needs_review' : 'failed';
            $job->save();
        } finally { self::unlock($lock); }
        return $job;
    }
}
