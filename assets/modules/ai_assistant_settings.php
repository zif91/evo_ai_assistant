<?php
/**
 * AI Assistant Settings Module
 *
 * This module provides a UI for configuring AI Assistant settings
 * Settings are stored in Evolution CMS system_settings table
 */

if (!defined('IN_MANAGER_MODE') || IN_MANAGER_MODE !== true) {
    exit('Access denied');
}

$modx = evo();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $settings = [
        'ai_assistant_provider' => $_POST['ai_assistant_provider'] ?? 'openai',
        'ai_assistant_api_key' => $_POST['ai_assistant_api_key'] ?? '',
        'ai_assistant_api_url' => $_POST['ai_assistant_api_url'] ?? 'https://api.openai.com/v1',
        'ai_assistant_model' => $_POST['ai_assistant_model'] ?? 'gpt-4',
    ];

    foreach ($settings as $key => $value) {
        // Check if setting exists
        $result = $modx->db->select('setting_name', $modx->getDatabase()->getFullTableName('system_settings'), "setting_name='" . $modx->db->escape($key) . "'");

        if ($modx->db->getRecordCount($result) > 0) {
            // Update
            $modx->db->update(
                ['setting_value' => $modx->db->escape($value)],
                $modx->getDatabase()->getFullTableName('system_settings'),
                "setting_name='" . $modx->db->escape($key) . "'"
            );
        } else {
            // Insert
            $modx->db->insert(
                ['setting_name' => $key, 'setting_value' => $modx->db->escape($value)],
                $modx->getDatabase()->getFullTableName('system_settings')
            );
        }
    }

    // Clear cache
    $modx->clearCache('full');

    $success = true;
}

// Get current values
$provider = $modx->getConfig('ai_assistant_provider', 'openai');
$apiKey = $modx->getConfig('ai_assistant_api_key', '');
$apiUrl = $modx->getConfig('ai_assistant_api_url', 'https://api.openai.com/v1');
$model = $modx->getConfig('ai_assistant_model', 'gpt-4');

// Mask API key for display
$maskedKey = $apiKey ? substr($apiKey, 0, 8) . '...' . substr($apiKey, -4) : '';

?>
<!DOCTYPE html>
<html>
<head>
    <title>AI Assistant Settings</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            margin-top: 0;
            color: #333;
            font-size: 24px;
            border-bottom: 2px solid #6366f1;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        .help-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        input:focus,
        select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        .btn {
            background: #6366f1;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #4f46e5;
        }
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        .api-key-container {
            position: relative;
        }
        .api-key-container input {
            padding-right: 100px;
        }
        .toggle-visibility {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6366f1;
            cursor: pointer;
            font-size: 12px;
        }
        .provider-info {
            background: #f0f0ff;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .provider-info h3 {
            margin-top: 0;
            font-size: 14px;
        }
        .provider-info p {
            margin-bottom: 0;
            font-size: 13px;
        }
        .current-value {
            font-size: 12px;
            color: #888;
            margin-top: 3px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🤖 AI Assistant Settings</h1>

        <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            Settings saved successfully! Cache has been cleared.
        </div>
        <?php endif; ?>

        <div class="provider-info">
            <h3>About AI Provider</h3>
            <p>AI Assistant supports OpenAI (GPT-4, GPT-3.5) and Anthropic (Claude) APIs.
               You can also use OpenAI-compatible APIs by specifying a custom API URL.</p>
        </div>

        <form method="post">
            <?php if (function_exists('csrf_token')): ?>
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="ai_assistant_provider">AI Provider</label>
                <select name="ai_assistant_provider" id="ai_assistant_provider">
                    <option value="openai" <?= $provider === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                    <option value="anthropic" <?= $provider === 'anthropic' ? 'selected' : '' ?>>Anthropic (Claude)</option>
                </select>
                <div class="help-text">Select your AI provider</div>
            </div>

            <div class="form-group">
                <label for="ai_assistant_api_key">API Key</label>
                <div class="api-key-container">
                    <input type="password"
                           name="ai_assistant_api_key"
                           id="ai_assistant_api_key"
                           value="<?= htmlspecialchars($apiKey) ?>"
                           placeholder="sk-...">
                    <button type="button" class="toggle-visibility" onclick="toggleApiKey()">Show/Hide</button>
                </div>
                <?php if ($maskedKey): ?>
                <div class="current-value">Current: <?= htmlspecialchars($maskedKey) ?></div>
                <?php endif; ?>
                <div class="help-text">Your API key from OpenAI or Anthropic</div>
            </div>

            <div class="form-group">
                <label for="ai_assistant_api_url">API URL</label>
                <input type="text"
                       name="ai_assistant_api_url"
                       id="ai_assistant_api_url"
                       value="<?= htmlspecialchars($apiUrl) ?>"
                       placeholder="https://api.openai.com/v1">
                <div class="help-text">API endpoint. Change for OpenAI-compatible APIs (like Azure, local LLMs)</div>
            </div>

            <div class="form-group">
                <label for="ai_assistant_model">Model</label>
                <input type="text"
                       name="ai_assistant_model"
                       id="ai_assistant_model"
                       value="<?= htmlspecialchars($model) ?>"
                       placeholder="gpt-4">
                <div class="help-text">Model name: gpt-4, gpt-3.5-turbo, claude-3-sonnet-20240229, etc.</div>
            </div>

            <button type="submit" name="save" class="btn">Save Settings</button>
        </form>
    </div>

    <script>
        function toggleApiKey() {
            var input = document.getElementById('ai_assistant_api_key');
            input.type = input.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>
</html>
