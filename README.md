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

## Установка (автоматическая)

### Шаг 1. Загрузка файлов

Скопируйте папку `ai-assistant` в:
```
/core/custom/packages/ai-assistant/
```

### Шаг 2. Запуск установщика

Откройте в браузере:
```
https://ваш-сайт.ru/core/custom/packages/ai-assistant/install.php
```

### Шаг 3. Настройка

1. Введите ваш API ключ OpenRouter (получить на https://openrouter.ai/keys)
2. Выберите модель (рекомендуется `openai/gpt-4o-mini`)
3. Нажмите **Install**

### Шаг 4. Очистка кеша

В админке: **Настройки** → **Очистить кеш**

**Готово!** В админке появится фиолетовая кнопка AI Assistant справа.

---

## Использование

1. Нажмите на фиолетовую кнопку AI Assistant справа
2. Примеры команд:
   - `Покажи все шаблоны`
   - `Создай страницу Контакты`
   - `Создай раздел Услуги с дочерними Услуга1 и Услуга2`
   - `Создай шаблон Продукт с TV цена и описание`

---

## Рекомендуемые модели OpenRouter

| Модель | Цена (за 1M токенов) | Tool Calling |
|--------|----------------------|--------------|
| `openai/gpt-4o-mini` | $0.15 / $0.60 | Отлично |
| `anthropic/claude-sonnet-4` | $3 / $15 | Отлично |
| `anthropic/claude-haiku-4` | $0.80 / $4 | Хорошо |
| `google/gemini-2.5-flash` | $0.15 / $0.60 | Хорошо |
| `deepseek/deepseek-chat-v3` | $0.14 / $0.28 | Хорошо |
| `x-ai/grok-3-fast` | $5 / $25 | Хорошо |
| `mistralai/devstral` | $0.10 / $0.30 | Хорошо |

---

## Изменение настроек

После установки настройки можно изменить:
- В админке: **Модули** → **AI Assistant Settings**

---

## Решение проблем

### "Unauthorized"
Залогиньтесь в админку Evolution CMS.

### "AI Assistant is not configured"
Проверьте API ключ в **Модули** → **AI Assistant Settings**.

### Пустой ответ / ошибка от AI
1. Проверьте API ключ OpenRouter
2. Убедитесь что выбрана модель с поддержкой tool calling
3. Проверьте баланс на OpenRouter

### Tool calls без имени функции
Используйте модель `openai/gpt-4o-mini` или `anthropic/claude-sonnet-4`.

---

## Ручная установка

<details>
<summary>Если автоматическая установка не работает</summary>

### Роуты (`/core/custom/routes.php`):

```php
<?php
Route::group(['prefix' => 'ai-assistant', 'middleware' => [\EvolutionCMS\AiAssistant\Http\Middleware\AiAssistantAuth::class]], function() {
    Route::get('/', [\EvolutionCMS\AiAssistant\Controllers\PanelController::class, 'index']);
    Route::post('/api/chat', [\EvolutionCMS\AiAssistant\Controllers\ApiController::class, 'chat']);
    Route::delete('/api/history', [\EvolutionCMS\AiAssistant\Controllers\ApiController::class, 'clearHistory']);
    Route::get('/api/resources', [\EvolutionCMS\AiAssistant\Controllers\ApiController::class, 'searchResources']);
    Route::get('/api/templates', [\EvolutionCMS\AiAssistant\Controllers\ApiController::class, 'listTemplates']);
    Route::get('/api/tv', [\EvolutionCMS\AiAssistant\Controllers\ApiController::class, 'listTv']);
});
```

### Service Provider (`/core/custom/config/app.php`):

```php
<?php
return [
    'providers' => [
        EvolutionCMS\AiAssistant\AiAssistantServiceProvider::class,
    ],
];
```

### SQL (замените `evo_` на ваш префикс):

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

INSERT INTO `evo_system_settings` (`setting_name`, `setting_value`) VALUES
('ai_assistant_provider', 'openai'),
('ai_assistant_api_key', 'YOUR-API-KEY'),
('ai_assistant_api_url', 'https://openrouter.ai/api/v1'),
('ai_assistant_model', 'openai/gpt-4o-mini');
```

### Плагин

Создайте плагин `AI Assistant` с событием `OnManagerMainFrameHeaderHTMLBlock` - код см. в README на GitHub.

</details>

---

## Лицензия

MIT License

## Автор

Created with Claude Code
