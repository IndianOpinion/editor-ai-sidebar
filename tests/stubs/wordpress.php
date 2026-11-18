<?php

/**
 * A small WordPress stub layer: enough of the functions and classes this
 * plugin calls for PHPUnit to run without a WordPress install. State lives
 * in WpStub and is reset before every test.
 *
 * phpcs:disable
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/fake-wordpress/');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
    define('HOUR_IN_SECONDS', 3600);
    define('MINUTE_IN_SECONDS', 60);
    define('ARRAY_A', 'ARRAY_A');
}

final class WpStub
{
    /** @var array<string, array<int, array<int, array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var array<string, int> */
    public static array $didAction = [];
    /** @var array<int, array<string, mixed>> blog id => options */
    public static array $options = [];
    /** @var array<int, array<string, array{0: mixed, 1: int}>> blog id => transients */
    public static array $transients = [];
    public static int $blogId = 1;
    /** @var int[] */
    public static array $blogStack = [];
    public static bool $multisite = false;
    /** @var int[] */
    public static array $sites = [1];
    public static int $userId = 0;
    /** @var array<int, array<string, bool|callable>> user => cap => bool or fn(args) */
    public static array $caps = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, array<int, mixed>>> */
    public static array $meta = [];
    /** @var array<string, array<string, array<string, mixed>>> subtype => key => args */
    public static array $registeredMeta = [];
    /** @var array<string, array{supports: string[], show_in_rest: bool}> */
    public static array $postTypes = [];
    /** @var array<int, int> post => user holding the lock */
    public static array $locks = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    public static array $routes = [];
    public static ?WP_Screen $screen = null;
    /** @var array<string, array<string, mixed>> */
    public static array $scripts = [];
    /** @var array<string, string> */
    public static array $styles = [];
    /** @var array<string, string> */
    public static array $inline = [];
    /** @var array<string, array<string, mixed>> */
    public static array $settings = [];
    /** @var array<int, array<string, mixed>> */
    public static array $cron = [];
    public static int $nextPostId = 100;
    /** @var array<int, string> */
    public static array $privacy = [];
    public static ?int $currentPostId = null;

    public static function reset(): void
    {
        self::$hooks = [];
        self::$didAction = ['init' => 1];
        self::$options = [];
        self::$transients = [];
        self::$blogId = 1;
        self::$blogStack = [];
        self::$multisite = false;
        self::$sites = [1];
        self::$userId = 0;
        self::$caps = [];
        self::$posts = [];
        self::$meta = [];
        self::$registeredMeta = [];
        self::$postTypes = [
            'post' => ['supports' => ['title', 'editor'], 'show_in_rest' => true],
            'page' => ['supports' => ['title', 'editor'], 'show_in_rest' => true],
            'attachment' => ['supports' => ['title'], 'show_in_rest' => true],
            'wp_block' => ['supports' => ['title', 'editor'], 'show_in_rest' => true],
            'legacy' => ['supports' => ['title', 'editor'], 'show_in_rest' => false],
        ];
        self::$locks = [];
        self::$routes = [];
        self::$screen = null;
        self::$scripts = [];
        self::$styles = [];
        self::$inline = [];
        self::$settings = [];
        self::$cron = [];
        self::$nextPostId = 100;
        self::$privacy = [];
        self::$currentPostId = null;
    }

    public static function now(): int
    {
        return \IndianOpinion\EditorAiSidebar\Clock::now();
    }
}

// ---------------------------------------------------------------------
// Classes
// ---------------------------------------------------------------------

class WP_Error
{
    /** @var array<string, string[]> */
    public array $errors = [];
    /** @var array<string, mixed> */
    public array $error_data = [];

    public function __construct(string $code = '', string $message = '', mixed $data = '')
    {
        if ($code !== '') {
            $this->errors[$code][] = $message;
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }
    }

    public function get_error_code(): string
    {
        return (string) (array_key_first($this->errors) ?? '');
    }

    public function get_error_message(string $code = ''): string
    {
        $code = $code !== '' ? $code : $this->get_error_code();
        return $this->errors[$code][0] ?? '';
    }

    public function get_error_data(string $code = ''): mixed
    {
        $code = $code !== '' ? $code : $this->get_error_code();
        return $this->error_data[$code] ?? null;
    }
}

class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'draft';
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public int $post_author = 0;
    public int $post_parent = 0;

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        foreach ($fields as $key => $value) {
            $this->{$key} = $value;
        }
    }
}

class WP_Site
{
    public string $blog_id = '1';
}

class WP_Screen
{
    public string $base = 'post';
    public string $id = 'post';
    public bool $blockEditor = true;

