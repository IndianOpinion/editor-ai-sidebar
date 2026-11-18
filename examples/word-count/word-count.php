<?php

/**
 * Plugin Name:       Editor AI Sidebar example: word count
 * Description:       Registers one harmless action with Editor AI Sidebar: it counts the words in the unsaved post and offers to copy the count. Makes no model call and writes nothing.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * License:           MIT
 * Text Domain:       editor-ai-sidebar-word-count
 *
 * A minimal provider. It uses only the array form of the contract, so it
 * does not depend on the host's PHP classes and loads safely whether or
 * not the host is active.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_filter('editor_ai_sidebar_actions', 'editor_ai_sidebar_word_count_register');

/**
 * @param array<string, mixed> $actions
 * @return array<string, mixed>
 */
function editor_ai_sidebar_word_count_register(array $actions): array
{
    $actions['example/word-count'] = [
        'id' => 'example/word-count',
        'label' => __('Count words', 'editor-ai-sidebar-word-count'),
        'description' => __('Counts the words in the post as it is now, saved or not.', 'editor-ai-sidebar-word-count'),
        'group' => 'other',
        'icon' => 'editor-ol',
        'needs' => ['text'],
        'result' => ['layout' => 'single', 'item_type' => 'text'],
        'apply' => ['mode' => 'editor', 'operation' => 'copy', 'label' => __('Copy', 'editor-ai-sidebar-word-count')],
        'timeout' => 10,
        'run' => 'editor_ai_sidebar_word_count_run',
    ];
    return $actions;
}

/**
 * @param array{context: array{text: string}} $request The array form of RunRequest.
 * @return array<string, mixed> The array form of a Result.
 */
function editor_ai_sidebar_word_count_run(array $request): array
{
    $words = editor_ai_sidebar_word_count_count($request['context']['text']);
    $minutes = max(1, (int) ceil($words / 200));
    $text = sprintf(
        /* translators: 1: "N words", 2: "about N minutes to read" */
        __('%1$s, %2$s.', 'editor-ai-sidebar-word-count'),
        /* translators: %d: number of words */
        sprintf(_n('%d word', '%d words', $words, 'editor-ai-sidebar-word-count'), $words),
        /* translators: %d: minutes */
        sprintf(_n('about %d minute to read', 'about %d minutes to read', $minutes, 'editor-ai-sidebar-word-count'), $minutes)
    );
    return [
        'layout' => 'single',
        'items' => [
            ['id' => 'count', 'type' => 'text', 'value' => $text],
        ],
        'notes' => $words === 0
            ? [['level' => 'info', 'text' => __('The post has no text yet.', 'editor-ai-sidebar-word-count')]]
            : [],
    ];
}

/**
 * Words are runs of letters, marks, digits, apostrophes and hyphens, so
 * Indic text with combining marks counts correctly.
 */
function editor_ai_sidebar_word_count_count(string $text): int
{
    $count = preg_match_all("/[\\p{L}\\p{M}\\p{N}][\\p{L}\\p{M}\\p{N}'\\x{2019}-]*/u", $text);
    return is_int($count) ? $count : 0;
}
