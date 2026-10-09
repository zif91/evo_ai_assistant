<?php

namespace EvolutionCMS\AiAssistant\Support;

final class ActionPolicy
{
    public static function allowed(string $action, array $settings): bool
    {
        $group = match ($action) {
            'search_resources', 'get_resource', 'get_resource_tv' => 'search_resources',
            'create_resource' => 'create_resources',
            'update_resource', 'update_tv' => 'edit_resources',
            'publish_resource' => 'publish_resources',
            'unpublish_resource' => 'unpublish_resources',
            'list_tv', 'get_tv', 'create_tv', 'update_tv_definition', 'bind_tv_to_templates' => 'manage_tv',
            'list_templates', 'get_template', 'create_template', 'update_template', 'update_blade_template' => 'edit_templates',
            'analyze_seo', 'optimize_seo', 'get_seo_suggestions' => 'seo_optimization',
            'rollback_checkpoint', 'rollback_session', 'list_checkpoints' => 'rollback_changes',
            default => null,
        };
        return $group !== null && ($settings[$group] ?? true);
    }
}
