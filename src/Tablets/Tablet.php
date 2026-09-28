<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One physical device, enrolled for one application.
 *
 * <b>Identified by `device_hash`, not by its row id or its token.</b>
 * The hash is of the application slug and the device's own identifier —
 * `ANDROID_ID` for a tablet signed in through the browser,
 * `fellowship:<device id>` for one handed over from a Link session — so
 * the same device signing in again, after a reinstall say, finds the same
 * row and keeps the configuration overrides an admin gave it. That is
 * re-attachment; see {@see \Freedom\Tablets\Enrolment}.
 *
 * Revoked, blocked and removed are different. Revoked kills the token and
 * keeps the row, and the device may sign in again. Blocked also refuses
 * that. Removed deletes the row and its overrides.
 */
final class Tablet
{
    public function __construct(
        public readonly int $id,
        public readonly int $applicationId,
        public readonly string $deviceHash,
        public readonly string $accountKind,
        public readonly string $accountEmail,
        public readonly int $memberId,
        public readonly int $accountId,
        public readonly int $fellowshipDeviceId,
        public readonly string $label,
        public readonly string $platform,
        public readonly string $model,
        public readonly string $appVersion,
        public readonly string $publicKey,
        public readonly ?int $keyFaultAt,
        public readonly int $createdAt,
        public readonly ?int $reattachedAt,
        public readonly int $lastSeenAt,
        public readonly int $lastManifestAt,
        public readonly ?int $revokedAt,
        public readonly ?int $blockedAt,
    ) {
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isBlocked(): bool
    {
        return $this->blockedAt !== null;
    }

    public function hasKeyFault(): bool
    {
        return $this->keyFaultAt !== null;
    }

    public function isMemberAccount(): bool
    {
        return $this->accountKind === Admission::MEMBER;
    }

    /** Enrolled by handing over a Link session rather than through the browser. */
    public function isLinkSession(): bool
    {
        return $this->fellowshipDeviceId > 0;
    }
}
