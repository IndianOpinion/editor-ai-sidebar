<?php

/**
 * Plugin Name: Editor AI Sidebar (must-use loader)
 * Description: Loads Editor AI Sidebar from wp-content/mu-plugins/editor-ai-sidebar/.
 *
 * Copy this file into wp-content/mu-plugins/ and the plugin folder into
 * wp-content/mu-plugins/editor-ai-sidebar/. Must-use plugins never run
 * activation hooks; the table is created on the first admin or REST
 * request instead, so nothing else is needed.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$editor_ai_sidebar_main = __DIR__ . '/editor-ai-sidebar/editor-ai-sidebar.php';
if (is_file($editor_ai_sidebar_main)) {
    require_once $editor_ai_sidebar_main;
}
unset($editor_ai_sidebar_main);
