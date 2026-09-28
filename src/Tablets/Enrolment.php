<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

use Freedom\Applications\Application;
use Freedom\Logger\HasLogger;
use RuntimeException;
use WP_Error;

/**
 * Where a tablet comes into existence, or comes back — for both ways in.
 *
 * A browser sign-in and a Link session hand-off prove the same thing by
 * different routes, and everything after the proof is identical: the
 * block check, the cap, the token, the audit line. One copy, for the
 * reason Fellowship's `enrolVerified()` gives: a second would be where
 * one route quietly lost the cap or stopped auditing.
 *
 * <b>Re-attachment.</b> A device that signs in again — after a reinstall,
 * or because its token was revoked — finds its old row by device hash and
 * gets a new token, a new key and whatever account it signed in with this
 * time. The admin's overrides for it survive, which is what makes a
 * reinstall cost nothing. A blocked row refuses instead.
 */
final class Enrolment
{
    use HasLogger;

    /**
     * Tablets one application may have, live or not.
     *
     * Generous for an intergroup's tablets. It exists so a bug in an app's
     * retry loop — or a device identifier that changes every launch —
     * cannot quietly fill the table with rows, each of them a credential.
     */
    public const MAX_TABLETS_PER_APPLICATION = 100;

    public const VIA_BROWSER = 'browser';
    public const VIA_LINK = 'link session';

    protected static function logChannel(): string
    {
        return 'freedom';
    }

    public function __construct(
        private readonly TabletRepository $tablets,
        private readonly TabletTokenMinter $minter,
        private readonly TabletAudit $audit,
    ) {
    }

    /**
     * @return array{token: string, tablet: Tablet, created: bool}|WP_Error
     */
    public function enrol(
        Application $application,
        string $deviceHash,
        Admission $admission,
        int $fellowshipDeviceId,
        TabletProfile $profile,
        string $via,
    ): array|WP_Error {
        $existing = $this->tablets->findByDeviceHash($application->id, $deviceHash);

        if ($existing !== null && $existing->isBlocked()) {
            return new WP_Error('freedom_tablet_blocked', 'This tablet has been blocked.', ['status' => 403]);
        }

        $token = $this->minter->mint();
        $now = time();

        if ($existing !== null) {
            if (!$this->tablets->reattach($existing->id, $this->minter->hash($token), $admission, $fellowshipDeviceId, $profile, $now)) {
                // Includes a block landing between the read above and the
                // write: the UPDATE carries blocked_at IS NULL.
                return new WP_Error('freedom_enrol_failed', 'The tablet could not be signed in. Please try again.', ['status' => 500]);
            }

            $tablet = $this->tablets->findById($existing->id) ?? $existing;
            $this->audit->enrolled($tablet, $application->slug, true, $via);

            return ['token' => $token, 'tablet' => $tablet, 'created' => false];
        }

        if ($this->tablets->countForApplication($application->id) >= self::MAX_TABLETS_PER_APPLICATION) {
            self::logWarning('Enrolment refused: the application has reached its tablet cap', [
                'application' => $application->slug,
            ]);

            return new WP_Error(
                'freedom_too_many_tablets',
                'This application has as many tablets as it may have. Remove one first.',
                ['status' => 409],
            );
        }

        try {
            $tablet = $this->tablets->create(
                $application->id,
                $deviceHash,
                $this->minter->hash($token),
                $admission,
                $fellowshipDeviceId,
                $profile,
                $now,
            );
        } catch (RuntimeException $e) {
            self::logError('Enrolment failed: ' . $e->getMessage());

            return new WP_Error('freedom_enrol_failed', 'The tablet could not be signed in. Please try again.', ['status' => 500]);
        }

        $this->audit->enrolled($tablet, $application->slug, false, $via);

        return ['token' => $token, 'tablet' => $tablet, 'created' => true];
    }
}
