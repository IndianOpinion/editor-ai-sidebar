<?php

/**
 * Plugin Name:       Editor AI Sidebar example: canned providers
 * Description:       Five example actions with canned results, one per action kind the sidebar was built for: summary, headlines, meta, alt text and translation. For trying the sidebar and for the test suite. Makes no model call.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * License:           MIT
 * Text Domain:       editor-ai-sidebar-canned
 *
 * These use the host's value objects, so they need Editor AI Sidebar
 * active. They show how each kind is expected to register (PRD 4.8); they
 * bind nothing in any real provider.
 */

declare(strict_types=1);

use IndianOpinion\EditorAiSidebar\ApplyOutcome;
use IndianOpinion\EditorAiSidebar\ApplyRequest;
use IndianOpinion\EditorAiSidebar\Item;
use IndianOpinion\EditorAiSidebar\Note;
use IndianOpinion\EditorAiSidebar\Pending;
use IndianOpinion\EditorAiSidebar\Result;
use IndianOpinion\EditorAiSidebar\RunRequest;

if (!defined('ABSPATH')) {
    exit;
}

const EDITOR_AI_SIDEBAR_CANNED_META_TITLE = 'canned_meta_title';
const EDITOR_AI_SIDEBAR_CANNED_META_DESCRIPTION = 'canned_meta_description';

add_action('init', 'editor_ai_sidebar_canned_register_meta');
add_filter('editor_ai_sidebar_actions', 'editor_ai_sidebar_canned_actions');

function editor_ai_sidebar_canned_register_meta(): void
{
    foreach ([EDITOR_AI_SIDEBAR_CANNED_META_TITLE, EDITOR_AI_SIDEBAR_CANNED_META_DESCRIPTION] as $key) {
        register_post_meta('post', $key, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => static fn (): bool => current_user_can('edit_posts'),
        ]);
    }
}

/**
 * @param array<string, mixed> $actions
 * @return array<string, mixed>
 */
