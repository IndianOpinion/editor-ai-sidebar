<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * Inspect the sidebar from the command line. There is no command to run or
 * apply an action: an apply needs a human click.
 */
final class Cli
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * Lists registered actions, their availability and rejections.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : Limit to actions for this post and show availability.
     *
     * [--user=<id>]
     * : Check availability as this user.
     *
     * [--format=<format>]
     * : table or json. Default table.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function actions(array $args, array $assoc): void
    {
        unset($args);
        $rows = $this->actionRows(
            isset($assoc['post']) ? (int) $assoc['post'] : null,
            isset($assoc['user']) ? (int) $assoc['user'] : null
        );
        \WP_CLI\Utils\format_items(
            self::format($assoc),
            $rows,
            ['id', 'label', 'group', 'mode', 'targets', 'status', 'available', 'reason']
        );
    }

    /**
     * Prints the event log, newest first.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : How many events. Default 50.
     *
     * [--format=<format>]
     * : table or json. Default table.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc
     */
    public function log(array $args, array $assoc): void
    {
        unset($args);
        $rows = $this->logRows(isset($assoc['limit']) ? (int) $assoc['limit'] : 50);
        \WP_CLI\Utils\format_items(
            self::format($assoc),
            $rows,
            ['time', 'event', 'action_id', 'post_id', 'user_id', 'result_id', 'code', 'detail']
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function actionRows(?int $postId, ?int $userId): array
    {
        if ($userId !== null) {
            wp_set_current_user($userId);
        }
        $registry = $this->plugin->registry();
        $rows = [];
        $definitions = $postId !== null ? $registry->enabledFor($postId) : $registry->all();
        $availability = [];
        if ($postId !== null) {
            try {
                foreach ($this->plugin->service()->listActions($postId, get_current_user_id()) as $descriptor) {
                    $availability[(string) $descriptor['id']] = $descriptor;
                }
            } catch (ApiError $e) {
                $availability = [];
            }
        }
        foreach ($definitions as $id => $definition) {
            $state = $availability[$id] ?? null;
            $rows[] = [
                'id' => $id,
                'label' => $definition->label(),
                'group' => $definition->group(),
                'mode' => $definition->isServerMode() ? 'server' : 'editor:' . (string) $definition->operation(),
                'targets' => implode(',', $definition->targets()),
                'status' => $registry->isEnabled($definition, $postId ?? 0) ? 'enabled' : 'disabled',
                'available' => $state === null ? '' : ($state['available'] ? 'yes' : 'no'),
                'reason' => $state === null ? '' : (string) $state['reason'],
            ];
        }
        foreach ($registry->rejections() as $rejection) {
            $rows[] = [
                'id' => $rejection['id'],
                'label' => '',
                'group' => '',
                'mode' => '',
                'targets' => '',
                'status' => 'rejected:' . $rejection['reason'],
                'available' => 'no',
                'reason' => $rejection['plugin'],
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function logRows(int $limit): array
    {
        $rows = [];
        foreach ($this->plugin->log()->latest(max(1, $limit)) as $entry) {
            $rows[] = [
                'time' => gmdate('Y-m-d H:i:s', $entry['time']),
                'event' => $entry['event'],
                'action_id' => $entry['action_id'],
                'post_id' => (string) $entry['post_id'],
                'user_id' => (string) $entry['user_id'],
                'result_id' => $entry['result_id'],
                'code' => $entry['code'],
                'detail' => $entry['detail'],
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, string> $assoc
     */
    private static function format(array $assoc): string
    {
        $format = $assoc['format'] ?? 'table';
        return in_array($format, ['table', 'json', 'csv', 'yaml'], true) ? $format : 'table';
    }
}
