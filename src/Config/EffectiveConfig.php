<?php

declare(strict_types=1);

namespace Freedom\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What one tablet actually gets: the application's defaults, with this
 * tablet's overrides laid over them.
 *
 * <b>The effective version is the winning row's own version.</b> That is
 * what makes overrides work without any bookkeeping:
 *
 * - Add an override, and the key's effective version becomes the
 *   override's — a new revision, so different from what the tablet holds.
 * - Remove it, and the effective version falls back to the default's.
 *   Still different from what the tablet holds (the override's), so the
 *   tablet fetches the default again.
 * - Change the default underneath an override, and the override still
 *   wins, so nothing changes for that tablet and it fetches nothing.
 *
 * All three rely on versions never repeating within an application, which
 * {@see ConfigEditor} guarantees, and on tablets comparing for difference
 * rather than order.
 *
 * Pure: no database, no WordPress, so the rules above are tested as a
 * table rather than through a controller.
 */
final class EffectiveConfig
{
    /**
     * @param list<ConfigValue> $defaults
     * @param list<ConfigValue> $overrides
     * @return array<string, ConfigValue> Keyed and sorted by config key.
     */
    public static function resolve(array $defaults, array $overrides): array
    {
        $effective = [];

        foreach ($defaults as $value) {
            $effective[$value->key] = $value;
        }

        foreach ($overrides as $value) {
            $effective[$value->key] = $value;
        }

        ksort($effective, SORT_STRING);

        return $effective;
    }
}
