<?php

declare(strict_types=1);

namespace Freedom\Tests\Support;

use Freedom\Applications\Application;
use Freedom\Applications\ApplicationRepository;
use RuntimeException;

final class InMemoryApplicationRepository implements ApplicationRepository
{
    /** @var array<int, Application> */
    public array $rows = [];

    private int $nextId = 1;

    public function create(string $slug, string $name, string $callbackUri, bool $allowLoopback, bool $acceptFellowshipSessions, int $now): Application
    {
        if ($this->findBySlug($slug) !== null) {
            throw new RuntimeException('slug taken');
        }

        $application = new Application($this->nextId++, $slug, $name, $callbackUri, $allowLoopback, $acceptFellowshipSessions, true, 0, $now, $now);
        $this->rows[$application->id] = $application;

        return $application;
    }

    public function update(int $id, string $name, string $callbackUri, bool $allowLoopback, bool $acceptFellowshipSessions, bool $enabled, int $now): bool
    {
        $current = $this->rows[$id] ?? null;
        if ($current === null) {
            return false;
        }

        $this->rows[$id] = new Application($id, $current->slug, $name, $callbackUri, $allowLoopback, $acceptFellowshipSessions, $enabled, $current->revision, $current->createdAt, $now);

        return true;
    }

    public function findById(int $id): ?Application
    {
        return $this->rows[$id] ?? null;
    }

    public function findBySlug(string $slug): ?Application
    {
        foreach ($this->rows as $row) {
            if ($row->slug === $slug) {
                return $row;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->rows);
    }

    public function nextRevision(int $id): int
    {
        $current = $this->rows[$id] ?? throw new RuntimeException('no such application');
        $next = $current->revision + 1;

        $this->rows[$id] = new Application(
            $id,
            $current->slug,
            $current->name,
            $current->callbackUri,
            $current->allowLoopback,
            $current->acceptFellowshipSessions,
            $current->enabled,
            $next,
            $current->createdAt,
            $current->updatedAt,
        );

        return $next;
    }

    public function remove(int $id): bool
    {
        $existed = isset($this->rows[$id]);
        unset($this->rows[$id]);

        return $existed;
    }

    /** Test convenience: an application with the given switches. */
    public function add(
        string $slug,
        string $callbackUri = 'org.example.register.freedom://auth',
        bool $allowLoopback = false,
        bool $acceptFellowshipSessions = false,
        bool $enabled = true,
    ): Application {
        $created = $this->create($slug, ucfirst($slug), $callbackUri, $allowLoopback, $acceptFellowshipSessions, 1_700_000_000);
        if (!$enabled) {
            $this->update($created->id, $created->name, $callbackUri, $allowLoopback, $acceptFellowshipSessions, false, 1_700_000_000);
        }

        return $this->rows[$created->id];
    }
}
