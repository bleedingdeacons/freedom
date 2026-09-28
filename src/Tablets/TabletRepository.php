<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

interface TabletRepository
{
    /** @throws \RuntimeException when the row cannot be written. */
    public function create(
        int $applicationId,
        string $deviceHash,
        string $tokenHash,
        Admission $admission,
        int $fellowshipDeviceId,
        TabletProfile $profile,
        int $now,
    ): Tablet;

    /**
     * Give an existing row a new token, key and account, and bring it back
     * from revoked. Overrides are keyed on the row id and are untouched.
     */
    public function reattach(
        int $id,
        string $tokenHash,
        Admission $admission,
        int $fellowshipDeviceId,
        TabletProfile $profile,
        int $now,
    ): bool;

    /** A live tablet — not revoked — by its token hash. */
    public function findByTokenHash(string $tokenHash): ?Tablet;

    public function findById(int $id): ?Tablet;

    public function findByDeviceHash(int $applicationId, string $deviceHash): ?Tablet;

    /** @return list<Tablet> */
    public function forApplication(int $applicationId): array;

    public function countForApplication(int $applicationId): int;

    public function touch(int $id, int $now, bool $manifest): void;

    public function markKeyFault(int $id, int $now): void;

    public function revoke(int $id, int $now): bool;

    public function block(int $id, int $now): bool;

    public function unblock(int $id): bool;

    public function remove(int $id): bool;

    /** Revoke every live tablet a member's own account enrolled. */
    public function revokeAllForMember(string $email, int $now): int;

    public function removeForApplication(int $applicationId): int;
}
