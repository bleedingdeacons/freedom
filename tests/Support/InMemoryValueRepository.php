<?php

declare(strict_types=1);

namespace Freedom\Tests\Support;

use Freedom\Config\ConfigValue;
use Freedom\Config\ValueRepository;
use RuntimeException;

/**
 * Values in an array. `$failReads` makes every read throw, as the real
 * repository does when the database errors — the case that must become a
 * 500 rather than an empty manifest.
 */
final class InMemoryValueRepository implements ValueRepository
{
    /** @var array<string, ConfigValue> Keyed "app|tablet|key". */
    public array $rows = [];

    public bool $failReads = false;

    private int $nextId = 1;

    public function defaults(int $applicationId): array
    {
        return $this->select($applicationId, 0);
    }

    public function overrides(int $applicationId, int $tabletId): array
    {
        return $tabletId <= 0 ? [] : $this->select($applicationId, $tabletId);
    }

    public function find(int $applicationId, int $tabletId, string $key): ?ConfigValue
    {
        return $this->rows[$this->id($applicationId, $tabletId, $key)] ?? null;
    }

    public function upsert(int $applicationId, int $tabletId, string $key, string $storedValue, bool $isSecret, int $version, int $updatedBy, int $now): void
    {
        $existing = $this->find($applicationId, $tabletId, $key);

        $this->rows[$this->id($applicationId, $tabletId, $key)] = new ConfigValue(
            $existing->id ?? $this->nextId++,
            $applicationId,
            $tabletId,
            $key,
            $storedValue,
            $isSecret,
            $version,
            $now,
            $updatedBy,
        );
    }

    public function delete(int $applicationId, int $tabletId, string $key): bool
    {
        $id = $this->id($applicationId, $tabletId, $key);
        $existed = isset($this->rows[$id]);
        unset($this->rows[$id]);

        return $existed;
    }

    public function removeForTablet(int $tabletId): int
    {
        if ($tabletId <= 0) {
            return 0;
        }

        $before = count($this->rows);
        $this->rows = array_filter($this->rows, fn(ConfigValue $v): bool => $v->tabletId !== $tabletId);

        return $before - count($this->rows);
    }

    public function removeForApplication(int $applicationId): int
    {
        $before = count($this->rows);
        $this->rows = array_filter($this->rows, fn(ConfigValue $v): bool => $v->applicationId !== $applicationId);

        return $before - count($this->rows);
    }

    /** @return list<ConfigValue> */
    private function select(int $applicationId, int $tabletId): array
    {
        if ($this->failReads) {
            throw new RuntimeException('database unavailable');
        }

        $found = array_values(array_filter(
            $this->rows,
            fn(ConfigValue $v): bool => $v->applicationId === $applicationId && $v->tabletId === $tabletId,
        ));
        usort($found, fn(ConfigValue $a, ConfigValue $b): int => strcmp($a->key, $b->key));

        return $found;
    }

    private function id(int $applicationId, int $tabletId, string $key): string
    {
        return $applicationId . '|' . $tabletId . '|' . $key;
    }
}
