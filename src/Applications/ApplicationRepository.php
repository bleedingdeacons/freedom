<?php

declare(strict_types=1);

namespace Freedom\Applications;

if (!defined('ABSPATH')) {
    exit;
}

interface ApplicationRepository
{
    /** @throws \RuntimeException when the row cannot be written, including a slug already taken. */
    public function create(
        string $slug,
        string $name,
        string $callbackUri,
        bool $allowLoopback,
        bool $acceptFellowshipSessions,
        int $now,
    ): Application;

    public function update(
        int $id,
        string $name,
        string $callbackUri,
        bool $allowLoopback,
        bool $acceptFellowshipSessions,
        bool $enabled,
        int $now,
    ): bool;

    /**
     * Set the application's own Google client: its id ('' to go back to
     * Fellowship's), and its secret as ciphertext. A null secret keeps the
     * stored one, so a settings form need never show it to save the id.
     */
    public function setGoogleClient(int $id, string $clientId, ?string $encryptedSecret, int $now): bool;

    public function findById(int $id): ?Application;

    public function findBySlug(string $slug): ?Application;

    /** @return list<Application> */
    public function all(): array;

    /**
     * Take the next revision number for this application, atomically.
     *
     * Two admins saving at once must get two different numbers, so this
     * is an increment in the database rather than a read and a write.
     *
     * @throws \RuntimeException when the counter cannot be advanced. A
     *     value written without a fresh version would be one no tablet
     *     ever notices had changed.
     */
    public function nextRevision(int $id): int;

    /** Delete the application row. Its values, accounts and tablets are the caller's to remove first. */
    public function remove(int $id): bool;
}
