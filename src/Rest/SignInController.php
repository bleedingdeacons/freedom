<?php

declare(strict_types=1);

namespace Freedom\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Fellowship\Auth\IdentityBroker;
use Fellowship\Core\RateLimiter;
use Fellowship\Crypto\DevicePublicKey;
use Freedom\Applications\Application;
use Freedom\Applications\ApplicationRepository;
use Freedom\Applications\CallbackUri;
use Freedom\Auth\FreedomAudience;
use Freedom\Auth\Pkce;
use Freedom\Auth\SignInContext;
use Freedom\Tablets\Admission;
use Freedom\Tablets\DeviceIdHasher;
use Freedom\Tablets\Enrolment;
use Freedom\Tablets\Tablet;
use Freedom\Tablets\TabletGate;
use Freedom\Tablets\TabletProfile;
use Freedom\Tablets\TabletTokenMinter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function register_rest_route;

/**
 * How a tablet gets a token: through the browser, or by handing over a
 * Link session it already holds.
 *
 * <b>Through the browser.</b> `start` checks the application, the callback
 * and the app's PKCE challenge and asks Fellowship's `IdentityBroker` to
 * begin a Google sign-in for the `freedom` audience. Google returns to
 * Fellowship's callback, which asks {@see FreedomAudience} where the code
 * may go and whether this account may have one, and sends the browser back
 * to the app's callback with a one-time code. `exchange` spends that code
 * with the verifier, and the tablet is enrolled.
 *
 * <b>From a Link session.</b> Link has already signed in through Google,
 * for Fellowship. A second trip through the browser would prove nothing
 * new and would ask a member to sign in twice, so `session` takes Link's
 * device token instead — for an application that has said it accepts
 * them.
 */
final class SignInController
{
    use RequiresSecureTransport;

    public const NAMESPACE = 'freedom/v1';

    /** Per-IP sign-in attempts per window. Higher than Fellowship's 30: a room of tablets enrols behind one address. */
    private const IP_MAX = 60;
    private const IP_WINDOW = 900;

    /** Hand-overs per Link device per window. */
    private const SESSION_MAX = 30;

    private const LABEL_MAX_BYTES = 200;
    private const MODEL_MAX_BYTES = 100;
    private const VERSION_MAX_BYTES = 32;
    private const PLATFORM_MAX_BYTES = 32;

    public function __construct(
        private readonly ApplicationRepository $applications,
        private readonly IdentityBroker $broker,
        private readonly TabletGate $gate,
        private readonly Enrolment $enrolment,
        private readonly DeviceIdHasher $hasher,
        private readonly TabletTokenMinter $minter,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/auth/start', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'start'],
            'permission_callback' => '__return_true',
            'args'                => [
                'application'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key'],
                // Validated whole against the application's callback, as
                // Fellowship does, rather than sanitised in parts.
                'redirect_uri'   => ['type' => 'string', 'required' => true],
                'code_challenge' => ['type' => 'string', 'required' => true],
                'provider'       => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/auth/exchange', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'exchange'],
            'permission_callback' => '__return_true',
            'args'                => $this->enrolmentArgs() + [
                'code'          => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'code_verifier' => ['type' => 'string', 'required' => true],
                'device_id'     => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/auth/session', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'session'],
            'permission_callback' => '__return_true',
            'args'                => $this->enrolmentArgs(),
        ]);
    }

    public function start(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($refused = $this->insecureTransport() ?? $this->ipLimited('start')) {
            return $refused;
        }

        $application = $this->application($request);
        if ($application instanceof WP_Error) {
            return $application;
        }

        $redirect = trim((string) $request->get_param('redirect_uri'));
        if (!CallbackUri::allows($redirect, $application->callbackUri, $application->allowLoopback)) {
            return new WP_Error('freedom_bad_redirect', 'That redirect target is not this application\'s.', ['status' => 400]);
        }

        $challenge = (string) $request->get_param('code_challenge');
        if (!Pkce::isChallenge($challenge)) {
            return new WP_Error('freedom_bad_challenge', 'Send an S256 PKCE code challenge.', ['status' => 400]);
        }

        $provider = (string) $request->get_param('provider');

        $begun = $this->broker->begin(
            $provider !== '' ? $provider : 'google',
            FreedomAudience::NAME,
            (new SignInContext($application->slug, $challenge))->encode(),
            $redirect,
        );

        if ($begun instanceof WP_Error) {
            return $begun;
        }

        return new WP_REST_Response($begun, 200);
    }

    public function exchange(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($refused = $this->insecureTransport() ?? $this->ipLimited('exchange')) {
            return $refused;
        }

        $application = $this->application($request);
        if ($application instanceof WP_Error) {
            return $application;
        }

        // Spent before anything else is checked, so a code that fails a
        // later check cannot be tried again with that check put right.
        $brokered = $this->broker->redeem((string) $request->get_param('code'), FreedomAudience::NAME);
        $context = $brokered === null ? null : SignInContext::decode($brokered->context);

        if ($brokered === null || $context === null || !hash_equals($context->application, $application->slug)) {
            return new WP_Error('freedom_bad_code', 'That sign-in code has expired or has already been used.', ['status' => 400]);
        }

        if (!Pkce::verifies((string) $request->get_param('code_verifier'), $context->challenge)) {
            return new WP_Error('freedom_bad_verifier', 'That sign-in was not started by this app.', ['status' => 400]);
        }

        $admission = $this->gate->admit($application, $brokered->identity->email);
        if ($admission === null) {
            return $this->notAuthorised();
        }

        $deviceId = trim((string) $request->get_param('device_id'));
        if (!$this->hasher->isValid($deviceId)) {
            return new WP_Error('freedom_bad_device', 'The device identifier is missing or malformed.', ['status' => 400]);
        }

        return $this->enrol(
            $request,
            $application,
            $this->hasher->hash($application->slug, $deviceId),
            $admission,
            0,
            Enrolment::VIA_BROWSER,
        );
    }

