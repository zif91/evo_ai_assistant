<?php
/**
 * AI Assistant Installation Script
 *
 * This script is executed when the package is installed via artisan or Extras manager
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Create plugin record if not exists
$pluginName = 'AI Assistant';
$pluginCode = file_get_contents(__DIR__ . '/assets/plugins/ai_assistant.php');

// Check if plugin exists
$existingPlugin = DB::table('site_plugins')->where('name', $pluginName)->first();

if (!$existingPlugin) {
    // Create plugin
    $pluginId = DB::table('site_plugins')->insertGetId([
        'name' => $pluginName,
        'description' => 'AI Assistant for Evolution CMS - Shopify Sidekick-like assistant',
        'plugincode' => $pluginCode,
        'disabled' => 0,
        'moduleguid' => '',
        'createdon' => time(),
        'editedon' => time(),
    ]);

    // Bind to events
    $events = ['OnManagerFrameLoader', 'OnManagerTopPrerender'];

    foreach ($events as $eventName) {
        $event = DB::table('system_eventnames')->where('name', $eventName)->first();
        if ($event) {
            DB::table('site_plugin_events')->insert([
                'pluginid' => $pluginId,
                'evtid' => $event->id,
                'priority' => 0,
            ]);
        }
    }

    echo "Plugin '{$pluginName}' installed successfully.\n";
} else {
    // Update existing plugin
    DB::table('site_plugins')
        ->where('id', $existingPlugin->id)
        ->update([
            'plugincode' => $pluginCode,
            'editedon' => time(),
        ]);

    echo "Plugin '{$pluginName}' updated successfully.\n";
}

// Copy assets to public directory
$sourceDir = __DIR__ . '/public';
$targetDir = MODX_BASE_PATH . 'assets/ai-assistant';

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

// Copy CSS
$cssSource = $sourceDir . '/css';
$cssTarget = $targetDir . '/css';
if (!is_dir($cssTarget)) {
    mkdir($cssTarget, 0755, true);
}
foreach (glob($cssSource . '/*.css') as $file) {
    copy($file, $cssTarget . '/' . basename($file));
}

// Copy JS
$jsSource = $sourceDir . '/js';
$jsTarget = $targetDir . '/js';
if (!is_dir($jsTarget)) {
    mkdir($jsTarget, 0755, true);
}
foreach (glob($jsSource . '/*.js') as $file) {
    copy($file, $jsTarget . '/' . basename($file));
}

echo "Assets copied to {$targetDir}\n";

// Run migrations
echo "Running migrations...\n";
Artisan::call('migrate', ['--path' => 'core/custom/packages/ai-assistant/migrations']);
echo Artisan::output();

echo "\n";
echo "==============================================\n";
echo "AI Assistant Installation Complete!\n";
echo "==============================================\n";
echo "\n";
echo "Next steps:\n";
echo "1. Add your AI API key to .env file:\n";
echo "   OPENAI_API_KEY=your-api-key-here\n";
echo "   or\n";
echo "   ANTHROPIC_API_KEY=your-api-key-here\n";
echo "\n";
echo "2. (Optional) Configure provider in .env:\n";
echo "   AI_ASSISTANT_PROVIDER=openai  (or anthropic)\n";
echo "\n";
echo "3. Clear cache and refresh the manager\n";
echo "\n";
