<?php

declare(strict_types=1);

namespace Freedom\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What a configuration key and value may be.
 *
 * Keys are lower-case and dotted by convention — `smtp.host`,
 * `unity.api_key` — so an app can group them, and narrow enough to be
 * safe anywhere an app might use one: a preference name, a file name, a
 * log line.
 */
final class ConfigKey
{
    public const PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,99}$/';

    /** A value is configuration, not a file. */
    public const MAX_VALUE_BYTES = 16384;

    /** The most keys one values request may ask for. */
    public const MAX_KEYS_PER_REQUEST = 100;

    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }
}