function editor_ai_sidebar_canned_actions(array $actions): array
{
    if (!class_exists(Result::class)) {
        return $actions;
    }

    // Summary: editor mode, a list block at the start of the content (case 62).
    $actions['canned/summary'] = [
        'id' => 'canned/summary',
        'label' => 'Suggest a summary',
        'group' => 'summary',
        'needs' => ['title', 'text'],
        'result' => ['layout' => 'single', 'item_type' => 'list'],
        'apply' => ['mode' => 'editor', 'operation' => 'insert_blocks', 'position' => 'start'],
        'run' => static fn (RunRequest $request): Result => Result::single(
            Item::list('summary', [
                'The first point of the post.',
                'The second point of the post.',
                'The third point of the post.',
            ])
        ),
    ];

    // Headlines: choices, set_title, editable (case 63).
    $actions['canned/headlines'] = [
        'id' => 'canned/headlines',
        'label' => 'Suggest headlines',
        'group' => 'headlines',
        'needs' => ['title', 'text', 'excerpt'],
        'editable' => true,
        'result' => ['layout' => 'choices', 'item_type' => 'text'],
        'apply' => ['mode' => 'editor', 'operation' => 'set_title', 'label' => 'Use this headline'],
        'run' => static fn (RunRequest $request): Result => Result::choices([
            Item::text('a', 'A plain headline that says what happened')->withScore(0.82, 'Clarity'),
            Item::text('b', 'A shorter headline')->withScore(0.64, 'Clarity')
                ->withNote(Note::warning('Shorter than most headlines on this site.')),
            Item::text('c', 'A question headline?')->withScore(0.41, 'Clarity'),
        ], [Note::info('Canned example: these never change.')]),
        'validate' => static function (Item $edited, RunRequest $original) {
            $value = $edited->value();
            return is_string($value) && mb_strlen($value) <= 120
                ? true
                : new WP_Error('headline_too_long', 'Keep the headline under 120 characters.');
        },
    ];

    // Meta: server mode, two registered meta keys, synced back (case 64).
    $actions['canned/meta'] = [
        'id' => 'canned/meta',
        'label' => 'Suggest a meta title and description',
        'group' => 'meta',
        'needs' => ['title', 'text'],
        'result' => [
            'layout' => 'single',
            'item_type' => 'fields',
            'fields' => [
                'title' => ['label' => 'Meta title', 'limit' => 60],
                'description' => ['label' => 'Meta description', 'limit' => 160],
            ],
        ],
        'apply' => [
            'mode' => 'server',
            'targets' => [
                'meta:' . EDITOR_AI_SIDEBAR_CANNED_META_TITLE,
                'meta:' . EDITOR_AI_SIDEBAR_CANNED_META_DESCRIPTION,
            ],
        ],
        'editable' => true,
        'run' => static fn (RunRequest $request): Result => Result::single(Item::fields('meta', [
            'title' => 'A meta title for the post',
            'description' => 'A one-sentence description of the post for search results.',
        ])),
        'apply_callback' => static function (ApplyRequest $apply): ApplyOutcome {
            $value = $apply->item()->value();
            update_post_meta($apply->postId(), EDITOR_AI_SIDEBAR_CANNED_META_TITLE, (string) ($value['title'] ?? ''));
            update_post_meta($apply->postId(), EDITOR_AI_SIDEBAR_CANNED_META_DESCRIPTION, (string) ($value['description'] ?? ''));
            return ApplyOutcome::done('Meta title and description saved.', [
                'meta:' . EDITOR_AI_SIDEBAR_CANNED_META_TITLE,
                'meta:' . EDITOR_AI_SIDEBAR_CANNED_META_DESCRIPTION,
            ]);
        },
    ];

    // Alt text: bound to an image block, sets its alt attribute (case 65).
    $actions['canned/alt'] = [
        'id' => 'canned/alt',
        'label' => 'Suggest alt text for this image',
        'group' => 'alt_text',
        'selection' => ['core/image'],
        'needs' => ['title', 'selection_attributes' => ['id', 'url', 'alt']],
        'editable' => true,
        'result' => ['layout' => 'single', 'item_type' => 'text'],
        'apply' => ['mode' => 'editor', 'operation' => 'set_block_attribute', 'attribute' => 'alt'],
        'run' => static fn (RunRequest $request): Result => Result::single(
            Item::text('alt', 'A short description of the image')
        ),
    ];

    // Translation: queued, server apply makes a draft and links to it (case 66).
    $actions['canned/translation'] = [
        'id' => 'canned/translation',
        'label' => 'Draft a French translation',
        'group' => 'translation',
        'needs' => ['title', 'content', 'language'],
        'result' => [
            'layout' => 'single',
            'item_type' => 'fields',
            'fields' => ['title' => 'Title', 'content' => 'Text'],
        ],
        'apply' => ['mode' => 'server', 'targets' => ['new_post'], 'label' => 'Create the draft'],
        'max_wait' => 120,
        'run' => static fn (RunRequest $request): Pending => Pending::job('canned-' . $request->postId(), 2),
        'poll' => static fn (string $ref, RunRequest $request): Result => Result::single(
            Item::fields('fr', ['title' => 'Un titre', 'content' => 'Un texte.'])->withLanguage('fr', 'ltr')
        ),
        'cancel' => static function (string $ref): void {
        },
        'apply_callback' => static function (ApplyRequest $apply): ApplyOutcome {
            $value = $apply->item()->value();
            $draft = wp_insert_post([
                'post_type' => 'post',
                'post_status' => 'draft',
                'post_title' => (string) ($value['title'] ?? ''),
                'post_content' => (string) ($value['content'] ?? ''),
            ]);
            return ApplyOutcome::done('Draft created. The current post is unchanged.', ['new_post'], [
                ['label' => 'Edit the draft', 'url' => admin_url('post.php?post=' . (int) $draft . '&action=edit')],
            ]);
        },
    ];

    return $actions;
}
