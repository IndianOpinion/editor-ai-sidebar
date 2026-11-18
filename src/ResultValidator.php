<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Turns what a provider returned into checked value objects (PRD 5.6).
 *
 * Every failure throws ContractViolation with a message naming the rule.
 * Messages never quote provider text.
 */
final class ResultValidator
{
    public const MAX_ITEMS = 20;
    public const MAX_TEXT = 20000;
    public const MAX_LIST_ENTRIES = 50;
    public const MAX_LIST_ENTRY = 1000;
    public const MAX_FIELDS = 10;
    public const MAX_NOTES = 10;
    public const MAX_NOTE = 300;

    private const ITEM_ID = '/^[a-z0-9-]{1,32}$/';
    private const LANG = '/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})*$/';

    /**
     * Classify a `run` or `poll` return value.
     *
     * @throws ContractViolation
     */
    public function runReturn(mixed $value, ActionDefinition $definition): Result|Pending|\WP_Error
    {
        if ($value instanceof \WP_Error) {
            return $value;
        }
        if ($value instanceof Pending) {
            return $this->pending($value->providerRef(), $value->pollAfter());
        }
        if ($value instanceof Result) {
            return $this->result($value->toArray(), $definition);
        }
        if (is_array($value)) {
            if (array_key_exists('pending', $value)) {
                $pending = $value['pending'];
                if (!is_array($pending) || !isset($pending['ref']) || !is_string($pending['ref'])) {
                    throw new ContractViolation('pending_shape');
                }
                $after = $pending['poll_after'] ?? 3;
                if (!is_int($after)) {
                    throw new ContractViolation('pending_shape');
                }
                return $this->pending($pending['ref'], $after);
            }
            if (array_key_exists('items', $value)) {
                return $this->result($value, $definition);
            }
        }
        throw new ContractViolation('wrong_return_type');
    }

    /**
     * @throws ContractViolation
     */
    private function pending(string $ref, int $pollAfter): Pending
    {
        if ($ref === '' || strlen($ref) > 1000) {
            throw new ContractViolation('pending_ref');
        }
        return Pending::job($ref, max(2, min(900, $pollAfter)));
    }

    /**
     * Validate the array form of a result against the action's declaration.
     *
     * @param array<mixed> $raw
     * @throws ContractViolation
     */
    public function result(array $raw, ActionDefinition $definition): Result
    {
        $layout = $raw['layout'] ?? $definition->layout();
        if (!in_array($layout, [Result::SINGLE, Result::CHOICES], true)) {
            throw new ContractViolation('layout');
        }
        $items = $raw['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new ContractViolation('items_shape');
        }
        $notes = $this->notes($raw['notes'] ?? []);
        if ($items === []) {
            return Result::none($notes);
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new ContractViolation('too_many_items');
        }
        if ($layout !== $definition->layout()) {
            throw new ContractViolation('layout_mismatch');
        }
        if ($layout === Result::SINGLE && count($items) !== 1) {
            throw new ContractViolation('single_needs_one_item');
        }
        if ($layout === Result::CHOICES && count($items) < 2) {
            throw new ContractViolation('choices_need_two_items');
        }
        $built = [];
        $seen = [];
        foreach ($items as $rawItem) {
            $item = $this->item($rawItem, $definition);
            if (isset($seen[$item->id()])) {
                throw new ContractViolation('duplicate_item_id');
            }
            $seen[$item->id()] = true;
            $built[] = $item;
        }
        return $layout === Result::SINGLE ? Result::single($built[0], $notes) : Result::choices($built, $notes);
    }

    /**
     * Check a value of a declared type, as returned by a provider or as
     * edited by a user. Returns the cleaned value.
     *
     * @return string|array<int|string, string>
     * @throws ContractViolation
     */
    public function value(string $type, mixed $value, ActionDefinition $definition): string|array
    {
        if ($type === Item::TEXT) {
            return $this->string($value, self::MAX_TEXT, 'text_value');
        }
        if ($type === Item::LIST) {
            if (!is_array($value) || !array_is_list($value) || $value === []) {
                throw new ContractViolation('list_value');
            }
            if (count($value) > self::MAX_LIST_ENTRIES) {
                throw new ContractViolation('list_too_long');
            }
            return array_map(fn (mixed $entry): string => $this->string($entry, self::MAX_LIST_ENTRY, 'list_entry'), $value);
        }
        if ($type === Item::FIELDS) {
            if (!is_array($value) || $value === [] || array_is_list($value)) {
                throw new ContractViolation('fields_value');
            }
            if (count($value) > self::MAX_FIELDS) {
                throw new ContractViolation('too_many_fields');
            }
            $declared = $definition->resultFields();
            $out = [];
            foreach ($value as $name => $entry) {
                if (!is_string($name) || !isset($declared[$name])) {
                    throw new ContractViolation('undeclared_field');
                }
                $out[$name] = $this->string($entry, self::MAX_TEXT, 'field_value');
            }
            return $out;
        }
        throw new ContractViolation('item_type');
    }

