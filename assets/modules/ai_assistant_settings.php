<?php

use EvolutionCMS\AiAssistant\Services\ModelCatalog;
use EvolutionCMS\AiAssistant\Support\ManagerSecurity;
use Illuminate\Support\Facades\DB;

if (!defined('IN_MANAGER_MODE') || !IN_MANAGER_MODE || !ManagerSecurity::allowed()) {
    http_response_code(403);
    exit('Administrator access required');
}
$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$error = '';
$success = false;
$names = ['provider', 'api_key', 'api_url', 'model'];
$values = [];
foreach ($names as $name) {
    $values[$name] = DB::table('system_settings')->where('setting_name', 'ai_assistant_' . $name)->value('setting_value') ?? '';
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!ManagerSecurity::validToken($_POST['_ai_token'] ?? null)) {
        $error = 'CSRF token mismatch. Reload settings.';
    } elseif (isset($_POST['save'])) {
        $provider = $_POST['provider'] ?? '';
        $url = rtrim(trim($_POST['api_url'] ?? ''), '/');
        $model = trim($_POST['model'] ?? '');
        $key = trim($_POST['api_key'] ?? '');
        if (!in_array($provider, ['openai', 'anthropic'], true) || !$model || strlen($model) > 200
            || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT)) {
            $error = 'Choose a provider, enter a model ID and a valid HTTPS API base URL.';
        } else {
            $values = ['provider' => $provider, 'api_url' => $url, 'model' => $model, 'api_key' => $key ?: $values['api_key']];
            DB::transaction(function () use ($values) {
                foreach ($values as $name => $value) {
                    DB::table('system_settings')->updateOrInsert(['setting_name' => 'ai_assistant_' . $name], ['setting_value' => $value]);
                }
            });
            evo()->clearCache('full');
            $success = true;
        }
    }
}
$catalog = (new ModelCatalog())->get(isset($_POST['refresh']) && !$error);
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Assistant Settings</title>
<style>body{font:15px system-ui;background:#f5f5f5;margin:24px;color:#222}.container{max-width:800px;margin:auto;background:white;padding:28px;border-radius:12px}label{display:block;margin-top:20px;font-weight:600}input,select{box-sizing:border-box;width:100%;padding:10px;margin:6px 0;border:1px solid #ccc;border-radius:6px}button{padding:10px 18px;background:#6366f1;color:white;border:0;border-radius:6px;cursor:pointer;margin-top:18px}small{color:#666}.error{color:#b91c1c}.success{color:#15803d}</style></head>
<body><main class="container"><h1>AI Assistant</h1>
<?php if ($error): ?><p class="error"><?= $escape($error) ?></p><?php endif ?>
<?php if ($success): ?><p class="success">Настройки сохранены, кеш CMS очищен.</p><?php endif ?>
<p>OpenRouter и другие API с форматом OpenAI Chat Completions; также прямой Anthropic Messages API. Доступ к ассистенту имеют администраторы CMS.</p>
<form method="post">
<input type="hidden" name="_ai_token" value="<?= $escape(ManagerSecurity::token()) ?>">
<label for="provider">Протокол API</label><select name="provider" id="provider">
<option value="openai" <?= $values['provider'] === 'openai' ? 'selected' : '' ?>>OpenRouter / OpenAI-compatible</option>
<option value="anthropic" <?= $values['provider'] === 'anthropic' ? 'selected' : '' ?>>Anthropic (прямой API)</option></select>
<label for="api_url">API Base URL</label><input type="url" id="api_url" name="api_url" required value="<?= $escape($values['api_url']) ?>">
<small>OpenRouter: https://openrouter.ai/api/v1 · OpenAI: https://api.openai.com/v1. Для прямого Anthropic используется фиксированный https://api.anthropic.com/v1/messages.</small>
<label for="api_key">API Key</label><input type="password" id="api_key" name="api_key" autocomplete="new-password" placeholder="<?= $values['api_key'] ? 'Ключ сохранён — оставьте пустым, чтобы не менять' : 'Введите ключ' ?>">
<label for="catalog">Каталог OpenRouter: модели с вызовом инструментов</label>
<select id="catalog"><option value="">Выберите модель или введите ID ниже</option>
<?php foreach ($catalog['models'] as $item): ?>
<option value="<?= $escape($item['id']) ?>"><?= $escape($item['name'] . ' · $' . $item['prompt_per_million'] . ' / $' . $item['completion_per_million'] . ' за 1M токенов') ?></option>
<?php endforeach ?></select>
<small>Цены: вход / выход. Снимок каталога: <?= $escape(gmdate('Y-m-d H:i', $catalog['fetched_at'])) ?> UTC. <?= $catalog['stale'] ? 'Каталог сейчас недоступен: показан сохранённый список.' : 'Каталог обновляется раз в сутки.' ?></small>
<label for="model">Model ID</label><input type="text" id="model" name="model" required value="<?= $escape($values['model']) ?>">
<small>Для прямых API укажите ID провайдера без префикса OpenRouter. Произвольный ID сохраняется, даже если его нет в каталоге.</small>
<button type="submit" name="save" value="1">Сохранить</button>
<button type="submit" name="refresh" value="1" formnovalidate>Обновить каталог</button>
</form></main>
<script>document.getElementById('catalog').addEventListener('change',function(){if(this.value){document.getElementById('model').value=this.value;document.getElementById('provider').value='openai';document.getElementById('api_url').value='https://openrouter.ai/api/v1';}});</script>
</body></html>
