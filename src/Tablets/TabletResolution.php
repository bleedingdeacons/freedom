<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

use Freedom\Applications\Application;
use WP_Error;

/**
 * The tablet behind a request, or why there is none.
 *
 * <b>Which refusals are told apart, and why those.</b> Fellowship keeps
 * every unauthenticated refusal identical so a caller who has proved
 * nothing learns nothing, and that holds here: no token, a malformed one,
 * an unknown one and a revoked one are all 401. The rest are only
 * reachable by a caller holding a live token this site issued, and each
 * asks the app to do something different — which is the whole point of
 * saying which:
 *
 * - `not_authorised`: the account is no longer admitted. The app clears
 *   its configuration.
 * - `tablet_blocked`: an admin blocked this tablet. The same.
 * - `application_disabled`: the application is stood down. The app
 *   *keeps* its configuration and tries again next start.
 */
final class TabletResolution
{
    public const UNAUTHENTICATED = 'unauthenticated';
    public const NOT_AUTHORISED = 'not_authorised';
    public const BLOCKED = 'tablet_blocked';
    public const DISABLED = 'application_disabled';

    private function __construct(
        public readonly ?Tablet $tablet,
        public readonly ?Application $application,
        public readonly string $refusal,
    ) {
    }

    public static function found(Tablet $tablet, Application $application): self
    {
        return new self($tablet, $application, '');
    }

    public static function refused(string $reason): self
    {
        return new self(null, null, $reason);
    }

    public function error(): WP_Error
    {
        return match ($this->refusal) {
            self::NOT_AUTHORISED => new WP_Error(
                'freedom_not_authorised',
                'This account may no longer use this application.',
                ['status' => 403],
            ),
            self::BLOCKED => new WP_Error('freedom_tablet_blocked', 'This tablet has been blocked.', ['status' => 403]),
            self::DISABLED => new WP_Error(
                'freedom_application_disabled',
                'This application is not currently available.',
                ['status' => 403],
            ),
            default => new WP_Error('freedom_unauthenticated', 'This tablet is not signed in.', ['status' => 401]),
        };
    }
}
