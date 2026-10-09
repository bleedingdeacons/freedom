<?php

declare(strict_types=1);

namespace Freedom\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Fellowship\Auth\BringsOwnClient;
use Fellowship\Auth\ProviderClient;
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
 *
 * <b>An application may bring its own Google client</b> ({@see clientFor()}),
 * so its tablets see its own consent screen and its own Google Cloud
 * project rather than Link's. Without one, Fellowship's client is used.
 */
final class FreedomAudience implements SignInAudience, BringsOwnClient
{
    use HasLogger;

    public const NAME = 'freedom';

    public const REFUSED = 'not_authorised';

    /** Guardian's name for Google, the one provider an application can bring a client for. */
    private const GOOGLE = 'google';

    protected static function logChannel(): string
    {
        return 'freedom';
    }

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly TabletGate $gate,
        private readonly ClientSecrets $secrets,
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

    /**
     * The application's own Google client, or null for Fellowship's.
     *
     * Asked by Fellowship when the sign-in starts and again at its callback,
     * with the same context, so both use the same client. A client whose
     * secret will not decrypt falls back to Fellowship's rather than
     * sending Google an id with no secret: the sign-in still works, and the
     * log says why it was not the application's own.
     */
    public function clientFor(string $provider, string $context): ?ProviderClient
    {
        if ($provider !== self::GOOGLE) {
            return null;
        }

        $decoded = SignInContext::decode($context);
        $application = $decoded === null ? null : $this->applications->findBySlug($decoded->application);
        if ($application === null || !$application->hasOwnGoogleClient()) {
            return null;
        }

        $secret = $this->secrets->decrypt($application->googleClientSecret);
        if ($secret === null) {
            self::logWarning('An application\'s own Google client has no readable secret; signing in with Fellowship\'s', [
                'application' => $application->slug,
            ]);

            return null;
        }

        return new ProviderClient($application->googleClientId, $secret);
    }
}
