<?php

namespace EvolutionCMS\AiAssistant\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Installer
{
    public function install(): void
    {
        $migration = require __DIR__ . '/../../migrations/2024_01_01_000001_create_ai_assistant_checkpoints_table.php';
        $migration->up();
        // The old standalone installer used a different table name. Preserve its
        // IDs so previously displayed checkpoint references remain meaningful.
        if (Schema::hasTable('ai_checkpoints') && DB::table('ai_assistant_checkpoints')->count() === 0) {
            DB::table('ai_checkpoints')->orderBy('id')->chunk(100, function ($rows) {
                foreach ($rows as $row) {
                    $data = (array) $row;
                    foreach (['old_value', 'new_value'] as $field) {
                        $value = $data[$field] ?? null;
                        if ($value !== null && json_decode($value, true) === null) {
                            $data[$field] = json_encode(['value' => $value]);
                        }
                    }
                    DB::table('ai_assistant_checkpoints')->insertOrIgnore($data);
                }
            });
        }
        foreach ([
            'ai_assistant_provider' => 'openai',
            'ai_assistant_api_key' => '',
            'ai_assistant_api_url' => 'https://openrouter.ai/api/v1',
            'ai_assistant_model' => 'openai/gpt-5.4-mini',
        ] as $name => $value) {
            if (!DB::table('system_settings')->where('setting_name', $name)->exists()) {
                DB::table('system_settings')->insert(['setting_name' => $name, 'setting_value' => $value]);
            }
        }
        $pluginFile = realpath(__DIR__ . '/../../assets/plugins/ai_assistant.php');
        $plugin = ['description' => 'AI Assistant for Evolution CMS',
            'plugincode' => 'include ' . var_export($pluginFile, true) . ';', 'disabled' => 0];
        $existing = DB::table('site_plugins')->where('name', 'AI Assistant')->first();
        if ($existing) {
            DB::table('site_plugins')->where('id', $existing->id)->update($plugin);
            $id = $existing->id;
        } else {
            $id = DB::table('site_plugins')->insertGetId(['name' => 'AI Assistant'] + $plugin);
        }
        DB::table('site_plugin_events')->where('pluginid', $id)->delete();
        $event = DB::table('system_eventnames')->where('name', 'OnManagerMainFrameHeaderHTMLBlock')->first();
        if (!$event) {
            throw new \RuntimeException('Evolution manager header event not found');
        }
        DB::table('site_plugin_events')->insert(['pluginid' => $id, 'evtid' => $event->id, 'priority' => 0]);
        $moduleFile = realpath(__DIR__ . '/../../assets/modules/ai_assistant_settings.php');
        DB::table('site_modules')->updateOrInsert(['name' => 'AI Assistant Settings'], [
            'description' => 'Configure AI Assistant', 'modulecode' => 'include ' . var_export($moduleFile, true) . ';', 'disabled' => 0,
        ]);
        $target = public_path('assets/ai-assistant');
        foreach (['css', 'js'] as $type) {
            if (!is_dir($target . '/' . $type)) {
                mkdir($target . '/' . $type, 0755, true);
            }
            foreach (glob(__DIR__ . '/../../public/' . $type . '/*') as $source) {
                if (!copy($source, $target . '/' . $type . '/' . basename($source))) {
                    throw new \RuntimeException('Cannot publish assistant assets');
                }
            }
        }
        evo()->clearCache('full');
    }
}
