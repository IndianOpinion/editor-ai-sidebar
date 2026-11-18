<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Translated messages the server sends to the editor. Each one says what
 * to do next (lesson 11).
 */
final class Messages
{
    public static function providerError(): string
    {
        return __('This action failed. The site log has details.', 'editor-ai-sidebar');
    }

    public static function forCode(string $code): string
    {
        switch ($code) {
            case 'sidebar_disabled':
                return __('AI actions are switched off on this site.', 'editor-ai-sidebar');
            case 'rest_forbidden':
                return __('You are not allowed to do that.', 'editor-ai-sidebar');
            case 'post_missing':
                return __('This post no longer exists.', 'editor-ai-sidebar');
            case 'action_unknown':
                return __('This action is not installed for this kind of content.', 'editor-ai-sidebar');
            case 'action_disabled':
                return __('The site owner has switched this action off.', 'editor-ai-sidebar');
            case 'selection_required':
                return __('Select a block this action works on, then try again.', 'editor-ai-sidebar');
            case 'context_invalid':
                return __('The request did not match what this action needs. Reload the editor and try again.', 'editor-ai-sidebar');
            case 'context_too_large':
                return __('This post is too long for this action.', 'editor-ai-sidebar');
            case 'rate_limited':
                return __('Too many runs in a short time. Wait a little and try again.', 'editor-ai-sidebar');
            case 'daily_cap_reached':
                return __('This action has reached its daily limit on this site. Try again tomorrow.', 'editor-ai-sidebar');
            case 'run_in_progress':
                return __('This action is already running for this post.', 'editor-ai-sidebar');
            case 'run_cancelled':
                return __('The run was cancelled.', 'editor-ai-sidebar');
            case 'provider_error':
                return self::providerError();
            case 'provider_wrote_during_run':
                return __('This action changed the post before approval, so its result was thrown away. The site log has details.', 'editor-ai-sidebar');
            case 'invalid_result':
                return __('This action returned something the sidebar cannot show. The site log has details.', 'editor-ai-sidebar');
            case 'job_gone':
            case 'result_expired':
                return __('This result is no longer available. Run the action again.', 'editor-ai-sidebar');
            case 'not_your_result':
                return __('Only the person who ran this action can approve its result.', 'editor-ai-sidebar');
            case 'result_mismatch':
                return __('This result was made for a different post or action.', 'editor-ai-sidebar');
            case 'result_used':
                return __('This result has already been used. Run the action again.', 'editor-ai-sidebar');
            case 'item_unknown':
                return __('That suggestion is not part of this result.', 'editor-ai-sidebar');
            case 'action_gone':
                return __('The plugin that made this result is no longer active.', 'editor-ai-sidebar');
            case 'approval_missing':
                return __('Nothing was written: the request did not carry an approval.', 'editor-ai-sidebar');
            case 'value_mismatch':
                return __('The value differs from the suggestion and this action does not allow edits.', 'editor-ai-sidebar');
            case 'value_invalid':
                return __('The edited value is empty or too long.', 'editor-ai-sidebar');
            case 'context_changed':
                return __('The post has changed since this result was made.', 'editor-ai-sidebar');
            case 'post_locked':
                return __('Another user is editing this post.', 'editor-ai-sidebar');
            default:
                return __('Something went wrong. Try again.', 'editor-ai-sidebar');
        }
    }
}
