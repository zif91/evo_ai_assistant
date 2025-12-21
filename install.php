<?php
/**
 * AI Assistant Installer for Evolution CMS 3.x
 *
 * Usage:
 * 1. Copy ai-assistant folder to /core/custom/packages/
 * 2. Open in browser: https://yoursite.com/core/custom/packages/ai-assistant/install.php
 * 3. Enter your OpenRouter API key
 * 4. Click Install
 */

// Find Evolution CMS
$basePath = dirname(__DIR__, 3);
$corePath = $basePath . '/core';

if (!file_exists($corePath . '/vendor/autoload.php')) {
    die('Error: Evolution CMS not found. Make sure this folder is in /core/custom/packages/');
}

// Try to get database connection
$configFile = $corePath . '/config/database.php';
if (!file_exists($configFile)) {
    die('Error: Database config not found.');
}

$dbConfig = include $configFile;
$connection = $dbConfig['connections'][$dbConfig['default']] ?? null;

if (!$connection) {
    die('Error: No database connection configured.');
}

$prefix = $connection['prefix'] ?? 'evo_';

// Connect to database
try {
    $pdo = new PDO(
        "mysql:host={$connection['host']};dbname={$connection['database']};charset=utf8mb4",
        $connection['username'],
        $connection['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

$messages = [];
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    $apiKey = trim($_POST['api_key'] ?? '');
    $model = trim($_POST['model'] ?? 'openai/gpt-4o-mini');

    if (empty($apiKey)) {
        $errors[] = 'API Key is required';
    }

    if (empty($errors)) {
        // 1. Create checkpoints table
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `{$prefix}ai_checkpoints` (
                    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                    `session_id` varchar(255) DEFAULT NULL,
                    `entity_type` varchar(50) NOT NULL,
                    `entity_id` int(11) unsigned NOT NULL,
                    `field_name` varchar(100) DEFAULT NULL,
                    `old_value` longtext,
                    `new_value` longtext,
                    `description` varchar(255) DEFAULT NULL,
                    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `entity_type_id` (`entity_type`, `entity_id`),
                    KEY `session_id` (`session_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $messages[] = 'Created table: ai_checkpoints';
        } catch (PDOException $e) {
            $errors[] = 'Failed to create table: ' . $e->getMessage();
        }

        // 2. Add system settings
        $settings = [
            'ai_assistant_provider' => 'openai',
            'ai_assistant_api_key' => $apiKey,
            'ai_assistant_api_url' => 'https://openrouter.ai/api/v1',
            'ai_assistant_model' => $model,
        ];

        foreach ($settings as $name => $value) {
            try {
                $stmt = $pdo->prepare("REPLACE INTO `{$prefix}system_settings` (`setting_name`, `setting_value`) VALUES (?, ?)");
                $stmt->execute([$name, $value]);
            } catch (PDOException $e) {
                $errors[] = "Failed to add setting {$name}: " . $e->getMessage();
            }
        }
        $messages[] = 'Added system settings';

        // 3. Create plugin
        $pluginName = 'AI Assistant';
        $pluginCode = <<<'PLUGIN'
$e = &$modx->Event;

if ($e->name !== 'OnManagerMainFrameHeaderHTMLBlock') {
    return;
}

$siteUrl = MODX_SITE_URL;
$baseUrl = rtrim($siteUrl, '/');
$panelUrl = $baseUrl . '/ai-assistant/';

$output = <<<HTML
<style>
#ai-toggle-btn{position:fixed;right:0;top:50%;transform:translateY(-50%);z-index:9998;background:#6366f1;color:#fff;border:none;padding:10px 8px;cursor:pointer;border-radius:8px 0 0 8px;box-shadow:-2px 0 10px rgba(0,0,0,.2);transition:all .3s}
#ai-toggle-btn:hover{padding-right:12px;background:#4f46e5}
#ai-toggle-btn svg{display:block}
#ai-sidebar{position:fixed;right:-420px;top:0;width:420px;height:100vh;z-index:9999;transition:right .3s ease;box-shadow:-5px 0 20px rgba(0,0,0,.15)}
#ai-sidebar.open{right:0}
#ai-sidebar iframe{width:100%;height:100%;border:none}
#ai-close-btn{position:absolute;left:-36px;top:10px;background:#6366f1;color:#fff;border:none;width:32px;height:32px;border-radius:8px 0 0 8px;cursor:pointer;display:flex;align-items:center;justify-content:center}
#ai-close-btn:hover{background:#4f46e5}
</style>
<button id="ai-toggle-btn" title="AI Assistant">
<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662"/>
</svg>
</button>
<div id="ai-sidebar">
<button id="ai-close-btn" title="Close">
<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
</svg>
</button>
<iframe id="ai-frame" src="about:blank"></iframe>
</div>
<script>
(function(){
var btn=document.getElementById('ai-toggle-btn'),
    sidebar=document.getElementById('ai-sidebar'),
    closeBtn=document.getElementById('ai-close-btn'),
    frame=document.getElementById('ai-frame'),
    loaded=false;
function toggle(){
    var isOpen=sidebar.classList.toggle('open');
    if(isOpen&&!loaded){frame.src='{$panelUrl}';loaded=true;}
    btn.style.display=isOpen?'none':'block';
}
btn.onclick=toggle;
closeBtn.onclick=toggle;
})();
</script>
HTML;

$e->output($output);
PLUGIN;

        // Check if plugin exists
        $stmt = $pdo->prepare("SELECT id FROM `{$prefix}site_plugins` WHERE name = ?");
        $stmt->execute([$pluginName]);
        $existingPlugin = $stmt->fetch();

        if ($existingPlugin) {
            $stmt = $pdo->prepare("UPDATE `{$prefix}site_plugins` SET plugincode = ?, disabled = 0 WHERE id = ?");
            $stmt->execute([$pluginCode, $existingPlugin['id']]);
            $messages[] = 'Updated plugin: AI Assistant';
        } else {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}site_plugins` (name, description, plugincode, disabled, category) VALUES (?, ?, ?, 0, 0)");
            $stmt->execute([$pluginName, 'AI Assistant sidebar for content management', $pluginCode]);
            $pluginId = $pdo->lastInsertId();

            // Get event ID
            $stmt = $pdo->prepare("SELECT id FROM `{$prefix}system_eventnames` WHERE name = 'OnManagerMainFrameHeaderHTMLBlock'");
            $stmt->execute();
            $event = $stmt->fetch();

            if ($event) {
                $stmt = $pdo->prepare("INSERT INTO `{$prefix}site_plugin_events` (pluginid, evtid, priority) VALUES (?, ?, 0)");
                $stmt->execute([$pluginId, $event['id']]);
            }
            $messages[] = 'Created plugin: AI Assistant';
        }

        // 4. Create module
        $moduleName = 'AI Assistant Settings';
        $moduleCode = <<<'MODULE'
$settings = [
    'ai_assistant_provider' => evo()->getConfig('ai_assistant_provider', 'openai'),
    'ai_assistant_api_key' => evo()->getConfig('ai_assistant_api_key', ''),
    'ai_assistant_api_url' => evo()->getConfig('ai_assistant_api_url', 'https://openrouter.ai/api/v1'),
    'ai_assistant_model' => evo()->getConfig('ai_assistant_model', 'openai/gpt-4o-mini'),
];

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $db = evo()->getDatabase();
    $table = evo()->getDatabase()->getFullTableName('system_settings');

    $fields = ['ai_assistant_provider', 'ai_assistant_api_key', 'ai_assistant_api_url', 'ai_assistant_model'];
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            $value = $db->escape($_POST[$field]);
            $db->query("REPLACE INTO {$table} (setting_name, setting_value) VALUES ('{$field}', '{$value}')");
            $settings[$field] = $_POST[$field];
        }
    }
    evo()->clearCache('full');
    $message = '<div style="background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:10px;border-radius:4px;margin-bottom:15px;">Settings saved!</div>';
}

$models = [
    'openai/gpt-4o-mini' => 'GPT-4o Mini ($0.15/M)',
    'anthropic/claude-sonnet-4' => 'Claude Sonnet 4 ($3/M)',
    'anthropic/claude-haiku-4' => 'Claude Haiku 4 ($0.25/M)',
    'google/gemini-2.5-flash' => 'Gemini 2.5 Flash ($0.10/M)',
    'deepseek/deepseek-chat-v3' => 'DeepSeek V3 ($0.14/M)',
    'x-ai/grok-3-fast' => 'Grok 3 Fast ($0.50/M)',
    'mistralai/devstral' => 'Devstral ($1/M)',
];

$modelOptions = '';
foreach ($models as $id => $label) {
    $selected = ($settings['ai_assistant_model'] === $id) ? 'selected' : '';
    $modelOptions .= "<option value=\"{$id}\" {$selected}>{$label}</option>";
}

echo <<<HTML
<h1 style="margin-bottom:20px;">AI Assistant Settings</h1>
{$message}
<form method="post" style="max-width:600px;">
<div style="margin-bottom:15px;">
    <label style="display:block;margin-bottom:5px;font-weight:bold;">API URL</label>
    <input type="text" name="ai_assistant_api_url" value="{$settings['ai_assistant_api_url']}" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
    <small style="color:#666;">OpenRouter: https://openrouter.ai/api/v1</small>
</div>
<div style="margin-bottom:15px;">
    <label style="display:block;margin-bottom:5px;font-weight:bold;">API Key</label>
    <input type="password" name="ai_assistant_api_key" value="{$settings['ai_assistant_api_key']}" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
    <small style="color:#666;">Get your key at <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a></small>
</div>
<div style="margin-bottom:15px;">
    <label style="display:block;margin-bottom:5px;font-weight:bold;">Model</label>
    <select name="ai_assistant_model" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
        {$modelOptions}
    </select>
</div>
<input type="hidden" name="ai_assistant_provider" value="openai">
<button type="submit" name="save_settings" value="1" style="background:#6366f1;color:white;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:14px;">Save Settings</button>
</form>
HTML;
MODULE;

        $stmt = $pdo->prepare("SELECT id FROM `{$prefix}site_modules` WHERE name = ?");
        $stmt->execute([$moduleName]);
        $existingModule = $stmt->fetch();

        if ($existingModule) {
            $stmt = $pdo->prepare("UPDATE `{$prefix}site_modules` SET modulecode = ?, disabled = 0 WHERE id = ?");
            $stmt->execute([$moduleCode, $existingModule['id']]);
            $messages[] = 'Updated module: AI Assistant Settings';
        } else {
            $stmt = $pdo->prepare("INSERT INTO `{$prefix}site_modules` (name, description, modulecode, disabled, category) VALUES (?, ?, ?, 0, 0)");
            $stmt->execute([$moduleName, 'Configure AI Assistant API settings', $moduleCode]);
            $messages[] = 'Created module: AI Assistant Settings';
        }

        // 5. Create/update routes.php
        $routesFile = dirname(__DIR__) . '/routes.php';
        $routesCode = <<<'ROUTES'
<?php
// AI Assistant Routes
Route::group(['prefix' => 'ai-assistant', 'middleware' => [\EvolutionCMS\AiAssistant\Http\Middleware\AiAssistantAuth::class]], function() {
    Route::get('/', [\EvolutionCMS\AiAssistant\Controllers\PanelController::class, 'index']);
    Route::post('/api/chat', [\EvolutionCMS\AiAssistant\Controllers\ApiController::class, 'chat']);
    Route::delete('/api/history', [\EvolutionCMS\AiAssistant\Controllers\ApiController::class, 'clearHistory']);
    Route::get('/api/resources', [\EvolutionCMS\AiAssistant\Controllers\ApiController::class, 'searchResources']);
    Route::get('/api/templates', [\EvolutionCMS\AiAssistant\Controllers\ApiController::class, 'listTemplates']);
    Route::get('/api/tv', [\EvolutionCMS\AiAssistant\Controllers\ApiController::class, 'listTv']);
});
ROUTES;

        if (file_exists($routesFile)) {
            $existingRoutes = file_get_contents($routesFile);
            if (strpos($existingRoutes, 'ai-assistant') === false) {
                file_put_contents($routesFile, $existingRoutes . "\n" . $routesCode);
                $messages[] = 'Added routes to existing routes.php';
            } else {
                $messages[] = 'Routes already exist in routes.php';
            }
        } else {
            file_put_contents($routesFile, $routesCode);
            $messages[] = 'Created routes.php';
        }

        // 6. Create/update config/app.php
        $configDir = dirname(__DIR__) . '/config';
        $appConfigFile = $configDir . '/app.php';

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $appConfig = <<<'CONFIG'
<?php
return [
    'providers' => [
        EvolutionCMS\AiAssistant\AiAssistantServiceProvider::class,
    ],
];
CONFIG;

        if (file_exists($appConfigFile)) {
            $existingConfig = file_get_contents($appConfigFile);
            if (strpos($existingConfig, 'AiAssistantServiceProvider') === false) {
                if (preg_match("/'providers'\s*=>\s*\[/", $existingConfig)) {
                    $existingConfig = preg_replace(
                        "/('providers'\s*=>\s*\[)/",
                        "$1\n        EvolutionCMS\\AiAssistant\\AiAssistantServiceProvider::class,",
                        $existingConfig
                    );
                    file_put_contents($appConfigFile, $existingConfig);
                    $messages[] = 'Added ServiceProvider to existing app.php';
                } else {
                    file_put_contents($appConfigFile, $appConfig);
                    $messages[] = 'Replaced app.php with new config';
                }
            } else {
                $messages[] = 'ServiceProvider already registered';
            }
        } else {
            file_put_contents($appConfigFile, $appConfig);
            $messages[] = 'Created config/app.php';
        }

        // 7. Clear cache
        $cacheDir = $corePath . '/cache';
        if (is_dir($cacheDir)) {
            array_map('unlink', glob($cacheDir . '/*.php'));
            $messages[] = 'Cleared cache';
        }

        if (empty($errors)) {
            $messages[] = '<strong>Installation complete!</strong>';
        }
    }
}

