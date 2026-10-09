<!DOCTYPE html>
<html lang="{{ config('global.manager_language', 'en') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $translations['title'] }}</title>
    <link rel="stylesheet" href="{{ $config['assetUrl'] . '/css/panel.css' }}">
</head>
<body>
    <div id="ai-assistant-panel" class="ai-panel" data-config='@json($config)'>
        <!-- Header -->
        <div class="ai-panel-header">
            <div class="ai-panel-title">
                <svg class="ai-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2a10 10 0 0 1 10 10 10 10 0 0 1-10 10A10 10 0 0 1 2 12 10 10 0 0 1 12 2z"/>
                    <circle cx="12" cy="10" r="3"/>
                    <path d="M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662"/>
                </svg>
                <span>{{ $translations['title'] }}</span>
            </div>
            <div class="ai-panel-actions">
                <button type="button" class="ai-btn-icon" id="ai-btn-history" title="{{ $translations['checkpoints'] }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="1 4 1 10 7 10"/>
                        <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                    </svg>
                </button>
                <button type="button" class="ai-btn-icon" id="ai-btn-clear" title="{{ $translations['clear'] }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    </svg>
                </button>
            </div>
        </div>

        @if(!$config['isConfigured'])
        <!-- Not Configured Warning -->
        <div class="ai-not-configured">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>{{ $translations['not_configured'] }}</p>
        </div>
        @endif

        <!-- Messages Container -->
        <div class="ai-messages" id="ai-messages">
            <!-- Welcome message -->
            <div class="ai-message ai-message-assistant">
                <div class="ai-message-avatar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2a10 10 0 0 1 10 10 10 10 0 0 1-10 10A10 10 0 0 1 2 12 10 10 0 0 1 12 2z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                </div>
                <div class="ai-message-content">
                    <p>Привет! Я AI-ассистент для Evolution CMS. Чем могу помочь?</p>
                    <p class="ai-message-hint">Вы можете спросить меня о:</p>
                    <ul class="ai-suggestions-list">
                        <li data-prompt="Найди все опубликованные страницы">Поиск страниц</li>
                        <li data-prompt="Проанализируй SEO страницы #1">SEO анализ</li>
                        <li data-prompt="Покажи все TV переменные">Просмотр TV</li>
                        <li data-prompt="Покажи список шаблонов">Список шаблонов</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Typing indicator -->
        <div class="ai-typing" id="ai-typing" style="display: none;">
            <div class="ai-message-avatar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2a10 10 0 0 1 10 10 10 10 0 0 1-10 10A10 10 0 0 1 2 12 10 10 0 0 1 12 2z"/>
                </svg>
            </div>
            <div class="ai-typing-dots">
                <span></span><span></span><span></span>
            </div>
        </div>

        <!-- Checkpoints Panel -->
        <div class="ai-checkpoints-panel" id="ai-checkpoints-panel" style="display: none;">
            <div class="ai-checkpoints-header">
                <h3>{{ $translations['checkpoints'] }}</h3>
                <button type="button" class="ai-btn-icon" id="ai-close-checkpoints">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>
            <div class="ai-checkpoints-list" id="ai-checkpoints-list">
                <!-- Checkpoints will be loaded here -->
            </div>
        </div>

        <!-- Input Area -->
        <div class="ai-input-area">
            <div class="ai-input-wrapper">
                <textarea
                    id="ai-input"
                    placeholder="{{ $translations['placeholder'] }}"
                    rows="1"
                    @if(!$config['isConfigured']) disabled @endif
                ></textarea>
                <button
                    type="button"
                    class="ai-btn-send"
                    id="ai-btn-send"
                    @if(!$config['isConfigured']) disabled @endif
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <script>
        window.AiAssistantConfig = @json($config);
        window.AiAssistantTranslations = @json($translations);
    </script>
    <script src="{{ $config['assetUrl'] . '/js/panel.js' }}"></script>
</body>
</html>
