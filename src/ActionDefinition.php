<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * A validated, normalised action definition (PRD section 5.2).
 *
 * Only the registry builds these. Providers pass arrays to the filter.
 */
final class ActionDefinition
{
    public const GROUPS = ['summary', 'headlines', 'meta', 'alt_text', 'translation', 'other'];
    public const NEEDS = [
        'title', 'excerpt', 'content', 'text', 'selection', 'featured_media', 'language', 'post_type', 'status',
    ];
    public const OPERATIONS = ['set_title', 'set_excerpt', 'insert_blocks', 'set_block_attribute', 'set_meta', 'copy'];
    public const CORE_FIELDS = ['title', 'excerpt', 'content', 'status', 'date', 'slug', 'author'];

    /**
     * @param array<string, mixed> $data Normalised keys, see Registry::normalise().
     * @param array<string, callable|null> $callbacks
     */
    public function __construct(
        private readonly array $data,
        private readonly array $callbacks,
        private readonly string $pluginFile
    ) {
    }

    public function id(): string
    {
        return (string) $this->data['id'];
    }

    public function contract(): int
    {
        return (int) $this->data['contract'];
    }

    public function label(): string
    {
        return (string) $this->data['label'];
    }

    public function group(): string
    {
        return (string) $this->data['group'];
    }

    public function order(): int
    {
        return (int) $this->data['order'];
    }

    /**
     * @return string[]|null Null means every sidebar post type.
     */
    public function postTypes(): ?array
    {
        /** @var string[]|null $types */
        $types = $this->data['post_types'];
        return $types;
    }

    public function appliesTo(string $postType): bool
    {
        $types = $this->postTypes();
        return $types === null || in_array($postType, $types, true);
    }

    /**
     * Declared context fields, `selection` included for block-bound actions.
     *
     * @return string[]
     */
    public function needs(): array
    {
        /** @var string[] $needs */
        $needs = $this->data['needs'];
        return $needs;
    }

    /**
     * @return string[]
     */
    public function selectionAttributes(): array
    {
        /** @var string[] $attributes */
        $attributes = $this->data['selection_attributes'];
        return $attributes;
    }

    /**
     * @return string[]|null
     */
    public function selection(): ?array
    {
        /** @var string[]|null $selection */
        $selection = $this->data['selection'];
        return $selection;
    }

    public function layout(): string
    {
        return (string) $this->data['result']['layout'];
    }

    public function itemType(): string
    {
        return (string) $this->data['result']['item_type'];
    }

    /**
     * @return array<string, array{label: string, limit: int|null}>
     */
    public function resultFields(): array
    {
        /** @var array<string, array{label: string, limit: int|null}> $fields */
        $fields = $this->data['result']['fields'];
        return $fields;
    }

    public function mode(): string
    {
        return (string) $this->data['apply']['mode'];
    }

    public function isServerMode(): bool
    {
        return $this->mode() === 'server';
    }

    public function operation(): ?string
    {
        $operation = $this->data['apply']['operation'];
        return is_string($operation) ? $operation : null;
    }

    /**
     * Declared server-mode targets, or for editor mode the derived ones.
     *
     * @return string[]
     */
    public function targets(): array
    {
        /** @var string[] $targets */
        $targets = $this->data['apply']['targets'];
        return $targets;
    }

    public function requiresSaved(): bool
    {
        return (bool) $this->data['apply']['requires_saved'];
    }

    public function reapply(): bool
    {
        return (bool) $this->data['apply']['reapply'];
    }

    public function position(): string
    {
        return (string) $this->data['apply']['position'];
    }

    public function attribute(): ?string
    {
        $attribute = $this->data['apply']['attribute'];
        return is_string($attribute) ? $attribute : null;
    }

    /**
     * @return array<string, string> Field name to meta key.
     */
    public function metaMap(): array
    {
        /** @var array<string, string> $map */
        $map = $this->data['apply']['meta_map'];
        return $map;
    }

    /**
     * Meta keys an editor-mode `set_meta` action writes.
     *
     * @return string[]
     */
    public function editorMetaKeys(): array
    {
        if ($this->operation() !== 'set_meta') {
            return [];
        }
        $keys = array_values($this->metaMap());
        foreach ($this->targets() as $target) {
            if (str_starts_with($target, 'meta:')) {
                $keys[] = substr($target, 5);
            }
        }
        return array_values(array_unique($keys));
    }

    public function editable(): bool
    {
        return (bool) $this->data['editable'];
    }

    public function runCapability(): string
    {
        return (string) $this->data['run_capability'];
    }

    public function applyCapability(): string
    {
        return (string) $this->data['apply_capability'];
    }

    public function timeout(): int
    {
        return (int) $this->data['timeout'];
    }

    public function maxWait(): int
    {
        return (int) $this->data['max_wait'];
    }

    public function dailyCap(): int
    {
        return (int) $this->data['daily_cap'];
    }

    /**
     * The required `run` callback.
     */
    public function runCallback(): callable
    {
        $run = $this->callbacks['run'] ?? null;
        if ($run === null) {
            throw new \LogicException('A validated definition always has a run callback.');
        }
        return $run;
    }

    public function callback(string $name): ?callable
    {
        return $this->callbacks[$name] ?? null;
    }

    public function pluginFile(): string
    {
        return $this->pluginFile;
    }

    /**
     * The REST descriptor without callbacks (PRD section 5.4).
     *
     * @return array<string, mixed>
     */
    public function descriptor(): array
    {
        $apply = $this->data['apply'];
        return [
            'id' => $this->id(),
            'label' => $this->label(),
            'description' => (string) $this->data['description'],
            'group' => $this->group(),
            'order' => $this->order(),
            'icon' => (string) $this->data['icon'],
            'needs' => $this->needs(),
            'selection_attributes' => $this->selectionAttributes(),
            'selection' => $this->selection(),
            'result' => [
                'layout' => $this->layout(),
                'item_type' => $this->itemType(),
                'fields' => (object) $this->resultFields(),
            ],
            'apply' => [
                'mode' => $apply['mode'],
                'operation' => $apply['operation'],
                'targets' => $apply['targets'],
                'requires_saved' => $apply['requires_saved'],
                'reapply' => $apply['reapply'],
                'label' => $apply['label'],
                'position' => $apply['position'],
                'attribute' => $apply['attribute'],
                'meta_map' => (object) $apply['meta_map'],
            ],
            'editable' => $this->editable(),
            'timeout' => $this->timeout(),
            'max_wait' => $this->maxWait(),
        ];
    }
}
