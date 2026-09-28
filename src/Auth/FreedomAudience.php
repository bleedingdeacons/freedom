<?php

declare(strict_types=1);

namespace Freedom\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Fellowship\Auth\SignInAudience;
use Fellowship\Auth\VerifiedIdentity;
use Freedom\Applications\ApplicationRepository;
use Freedom\Applications\CallbackUri;
use Freedom\Logger\HasLogger;
use Freedom\Tablets\TabletGate;

/**
 * Freedom's rules for Fellowship's browser leg.
 *
 * Registered with Fellowship's `IdentityBroker` on `fellowship/loaded`.
 * When a sign-in Freedom started comes back through Fellowship's callback,
 * Fellowship asks this where the code may go — only the application's own
 * callback URI, or loopback if the application allows it — and whether
 * this Google account may have one: {@see TabletGate}, so a member or one
 * of the application's common accounts.
 *
 * A stranger is refused with `not_authorised` in the browser, where they
 * can read it, and again at the exchange, where the tablet row would be
 * written.
 */
final class FreedomAudience implements SignInAudience
{
    use HasLogger;

    public const NAME = 'freedom';

    public const REFUSED = 'not_authorised';

    protected static function logChannel(): string
    {
        return 'freedom';
    }

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly TabletGate $gate,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function allowsRedirect(string $redirectUri, string $context): bool
    {
        $decoded = SignInContext::decode($context);
        if ($decoded === null) {
            return false;
        }

        $application = $this->applications->findBySlug($decoded->application);

        return $application !== null
            && CallbackUri::allows($redirectUri, $application->callbackUri, $application->allowLoopback);
    }

    public function refusalFor(VerifiedIdentity $identity, string $context): ?string
    {
        $decoded = SignInContext::decode($context);
        $application = $decoded === null ? null : $this->applications->findBySlug($decoded->application);

        if ($application !== null && $this->gate->admit($application, $identity->email) !== null) {
            return null;
        }

        self::logInfo('Sign-in refused: the verified address may not use this application', [
            'application' => $decoded->application ?? '',
            'provider'    => $identity->provider,
        ]);

        return self::REFUSED;
    }
}
