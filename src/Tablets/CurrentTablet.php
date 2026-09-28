<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

use Fellowship\Auth\IdentityBroker;
use Freedom\Applications\ApplicationRepository;
use WP_REST_Request;

/**
 * Resolves the tablet behind an authenticated request.
 *
 * <b>The token is the start of the check, not the end of it.</b> A live
 * token proves this site enrolled the tablet. Whether its account may
 * still use the application is re-decided by {@see TabletGate} on every
 * request, and a tablet handed over from a Link session is also asked of
 * Fellowship whether that Link enrolment is still live — so signing out
 * of Link, being revoked there, or being removed from Unity stops the
 * configuration at the next request rather than whenever somebody
 * remembers the tablet.
 */
final class CurrentTablet
{
    /** As Fellowship's CurrentDevice: last-seen is worth a write every five minutes, not every poll. */
    private const TOUCH_INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly TabletRepository $tablets,
        private readonly ApplicationRepository $applications,
        private readonly TabletTokenMinter $minter,
        private readonly TabletGate $gate,
        private readonly IdentityBroker $broker,
    ) {
    }

    /** @param bool $manifest Whether this request is a manifest poll, which the admin list reports separately. */
    public function resolve(WP_REST_Request $request, bool $manifest = false): TabletResolution
    {
        $token = $this->minter->bearerFrom((string) $request->get_header('authorization'));
        if ($token === '' || !$this->minter->looksLikeToken($token)) {
            return TabletResolution::refused(TabletResolution::UNAUTHENTICATED);
        }

        $tablet = $this->tablets->findByTokenHash($this->minter->hash($token));
        if ($tablet === null) {
            return TabletResolution::refused(TabletResolution::UNAUTHENTICATED);
        }

        if ($tablet->isBlocked()) {
            return TabletResolution::refused(TabletResolution::BLOCKED);
        }

        $application = $this->applications->findById($tablet->applicationId);
        if ($application === null) {
            return TabletResolution::refused(TabletResolution::UNAUTHENTICATED);
        }

        if (!$application->enabled) {
            return TabletResolution::refused(TabletResolution::DISABLED);
        }

        // A Link session ends when Link's does. Answered as a 401, like a
        // revoked token: the tablet signs in again (the app hands the new
        // Link session over), rather than being told it is not allowed.
        if ($tablet->isLinkSession() && !$this->broker->isLive($tablet->fellowshipDeviceId)) {
            return TabletResolution::refused(TabletResolution::UNAUTHENTICATED);
        }

        if ($this->gate->admit($application, $tablet->accountEmail) === null) {
            return TabletResolution::refused(TabletResolution::NOT_AUTHORISED);
        }

        $now = time();
        if ($manifest || $now - $tablet->lastSeenAt >= self::TOUCH_INTERVAL_SECONDS) {
            $this->tablets->touch($tablet->id, $now, $manifest);
        }

        return TabletResolution::found($tablet, $application);
    }
}
