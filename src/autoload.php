<?php

/**
 * Autoloader for installs without Composer.
 *
 * The plugin file requires this. A Composer install uses the PSR-4 map in
 * composer.json instead, and this file then does nothing harmful.
 */

declare(strict_types=1);

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'IndianOpinion\\EditorAiSidebar\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
);

require_once __DIR__ . '/functions.php';
