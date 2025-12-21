# AI Assistant for Evolution CMS 3.x

AI-ассистент для управления контентом Evolution CMS через чат-интерфейс.

## Возможности

- Создание страниц, шаблонов, TV (Template Variables)
- Поиск и редактирование контента
- Привязка TV к шаблонам
- SEO-анализ страниц
- Multi-turn agent: выполняет сложные задачи в несколько шагов
- Сохранение истории чата в localStorage

## Требования

- Evolution CMS 3.2+
- PHP 8.1+
- Composer 2
- API ключ OpenRouter (https://openrouter.ai)

---

## Установка

### Шаг 1. Загрузка файлов

Скопируйте папку `ai-assistant` в:
```
/core/custom/packages/ai-assistant/
```

### Шаг 2. Регистрация роутов

Откройте (или создайте) файл `/core/custom/routes.php` и добавьте:

```php
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
```

### Шаг 3. Регистрация Service Provider

Откройте (или создайте) `/core/custom/config/app.php`:

```php
<?php
return [
    'providers' => [
        EvolutionCMS\AiAssistant\AiAssistantServiceProvider::class,
    ],
];
```

### Шаг 4. Создание таблицы чекпоинтов

Выполните SQL-запрос в phpMyAdmin или через консоль:

```sql
CREATE TABLE IF NOT EXISTS `evo_ai_checkpoints` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> Замените `evo_` на ваш префикс таблиц если он отличается.

### Шаг 5. Добавление системных настроек

Выполните SQL:

```sql
INSERT INTO `evo_system_settings` (`setting_name`, `setting_value`) VALUES
('ai_assistant_provider', 'openai'),
('ai_assistant_api_key', 'sk-or-v1-ВАШ-КЛЮЧ-OPENROUTER'),
('ai_assistant_api_url', 'https://openrouter.ai/api/v1'),
('ai_assistant_model', 'openai/gpt-4o-mini');
```

> Получите API ключ на https://openrouter.ai/keys

### Шаг 6. Установка плагина

В админке: **Элементы** → **Плагины** → **Новый плагин**

- **Имя:** `AI Assistant`
- **Системные события:** `OnManagerMainFrameHeaderHTMLBlock`
- **Код плагина:**

```php
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
```

> **Важно:** Код плагина НЕ должен начинаться с `<?php` - EVO плагины выполняются через eval().

### Шаг 7. Модуль настроек (опционально)

В админке: **Модули** → **Управление модулями** → **Новый модуль**

- **Имя:** `AI Assistant Settings`
- **Код:**

```php
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

echo <<<HTML
<h1>AI Assistant Settings</h1>
{$message}
<form method="post" style="max-width:600px;">
<div style="margin-bottom:15px;">
    <label style="display:block;margin-bottom:5px;font-weight:bold;">Provider</label>
    <select name="ai_assistant_provider" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
        <option value="openai">OpenAI / OpenRouter</option>
    </select>
</div>
<div style="margin-bottom:15px;">
    <label style="display:block;margin-bottom:5px;font-weight:bold;">API URL</label>
    <input type="text" name="ai_assistant_api_url" value="{$settings['ai_assistant_api_url']}" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
    <small style="color:#666;">OpenRouter: https://openrouter.ai/api/v1</small>
</div>
<div style="margin-bottom:15px;">
    <label style="display:block;margin-bottom:5px;font-weight:bold;">API Key</label>
    <input type="password" name="ai_assistant_api_key" value="{$settings['ai_assistant_api_key']}" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
</div>
<div style="margin-bottom:15px;">
    <label style="display:block;margin-bottom:5px;font-weight:bold;">Model</label>
    <input type="text" name="ai_assistant_model" value="{$settings['ai_assistant_model']}" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;">
    <small style="color:#666;">Recommended: openai/gpt-4o-mini</small>
</div>
<button type="submit" name="save_settings" value="1" style="background:#6366f1;color:white;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;">Save Settings</button>
</form>
HTML;
```

### Шаг 8. Очистка кеша

В админке: **Настройки** → **Очистить кеш**

---

## Использование

1. В админке появится фиолетовая кнопка AI Assistant справа
2. Нажмите на неё для открытия чат-панели
3. Примеры команд:
   - `Покажи все шаблоны`
   - `Создай страницу Контакты`
   - `Создай раздел Услуги с дочерними Услуга1 и Услуга2`
   - `Создай шаблон Продукт с TV цена и описание`

---

## Рекомендуемые модели OpenRouter

| Модель | Цена (за 1M токенов) | Tool Calling |
|--------|----------------------|--------------|
| `openai/gpt-4o-mini` | $0.15 input / $0.60 output | Отлично |
| `anthropic/claude-3.5-haiku` | $0.25 / $1.25 | Хорошо |
| `google/gemini-2.0-flash-001` | $0.10 / $0.40 | Хорошо |
| `deepseek/deepseek-chat` | $0.14 / $0.28 | Хорошо |

---

## Структура файлов

```
ai-assistant/
├── config/
│   └── ai-assistant.php          # Конфигурация и системный промпт
├── src/
│   ├── Controllers/
│   │   ├── ApiController.php     # API для чата и действий
│   │   └── PanelController.php   # UI панели
│   ├── Http/Middleware/
│   │   └── AiAssistantAuth.php   # Авторизация
│   ├── Models/
│   │   └── Checkpoint.php        # Модель чекпоинтов
│   └── Services/
│       ├── AiService.php         # Работа с AI API
│       ├── CheckpointService.php # Откат изменений
│       ├── ResourceService.php   # Работа со страницами
│       ├── SeoService.php        # SEO-анализ
│       ├── TemplateService.php   # Шаблоны
│       └── TvService.php         # Template Variables
├── AiAssistantServiceProvider.php
├── composer.json
└── README.md
```

---

## Решение проблем

### "Unauthorized"
Залогиньтесь в админку Evolution CMS.

### "AI Assistant is not configured"
Проверьте что добавлены системные настройки (Шаг 5).

### "Invalid schema for function"
Используйте актуальную версию файлов. Проблема была в `'properties' => []` - должно быть `'properties' => (object)[]`.

### Пустой ответ / ошибка от AI
1. Проверьте API ключ OpenRouter
2. Убедитесь что выбрана модель с поддержкой tool calling
3. Проверьте баланс на OpenRouter

### Tool calls без имени функции
Используйте модель с хорошей поддержкой tools: `openai/gpt-4o-mini` или `anthropic/claude-3.5-haiku`.

---

## Лицензия

MIT License

## Автор

Created with Claude Code
