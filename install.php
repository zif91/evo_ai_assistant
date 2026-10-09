<?php
/** CLI-only installer. No public endpoint may change CMS configuration. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run php core/custom/packages/ai-assistant/install.php from the site directory.');
}
$corePath = dirname(__DIR__, 3);
if (!is_file($corePath . '/bootstrap.php')) {
    fwrite(STDERR, "Place this package in core/custom/packages/ai-assistant first.\n");
    exit(1);
}
define('MODX_API_MODE', true);
define('IN_MANAGER_MODE', true);
define('IN_INSTALL_MODE', false);
require $corePath . '/bootstrap.php';
// Bootstrap registration also works before Composer knows this package.
spl_autoload_register(function ($class) {
    $prefix = 'EvolutionCMS\\AiAssistant\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
try {
    evo();
    (new EvolutionCMS\AiAssistant\Services\Installer())->install();
    $customComposer = $corePath . '/custom/composer.json';
    $composer = is_file($customComposer) ? json_decode(file_get_contents($customComposer), true, 512, JSON_THROW_ON_ERROR) : [];
    $composer['autoload']['psr-4']['EvolutionCMS\\AiAssistant\\'] = 'packages/ai-assistant/src/';
    $providers = $composer['extra']['laravel']['providers'] ?? [];
    $providers[] = EvolutionCMS\AiAssistant\AiAssistantServiceProvider::class;
    $composer['extra']['laravel']['providers'] = array_values(array_unique($providers));
    if (is_file($customComposer)) {
        copy($customComposer, $customComposer . '.ai-assistant-' . date('Ymd-His') . '.bak');
    }
    if (file_put_contents($customComposer, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        throw new RuntimeException('Cannot update custom/composer.json');
    }
    echo "Installed. Now run in core/: composer dump-autoload && php artisan package:discover && php artisan cache:clear-full\n";
    echo "Configure your API key in Modules → AI Assistant Settings. Existing settings are preserved.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Installation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
