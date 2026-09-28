<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What a tablet says about itself at enrolment. Shown to admins so they
 * can tell tablets apart; trusted for nothing else.
 */
final class TabletProfile
{
    public function __construct(
        public readonly string $label,
        public readonly string $platform,
        public readonly string $model,
        public readonly string $appVersion,
        public readonly string $publicKey,
    ) {
    }
}
