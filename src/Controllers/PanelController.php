<?php

namespace EvolutionCMS\AiAssistant\Controllers;

use EvolutionCMS\AiAssistant\Support\ManagerSecurity;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PanelController extends Controller
{
    public function index(): Response
    {
        $baseUrl = rtrim(defined('MODX_SITE_URL') ? MODX_SITE_URL : '', '/');
        $language = evo()->getConfig('manager_language', 'en');
        $language = str_starts_with($language, 'ru') || $language === 'russian' ? 'ru' : 'en';
        $translations = require __DIR__ . '/../../lang/' . $language . '/messages.php';
        $translations = $translations['panel'];
        $config = [
            'apiUrl' => $baseUrl . '/ai-assistant/api',
            'assetUrl' => $baseUrl . '/assets/ai-assistant',
            'isConfigured' => (bool) evo()->getConfig('ai_assistant_api_key', ''),
            'csrfToken' => ManagerSecurity::token(),
        ];
        return response(view('ai-assistant::panel', compact('config', 'translations'))->render())
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }
}