    public function is_block_editor(): bool
    {
        return $this->blockEditor;
    }
}

class WP_REST_Request
{
    /** @var array<string, mixed> */
    private array $params = [];

    public function __construct(private string $method = 'GET', private string $route = '')
    {
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function get_route(): string
    {
        return $this->route;
    }

    public function set_param(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_params(): array
    {
        return $this->params;
    }
}

class WP_REST_Response
{
    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(private mixed $data = null, private int $status = 200)
    {
    }

    public function get_data(): mixed
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }

    public function set_status(int $status): void
    {
        $this->status = $status;
    }

    public function header(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    /**
     * @return array<string, string>
     */
    public function get_headers(): array
    {
        return $this->headers;
    }
}

// ---------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStub::$hooks[$hook][$priority][] = [$callback, $args];
    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    if (!isset(WpStub::$hooks[$hook][$priority])) {
        return false;
    }
    foreach (WpStub::$hooks[$hook][$priority] as $i => [$registered]) {
        if ($registered == $callback) {
            unset(WpStub::$hooks[$hook][$priority][$i]);
            return true;
        }
    }
    return false;
}

function remove_action(string $hook, callable $callback, int $priority = 10): bool
{
    return remove_filter($hook, $callback, $priority);
}

function remove_all_filters(string $hook): void
{
    unset(WpStub::$hooks[$hook]);
}

function has_filter(string $hook): bool
{
    return !empty(WpStub::$hooks[$hook]);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (empty(WpStub::$hooks[$hook])) {
        return $value;
    }
    $priorities = WpStub::$hooks[$hook];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, $accepted));
        }
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpStub::$didAction[$hook] = (WpStub::$didAction[$hook] ?? 0) + 1;
    if (empty(WpStub::$hooks[$hook])) {
        return;
    }
    $priorities = WpStub::$hooks[$hook];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
    }
}

function did_action(string $hook): int
{
    return WpStub::$didAction[$hook] ?? 0;
}

// ---------------------------------------------------------------------
// Options and transients (per site)
// ---------------------------------------------------------------------

