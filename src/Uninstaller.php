<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

use IndianOpinion\EditorAiSidebar\Store\Schema;

/**
 * Clean-up on uninstall (PRD section 6).
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::cleanSite();
                restore_current_blog();
            }
            return;
        }
        self::cleanSite();
    }

    public static function cleanSite(): void
    {
        global $wpdb;
        delete_option(Settings::OPTION);
        delete_option(EventLog::OPTION);
        wp_clear_scheduled_hook(Plugin::PURGE_HOOK);
        foreach (['_transient_editor_ai_sidebar_', '_transient_timeout_editor_ai_sidebar_'] as $prefix) {
            $wpdb->query(
                (string) $wpdb->prepare(
                    'DELETE FROM %i WHERE option_name LIKE %s',
                    $wpdb->options,
                    $wpdb->esc_like($prefix) . '%'
                )
            );
        }
        Schema::drop($wpdb);
    }
}
