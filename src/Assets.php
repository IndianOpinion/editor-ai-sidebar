<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Enqueues the sidebar in the post editor only (cases 11 to 13, 16, 19).
 */
final class Assets
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function enqueue(): void
    {
        if (!$this->plugin->settings()->sidebarEnabled()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->base !== 'post' || !$screen->is_block_editor()) {
            return;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post || !in_array($post->post_type, Plugin::postTypes(), true)) {
            return;
        }
        if (!current_user_can(Plugin::viewCapability()) || !current_user_can('edit_post', $post->ID)) {
            return;
        }
        $hasActions = $this->plugin->registry()->all() !== [];
        if (!$hasActions && !(bool) apply_filters('editor_ai_sidebar_show_when_empty', true)) {
            return;
        }

        $base = plugins_url('', $this->plugin->file());
        $version = Plugin::VERSION;
        wp_register_script('editor-ai-sidebar-core', $base . '/assets/js/core.js', [], $version, true);
        wp_register_script(
            'editor-ai-sidebar-view',
            $base . '/assets/js/view.js',
            ['editor-ai-sidebar-core', 'wp-i18n'],
            $version,
            true
        );
        wp_enqueue_script(
            'editor-ai-sidebar',
            $base . '/assets/js/sidebar.js',
            [
                'editor-ai-sidebar-core',
                'editor-ai-sidebar-view',
                'wp-a11y',
                'wp-api-fetch',
                'wp-blocks',
                'wp-block-editor',
                'wp-components',
                'wp-core-data',
                'wp-data',
                'wp-edit-post',
                'wp-editor',
                'wp-element',
                'wp-i18n',
                'wp-plugins',
            ],
            $version,
            true
        );
        wp_enqueue_style('editor-ai-sidebar', $base . '/assets/css/sidebar.css', ['wp-components'], $version);

        $config = [
            'namespace' => Rest::NAMESPACE,
            'postId' => $post->ID,
            'title' => Plugin::title(),
            'hasActions' => $hasActions,
            'canManage' => current_user_can(Plugin::settingsCapability()),
            'helpUrl' => Plugin::HELP_URL,
            'contextLimit' => Service::contextLimit(),
            'language' => Plugin::siteLanguage(),
            'contract' => Plugin::CONTRACT_VERSIONS,
        ];
        wp_add_inline_script(
            'editor-ai-sidebar',
            'window.editorAiSidebarConfig = ' . wp_json_encode($config) . ';',
            'before'
        );
        wp_set_script_translations('editor-ai-sidebar', 'editor-ai-sidebar');
        wp_set_script_translations('editor-ai-sidebar-view', 'editor-ai-sidebar');
    }
}
