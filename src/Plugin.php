<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

use IndianOpinion\EditorAiSidebar\Store\DbRecordStore;
use IndianOpinion\EditorAiSidebar\Store\RecordStore;
use IndianOpinion\EditorAiSidebar\Store\Schema;

/**
 * Wires the parts together and owns the WordPress hooks.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const CONTRACT_VERSIONS = [1];
    public const PURGE_HOOK = 'editor_ai_sidebar_purge';
    public const HELP_URL = 'https://github.com/IndianOpinion/editor-ai-sidebar#writing-a-provider';

    private static ?self $instance = null;

    private string $file = '';
    private ?Settings $settings = null;
    private ?EventLog $log = null;
    private ?Registry $registry = null;
    private ?RecordStore $store = null;
    private ?Service $service = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Drop the shared instance (tests).
     */
    public static function reset(?RecordStore $store = null): self
    {
        self::$instance = new self();
        self::$instance->store = $store;
        return self::$instance;
    }

    public static function boot(string $file): void
    {
        $plugin = self::instance();
        $plugin->file = $file;
        $plugin->hooks();
    }

    public function file(): string
    {
        return $this->file;
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings();
    }

    public function log(): EventLog
    {
        return $this->log ??= new EventLog();
    }

    public function registry(): Registry
    {
        return $this->registry ??= new Registry($this->settings(), $this->log());
    }

    public function store(): RecordStore
    {
        if ($this->store === null) {
            global $wpdb;
            $this->store = new DbRecordStore($wpdb);
        }
        return $this->store;
    }

    public function service(): Service
    {
        return $this->service ??= new Service(
            $this->registry(),
            $this->store(),
            new Limiter($this->store()),
            $this->log(),
            new ResultValidator(),
            new WriteWatch(),
            new FatalGuard($this->log())
        );
    }

    public function rest(): Rest
    {
        return new Rest($this->service(), $this->settings());
    }

    public function hooks(): void
    {
        add_action('init', [$this, 'onInit']);
        add_action('rest_api_init', [$this, 'onRestInit']);
        add_filter('rest_post_dispatch', [Rest::class, 'headers'], 10, 3);
        add_action('enqueue_block_editor_assets', [new Assets($this), 'enqueue']);
        add_action('admin_init', [$this, 'onAdminInit']);
        add_action(self::PURGE_HOOK, [$this, 'purge']);
        add_action('wp_initialize_site', [$this, 'onNewSite'], 20, 1);
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('editor-ai-sidebar', new Cli($this));
        }
    }

    public function onInit(): void
    {
        load_plugin_textdomain('editor-ai-sidebar', false, dirname(plugin_basename($this->file)) . '/languages');
    }

    public function onRestInit(): void
    {
        $this->installForCurrentSite();
        $this->rest()->register();
    }

    public function onAdminInit(): void
    {
        $this->installForCurrentSite();
        (new AdminSettings($this))->register();
        Privacy::register();
    }

    public function installForCurrentSite(): void
    {
        global $wpdb;
        Schema::maybeInstall($wpdb);
        if (!wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(Clock::now() + HOUR_IN_SECONDS, 'hourly', self::PURGE_HOOK);
        }
    }

    public function purge(): void
    {
        $this->store()->purgeExpired(Clock::now());
    }

    /**
     * New site on a network where the plugin is network-active (case 76).
     */
    public function onNewSite(\WP_Site $site): void
    {
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active_for_network(plugin_basename($this->file))) {
            return;
        }
        switch_to_blog((int) $site->blog_id);
        $this->installForCurrentSite();
        restore_current_blog();
    }

    public static function activate(bool $networkWide = false): void
    {
        $plugin = self::instance();
        if (is_multisite() && $networkWide) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                $plugin->installForCurrentSite();
                restore_current_blog();
            }
            return;
        }
        $plugin->installForCurrentSite();
    }

    public static function deactivate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                wp_clear_scheduled_hook(self::PURGE_HOOK);
                restore_current_blog();
            }
            return;
        }
        wp_clear_scheduled_hook(self::PURGE_HOOK);
    }

    public static function viewCapability(): string
    {
        return (string) apply_filters('editor_ai_sidebar_view_capability', 'edit_posts');
    }

    public static function settingsCapability(): string
    {
        return (string) apply_filters('editor_ai_sidebar_settings_capability', 'manage_options');
    }

    public static function title(): string
    {
        return (string) apply_filters('editor_ai_sidebar_title', __('AI actions', 'editor-ai-sidebar'));
    }

    /**
     * Post types that support the editor and are shown in REST (case 11).
     *
     * @return string[]
     */
    public static function postTypes(): array
    {
        $types = [];
        foreach (get_post_types(['show_in_rest' => true], 'names') as $type) {
            if (is_string($type) && post_type_supports($type, 'editor')) {
                $types[] = $type;
            }
        }
        $filtered = apply_filters('editor_ai_sidebar_post_types', $types);
        return is_array($filtered) ? array_values(array_filter($filtered, 'is_string')) : $types;
    }

    /**
     * The site locale as a BCP 47 tag.
     */
    public static function siteLanguage(): string
    {
        return str_replace('_', '-', (string) get_locale());
    }
}
