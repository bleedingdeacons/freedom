<?php

declare(strict_types=1);

namespace Freedom\Tests\Support;

use Freedom\Tablets\Admission;
use Freedom\Tablets\Tablet;
use Freedom\Tablets\TabletProfile;
use Freedom\Tablets\TabletRepository;

/**
 * Tablets in an array, keeping the one rule the real table enforces in
 * its lookup: a revoked token is never found.
 */
final class InMemoryTabletRepository implements TabletRepository
{
    /** @var array<int, array{tablet: Tablet, token: string}> */
    public array $rows = [];

    private int $nextId = 1;

    public function create(int $applicationId, string $deviceHash, string $tokenHash, Admission $admission, int $fellowshipDeviceId, TabletProfile $profile, int $now): Tablet
    {
        $tablet = new Tablet(
            $this->nextId++,
            $applicationId,
            $deviceHash,
            $admission->kind,
            $admission->email,
            $admission->memberId,
            $admission->accountId,
            $fellowshipDeviceId,
            $profile->label,
            $profile->platform,
            $profile->model,
            $profile->appVersion,
            $profile->publicKey,
            null,
            $now,
            null,
            $now,
            0,
            null,
            null,
        );

        $this->rows[$tablet->id] = ['tablet' => $tablet, 'token' => $tokenHash];

        return $tablet;
    }

    public function reattach(int $id, string $tokenHash, Admission $admission, int $fellowshipDeviceId, TabletProfile $profile, int $now): bool
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null || $row['tablet']->isBlocked()) {
            return false;
        }

        $this->replace($id, [
            'accountKind'        => $admission->kind,
            'accountEmail'       => $admission->email,
            'memberId'           => $admission->memberId,
            'accountId'          => $admission->accountId,
            'fellowshipDeviceId' => $fellowshipDeviceId,
            'label'              => $profile->label,
            'platform'           => $profile->platform,
            'model'              => $profile->model,
            'appVersion'         => $profile->appVersion,
            'publicKey'          => $profile->publicKey,
            'keyFaultAt'         => null,
            'revokedAt'          => null,
            'reattachedAt'       => $now,
            'lastSeenAt'         => $now,
        ]);
        $this->rows[$id]['token'] = $tokenHash;

        return true;
    }

    public function findByTokenHash(string $tokenHash): ?Tablet
    {
        foreach ($this->rows as $row) {
            if ($row['token'] === $tokenHash && !$row['tablet']->isRevoked()) {
                return $row['tablet'];
            }
        }

        return null;
    }

    public function findById(int $id): ?Tablet
    {
        return $this->rows[$id]['tablet'] ?? null;
    }

    public function findByDeviceHash(int $applicationId, string $deviceHash): ?Tablet
    {
        foreach ($this->rows as $row) {
            if ($row['tablet']->applicationId === $applicationId && $row['tablet']->deviceHash === $deviceHash) {
                return $row['tablet'];
            }
        }

        return null;
    }

    public function forApplication(int $applicationId): array
    {
        $found = [];
        foreach ($this->rows as $row) {
            if ($row['tablet']->applicationId === $applicationId) {
                $found[] = $row['tablet'];
            }
        }

        return $found;
    }

    public function countForApplication(int $applicationId): int
    {
        return count($this->forApplication($applicationId));
    }

    public function touch(int $id, int $now, bool $manifest): void
    {
        $this->replace($id, $manifest ? ['lastSeenAt' => $now, 'lastManifestAt' => $now] : ['lastSeenAt' => $now]);
    }

    public function markKeyFault(int $id, int $now): void
    {
        $this->replace($id, ['keyFaultAt' => $now]);
    }

    public function revoke(int $id, int $now): bool
    {
        $tablet = $this->findById($id);
        if ($tablet === null || $tablet->isRevoked()) {
            return false;
        }

        $this->replace($id, ['revokedAt' => $now]);

        return true;
    }

    public function block(int $id, int $now): bool
    {
        $tablet = $this->findById($id);
        if ($tablet === null) {
            return false;
        }

        $this->replace($id, ['blockedAt' => $now, 'revokedAt' => $tablet->revokedAt ?? $now]);

        return true;
    }

    public function unblock(int $id): bool
    {
        if ($this->findById($id) === null) {
            return false;
        }

        $this->replace($id, ['blockedAt' => null]);

        return true;
    }

    public function remove(int $id): bool
    {
        $existed = isset($this->rows[$id]);
        unset($this->rows[$id]);

        return $existed;
    }

    public function revokeAllForMember(string $email, int $now): int
    {
        $count = 0;
        foreach ($this->rows as $id => $row) {
            $tablet = $row['tablet'];
            if ($tablet->isMemberAccount() && $tablet->accountEmail === strtolower($email) && !$tablet->isRevoked()) {
                $this->replace($id, ['revokedAt' => $now]);
                $count++;
            }
        }

        return $count;
    }

    public function removeForApplication(int $applicationId): int
    {
        $before = count($this->rows);
        $this->rows = array_filter($this->rows, fn(array $row): bool => $row['tablet']->applicationId !== $applicationId);

        return $before - count($this->rows);
    }

    /** @param array<string, mixed> $changes */
    private function replace(int $id, array $changes): void
    {
        $current = $this->rows[$id]['tablet'] ?? null;
        if ($current === null) {
            return;
        }

        $fields = get_object_vars($current);
        foreach ($changes as $name => $value) {
            $fields[$name] = $value;
        }

        $this->rows[$id]['tablet'] = new Tablet(...array_values($fields));
    }
}
