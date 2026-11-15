<?php

declare(strict_types=1);

namespace IndianOpinion\EditorAiSidebar;

/**
 * The one settings option: an enabled flag and the disabled action ids.
 */
final class Settings
{
    public const OPTION = 'editor_ai_sidebar_settings';

    /**
     * @return array{enabled: bool, disabled: string[]}
     */
    public function get(): array
    {
        return self::sanitise(get_option(self::OPTION, []));
    }

    public function sidebarEnabled(): bool
    {
        if (defined('EDITOR_AI_SIDEBAR_DISABLED') && constant('EDITOR_AI_SIDEBAR_DISABLED')) {
            return false;
        }
        return $this->get()['enabled'];
    }

    /**
     * @return string[]
     */
    public function disabledActions(): array
    {
        return $this->get()['disabled'];
    }

    /**
     * Sanitise callback for the Settings API, also used on read.
     *
     * @return array{enabled: bool, disabled: string[]}
     */
    public static function sanitise(mixed $value): array
    {
        if (!is_array($value)) {
            $value = [];
        }
        $enabled = !array_key_exists('enabled', $value) || (bool) $value['enabled'];
        $disabled = [];
        if (isset($value['disabled']) && is_array($value['disabled'])) {
            foreach ($value['disabled'] as $id) {
                if (is_string($id) && preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $id) === 1 && strlen($id) <= 64) {
                    $disabled[] = $id;
                }
            }
        }
        return ['enabled' => $enabled, 'disabled' => array_values(array_unique($disabled))];
    }
}
