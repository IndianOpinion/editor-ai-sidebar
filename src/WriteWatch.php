<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Watches for writes to an action's targets while a provider runs
 * (case 30). A provider may write its own storage freely; it may not
 * change the post, or a declared target, before the user approves.
 */
final class WriteWatch
{
    private int $postId = 0;

    /** @var array<int, array<string, true>> Object id to watched meta keys. */
    private array $meta = [];

    private bool $tripped = false;

    private string $what = '';

    /**
     * @param array{name: string, clientId: string, attributes: array<string, mixed>}|null $selection
     */
    public function start(ActionDefinition $definition, int $postId, ?array $selection): void
    {
        $this->postId = $postId;
        $this->meta = [];
        $this->tripped = false;
        $this->what = '';

        $targets = $definition->isServerMode() ? $definition->targets() : [];
        foreach ($definition->editorMetaKeys() as $key) {
            $this->meta[$postId][$key] = true;
        }
        foreach ($targets as $target) {
            if (str_starts_with($target, 'meta:')) {
                $this->meta[$postId][substr($target, 5)] = true;
            } elseif ($target === 'featured_media') {
                $this->meta[$postId]['_thumbnail_id'] = true;
            } elseif ($target === 'attachment_alt:<from selection>') {
                $id = is_array($selection) ? (int) ($selection['attributes']['id'] ?? 0) : 0;
                if ($id > 0) {
                    $this->meta[$id]['_wp_attachment_image_alt'] = true;
                }
            } elseif (str_starts_with($target, 'attachment_alt:')) {
                $this->meta[(int) substr($target, 15)]['_wp_attachment_image_alt'] = true;
            }
        }

        add_filter('wp_insert_post_data', [$this, 'onInsertPostData'], 1, 2);
        add_action('post_updated', [$this, 'onPostUpdated'], 1, 1);
        add_action('added_post_meta', [$this, 'onMeta'], 1, 3);
        add_action('updated_post_meta', [$this, 'onMeta'], 1, 3);
        add_action('deleted_post_meta', [$this, 'onMeta'], 1, 3);
    }

    /**
     * Stop watching. Returns what was written, or '' when nothing was.
     */
    public function stop(): string
    {
        remove_filter('wp_insert_post_data', [$this, 'onInsertPostData'], 1);
        remove_action('post_updated', [$this, 'onPostUpdated'], 1);
        remove_action('added_post_meta', [$this, 'onMeta'], 1);
        remove_action('updated_post_meta', [$this, 'onMeta'], 1);
        remove_action('deleted_post_meta', [$this, 'onMeta'], 1);
        return $this->tripped ? $this->what : '';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public function onInsertPostData(array $data, array $postarr): array
    {
        if (isset($postarr['ID']) && (int) $postarr['ID'] === $this->postId && $this->postId > 0) {
            $this->trip('post');
        }
        return $data;
    }

    public function onPostUpdated(int $postId): void
    {
        if ($postId === $this->postId) {
            $this->trip('post');
        }
    }

    /**
     * @param int|int[] $metaId
     */
    public function onMeta(int|array $metaId, int $objectId, string $key): void
    {
        unset($metaId);
        if (isset($this->meta[$objectId][$key])) {
            $this->trip('meta:' . $key);
        }
    }

    private function trip(string $what): void
    {
        if (!$this->tripped) {
            $this->tripped = true;
            $this->what = $what;
        }
    }
}
