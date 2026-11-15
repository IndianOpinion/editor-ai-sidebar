<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Tests\Unit;

use IndianOpinion\EditorAiSidebar\AdminSettings;
use IndianOpinion\EditorAiSidebar\Cli;
use IndianOpinion\EditorAiSidebar\EventLog;
use IndianOpinion\EditorAiSidebar\FatalGuard;
use IndianOpinion\EditorAiSidebar\Plugin;
use IndianOpinion\EditorAiSidebar\Privacy;
use IndianOpinion\EditorAiSidebar\Settings;
use IndianOpinion\EditorAiSidebar\Store\DbRecordStore;
use IndianOpinion\EditorAiSidebar\Store\Schema;
use IndianOpinion\EditorAiSidebar\Tests\Support\TestCase;
use IndianOpinion\EditorAiSidebar\Uninstaller;

/**
 * Site-level pieces: multisite, settings screen, uninstall, CLI, privacy,
 * the fatal guard and the event log.
 */
final class SiteTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testT76SettingsAndRecordsArePerSite(): void
    {
        \WpStub::$multisite = true;
        \WpStub::$sites = [1, 2];
        $GLOBALS['wpdb'] = new \wpdb();
        $this->provide($this->definition());
        $post = $this->post();

        update_option(Settings::OPTION, ['enabled' => false]);
        switch_to_blog(2);
        self::assertTrue((new Settings())->sidebarEnabled(), 'site 2 keeps its own settings');
        $envelope = $this->runAction('test/headline', $post, ['title' => 'T', 'text' => 'B'])->get_data();
        restore_current_blog();
        self::assertFalse((new Settings())->sidebarEnabled());
        self::assertNull($this->store->find($envelope['result_id'], 'result'), 'site 1 does not see site 2 records');

        // The SQL store uses each site's own table.
        $db = $GLOBALS['wpdb'];
        $store = new DbRecordStore($db);
        $store->delete('x');
        switch_to_blog(2);
        $store->delete('x');
        restore_current_blog();
        self::assertStringContainsString('`wp_editor_ai_sidebar_records`', $db->queries[0]);
        self::assertStringContainsString('`wp_2_editor_ai_sidebar_records`', $db->queries[1]);
    }

    public function testActivationInstallsOnEverySiteOfANetwork(): void
    {
        \WpStub::$multisite = true;
        \WpStub::$sites = [1, 2, 3];
        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public function get_var(string $query): mixed
            {
                $this->queries[] = $query;
                preg_match("/LIKE '([^']+)'/", $query, $m);
                return stripslashes(stripslashes($m[1] ?? ''));
            }
        };
        Plugin::activate(true);
        $creates = array_values(array_filter($GLOBALS['wpdb']->queries, static fn (string $q): bool => str_starts_with($q, 'CREATE TABLE')));
        self::assertCount(3, $creates);
        self::assertStringContainsString('wp_3_editor_ai_sidebar_records', $creates[2]);
        foreach ([1, 2, 3] as $site) {
            switch_to_blog($site);
            self::assertSame(1, get_option(Schema::VERSION_OPTION));
            self::assertNotFalse(wp_next_scheduled(Plugin::PURGE_HOOK));
            restore_current_blog();
        }
        Plugin::deactivate(true);
        self::assertSame([], \WpStub::$cron);
    }

    public function testUninstallRemovesOptionsTransientsAndTable(): void
    {
        $GLOBALS['wpdb'] = new \wpdb();
        update_option(Settings::OPTION, ['enabled' => true]);
        update_option(EventLog::OPTION, [['time' => 1, 'event' => 'x']]);
        update_option('someone_elses_option', 'kept');
        wp_schedule_event(1, 'hourly', Plugin::PURGE_HOOK);
        Uninstaller::run();
        self::assertFalse(get_option(Settings::OPTION));
        self::assertFalse(get_option(EventLog::OPTION));
        self::assertSame('kept', get_option('someone_elses_option'));
        self::assertSame([], \WpStub::$cron);
        $queries = implode("\n", $GLOBALS['wpdb']->queries);
        self::assertStringContainsString("LIKE '\\\\_transient\\\\_editor\\\\_ai\\\\_sidebar\\\\_%'", $queries);
        self::assertStringContainsString("LIKE '\\\\_transient\\\\_timeout\\\\_editor\\\\_ai\\\\_sidebar\\\\_%'", $queries);
        self::assertStringContainsString('DROP TABLE IF EXISTS `wp_editor_ai_sidebar_records`', $queries);
    }

    public function testSettingsSanitiseAndScreen(): void
    {
        self::assertSame(['enabled' => true, 'disabled' => []], Settings::sanitise(null));
        self::assertSame(
            ['enabled' => false, 'disabled' => ['a/b']],
            Settings::sanitise(['enabled' => '0', 'disabled' => ['a/b', 'Bad Id', ['x'], 'a/b']])
        );

        $this->provide($this->definition());
        wp_set_current_user(self::EDITOR);
        $admin = new AdminSettings($this->plugin);
        $admin->register();
        self::assertArrayHasKey(Settings::OPTION, \WpStub::$settings['registered']);
        self::assertArrayNotHasKey('sections', \WpStub::$settings, 'no section for users without the settings capability');

        wp_set_current_user(self::ADMIN);
        $admin->register();
        self::assertArrayHasKey('editor_ai_sidebar', \WpStub::$settings['sections']);
        ob_start();
        $admin->renderEnabled();
        $admin->renderActions();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('name="editor_ai_sidebar_settings[enabled]" value="0"', $html);
        self::assertStringContainsString('test/headline', $html);
        self::assertStringContainsString('editor: set_title', $html);
    }

    public function testSettingsScreenEscapesProviderText(): void
    {
        $this->provide($this->definition(['label' => '<script>alert(1)</script>']));
        wp_set_current_user(self::ADMIN);
        ob_start();
        (new AdminSettings($this->plugin))->renderActions();
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCliRows(): void
    {
        $this->provide(
            $this->definition(['available' => static fn (): string => 'Not today.']),
            $this->definition(['id' => 'Bad/Id'])
        );
        $cli = new Cli($this->plugin);
        $rows = $cli->actionRows($this->post(), self::EDITOR);
        self::assertSame('test/headline', $rows[0]['id']);
        self::assertSame('no', $rows[0]['available']);
        self::assertSame('Not today.', $rows[0]['reason']);
        self::assertSame('rejected:invalid_id', $rows[1]['status']);

        $this->plugin->log()->add('applied', ['action_id' => 'test/headline', 'code' => 'editor']);
        $log = $cli->logRows(10);
        self::assertSame('applied', $log[0]['event']);
        self::assertSame(gmdate('Y-m-d H:i:s', 1_800_000_000), $log[0]['time']);
    }

    public function testEventLogIsARingOf500(): void
    {
        $log = new EventLog();
        for ($i = 0; $i < 510; $i++) {
            $log->add('run_started', ['action_id' => 'a/b', 'post_id' => $i]);
        }
        $all = $log->all();
        self::assertCount(500, $all);
        self::assertSame(10, $all[0]['post_id']);
        self::assertSame(509, $log->latest(1)[0]['post_id']);
    }

    public function testPrivacyText(): void
    {
        Privacy::register();
        self::assertSame(['Editor AI Sidebar'], \WpStub::$privacy);
        self::assertStringContainsString('itself sends nothing anywhere', Privacy::text());
    }

    public function testFatalGuardRewritesCoreHandlerOutput(): void
    {
        $guard = new FatalGuard($this->plugin->log());
        $error = ['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => '/x.php', 'line' => 1];
        self::assertSame(['response' => 500], $guard->filterArgs(['response' => 500], $error), 'unarmed: untouched');

        $guard->arm('test/headline', 5, self::EDITOR);
        $args = apply_filters('wp_php_error_args', ['response' => 500, 'code' => 'internal_server_error'], $error);
        $message = apply_filters('wp_php_error_message', 'There has been a critical error.', $error);
        self::assertSame('provider_error', $args['code']);
        self::assertSame('This action failed. The site log has details.', $message);
        self::assertContains('run_failed:provider_error', $this->loggedEvents());
        self::assertCount(1, $this->firedOf('editor_ai_sidebar_run_failed'), 'recorded once');
        $guard->disarm();
        self::assertFalse($guard->armed());
        self::assertSame('x', apply_filters('wp_php_error_message', 'x', $error));
    }

    public function testPurgeRemovesExpiredRecords(): void
    {
        $this->store->insert(str_repeat('a', 32), 'result', 1, 1, 'a/b', [], '', 0, 10);
        $this->store->insert(str_repeat('b', 32), 'result', 1, 1, 'a/b', [], '', 0, 1_800_000_100);
        $this->plugin->purge();
        self::assertNull($this->store->find(str_repeat('a', 32), 'result'));
        self::assertNotNull($this->store->find(str_repeat('b', 32), 'result'));
    }
}