function get_option(string $name, mixed $default = false): mixed
{
    return WpStub::$options[WpStub::$blogId][$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpStub::$options[WpStub::$blogId][$name] = $value;
    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    if (isset(WpStub::$options[WpStub::$blogId][$name])) {
        return false;
    }
    WpStub::$options[WpStub::$blogId][$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    unset(WpStub::$options[WpStub::$blogId][$name]);
    return true;
}

function get_transient(string $name): mixed
{
    $entry = WpStub::$transients[WpStub::$blogId][$name] ?? null;
    if ($entry === null) {
        return false;
    }
    if ($entry[1] > 0 && $entry[1] <= WpStub::now()) {
        unset(WpStub::$transients[WpStub::$blogId][$name]);
        return false;
    }
    return $entry[0];
}

function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    WpStub::$transients[WpStub::$blogId][$name] = [$value, $expiration > 0 ? WpStub::now() + $expiration : 0];
    return true;
}

function delete_transient(string $name): bool
{
    unset(WpStub::$transients[WpStub::$blogId][$name]);
    return true;
}

// ---------------------------------------------------------------------
// i18n, escaping, formatting
// ---------------------------------------------------------------------

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function wp_kses_post(string $text): string
{
    return $text;
}

function checked(mixed $checked, mixed $current = true, bool $display = true): string
{
    $out = (string) $checked === (string) $current ? " checked='checked'" : '';
    if ($display) {
        echo $out;
    }
    return $out;
}

function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
{
    return json_encode($data, $options, $depth);
}

function wp_strip_all_tags(string $text): string
{
    return trim(strip_tags($text));
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function get_locale(): string
{
    return WpStub::$options[WpStub::$blogId]['WPLANG'] ?? 'en_GB';
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function plugins_url(string $path = '', string $plugin = ''): string
{
    return 'https://example.test/wp-content/plugins/editor-ai-sidebar' . $path;
}

function load_plugin_textdomain(string $domain, bool $deprecated = false, string $path = ''): bool
{
    return true;
}

// ---------------------------------------------------------------------
// Users and capabilities
// ---------------------------------------------------------------------

function get_current_user_id(): int
{
    return WpStub::$userId;
}

function wp_set_current_user(int $id): void
{
    WpStub::$userId = $id;
}

function is_user_logged_in(): bool
{
    return WpStub::$userId > 0;
}

function current_user_can(string $cap, mixed ...$args): bool
{
    $grant = WpStub::$caps[WpStub::$userId][$cap] ?? false;
    if (is_callable($grant)) {
        return (bool) $grant(...$args);
    }
    return (bool) $grant;
}

// ---------------------------------------------------------------------
// Posts and meta
// ---------------------------------------------------------------------

function get_post(mixed $post = null): ?WP_Post
{
    if ($post === null) {
        $post = WpStub::$currentPostId;
    }
    if ($post instanceof WP_Post) {
        return $post;
    }
    return WpStub::$posts[(int) $post] ?? null;
}

function get_post_type(mixed $post = null): string|false
{
    $found = get_post($post);
    return $found ? $found->post_type : false;
}

function get_post_status(mixed $post = null): string|false
{
    $found = get_post($post);
    return $found ? $found->post_status : false;
}

/**
 * @param array<string, mixed> $args
 * @return array<string, string>
 */
function get_post_types(array $args = [], string $output = 'names'): array
{
    $out = [];
    foreach (WpStub::$postTypes as $name => $spec) {
        if (isset($args['show_in_rest']) && $spec['show_in_rest'] !== $args['show_in_rest']) {
            continue;
        }
        $out[$name] = $name;
    }
    return $out;
}

function post_type_supports(string $type, string $feature): bool
{
    return in_array($feature, WpStub::$postTypes[$type]['supports'] ?? [], true);
}

/**
 * @return array<string, array<string, mixed>>
 */
function get_registered_meta_keys(string $objectType, string $subtype = ''): array
{
    return WpStub::$registeredMeta[$subtype] ?? [];
}

function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
{
    $values = WpStub::$meta[$postId][$key] ?? [];
    if ($single) {
        return $values[0] ?? '';
    }
    return $values;
}

function update_post_meta(int $postId, string $key, mixed $value): bool
{
    $existed = isset(WpStub::$meta[$postId][$key]);
    WpStub::$meta[$postId][$key] = [$value];
    do_action($existed ? 'updated_post_meta' : 'added_post_meta', 1, $postId, $key, $value);
    return true;
}

function delete_post_meta(int $postId, string $key): bool
{
    unset(WpStub::$meta[$postId][$key]);
    do_action('deleted_post_meta', [1], $postId, $key, '');
    return true;
}

function get_post_thumbnail_id(int $postId): int
{
    return (int) get_post_meta($postId, '_thumbnail_id', true);
}

/**
 * @param array<string, mixed> $postarr
 */
function wp_insert_post(array $postarr, bool $wpError = false): int
{
    $data = apply_filters('wp_insert_post_data', $postarr, $postarr);
    $id = (int) ($postarr['ID'] ?? 0);
    if ($id === 0) {
        $id = WpStub::$nextPostId++;
        WpStub::$posts[$id] = new WP_Post(['ID' => $id]);
    }
    $post = WpStub::$posts[$id];
    foreach (['post_title', 'post_content', 'post_excerpt', 'post_status', 'post_type'] as $field) {
        if (isset($data[$field])) {
            $post->{$field} = $data[$field];
        }
    }
    if (isset($postarr['ID'])) {
        do_action('post_updated', $id, $post, $post);
    }
    return $id;
}

/**
 * @param array<string, mixed> $postarr
 */
function wp_update_post(array $postarr): int
{
    return wp_insert_post($postarr);
}

function wp_check_post_lock(int $postId): int|false
{
    $holder = WpStub::$locks[$postId] ?? 0;
    return $holder > 0 && $holder !== get_current_user_id() ? $holder : false;
}

// ---------------------------------------------------------------------
// REST
// ---------------------------------------------------------------------

/**
 * @param array<string, mixed> $args
 */
function register_rest_route(string $namespace, string $route, array $args): bool
{
    $handlers = isset($args['methods']) ? [$args] : $args;
    foreach ($handlers as $handler) {
        WpStub::$routes['/' . $namespace . $route][] = $handler;
    }
    return true;
}

// ---------------------------------------------------------------------
// Admin, assets, cron, multisite
// ---------------------------------------------------------------------

function get_current_screen(): ?WP_Screen
{
    return WpStub::$screen;
}

/**
 * @param string[] $deps
 */
function wp_register_script(string $handle, string $src, array $deps = [], mixed $ver = false, mixed $footer = false): bool
{
    WpStub::$scripts[$handle] = ['src' => $src, 'deps' => $deps, 'enqueued' => false];
    return true;
}

/**
 * @param string[] $deps
 */
function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $footer = false): void
{
    WpStub::$scripts[$handle] = ['src' => $src, 'deps' => $deps, 'enqueued' => true];
}

/**
 * @param string[] $deps
 */
function wp_enqueue_style(string $handle, string $src = '', array $deps = [], mixed $ver = false): void
{
    WpStub::$styles[$handle] = $src;
}

function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
{
    WpStub::$inline[$handle] = $data;
    return true;
}

function wp_set_script_translations(string $handle, string $domain = 'default', string $path = ''): bool
{
    return true;
}

function wp_next_scheduled(string $hook): int|false
{
    foreach (WpStub::$cron as $event) {
        if ($event['hook'] === $hook && $event['blog'] === WpStub::$blogId) {
            return (int) $event['time'];
        }
    }
    return false;
}

function wp_schedule_event(int $time, string $recurrence, string $hook): bool
{
    WpStub::$cron[] = ['time' => $time, 'recurrence' => $recurrence, 'hook' => $hook, 'blog' => WpStub::$blogId];
    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $before = count(WpStub::$cron);
    WpStub::$cron = array_values(array_filter(
        WpStub::$cron,
        static fn (array $event): bool => !($event['hook'] === $hook && $event['blog'] === WpStub::$blogId)
    ));
    return $before - count(WpStub::$cron);
}

function is_multisite(): bool
{
    return WpStub::$multisite;
}

/**
 * @param array<string, mixed> $args
 * @return int[]
 */
function get_sites(array $args = []): array
{
    return WpStub::$sites;
}

function switch_to_blog(int $id): bool
{
    WpStub::$blogStack[] = WpStub::$blogId;
    WpStub::$blogId = $id;
    if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) {
        $GLOBALS['wpdb']->set_blog($id);
    }
    return true;
}

function restore_current_blog(): bool
{
    WpStub::$blogId = array_pop(WpStub::$blogStack) ?? 1;
    if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) {
        $GLOBALS['wpdb']->set_blog(WpStub::$blogId);
    }
    return true;
}

