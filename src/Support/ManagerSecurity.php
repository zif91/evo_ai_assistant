<?php

namespace EvolutionCMS\AiAssistant\Support;

final class ManagerSecurity
{
    public static function allowed(): bool
    {
        // Templates and Blade files can execute PHP. Restrict the assistant to
        // administrators rather than bypassing resource-group ACLs for editors.
        return !empty($_SESSION['mgrValidated'])
            && !empty($_SESSION['mgrInternalKey'])
            && (int) ($_SESSION['mgrRole'] ?? 0) === 1;
    }

    public static function token(): string
    {
        if (empty($_SESSION['ai_assistant_csrf'])) {
            $_SESSION['ai_assistant_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['ai_assistant_csrf'];
    }

    public static function validToken(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['ai_assistant_csrf'])
            && hash_equals($_SESSION['ai_assistant_csrf'], $token);
    }
}
