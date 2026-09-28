<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Why a Google account was let in: it is a Unity member's, or it is one
 * of the application's common tablet accounts.
 *
 * Kept on the tablet row because it decides two later things: which
 * Scrutiny entity an audit line is written against, and whether the
 * tablet goes when a member is erased from Unity.
 */
final class Admission
{
    public const MEMBER = 'member';
    public const COMMON = 'common';

    private function __construct(
        public readonly string $kind,
        public readonly string $email,
        public readonly int $memberId,
        public readonly int $accountId,
    ) {
    }

    public static function member(string $email, int $memberId): self
    {
        return new self(self::MEMBER, $email, $memberId, 0);
    }

    public static function common(string $email, int $accountId): self
    {
        return new self(self::COMMON, $email, 0, $accountId);
    }

    public function isMember(): bool
    {
        return $this->kind === self::MEMBER;
    }
}
