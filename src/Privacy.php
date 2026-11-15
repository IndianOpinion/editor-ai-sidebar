<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Suggested privacy policy text (PRD section 8).
 */
final class Privacy
{
    public static function register(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            __('Editor AI Sidebar', 'editor-ai-sidebar'),
            wp_kses_post(self::text())
        );
    }

    public static function text(): string
    {
        return '<p>' . esc_html__(
            'The AI actions installed on this site may send the content of a post that is being edited to the services their providers use. Editor AI Sidebar itself sends nothing anywhere: it shows the buttons and keeps short-lived records of each suggestion so an editor can approve it.',
            'editor-ai-sidebar'
        ) . '</p>';
    }
}
