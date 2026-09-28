<?php

declare(strict_types=1);

namespace Freedom\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One stored value: an application default (`tabletId` 0) or one
 * tablet's override.
 *
 * `storedValue` is always ciphertext, secret or not. Encrypting only the
 * secrets would make the flag the thing standing between a database dump
 * and an SMTP password, and a flag is exactly what somebody forgets to
 * tick.
 */
final class ConfigValue
{
    public function __construct(
        public readonly int $id,
        public readonly int $applicationId,
        public readonly int $tabletId,
        public readonly string $key,
        public readonly string $storedValue,
        public readonly bool $isSecret,
        public readonly int $version,
        public readonly int $updatedAt,
        public readonly int $updatedBy,
    ) {
    }

    public function isDefault(): bool
    {
        return $this->tabletId === 0;
    }
}