    /**
     * @throws ContractViolation
     */
    private function item(mixed $raw, ActionDefinition $definition): Item
    {
        if (!is_array($raw)) {
            throw new ContractViolation('item_shape');
        }
        $id = $raw['id'] ?? null;
        if (!is_string($id) || preg_match(self::ITEM_ID, $id) !== 1) {
            throw new ContractViolation('item_id');
        }
        $type = $raw['type'] ?? null;
        if (!in_array($type, [Item::TEXT, Item::LIST, Item::FIELDS], true)) {
            throw new ContractViolation('unknown_item_type');
        }
        if ($type !== $definition->itemType()) {
            throw new ContractViolation('item_type_mismatch');
        }
        $value = $this->value($type, $raw['value'] ?? null, $definition);
        $notes = $this->notes($raw['notes'] ?? []);

        $score = $raw['score'] ?? null;
        if ($score !== null) {
            if ((!is_float($score) && !is_int($score)) || !is_finite((float) $score)) {
                throw new ContractViolation('score');
            }
            $score = (float) $score;
        }
        $scoreLabel = $raw['score_label'] ?? null;
        if ($scoreLabel !== null) {
            $scoreLabel = $this->string($scoreLabel, 100, 'score_label');
        }
        $lang = $raw['lang'] ?? null;
        if ($lang !== null && (!is_string($lang) || preg_match(self::LANG, $lang) !== 1)) {
            throw new ContractViolation('lang');
        }
        $dir = $raw['dir'] ?? null;
        if ($dir !== null && !in_array($dir, ['ltr', 'rtl'], true)) {
            throw new ContractViolation('dir');
        }
        return Item::make($id, $type, $value, $notes, $score, $scoreLabel, $lang, $dir);
    }

    /**
     * @return Note[]
     * @throws ContractViolation
     */
    private function notes(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ContractViolation('notes_shape');
        }
        if (count($raw) > self::MAX_NOTES) {
            throw new ContractViolation('too_many_notes');
        }
        $out = [];
        foreach ($raw as $note) {
            if ($note instanceof Note) {
                $note = $note->toArray();
            }
            if (!is_array($note)) {
                throw new ContractViolation('note_shape');
            }
            $text = $this->string($note['text'] ?? null, self::MAX_NOTE, 'note_text');
            $level = $note['level'] ?? Note::INFO;
            if ($level === Note::WARNING) {
                $out[] = Note::warning($text);
            } elseif ($level === Note::INFO) {
                $out[] = Note::info($text);
            } else {
                throw new ContractViolation('note_level');
            }
        }
        return $out;
    }

    /**
     * Normalise an `apply_callback` return value.
     *
     * @return array{message: string, targets: string[], links: array<int, array{label: string, url: string}>, dropped_links: int}
     * @throws ContractViolation
     */
    public function outcome(mixed $value): array
    {
        if ($value instanceof ApplyOutcome) {
            $value = ['message' => $value->message(), 'targets' => $value->targets(), 'links' => $value->links()];
        }
        if (!is_array($value) || !isset($value['message']) || !is_string($value['message'])) {
            throw new ContractViolation('outcome_shape');
        }
        $targets = $value['targets'] ?? [];
        if (!is_array($targets) || !array_is_list($targets)) {
            throw new ContractViolation('outcome_targets');
        }
        foreach ($targets as $target) {
            if (!is_string($target)) {
                throw new ContractViolation('outcome_targets');
            }
        }
        $links = $value['links'] ?? [];
        if (!is_array($links) || !array_is_list($links)) {
            throw new ContractViolation('outcome_links');
        }
        $siteHost = (string) wp_parse_url(admin_url(), PHP_URL_HOST);
        $kept = [];
        $dropped = 0;
        foreach (array_slice($links, 0, 10) as $link) {
            if (!is_array($link) || !is_string($link['label'] ?? null) || !is_string($link['url'] ?? null)) {
                throw new ContractViolation('outcome_links');
            }
            $host = wp_parse_url($link['url'], PHP_URL_HOST);
            $scheme = wp_parse_url($link['url'], PHP_URL_SCHEME);
            if ($host !== $siteHost || !in_array($scheme, ['http', 'https'], true)) {
                $dropped++;
                continue;
            }
            $kept[] = ['label' => $this->string($link['label'], 200, 'link_label'), 'url' => $link['url']];
        }
        $message = trim(wp_strip_all_tags($value['message']));
        return [
            'message' => mb_substr($message, 0, 300),
            'targets' => $targets,
            'links' => $kept,
            'dropped_links' => $dropped,
        ];
    }

    /**
     * @throws ContractViolation
     */
    private function string(mixed $value, int $max, string $rule): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new ContractViolation($rule);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max) {
            throw new ContractViolation($rule);
        }
        return $value;
    }
}
