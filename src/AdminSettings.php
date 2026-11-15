<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * The "AI actions" section on Settings, Writing: the one admin screen
 * (PRD section 7). Uses the Settings API, so the nonce and the page's
 * capability check come from core.
 */
final class AdminSettings
{
    private const SECTION = 'editor_ai_sidebar';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        register_setting('writing', Settings::OPTION, [
            'type' => 'object',
            'sanitize_callback' => [Settings::class, 'sanitise'],
            'default' => ['enabled' => true, 'disabled' => []],
            'show_in_rest' => false,
        ]);
        if (!current_user_can(Plugin::settingsCapability())) {
            return;
        }
        add_settings_section(self::SECTION, esc_html__('AI actions', 'editor-ai-sidebar'), [$this, 'renderIntro'], 'writing');
        add_settings_field(
            'editor_ai_sidebar_enabled',
            esc_html__('Sidebar', 'editor-ai-sidebar'),
            [$this, 'renderEnabled'],
            'writing',
            self::SECTION
        );
        add_settings_field(
            'editor_ai_sidebar_actions',
            esc_html__('Registered actions', 'editor-ai-sidebar'),
            [$this, 'renderActions'],
            'writing',
            self::SECTION
        );
        add_settings_field(
            'editor_ai_sidebar_rejected',
            esc_html__('Rejected definitions', 'editor-ai-sidebar'),
            [$this, 'renderRejected'],
            'writing',
            self::SECTION
        );
    }

    public function renderIntro(): void
    {
        echo '<p>' . esc_html__(
            'One sidebar in the block editor for the AI actions other plugins provide. Nothing is written to a post until an editor approves a result.',
            'editor-ai-sidebar'
        ) . '</p>';
        if (defined('EDITOR_AI_SIDEBAR_DISABLED') && constant('EDITOR_AI_SIDEBAR_DISABLED')) {
            echo '<p><strong>' . esc_html__(
                'The EDITOR_AI_SIDEBAR_DISABLED constant is set, so the sidebar is off whatever this page says.',
                'editor-ai-sidebar'
            ) . '</strong></p>';
        }
    }

    public function renderEnabled(): void
    {
        $settings = $this->plugin->settings()->get();
        printf(
            '<input type="hidden" name="%1$s[enabled]" value="0" /><label><input type="checkbox" name="%1$s[enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr(Settings::OPTION),
            checked($settings['enabled'], true, false),
            esc_html__('Show the AI actions sidebar in the block editor', 'editor-ai-sidebar')
        );
    }

    public function renderActions(): void
    {
        $actions = $this->plugin->registry()->all();
        if ($actions === []) {
            echo '<p>' . esc_html__('No AI actions are installed.', 'editor-ai-sidebar') . '</p>';
            return;
        }
        $disabled = $this->plugin->settings()->disabledActions();
        $counts = $this->plugin->log()->countsByAction();
        echo '<table class="widefat striped"><thead><tr>';
        foreach (
            [
                __('Switch off', 'editor-ai-sidebar'),
                __('Action', 'editor-ai-sidebar'),
                __('Provider', 'editor-ai-sidebar'),
                __('Mode', 'editor-ai-sidebar'),
                __('Targets', 'editor-ai-sidebar'),
                __('Applied', 'editor-ai-sidebar'),
                __('Failed', 'editor-ai-sidebar'),
            ] as $heading
        ) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($actions as $id => $definition) {
            $mode = $definition->isServerMode() ? 'server' : 'editor: ' . (string) $definition->operation();
            echo '<tr>';
            printf(
                '<td><input type="checkbox" name="%s[disabled][]" value="%s" %s aria-label="%s" /></td>',
                esc_attr(Settings::OPTION),
                esc_attr($id),
                checked(in_array($id, $disabled, true), true, false),
                esc_attr(sprintf(
                    /* translators: %s: action label */
                    __('Switch off %s', 'editor-ai-sidebar'),
                    $definition->label()
                ))
            );
            echo '<td><strong>' . esc_html($definition->label()) . '</strong><br /><code>' . esc_html($id) . '</code></td>';
            echo '<td>' . esc_html($definition->pluginFile()) . '</td>';
            echo '<td>' . esc_html($mode) . '</td>';
            echo '<td>' . esc_html(implode(', ', $definition->targets())) . '</td>';
            echo '<td>' . esc_html((string) ($counts[$id]['applied'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ($counts[$id]['failed'] ?? 0)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Counts cover the event log window (the latest 500 events).', 'editor-ai-sidebar') . '</p>';
    }

    public function renderRejected(): void
    {
        $registry = $this->plugin->registry();
        foreach (Plugin::postTypes() as $type) {
            $registry->forPostType($type);
        }
        $rejections = $registry->rejections();
        if ($rejections === []) {
            echo '<p>' . esc_html__('None.', 'editor-ai-sidebar') . '</p>';
            return;
        }
        echo '<ul>';
        foreach ($rejections as $rejection) {
            $where = $rejection['post_type'] !== '' ? ' (' . $rejection['post_type'] . ')' : '';
            printf(
                '<li><code>%s</code>: %s%s %s</li>',
                esc_html($rejection['id']),
                esc_html($rejection['reason']),
                esc_html($where),
                $rejection['plugin'] !== '' ? esc_html('[' . $rejection['plugin'] . ']') : ''
            );
        }
        echo '</ul>';
    }
}
