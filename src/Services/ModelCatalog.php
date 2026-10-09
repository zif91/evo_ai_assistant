<?php

namespace EvolutionCMS\AiAssistant\Services;

use Illuminate\Support\Facades\Http;

class ModelCatalog
{
    public static function normalize(array $models): array
    {
        $result = [];
        foreach ($models as $model) {
            if (!is_array($model) || empty($model['id'])
                || !in_array('tools', $model['supported_parameters'] ?? [], true)
                || !in_array('text', $model['architecture']['output_modalities'] ?? [], true)
                || str_contains($model['id'], ':batch')) {
                continue;
            }
            $result[$model['id']] = [
                'id' => $model['id'], 'name' => $model['name'] ?? $model['id'],
                'context_length' => (int) ($model['context_length'] ?? 0),
                'prompt_per_million' => isset($model['pricing']['prompt']) ? (float) $model['pricing']['prompt'] * 1000000 : null,
                'completion_per_million' => isset($model['pricing']['completion']) ? (float) $model['pricing']['completion'] * 1000000 : null,
            ];
        }
        ksort($result);
        return array_values($result);
    }

    public function get(bool $refresh = false): array
    {
        $path = storage_path('cache/ai-assistant-models.json');
        $cached = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (!$refresh && !empty($cached['models']) && ($cached['fetched_at'] ?? 0) > time() - 86400) {
            return $cached + ['stale' => false];
        }
        try {
            $response = Http::timeout(20)->get('https://openrouter.ai/api/v1/models');
            if (!$response->successful()) {
                throw new \RuntimeException('Model catalog unavailable');
            }
            $models = self::normalize($response->json('data') ?? []);
            if (!$models) {
                throw new \RuntimeException('Empty model catalog');
            }
            $data = ['models' => $models, 'fetched_at' => time()];
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $data + ['stale' => false];
        } catch (\Throwable $e) {
            $fallback = $cached ?: json_decode(file_get_contents(__DIR__ . '/../../config/models.json'), true);
            return $fallback + ['stale' => true];
        }
    }
}
