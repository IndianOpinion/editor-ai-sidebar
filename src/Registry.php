<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Collects definitions from the `editor_ai_sidebar_actions` filter,
 * validates each one, keeps the good ones and records the rest
 * (PRD section 4.1).
 */
final class Registry
{
    public const FILTER = 'editor_ai_sidebar_actions';

    private const ID_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+$/';
    private const FIELD_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';
    private const BLOCK_PATTERN = '/^[a-z0-9-]+\/[a-z0-9-]+$/';
    private const ATTRIBUTE_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';
    private const META_KEY_PATTERN = '/^[^\s]{1,255}$/';

    private const OPERATION_ITEM_TYPES = [
        'set_title' => ['text'],
        'set_excerpt' => ['text'],
        'insert_blocks' => ['text', 'list'],
        'set_block_attribute' => ['text'],
        'set_meta' => ['text', 'fields'],
        'copy' => ['text', 'list', 'fields'],
    ];

    private const EDITOR_TARGETS = [
        'set_title' => ['title'],
        'set_excerpt' => ['excerpt'],
        'insert_blocks' => ['content'],
        'set_block_attribute' => ['content'],
        'set_meta' => [],
        'copy' => [],
    ];

    /** @var array<string, ActionDefinition>|null */
    private ?array $definitions = null;

    /** @var array<int, array{id: string, reason: string, plugin: string, post_type: string}> */
    private array $rejections = [];

