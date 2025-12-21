<?php

namespace EvolutionCMS\AiAssistant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AiAssistantAuth
{
    /**
     * Handle an incoming request.
     * Ensure user is logged in to the manager and has appropriate permissions.
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if user is logged in to the manager
        $isLoggedIn = isset($_SESSION['mgrValidated']) && $_SESSION['mgrValidated'] == 1;

        // Also check via evo() if available
        if (!$isLoggedIn && function_exists('evo')) {
            try {
                $internalKey = evo()->getLoginUserID('mgr');
                $isLoggedIn = !empty($internalKey);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if (!$isLoggedIn) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized. Please log in to the manager.',
            ], 401);
        }

        return $next($request);
    }
}
