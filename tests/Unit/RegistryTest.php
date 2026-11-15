<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Unit;

use IndianOpinion\EditorAiSidebar\Clock;
use IndianOpinion\EditorAiSidebar\Tests\Support\TestCase;

/**
 * Registration and validation (PRD 4.1, tests T1 to T9).
 */
final class RegistryTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function reasons(): array
    {
        $out = [];
        foreach (editor_ai_sidebar_rejected_definitions() as $rejection) {
            $out[$rejection['id']] = $rejection['reason'];
        }
        return $out;
    }

    public function testT1ValidDefinitionAppears(): void
    {
        $this->provide($this->definition());
        $actions = editor_ai_sidebar_actions();
        self::assertArrayHasKey('test/headline', $actions);
        self::assertSame('Suggest a headline', $actions['test/headline']->label());
        self::assertSame(1, $actions['test/headline']->contract());
        self::assertSame([], editor_ai_sidebar_rejected_definitions());
        self::assertSame([1], editor_ai_sidebar_contract_versions());
    }

    public function testT2BadIdsRejected(): void
    {
        $long = 'a/' . str_repeat('b', 63);
        self::assertSame(65, strlen($long));
        $this->provide(
            $this->definition(['id' => 'Bad/Name']),
            $this->definition(['id' => 'noslash']),
            $this->definition(['id' => $long]),
            $this->definition(['id' => 'ok/fine'])
        );
        self::assertSame(['ok/fine'], array_keys(editor_ai_sidebar_actions()));
        self::assertSame(
            ['Bad/Name' => 'invalid_id', 'noslash' => 'invalid_id', $long => 'invalid_id'],
            $this->reasons()
        );
    }

    public function testT3MissingRunRejectsOnlyThatDefinition(): void
    {
        $broken = $this->definition(['id' => 'test/broken']);
        unset($broken['run']);
        $this->provide($broken, $this->definition(['id' => 'test/good']));
        self::assertSame(['test/good'], array_keys(editor_ai_sidebar_actions()));
        self::assertSame(['test/broken' => 'invalid_definition'], $this->reasons());
    }

    public function testWrongTypesAreInvalidDefinitions(): void
    {
        $this->provide(
            $this->definition(['id' => 'test/a', 'label' => 42]),
            $this->definition(['id' => 'test/b', 'group' => 'nonsense']),
            $this->definition(['id' => 'test/c', 'editable' => 'yes']),
            $this->definition(['id' => 'test/d', 'needs' => ['title', 'everything']]),
            $this->definition(['id' => 'test/e', 'run' => 'not_a_function_anywhere']),
            $this->definition(['id' => 'test/f', 'result' => ['layout' => 'grid', 'item_type' => 'text']]),
            'not even an array'
        );
        self::assertSame([], editor_ai_sidebar_actions());
        $reasons = array_values($this->reasons());
        self::assertCount(7, $reasons);
        self::assertSame(['invalid_definition'], array_values(array_unique($reasons)));
    }

    public function testT4DuplicateIdFirstWins(): void
    {
        $this->provide(
            $this->definition(['label' => 'First']),
            $this->definition(['label' => 'Second'])
        );
        $actions = editor_ai_sidebar_actions();
        self::assertSame('First', $actions['test/headline']->label());
        self::assertSame(['test/headline' => 'duplicate_id'], $this->reasons());
    }

    public function testT5ContractVersions(): void
    {
        $this->provide(
            $this->definition(['id' => 'test/v2', 'contract' => 2]),
            $this->definition(['id' => 'test/v1', 'contract' => 1]),
            $this->definition(['id' => 'test/none'])
        );
        $actions = editor_ai_sidebar_actions();
        self::assertSame(['test/v1', 'test/none'], array_keys($actions));
        self::assertSame(1, $actions['test/none']->contract());
        self::assertSame(['test/v2' => 'contract_unsupported'], $this->reasons());
    }

    public function testT6ServerModeCoreFieldRejected(): void
    {
        $server = static fn (string $target): array => [
            'mode' => 'server',
            'targets' => [$target],
        ];
        $callback = static fn (): array => ['message' => 'ok', 'targets' => []];
        $this->provide(
            $this->definition(['id' => 'test/title', 'apply' => $server('title'), 'apply_callback' => $callback]),
            $this->definition(['id' => 'test/status', 'apply' => $server('status'), 'apply_callback' => $callback]),
            $this->definition(['id' => 'test/meta', 'apply' => $server('meta:my_key'), 'apply_callback' => $callback]),
            $this->definition(['id' => 'test/nocb', 'apply' => $server('meta:my_key')])
        );
        self::assertSame(['test/meta'], array_keys(editor_ai_sidebar_actions()));
        self::assertSame([
            'test/title' => 'core_field_server_write',
            'test/status' => 'core_field_server_write',
            'test/nocb' => 'invalid_definition',
        ], $this->reasons());
    }

    public function testT7UnknownOperationRejected(): void
    {
        $this->provide($this->definition(['apply' => ['mode' => 'editor', 'operation' => 'run_script']]));
        self::assertSame([], editor_ai_sidebar_actions());
        self::assertSame(['test/headline' => 'unknown_operation'], $this->reasons());
    }

    public function testOperationNeedsItsKeys(): void
    {
        $this->provide(
            // set_block_attribute without an attribute or a selection.
            $this->definition(['id' => 'test/a', 'apply' => ['mode' => 'editor', 'operation' => 'set_block_attribute']]),
            // set_meta with a text item and no meta target.
            $this->definition(['id' => 'test/b', 'apply' => ['mode' => 'editor', 'operation' => 'set_meta']]),
            // set_title with a list item.
            $this->definition([
                'id' => 'test/c',
                'result' => ['layout' => 'single', 'item_type' => 'list'],
            ]),
            // insert_blocks with a bad position.
            $this->definition([
                'id' => 'test/d',
                'apply' => ['mode' => 'editor', 'operation' => 'insert_blocks', 'position' => 'middle'],
            ])
        );
        self::assertSame([], editor_ai_sidebar_actions());
        self::assertSame(['invalid_definition'], array_values(array_unique($this->reasons())));
    }

    public function testT8SetMetaNeedsRestMetaForThatTypeOnly(): void
    {
        register_post_meta('post', 'summary_line', ['show_in_rest' => true, 'single' => true]);
        register_post_meta('page', 'summary_line', ['show_in_rest' => false, 'single' => true]);
        $this->provide($this->definition([
            'id' => 'test/meta',
            'apply' => ['mode' => 'editor', 'operation' => 'set_meta', 'targets' => ['meta:summary_line']],
        ]));
        $post = $this->post('post');
        $page = $this->post('page');
        self::assertArrayHasKey('test/meta', editor_ai_sidebar_actions($post));
        self::assertSame([], editor_ai_sidebar_actions($page));
        $rejections = $this->plugin->registry()->rejections();
        self::assertCount(1, $rejections);
        self::assertSame('meta_not_in_rest', $rejections[0]['reason']);
        self::assertSame('page', $rejections[0]['post_type']);
    }

    public function testT9RejectionsLoggedOncePerDayAndListed(): void
    {
        $this->provide($this->definition(['id' => 'Bad/Id']));
        $registry = $this->plugin->registry();
        $registry->all();
        $registry->reset();
        $registry->all();
        $logged = array_filter(
            $this->plugin->log()->all(),
            static fn (array $e): bool => $e['event'] === 'definition_rejected'
        );
        self::assertCount(1, $logged);
        $entry = array_values($logged)[0];
        self::assertSame('invalid_id', $entry['code']);
        self::assertStringContainsString('TestCase.php', $entry['detail'], 'the file of the run callback is recorded');

        Clock::advance(86400);
        $registry->reset();
        $registry->all();
        $logged = array_filter(
            $this->plugin->log()->all(),
            static fn (array $e): bool => $e['event'] === 'definition_rejected'
        );
        self::assertCount(2, $logged, 'logged again on the next day');

        // Listed on the settings screen.
        wp_set_current_user(self::ADMIN);
        $admin = new \IndianOpinion\EditorAiSidebar\AdminSettings($this->plugin);
        ob_start();
        $admin->renderRejected();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Bad/Id', $html);
        self::assertStringContainsString('invalid_id', $html);
    }

    public function testFilterRunsOncePerRequest(): void
    {
        $calls = 0;
        add_filter('editor_ai_sidebar_actions', static function (array $actions) use (&$calls): array {
            $calls++;
            return $actions;
        });
        $registry = $this->plugin->registry();
        $registry->all();
        $registry->all();
        editor_ai_sidebar_actions();
        self::assertSame(1, $calls);
    }

    public function testDefaultsAndClamping(): void
    {
        $this->provide($this->definition(['timeout' => 1, 'max_wait' => 99999, 'icon' => 'Edit<script>']));
        $action = editor_ai_sidebar_actions()['test/headline'];
        self::assertSame(5, $action->timeout());
        self::assertSame(3600, $action->maxWait());
        self::assertSame('editscript', $action->descriptor()['icon']);
        self::assertSame(10, $action->order());
        self::assertSame('edit_post', $action->runCapability());
        self::assertSame(200, $action->dailyCap());
        self::assertFalse($action->reapply(), 'reapply is not the default (ruling 7)');
        self::assertArrayNotHasKey('run', $action->descriptor());
    }

    public function testSelectionAddsSelectionNeed(): void
    {
        $this->provide($this->definition([
            'selection' => ['core/image'],
            'needs' => ['title', 'selection_attributes' => ['id', 'alt']],
            'apply' => ['mode' => 'editor', 'operation' => 'set_block_attribute', 'attribute' => 'alt'],
        ]));
        $action = editor_ai_sidebar_actions()['test/headline'];
        self::assertSame(['title', 'selection'], $action->needs());
        self::assertSame(['id', 'alt'], $action->selectionAttributes());
    }

    public function testActionSwitchedOffBySettingsOrFilter(): void
    {
        $this->provide($this->definition(), $this->definition(['id' => 'test/other']));
        update_option('editor_ai_sidebar_settings', ['enabled' => true, 'disabled' => ['test/headline']]);
        $post = $this->post();
        self::assertSame(['test/other'], array_keys(editor_ai_sidebar_actions($post)));
        add_filter('editor_ai_sidebar_action_enabled', static fn (bool $on, string $id, int $p): bool => $on && $p !== $post, 10, 3);
        self::assertSame([], editor_ai_sidebar_actions($post));
    }

    public function testZeroActionsIsFine(): void
    {
        self::assertSame([], editor_ai_sidebar_actions());
        self::assertSame([], editor_ai_sidebar_actions($this->post()));
        self::assertSame([], editor_ai_sidebar_rejected_definitions());
    }
}
