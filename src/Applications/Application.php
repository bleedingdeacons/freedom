<?php

declare(strict_types=1);

namespace Freedom\Applications;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One app that tablets fetch configuration for — "register", "link".
 *
 * <b>The slug is the name the app knows itself by</b>, built into it
 * alongside the site's address and its callback URI. None of the three is
 * a secret, which is the point: they are the only things an app using
 * Freedom has baked in.
 *
 * <b>`revision` is the counter every value's version is taken from.</b>
 * It only goes up, and every write to any value in this application takes
 * the next number, so a version is never reused within an application and
 * a tablet can compare versions for difference rather than order. See
 * {@see \Freedom\Config\ConfigEditor}.
 */
final class Application
{
    /** Lower-case, digits and hyphens; what the app sends as `application`. */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $callbackUri,
        public readonly bool $allowLoopback,
        public readonly bool $acceptFellowshipSessions,
        public readonly bool $enabled,
        public readonly int $revision,
        public readonly int $createdAt,
        public readonly int $updatedAt,
    ) {
    }
}
