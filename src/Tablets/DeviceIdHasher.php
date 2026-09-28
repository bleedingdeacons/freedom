<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns a device's own identifier into the key its tablet row is found by.
 *
 * <b>Hashed, keyed and scoped to the application.</b> `ANDROID_ID` is a
 * stable hardware-ish identifier and has no business sitting in a table
 * in the clear; keyed with the site salt it cannot be matched against
 * another site's table either; and with the slug folded in, the same
 * device enrolled for two applications is two unrelated rows.
 *
 * <b>It is not proof of anything.</b> The app reports it and nothing can
 * check it. The Google account is the proof; this only decides *which*
 * tablet row an admitted sign-in lands on. See the README on what that
 * means for a re-attachment.
 */
final class DeviceIdHasher
{
    /** Link sessions are keyed on the Fellowship device, under this prefix. */
    public const FELLOWSHIP_PREFIX = 'fellowship:';

    private const PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    public function isValid(string $deviceId): bool
    {
        return preg_match(self::PATTERN, $deviceId) === 1
            // The prefix is reserved for sessions handed over from Link, so
            // a browser sign-in cannot claim a Link device's row.
            && !str_starts_with($deviceId, self::FELLOWSHIP_PREFIX);
    }

    public function hash(string $applicationSlug, string $deviceId): string
    {
        return hash_hmac('sha256', $applicationSlug . '|' . $deviceId, $this->key());
    }

    public function forFellowshipDevice(string $applicationSlug, int $fellowshipDeviceId): string
    {
        return $this->hash($applicationSlug, self::FELLOWSHIP_PREFIX . $fellowshipDeviceId);
    }

    private function key(): string
    {
        return hash('sha256', wp_salt('auth') . '|freedom-tablet-id', true);
    }
}
