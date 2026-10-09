<?php
/** Run from hosting cron, never over HTTP. Uses the same database and storage as CMS. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$options = getopt('', ['max-seconds:', 'user:', 'once', 'prune']);
$budget = max(1, min(900, (int) ($options['max-seconds'] ?? 50)));
$core = dirname(__DIR__, 3);
if (!is_file($core . '/bootstrap.php')) {
    fwrite(STDERR, "Place the package in core/custom/packages/ai-assistant.\n"); exit(1);
}
// Set BEFORE bootstrap: Evolution otherwise fixes the CLI author to user 1.
$workerUser = max(1, (int) ($options['user'] ?? 1));
define('EVO_CLI_USER', $workerUser);
define('MODX_API_MODE', true);
define('IN_MANAGER_MODE', true);
define('IN_INSTALL_MODE', false);
require $core . '/bootstrap.php';
spl_autoload_register(static function ($class) {
    $prefix = 'EvolutionCMS\\AiAssistant\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require_once $path;
    }
});

use EvolutionCMS\AiAssistant\Models\Job;
use EvolutionCMS\AiAssistant\Services\JobService;
use EvolutionCMS\AiAssistant\Controllers\ApiController;
use Illuminate\Support\Facades\DB;

$lock = null;
try {
    evo();
    // Never persist the synthetic manager context in a native PHP session.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $_SESSION = [];
    $lock = JobService::lock('worker-' . $workerUser);
    if (!$lock) { echo "Worker already running.\n"; exit(0); }
    @set_time_limit(0);
    JobService::heartbeat();
    if (isset($options['prune'])) {
        Job::where('user_id', $workerUser)->whereNotIn('status', JobService::ACTIVE)->where('updated_at', '<', date('Y-m-d H:i:s', time() - 30 * 86400))->delete();
    }
    $deadline = microtime(true) + $budget;
    $steps = 0;
    $failures = 0;
    do {
        JobService::heartbeat();
        $job = Job::where('user_id', $workerUser)->where('mode', 'worker')->whereIn('status', JobService::ACTIVE)->oldest()->first();
        if (!$job) break;
        // Authorization may have been revoked since the task was submitted.
        $user = DB::table('user_attributes')->where('internalKey', $job->user_id)->first();
        if (!$user || (int) $user->role !== 1 || !empty($user->blocked)
            || (int) ($user->blockeduntil ?? 0) > time()
            || ((int) ($user->blockedafter ?? 0) > 0 && (int) $user->blockedafter <= time())
            || !DB::table('users')->where('id', $job->user_id)->exists()) {
            // Cancellation obtains the same per-job lock as AJAX.
            (new JobService())->cancel($job);
            continue;
        }
        $_SESSION = ['mgrValidated' => true, 'mgrInternalKey' => $workerUser, 'mgrRole' => 1];
        evo()->setContext('mgr');
        $result = (new ApiController())->processJob($job);
        if (in_array($result->status, ['failed', 'needs_review'], true)) {
            $failures++;
            fwrite(STDERR, 'Job ' . $job->id . ': ' . $result->status . '. Check the assistant panel.' . PHP_EOL);
        }
        $steps++;
        JobService::heartbeat();
        if (isset($options['once'])) break;
    } while (microtime(true) < $deadline);
    echo "Worker OK: {$steps} step(s), {$failures} failed job(s).\n";
    if ($failures) exit(1);
} catch (Throwable $e) {
    // Avoid provider errors exposing keys or CMS text in hosting cron email.
    fwrite(STDERR, "Worker failed. Check installation, database and storage permissions.\n");
    exit(1);
} finally { JobService::unlock($lock); }
