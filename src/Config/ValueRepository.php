<?php

declare(strict_types=1);

namespace Freedom\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stored values.
 *
 * <b>Reads throw on a database error rather than answering empty.</b> An
 * empty list is a legitimate answer — an application with nothing set —
 * and a tablet that is told its configuration is empty deletes what it
 * holds. So a read that failed must never be mistaken for one that found
 * nothing.
 */
interface ValueRepository
{
    /**
     * @return list<ConfigValue>
     * @throws \RuntimeException on a database error.
     */
    public function defaults(int $applicationId): array;

    /**
     * @return list<ConfigValue>
     * @throws \RuntimeException on a database error.
     */
    public function overrides(int $applicationId, int $tabletId): array;

    public function find(int $applicationId, int $tabletId, string $key): ?ConfigValue;

    /** @throws \RuntimeException when the row cannot be written. */
    public function upsert(
        int $applicationId,
        int $tabletId,
        string $key,
        string $storedValue,
        bool $isSecret,
        int $version,
        int $updatedBy,
        int $now,
    ): void;

    public function delete(int $applicationId, int $tabletId, string $key): bool;

    public function removeForTablet(int $tabletId): int;

    public function removeForApplication(int $applicationId): int;
}
