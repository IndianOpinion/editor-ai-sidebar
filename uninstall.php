<?php

/**
 * Removes what this plugin created: its two options, its schema version
 * option, its transients and its table, on every site of a network. It
 * touches nothing a provider stored.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!class_exists(\IndianOpinion\EditorAiSidebar\Uninstaller::class)) {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\EditorAiSidebar\Uninstaller::run();
