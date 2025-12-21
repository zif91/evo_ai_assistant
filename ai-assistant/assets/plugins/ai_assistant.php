/**
 * AI Assistant Plugin for Evolution CMS
 *
 * This plugin injects the AI Assistant sidebar into the manager interface.
 *
 * @category    Plugin
 * @package     EvolutionCMS\AiAssistant
 * @author      Evolution CMS Community
 * @license     MIT
 *
 * Events: OnManagerFrameLoader, OnManagerTopPrerender
 */

if (!defined('IN_MANAGER_MODE') || IN_MANAGER_MODE !== true) {
    return;
}

$e = evo()->event;

switch ($e->name) {
    case 'OnManagerTopPrerender':
        // Add CSS to head
        $output = '
        <link rel="stylesheet" href="' . MODX_SITE_URL . 'assets/ai-assistant/css/sidebar.css">
        ';
        $e->output($output);
        break;

    case 'OnManagerFrameLoader':
        // Inject the sidebar HTML and JS
        $panelUrl = MODX_SITE_URL . 'ai-assistant/panel';
        $output = '
        <div id="ai-assistant-sidebar" class="ai-sidebar ai-sidebar-closed">
            <button type="button" class="ai-sidebar-toggle" id="ai-sidebar-toggle" title="AI Assistant">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2a10 10 0 0 1 10 10 10 10 0 0 1-10 10A10 10 0 0 1 2 12 10 10 0 0 1 12 2z"/>
                    <circle cx="12" cy="10" r="3"/>
                    <path d="M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662"/>
                </svg>
            </button>
            <div class="ai-sidebar-content">
                <iframe id="ai-assistant-frame" src="' . $panelUrl . '" frameborder="0"></iframe>
            </div>
        </div>
        <script src="' . MODX_SITE_URL . 'assets/ai-assistant/js/sidebar.js"></script>
        ';
        echo $output;
        break;
}
