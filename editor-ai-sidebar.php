<?php

/**
 * Plugin Name:       Editor AI Sidebar
 * Plugin URI:        https://github.com/IndianOpinion/editor-ai-sidebar
 * Description:       One block-editor sidebar for AI actions. Each action calls a server endpoint and shows its result for approval before anything is written. Makes no model calls of its own.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       editor-ai-sidebar
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(\IndianOpinion\EditorAiSidebar\Plugin::class)) {
    require_once __DIR__ . '/src/autoload.php';
}

register_activation_hook(__FILE__, [\IndianOpinion\EditorAiSidebar\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\IndianOpinion\EditorAiSidebar\Plugin::class, 'deactivate']);

\IndianOpinion\EditorAiSidebar\Plugin::boot(__FILE__);