    /** @var array<string, true> */
    private array $metaRejected = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly EventLog $log
    ) {
    }

    /**
     * Every valid definition, memoised for the request once `init` has run.
     *
     * @return array<string, ActionDefinition>
     */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }
        $this->rejections = [];
        $this->metaRejected = [];
        $raw = apply_filters(self::FILTER, []);
        $definitions = [];
        if (!is_array($raw)) {
            $raw = [];
        }
        foreach ($raw as $key => $entry) {
            $id = is_array($entry) && isset($entry['id']) && is_string($entry['id']) ? $entry['id'] : (string) $key;
            $plugin = is_array($entry) ? $this->pluginFileOf($entry['run'] ?? null) : '';
            try {
                $definition = $this->normalise($entry, $plugin);
            } catch (Rejection $rejection) {
                $this->reject($id, $rejection->getMessage(), $plugin);
                continue;
            }
            if (isset($definitions[$definition->id()])) {
                $this->reject($definition->id(), 'duplicate_id', $plugin);
                continue;
            }
            $definitions[$definition->id()] = $definition;
        }
        if (did_action('init') > 0) {
            $this->definitions = $definitions;
        }
        return $definitions;
    }

    /**
     * Forget the memoised list (tests, and after a settings change).
     */
    public function reset(): void
    {
        $this->definitions = null;
        $this->rejections = [];
        $this->metaRejected = [];
    }

    /**
     * @return array<int, array{id: string, reason: string, plugin: string, post_type: string}>
     */
    public function rejections(): array
    {
        $this->all();
        return $this->rejections;
    }

    public function find(string $id): ?ActionDefinition
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * Actions that apply to a post type, after the per-type meta check.
     *
     * @return array<string, ActionDefinition>
     */
    public function forPostType(string $postType): array
    {
        $out = [];
        foreach ($this->all() as $id => $definition) {
            if (!$definition->appliesTo($postType)) {
                continue;
            }
            if (!$this->metaInRest($definition, $postType)) {
                continue;
            }
            $out[$id] = $definition;
        }
        return $out;
    }

    /**
     * Enabled actions, limited to a post's type when a post id is given.
     *
     * @return array<string, ActionDefinition>
     */
    public function enabledFor(?int $postId): array
    {
        if ($postId === null) {
            $out = [];
            foreach ($this->all() as $id => $definition) {
                if ($this->isEnabled($definition, 0)) {
                    $out[$id] = $definition;
                }
            }
            return $out;
        }
        $postType = get_post_type($postId);
        if (!is_string($postType)) {
            return [];
        }
        $out = [];
        foreach ($this->forPostType($postType) as $id => $definition) {
            if ($this->isEnabled($definition, $postId)) {
                $out[$id] = $definition;
            }
        }
        return $out;
    }

    public function isEnabled(ActionDefinition $definition, int $postId): bool
    {
        $on = !in_array($definition->id(), $this->settings->disabledActions(), true);
        return (bool) apply_filters('editor_ai_sidebar_action_enabled', $on, $definition->id(), $postId);
    }

    /**
     * An editor-mode `set_meta` action needs every key it writes to be
     * registered with `show_in_rest` for the post type (case 8).
     */
    public function metaInRest(ActionDefinition $definition, string $postType): bool
    {
        $keys = $definition->editorMetaKeys();
        if ($keys === [] || $definition->isServerMode()) {
            return true;
        }
        $registered = array_merge(
            get_registered_meta_keys('post', ''),
            get_registered_meta_keys('post', $postType)
        );
        foreach ($keys as $key) {
            if (!isset($registered[$key]) || empty($registered[$key]['show_in_rest'])) {
                $marker = $definition->id() . '|' . $postType;
                if (!isset($this->metaRejected[$marker])) {
                    $this->metaRejected[$marker] = true;
                    $this->reject($definition->id(), 'meta_not_in_rest', $definition->pluginFile(), $postType);
                }
                return false;
            }
        }
        return true;
    }

    private function reject(string $id, string $reason, string $plugin, string $postType = ''): void
    {
        $this->rejections[] = [
            'id' => $id,
            'reason' => $reason,
            'plugin' => $plugin,
            'post_type' => $postType,
        ];
        $marker = 'editor_ai_sidebar_rj_' . md5($id . '|' . $reason . '|' . $postType . '|' . gmdate('Ymd', Clock::now()));
        if (get_transient($marker) === false) {
            set_transient($marker, 1, DAY_IN_SECONDS);
            $this->log->add('definition_rejected', [
                'action_id' => substr($id, 0, 64),
                'code' => $reason,
                'detail' => trim($plugin . ($postType !== '' ? ' (' . $postType . ')' : '')),
            ]);
        }
    }

    private function pluginFileOf(mixed $callable): string
    {
        try {
            if ($callable instanceof \Closure || (is_string($callable) && function_exists($callable))) {
                $file = (new \ReflectionFunction($callable))->getFileName();
            } elseif (is_array($callable) && count($callable) === 2 && is_callable($callable)) {
                $file = (new \ReflectionMethod($callable[0], (string) $callable[1]))->getFileName();
            } elseif (is_object($callable) && method_exists($callable, '__invoke')) {
                $file = (new \ReflectionMethod($callable, '__invoke'))->getFileName();
            } else {
                return '';
            }
        } catch (\ReflectionException) {
            return '';
        }
        if (!is_string($file)) {
            return '';
        }
        return function_exists('plugin_basename') ? plugin_basename($file) : basename($file);
    }

    /**
     * @throws Rejection
     */
    private function normalise(mixed $entry, string $plugin): ActionDefinition
    {
        if (!is_array($entry)) {
            throw new Rejection('invalid_definition');
        }
        if (!isset($entry['id']) || !is_string($entry['id'])) {
            throw new Rejection('invalid_definition');
        }
        $id = $entry['id'];
        $length = strlen($id);
        if ($length < 3 || $length > 64 || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new Rejection('invalid_id');
        }
        foreach (['label', 'group', 'result', 'apply', 'run'] as $required) {
            if (!array_key_exists($required, $entry)) {
                throw new Rejection('invalid_definition');
            }
        }

        $contract = $entry['contract'] ?? 1;
        if (!is_int($contract) || $contract < 1) {
            throw new Rejection('invalid_definition');
        }
        if ($contract > max(Plugin::CONTRACT_VERSIONS)) {
            throw new Rejection('contract_unsupported');
        }

        $label = $this->string($entry, 'label', null);
        if (trim($label) === '') {
            throw new Rejection('invalid_definition');
        }
        $group = $this->string($entry, 'group', null);
        if (!in_array($group, ActionDefinition::GROUPS, true)) {
            throw new Rejection('invalid_definition');
        }

        $callbacks = [];
        foreach (['run', 'poll', 'cancel', 'apply_callback', 'validate', 'dismiss', 'available'] as $name) {
            if (!array_key_exists($name, $entry) || $entry[$name] === null) {
                $callbacks[$name] = null;
                continue;
            }
            if (!is_callable($entry[$name])) {
                throw new Rejection('invalid_definition');
            }
            $callbacks[$name] = $entry[$name];
        }
        if ($callbacks['run'] === null) {
            throw new Rejection('invalid_definition');
        }

        $selection = $entry['selection'] ?? null;
        if ($selection !== null) {
            if (!is_array($selection) || $selection === [] || !array_is_list($selection)) {
                throw new Rejection('invalid_definition');
            }
            foreach ($selection as $block) {
                if (!is_string($block) || preg_match(self::BLOCK_PATTERN, $block) !== 1) {
                    throw new Rejection('invalid_definition');
                }
            }
        }

        [$needs, $selectionAttributes] = $this->needs($entry['needs'] ?? ['title', 'text'], $selection !== null);

        $postTypes = $entry['post_types'] ?? null;
        if ($postTypes !== null) {
            $postTypes = $this->stringList($postTypes);
        }

        $result = $this->result($entry['result']);
        $apply = $this->apply($entry['apply'], $result['item_type'], $result['fields'], $selection);

        if ($apply['mode'] === 'server' && $callbacks['apply_callback'] === null) {
            throw new Rejection('invalid_definition');
        }

        $data = [
            'id' => $id,
            'contract' => $contract,
            'label' => $label,
            'description' => $this->string($entry, 'description', ''),
            'group' => $group,
            'order' => $this->int($entry, 'order', 10),
            'icon' => (string) preg_replace('/[^a-z0-9-]/', '', strtolower($this->string($entry, 'icon', ''))),
            'post_types' => $postTypes,
            'needs' => $needs,
            'selection_attributes' => $selectionAttributes,
            'selection' => $selection,
            'result' => $result,
            'apply' => $apply,
            'editable' => $this->bool($entry, 'editable', false),
            'run_capability' => $this->string($entry, 'run_capability', 'edit_post'),
            'apply_capability' => $this->string($entry, 'apply_capability', 'edit_post'),
            'timeout' => max(5, min(300, $this->int($entry, 'timeout', 60))),
            'max_wait' => max(30, min(3600, $this->int($entry, 'max_wait', 600))),
            'daily_cap' => max(1, min(100000, $this->int($entry, 'daily_cap', 200))),
        ];

        return new ActionDefinition($data, $callbacks, $plugin);
    }

    /**
     * @return array{0: string[], 1: string[]}
     * @throws Rejection
     */
    private function needs(mixed $raw, bool $selectionBound): array
    {
        if (!is_array($raw)) {
            throw new Rejection('invalid_definition');
        }
        $needs = [];
        $attributes = [];
        foreach ($raw as $key => $value) {
            if ($key === 'selection_attributes') {
                foreach ($this->stringList($value) as $attribute) {
                    if (preg_match(self::ATTRIBUTE_PATTERN, $attribute) !== 1) {
                        throw new Rejection('invalid_definition');
                    }
                    $attributes[] = $attribute;
                }
                continue;
            }
            if (!is_int($key) || !is_string($value) || !in_array($value, ActionDefinition::NEEDS, true)) {
                throw new Rejection('invalid_definition');
            }
            $needs[] = $value;
        }
        if ($selectionBound && !in_array('selection', $needs, true)) {
            $needs[] = 'selection';
        }
        if (!$selectionBound && in_array('selection', $needs, true)) {
            throw new Rejection('invalid_definition');
        }
        if ($attributes !== [] && !$selectionBound) {
            throw new Rejection('invalid_definition');
        }
        return [array_values(array_unique($needs)), array_values(array_unique($attributes))];
    }

    /**
     * @return array{layout: string, item_type: string, fields: array<string, array{label: string, limit: int|null}>}
     * @throws Rejection
     */
    private function result(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new Rejection('invalid_definition');
        }
        $layout = $raw['layout'] ?? null;
        $type = $raw['item_type'] ?? null;
        if (!in_array($layout, [Result::SINGLE, Result::CHOICES], true)) {
            throw new Rejection('invalid_definition');
        }
        if (!in_array($type, [Item::TEXT, Item::LIST, Item::FIELDS], true)) {
            throw new Rejection('invalid_definition');
        }
        $fields = [];
        if ($type === Item::FIELDS) {
            $rawFields = $raw['fields'] ?? null;
            if (!is_array($rawFields) || $rawFields === [] || count($rawFields) > 10) {
                throw new Rejection('invalid_definition');
            }
            foreach ($rawFields as $name => $spec) {
                if (!is_string($name) || preg_match(self::FIELD_PATTERN, $name) !== 1) {
                    throw new Rejection('invalid_definition');
                }
                if (is_string($spec)) {
                    $fields[$name] = ['label' => $spec, 'limit' => null];
                    continue;
                }
                if (!is_array($spec) || !isset($spec['label']) || !is_string($spec['label'])) {
                    throw new Rejection('invalid_definition');
                }
                $limit = $spec['limit'] ?? null;
                if ($limit !== null && (!is_int($limit) || $limit < 1)) {
                    throw new Rejection('invalid_definition');
                }
                $fields[$name] = ['label' => $spec['label'], 'limit' => $limit];
            }
        }
        return ['layout' => $layout, 'item_type' => $type, 'fields' => $fields];
    }

    /**
     * @param array<string, array{label: string, limit: int|null}> $fields
     * @param string[]|null $selection
     * @return array<string, mixed>
     * @throws Rejection
     */
    private function apply(mixed $raw, string $itemType, array $fields, ?array $selection): array
    {
        if (!is_array($raw)) {
            throw new Rejection('invalid_definition');
        }
        $mode = $raw['mode'] ?? null;
        if (!in_array($mode, ['editor', 'server'], true)) {
            throw new Rejection('invalid_definition');
        }
        $targets = isset($raw['targets']) ? $this->stringList($raw['targets']) : [];
        $out = [
            'mode' => $mode,
            'operation' => null,
            'targets' => [],
            'requires_saved' => $this->bool($raw, 'requires_saved', false),
            'reapply' => $this->bool($raw, 'reapply', false),
            'label' => $this->string($raw, 'label', ''),
            'position' => 'end',
            'attribute' => null,
            'meta_map' => [],
        ];

        if ($mode === 'server') {
            if ($targets === []) {
                throw new Rejection('invalid_definition');
            }
            foreach ($targets as $target) {
                if (in_array($target, ActionDefinition::CORE_FIELDS, true)) {
                    throw new Rejection('core_field_server_write');
                }
                if (!self::validServerTarget($target, $selection !== null)) {
                    throw new Rejection('invalid_definition');
                }
            }
            $out['targets'] = array_values(array_unique($targets));
            return $out;
        }

        $operation = $raw['operation'] ?? null;
        if (!is_string($operation) || !in_array($operation, ActionDefinition::OPERATIONS, true)) {
            throw new Rejection('unknown_operation');
        }
        if (!in_array($itemType, self::OPERATION_ITEM_TYPES[$operation], true)) {
            throw new Rejection('invalid_definition');
        }
        $out['operation'] = $operation;
        $out['targets'] = self::EDITOR_TARGETS[$operation];

        if ($operation === 'insert_blocks') {
            $position = $raw['position'] ?? 'end';
            if (!in_array($position, ['start', 'end', 'after_selection'], true)) {
                throw new Rejection('invalid_definition');
            }
            $out['position'] = $position;
        }
        if ($operation === 'set_block_attribute') {
            $attribute = $raw['attribute'] ?? null;
            if (!is_string($attribute) || preg_match(self::ATTRIBUTE_PATTERN, $attribute) !== 1 || $selection === null) {
                throw new Rejection('invalid_definition');
            }
            $out['attribute'] = $attribute;
        }
        if ($operation === 'set_meta') {
            if ($itemType === Item::FIELDS) {
                $map = $raw['meta_map'] ?? null;
                if (!is_array($map) || $map === []) {
                    throw new Rejection('invalid_definition');
                }
                foreach ($map as $field => $key) {
                    if (!is_string($field) || !isset($fields[$field]) || !is_string($key)) {
                        throw new Rejection('invalid_definition');
                    }
                    if (preg_match(self::META_KEY_PATTERN, $key) !== 1) {
                        throw new Rejection('invalid_definition');
                    }
                }
                $out['meta_map'] = $map;
                $out['targets'] = array_values(array_map(static fn (string $key): string => 'meta:' . $key, $map));
            } else {
                if (count($targets) !== 1 || !str_starts_with($targets[0], 'meta:') || strlen($targets[0]) < 6) {
                    throw new Rejection('invalid_definition');
                }
                $out['targets'] = $targets;
            }
        }
        return $out;
    }

    private static function validServerTarget(string $target, bool $selectionBound): bool
    {
        if (in_array($target, ['featured_media', 'new_post', 'provider'], true)) {
            return true;
        }
        if (str_starts_with($target, 'meta:')) {
            return preg_match(self::META_KEY_PATTERN, substr($target, 5)) === 1;
        }
        if ($target === 'attachment_alt:<from selection>') {
            return $selectionBound;
        }
        if (str_starts_with($target, 'attachment_alt:')) {
            return preg_match('/^[1-9][0-9]*$/', substr($target, 15)) === 1;
        }
        return false;
    }

    /**
     * @param array<mixed> $entry
     * @throws Rejection
     */
    private function string(array $entry, string $key, ?string $default): string
    {
        if (!array_key_exists($key, $entry) || $entry[$key] === null) {
            if ($default === null) {
                throw new Rejection('invalid_definition');
            }
            return $default;
        }
        if (!is_string($entry[$key])) {
            throw new Rejection('invalid_definition');
        }
        return $entry[$key];
    }

    /**
     * @param array<mixed> $entry
     * @throws Rejection
     */
    private function int(array $entry, string $key, int $default): int
    {
        if (!array_key_exists($key, $entry) || $entry[$key] === null) {
            return $default;
        }
        if (!is_int($entry[$key])) {
            throw new Rejection('invalid_definition');
        }
        return $entry[$key];
    }

    /**
     * @param array<mixed> $entry
     * @throws Rejection
     */
    private function bool(array $entry, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $entry) || $entry[$key] === null) {
            return $default;
        }
        if (!is_bool($entry[$key])) {
            throw new Rejection('invalid_definition');
        }
        return $entry[$key];
    }

    /**
     * @return string[]
     * @throws Rejection
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new Rejection('invalid_definition');
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new Rejection('invalid_definition');
            }
        }
        return $value;
    }
}