function get_current_blog_id(): int
{
    return WpStub::$blogId;
}

/**
 * @param array<string, mixed> $args
 */
function register_setting(string $group, string $name, array $args = []): void
{
    WpStub::$settings['registered'][$name] = $args;
}

function add_settings_section(string $id, string $title, callable $callback, string $page): void
{
    WpStub::$settings['sections'][$id] = $callback;
}

function add_settings_field(string $id, string $title, callable $callback, string $page, string $section = 'default'): void
{
    WpStub::$settings['fields'][$id] = $callback;
}

function wp_add_privacy_policy_content(string $name, string $text): void
{
    WpStub::$privacy[] = $name;
}

function is_plugin_active_for_network(string $plugin): bool
{
    return (bool) (WpStub::$options[0]['network_active'] ?? false);
}

function _n(string $single, string $plural, int $number, string $domain = 'default'): string
{
    return $number === 1 ? $single : $plural;
}

/**
 * @param array<string, mixed> $args
 */
function register_post_meta(string $postType, string $key, array $args): bool
{
    WpStub::$registeredMeta[$postType][$key] = $args;
    return true;
}

function __return_false(): bool
{
    return false;
}

function __return_true(): bool
{
    return true;
}

/**
 * Minimal wpdb: prepare() follows core's placeholders (%s, %d, %i), and
 * query() records what it was given. The MySQL integration test extends
 * it with a real connection.
 */
class wpdb
{
    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $options = 'wp_options';
    /** @var string[] */
    public array $queries = [];
    public string $last_error = '';

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $index = 0;
        return (string) preg_replace_callback('/%[sdi]/', function (array $match) use (&$index, $args): string {
            $value = $args[$index++] ?? '';
            if ($match[0] === '%d') {
                return (string) (int) $value;
            }
            if ($match[0] === '%i') {
                return '`' . str_replace('`', '``', (string) $value) . '`';
            }
            return "'" . $this->escape((string) $value) . "'";
        }, $query);
    }

    protected function escape(string $value): string
    {
        return addslashes($value);
    }

    public function query(string $query): int|bool
    {
        $this->queries[] = $query;
        return 0;
    }

    public function get_row(string $query, string $output = 'OBJECT'): mixed
    {
        $this->queries[] = $query;
        return null;
    }

    /**
     * @return array<int, mixed>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        $this->queries[] = $query;
        return [];
    }

    public function get_var(string $query): mixed
    {
        $this->queries[] = $query;
        return null;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function set_blog(int $id): void
    {
        $this->prefix = $id === 1 ? $this->base_prefix : $this->base_prefix . $id . '_';
        $this->options = $this->prefix . 'options';
    }
}

/**
 * @return array<string, string>
 */
function dbDelta(string $sql): array
{
    if (isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb) {
        $GLOBALS['wpdb']->query($sql);
    }
    return [];
}
