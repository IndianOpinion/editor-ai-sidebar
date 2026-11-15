<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar\Store;

/**
 * The plugin's one table, per site, and its install and removal.
 */
final class Schema
{
    public const VERSION = 1;
    public const VERSION_OPTION = 'editor_ai_sidebar_db_version';
    public const TABLE = 'editor_ai_sidebar_records';

    public static function table(\wpdb $db): string
    {
        return $db->prefix . self::TABLE;
    }

    public static function createSql(\wpdb $db): string
    {
        $charset = $db->get_charset_collate();
        return 'CREATE TABLE ' . self::table($db) . " (
  id varchar(64) NOT NULL,
  kind varchar(8) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  action_id varchar(64) NOT NULL,
  data longtext NOT NULL,
  context_hash char(64) NOT NULL DEFAULT '',
  created_at bigint(20) NOT NULL,
  expires_at bigint(20) NOT NULL,
  used tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY owner (user_id,post_id,kind),
  KEY expires_at (expires_at)
) $charset;";
    }

    /**
     * Create or update the table for the current site when the stored
     * schema version is behind, then check the table really exists.
     */
    public static function maybeInstall(\wpdb $db): void
    {
        if ((int) get_option(self::VERSION_OPTION, 0) >= self::VERSION) {
            return;
        }
        self::install($db);
    }

    public static function install(\wpdb $db): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta(self::createSql($db));
        $found = $db->get_var((string) $db->prepare('SHOW TABLES LIKE %s', $db->esc_like(self::table($db))));
        if ($found === self::table($db)) {
            update_option(self::VERSION_OPTION, self::VERSION, true);
        }
    }

    public static function drop(\wpdb $db): void
    {
        $db->query((string) $db->prepare('DROP TABLE IF EXISTS %i', self::table($db)));
        delete_option(self::VERSION_OPTION);
    }
}