    public function session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($refused = $this->insecureTransport() ?? $this->ipLimited('session')) {
            return $refused;
        }

        $session = $this->broker->sessionFor($this->minter->bearerFrom((string) $request->get_header('authorization')));
        if ($session === null) {
            return new WP_Error('freedom_unauthenticated', 'That Link session is not signed in.', ['status' => 401]);
        }

        if ($this->rateLimiter->overLimit('freedom_session_' . $session->deviceId, self::SESSION_MAX, self::IP_WINDOW)) {
            return $this->rateLimitedError();
        }

        $application = $this->application($request);
        if ($application instanceof WP_Error) {
            return $application;
        }

        if (!$application->acceptFellowshipSessions) {
            return new WP_Error(
                'freedom_sessions_not_accepted',
                'This application does not accept a Link session. Sign in through the browser.',
                ['status' => 403],
            );
        }

        $admission = $this->gate->admit($application, $session->email);
        if ($admission === null) {
            return $this->notAuthorised();
        }

        return $this->enrol(
            $request,
            $application,
            $this->hasher->forFellowshipDevice($application->slug, $session->deviceId),
            $admission,
            $session->deviceId,
            Enrolment::VIA_LINK,
        );
    }

    private function enrol(
        WP_REST_Request $request,
        Application $application,
        string $deviceHash,
        Admission $admission,
        int $fellowshipDeviceId,
        string $via,
    ): WP_REST_Response|WP_Error {
        $publicKey = DevicePublicKey::normalise((string) $request->get_param('public_key'));
        if ($publicKey === '') {
            // Refused before the row is written: a tablet whose key will
            // not load could be sent no secret at all, and would look
            // perfectly healthy while receiving none.
            return new WP_Error(
                'freedom_bad_public_key',
                'The public key is missing, unreadable, or shorter than ' . DevicePublicKey::MIN_BITS . ' bits.',
                ['status' => 400],
            );
        }

        $profile = new TabletProfile(
            $this->cap((string) $request->get_param('label'), self::LABEL_MAX_BYTES),
            $this->cap(sanitize_key((string) $request->get_param('platform')), self::PLATFORM_MAX_BYTES),
            $this->cap((string) $request->get_param('model'), self::MODEL_MAX_BYTES),
            $this->cap((string) $request->get_param('app_version'), self::VERSION_MAX_BYTES),
            $publicKey,
        );

        $enrolled = $this->enrolment->enrol($application, $deviceHash, $admission, $fellowshipDeviceId, $profile, $via);
        if ($enrolled instanceof WP_Error) {
            return $enrolled;
        }

        return new WP_REST_Response([
            'token'       => $enrolled['token'],
            'tablet'      => $this->describe($enrolled['tablet'], !$enrolled['created']),
            'application' => ['slug' => $application->slug, 'name' => $application->name],
        ], $enrolled['created'] ? 201 : 200);
    }

    private function application(WP_REST_Request $request): Application|WP_Error
    {
        $slug = (string) $request->get_param('application');
        $application = Application::isValidSlug($slug) ? $this->applications->findBySlug($slug) : null;

        if ($application === null) {
            return new WP_Error('freedom_unknown_application', 'Unknown application.', ['status' => 404]);
        }

        if (!$application->enabled) {
            return new WP_Error('freedom_application_disabled', 'This application is not currently available.', ['status' => 403]);
        }

        return $application;
    }

    private function ipLimited(string $bucket): ?WP_Error
    {
        $key = 'freedom_' . $bucket . '_' . $this->rateLimiter->clientIp();

        return $this->rateLimiter->overLimit($key, self::IP_MAX, self::IP_WINDOW) ? $this->rateLimitedError() : null;
    }

    private function rateLimitedError(): WP_Error
    {
        return new WP_Error(
            'freedom_rate_limited',
            'Too many sign-in attempts. Please wait a few minutes and try again.',
            ['status' => 429],
        );
    }

    private function notAuthorised(): WP_Error
    {
        return new WP_Error(
            'freedom_not_authorised',
            'This account may not use this application.',
            ['status' => 403],
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function enrolmentArgs(): array
    {
        return [
            'application' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key'],
            // Validated by DevicePublicKey, not sanitised as text.
            'public_key'  => ['type' => 'string', 'required' => true],
            'label'       => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field'],
            'platform'    => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key'],
            'model'       => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field'],
            'app_version' => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field'],
        ];
    }

    private function cap(string $value, int $maxBytes): string
    {
        $value = trim($value);

        return strlen($value) <= $maxBytes ? $value : substr($value, 0, $maxBytes);
    }

    /** @return array<string, mixed> */
    private function describe(Tablet $tablet, bool $reattached): array
    {
        return [
            'id'         => $tablet->id,
            'label'      => $tablet->label,
            'created_at' => $tablet->createdAt,
            'reattached' => $reattached,
        ];
    }
}
