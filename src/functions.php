<?php

/**
 * Public PHP functions (PRD section 5.1).
 *
 * There is no register function: the `editor_ai_sidebar_actions` filter is
 * the one way to add an action.
 */

declare(strict_types=1);

use IndianOpinion\EditorAiSidebar\ActionDefinition;
use IndianOpinion\EditorAiSidebar\Plugin;

if (!function_exists('editor_ai_sidebar_actions')) {
    /**
     * Validated, enabled actions keyed by id.
     *
     * With a post id the list is limited to actions that apply to that
     * post's type. The user id is accepted for symmetry with the REST list
     * and does not filter: availability is reported by the REST route.
     *
     * @return array<string, ActionDefinition>
     */
    function editor_ai_sidebar_actions(?int $post_id = null, ?int $user_id = null): array
    {
        unset($user_id);
        return Plugin::instance()->registry()->enabledFor($post_id);
    }
}

if (!function_exists('editor_ai_sidebar_rejected_definitions')) {
    /**
     * Definitions rejected during this request, with the reason.
     *
     * @return array<int, array{id: string, reason: string}>
     */
    function editor_ai_sidebar_rejected_definitions(): array
    {
        $out = [];
        foreach (Plugin::instance()->registry()->rejections() as $rejection) {
            $out[] = ['id' => $rejection['id'], 'reason' => $rejection['reason']];
        }
        return $out;
    }
}

if (!function_exists('editor_ai_sidebar_contract_versions')) {
    /**
     * Contract versions this host supports.
     *
     * @return int[]
     */
    function editor_ai_sidebar_contract_versions(): array
    {
        return Plugin::CONTRACT_VERSIONS;
    }
}