// Available models
$models = [
    'openai/gpt-4o-mini' => 'GPT-4o Mini - $0.15/M (recommended)',
    'anthropic/claude-sonnet-4' => 'Claude Sonnet 4 - $3/M',
    'anthropic/claude-haiku-4' => 'Claude Haiku 4 - $0.25/M',
    'google/gemini-2.5-flash' => 'Gemini 2.5 Flash - $0.10/M',
    'deepseek/deepseek-chat-v3' => 'DeepSeek V3 - $0.14/M',
    'x-ai/grok-3-fast' => 'Grok 3 Fast - $0.50/M',
    'mistralai/devstral' => 'Devstral - $1/M',
];

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Assistant Installer</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; padding: 40px 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #6366f1; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 5px; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
        input:focus, select:focus { border-color: #6366f1; outline: none; }
        small { color: #888; display: block; margin-top: 5px; }
        button { background: #6366f1; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: 600; }
        button:hover { background: #4f46e5; }
        .message { padding: 10px 15px; border-radius: 5px; margin-bottom: 10px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #e7f3ff; color: #0c5460; border: 1px solid #bee5eb; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>AI Assistant Installer</h1>
        <p class="subtitle">for Evolution CMS 3.x</p>

        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $msg): ?>
                <div class="message success"><?= $msg ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <div class="message error"><?= $err ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (empty($messages) || !empty($errors)): ?>
            <div class="info">
                This will install AI Assistant and configure:
                <ul style="margin: 10px 0 0 20px;">
                    <li>Database table for checkpoints</li>
                    <li>System settings</li>
                    <li>Plugin (sidebar button)</li>
                    <li>Settings module</li>
                    <li>Routes</li>
                    <li>Service Provider</li>
                </ul>
            </div>

            <form method="post">
                <div class="form-group">
                    <label>OpenRouter API Key *</label>
                    <input type="text" name="api_key" placeholder="sk-or-v1-..." required>
                    <small>Get your key at <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a></small>
                </div>

                <div class="form-group">
                    <label>AI Model</label>
                    <select name="model">
                        <?php foreach ($models as $id => $label): ?>
                            <option value="<?= $id ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" name="install" value="1">Install AI Assistant</button>
            </form>
        <?php else: ?>
            <p style="margin-top: 20px;">
                <strong>Go to Manager</strong> and look for the purple AI Assistant button on the right side.
            </p>
            <p style="margin-top: 10px; color: #888;">
                You can change settings in: Modules &rarr; AI Assistant Settings
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
