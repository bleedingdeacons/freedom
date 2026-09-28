<?php

declare(strict_types=1);

namespace Freedom\Tests;

use function Brain\Monkey\Functions\when;
use Fellowship\Auth\VerifiedIdentity;
use Freedom\Auth\FreedomAudience;
use Freedom\Auth\Pkce;
use Freedom\Auth\SignInContext;
use Freedom\Rest\SignInController;
use Freedom\Tablets\Enrolment;
use Freedom\Tablets\TabletAudit;
use Freedom\Tests\Support\FreedomWorld;
use Scrutiny\Audit\Interfaces\AuditLogger;
use WP_Error;
use WP_REST_Response;

/**
 * Both ways a tablet gets a token, and every way it is refused one.
 *
 * <b>The seam with Fellowship is exercised for real.</b> The broker, the
 * code store and the member gate here are Fellowship's own classes, so a
 * test that passes means Fellowship would actually issue, carry and
 * redeem what Freedom expects — not that a fake agreed with it.
 */

covers(SignInController::class, Enrolment::class, FreedomAudience::class, TabletAudit::class);

beforeEach(function () {
    when('is_ssl')->justReturn(true);
    when('rest_url')->alias(static fn(string $path = ''): string => 'https://aa-bristol.org/wp-json/' . ltrim($path, '/'));

    $this->world = new FreedomWorld();
});

afterEach(function () {
    unset($_SERVER['REMOTE_ADDR']);
});

// ── Start ─────────────────────────────────────────────────────────

test('start answers a Google URL for an application, its callback and a challenge', function () {
    $w = $this->world;

    $response = $w->signIn->start($w->request([
        'application'    => 'register',
        'redirect_uri'   => FreedomWorld::CALLBACK,
        'code_challenge' => Pkce::challengeFor(str_repeat('v', 64)),
    ]));

    expect($response)->toBeInstanceOf(WP_REST_Response::class);
    $data = (array) $response->get_data();
    expect($data['authorization_url'])->toContain('state=' . $data['state']);

    // Fellowship is carrying Freedom's context, for Freedom's audience.
    $stored = $w->states->consume($data['state']);
    expect($stored['audience'])->toBe('freedom');
    expect(SignInContext::decode($stored['context'])->application)->toBe('register');
});

test('start refuses what the application does not allow', function (array $params, string $code) {
    $w = $this->world;

    $response = $w->signIn->start($w->request($params + [
        'application'    => 'register',
        'redirect_uri'   => FreedomWorld::CALLBACK,
        'code_challenge' => Pkce::challengeFor(str_repeat('v', 64)),
    ]));

    expect($response)->toBeInstanceOf(WP_Error::class);
    expect($response->get_error_code())->toBe($code);
})->with([
    'an unknown application'        => [['application' => 'nobody'], 'freedom_unknown_application'],
    'another application\'s scheme' => [['redirect_uri' => 'org.other.app://auth'], 'freedom_bad_redirect'],
    'Link\'s own callback'          => [['redirect_uri' => 'link://auth'], 'freedom_bad_redirect'],
    'no challenge'                  => [['code_challenge' => ''], 'freedom_bad_challenge'],
    'a plain-text challenge'        => [['code_challenge' => 'not-a-hash'], 'freedom_bad_challenge'],
]);

test('start refuses a disabled application', function () {
    $w = $this->world;
    $w->applications->update($w->app->id, 'Register', FreedomWorld::CALLBACK, true, false, false, 1);

    $response = $w->signIn->start($w->request([
        'application'    => 'register',
        'redirect_uri'   => FreedomWorld::CALLBACK,
        'code_challenge' => Pkce::challengeFor(str_repeat('v', 64)),
    ]));

    expect($response->get_error_code())->toBe('freedom_application_disabled');
});

test('plain HTTP is refused', function () {
    when('is_ssl')->justReturn(false);
    $w = $this->world;

    expect($w->signIn->start($w->request(['application' => 'register']))->get_error_code())->toBe('freedom_insecure_transport');
    expect($w->signIn->exchange($w->request(['application' => 'register']))->get_error_code())->toBe('freedom_insecure_transport');
    expect($w->signIn->session($w->request(['application' => 'register']))->get_error_code())->toBe('freedom_insecure_transport');
});

test('sign-in attempts from one address are limited', function () {
    $w = $this->world;
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';

    for ($i = 0; $i < 60; $i++) {
        $w->signIn->exchange($w->request(['application' => 'register']));
    }

    expect($w->signIn->exchange($w->request(['application' => 'register']))->get_error_code())->toBe('freedom_rate_limited');
});

// ── Fellowship's callback, for Freedom ───────────────────────────

test('Fellowship\'s callback admits a common account for Freedom and refuses a stranger', function () {
    $w = $this->world;
    $audience = $w->audiences->get('freedom');
    $context = (new SignInContext('register', Pkce::challengeFor(str_repeat('v', 64))))->encode();

    expect($audience->refusalFor(new VerifiedIdentity(FreedomWorld::TABLET_ACCOUNT, 'google', 's'), $context))->toBeNull();
    expect($audience->refusalFor(new VerifiedIdentity(FreedomWorld::MEMBER, 'google', 's'), $context))->toBeNull();
    expect($audience->refusalFor(new VerifiedIdentity('stranger@example.org', 'google', 's'), $context))->toBe('not_authorised');
    expect($audience->allowsRedirect(FreedomWorld::CALLBACK, $context))->toBeTrue();
    expect($audience->allowsRedirect('link://auth', $context))->toBeFalse();
    expect($audience->allowsRedirect(FreedomWorld::CALLBACK, 'not json'))->toBeFalse();
});

// ── Exchange ──────────────────────────────────────────────────────

test('a member\'s browser sign-in enrols a tablet', function () {
    $w = $this->world;

    $response = $w->signInThroughBrowser();

    expect($response)->toBeInstanceOf(WP_REST_Response::class);
    expect($response->get_status())->toBe(201);
    $data = (array) $response->get_data();
    expect($data['token'])->toStartWith('frt_');
    expect($data['tablet']['reattached'])->toBeFalse();
    expect($data['application'])->toBe(['slug' => 'register', 'name' => 'Register']);

    $tablet = $w->tablets->findById((int) $data['tablet']['id']);
    expect($tablet->accountKind)->toBe('member');
    expect($tablet->memberId)->toBe(7);
    expect($tablet->model)->toBe('SM-X200');
    // Hashed, never stored as sent.
    expect($tablet->deviceHash)->not->toContain(FreedomWorld::DEVICE);
});

test('a common account enrols a tablet belonging to nobody\'s membership', function () {
    $w = $this->world;

    $response = $w->signInThroughBrowser(FreedomWorld::TABLET_ACCOUNT);

    expect($response->get_status())->toBe(201);
    $tablet = $w->tablets->findById((int) ((array) $response->get_data())['tablet']['id']);
    expect($tablet->accountKind)->toBe('common');
    expect($tablet->memberId)->toBe(0);
});

test('enrolment is audited against the member, or the tablet — never by address', function () {
    $w = $this->world;

    $w->signInThroughBrowser(FreedomWorld::MEMBER, 'device-member-1');
    $w->signInThroughBrowser(FreedomWorld::TABLET_ACCOUNT, 'device-common-1');

    $entries = $w->auditLogger->entries;
    expect($entries)->toHaveCount(2);
    expect($entries[0]['entityType'])->toBe(AuditLogger::ENTITY_MEMBER);
    expect($entries[0]['entityId'])->toBe(7);
    expect($entries[1]['entityType'])->toBe(TabletAudit::ENTITY_TABLET);
    expect(json_encode($entries))->not->toContain('@');
});

test('a stranger is refused at the exchange too', function () {
    $w = $this->world;

    $response = $w->signInThroughBrowser('stranger@example.org');

    expect($response->get_error_code())->toBe('freedom_not_authorised');
    expect($w->tablets->rows)->toBe([]);
});

test('the code is worthless without the verifier', function () {
    // The case PKCE exists for: another app on the tablet registered the
    // same scheme and caught the code on its way back.
    $w = $this->world;

    $response = $w->signInThroughBrowser(overrides: ['code_verifier' => str_repeat('x', 64)]);

    expect($response->get_error_code())->toBe('freedom_bad_verifier');
});

test('a code issued for one application cannot enrol a tablet for another', function () {
    $w = $this->world;
    $w->applications->add('other', 'org.example.other.freedom://auth');

    $response = $w->signInThroughBrowser(overrides: ['application' => 'other']);

    expect($response->get_error_code())->toBe('freedom_bad_code');
});

test('a code issued to Link cannot enrol a Freedom tablet', function () {
    $w = $this->world;
    $linkCode = $w->codes->issue(new VerifiedIdentity(FreedomWorld::MEMBER, 'google', 's'));

    $response = $w->signInThroughBrowser(overrides: ['code' => $linkCode]);

    expect($response->get_error_code())->toBe('freedom_bad_code');
});

test('a code is spent by its first use, successful or not', function () {
    $w = $this->world;
    $verifier = str_repeat('v', 64);
    $code = $w->codes->issue(
        new VerifiedIdentity(FreedomWorld::MEMBER, 'google', 's'),
        'freedom',
        (new SignInContext('register', Pkce::challengeFor($verifier)))->encode(),
    );

    $first = $w->signInThroughBrowser(overrides: ['code' => $code, 'code_verifier' => str_repeat('x', 64)]);
    $second = $w->signInThroughBrowser(overrides: ['code' => $code, 'code_verifier' => $verifier]);

    expect($first->get_error_code())->toBe('freedom_bad_verifier');
    expect($second->get_error_code())->toBe('freedom_bad_code');
});

test('a malformed device identifier or public key is refused before anything is written', function (array $overrides, string $code) {
    $w = $this->world;

    expect($w->signInThroughBrowser(overrides: $overrides)->get_error_code())->toBe($code);
    expect($w->tablets->rows)->toBe([]);
})->with([
    'no device id'              => [['device_id' => ''], 'freedom_bad_device'],
    'a short device id'         => [['device_id' => 'abc'], 'freedom_bad_device'],
    'Link\'s reserved prefix'   => [['device_id' => 'fellowship:12'], 'freedom_bad_device'],
    'an unreadable public key'  => [['public_key' => 'not-a-key'], 'freedom_bad_public_key'],
]);

// ── Re-attachment ─────────────────────────────────────────────────

test('signing in again re-attaches the same tablet, with a new token, and keeps its overrides', function () {
    $w = $this->world;
    $first = (array) $w->signInThroughBrowser()->get_data();
    $w->editor->set($w->app(), (int) $first['tablet']['id'], 'device.label', 'Front desk', false, 1);

    $again = $w->signInThroughBrowser(FreedomWorld::TABLET_ACCOUNT);

    expect($again->get_status())->toBe(200);
    $second = (array) $again->get_data();
    expect($second['tablet']['id'])->toBe($first['tablet']['id']);
    expect($second['tablet']['reattached'])->toBeTrue();
    expect($second['token'])->not->toBe($first['token']);
    expect($w->values->overrides($w->app->id, (int) $first['tablet']['id']))->toHaveCount(1);

    // The old token is dead; the account is the one it signed in with now.
    expect($w->tablets->findByTokenHash($w->minter->hash($first['token'])))->toBeNull();
    expect($w->tablets->findById((int) $first['tablet']['id'])->accountKind)->toBe('common');
});

test('a revoked tablet may sign in again, and a blocked one may not', function () {
    $w = $this->world;
    $id = (int) ((array) $w->signInThroughBrowser()->get_data())['tablet']['id'];

    $w->tablets->revoke($id, time());
    expect($w->signInThroughBrowser()->get_status())->toBe(200);

    $w->tablets->block($id, time());
    expect($w->signInThroughBrowser()->get_error_code())->toBe('freedom_tablet_blocked');
});

test('an application has a cap on tablets', function () {
    $w = $this->world;
    for ($i = 0; $i < Enrolment::MAX_TABLETS_PER_APPLICATION; $i++) {
        // A fresh address each time, or the per-IP sign-in limit — which is
        // lower than the cap — is what this would end up testing.
        $_SERVER['REMOTE_ADDR'] = '10.0.' . intdiv($i, 250) . '.' . ($i % 250 + 1);
        expect($w->signInThroughBrowser(deviceId: sprintf('device-%04d', $i))->get_status())->toBe(201);
    }

    expect($w->signInThroughBrowser(deviceId: 'device-over-the-cap')->get_error_code())->toBe('freedom_too_many_tablets');
    // A known device still re-attaches at the cap.
    expect($w->signInThroughBrowser(deviceId: 'device-0000')->get_status())->toBe(200);
});

// ── A Link session instead of a second sign-in ───────────────────

test('a Link session enrols a tablet with no browser at all', function () {
    $w = $this->world;
    $link = $w->applications->add('link', '', acceptFellowshipSessions: true);

    $response = $w->signIn->session($w->request([
        'application' => 'link',
        'public_key'  => FreedomWorld::keypair()[1],
        'platform'    => 'android',
    ], $w->linkToken()));

    expect($response)->toBeInstanceOf(WP_REST_Response::class);
    expect($response->get_status())->toBe(201);

    $tablet = $w->tablets->findById((int) ((array) $response->get_data())['tablet']['id']);
    expect($tablet->applicationId)->toBe($link->id);
    expect($tablet->fellowshipDeviceId)->toBeGreaterThan(0);
    expect($tablet->accountKind)->toBe('member');
    // Nothing went through the browser leg.
    expect($w->google->identity)->not->toBeNull();
});

test('the same Link device handing over again re-attaches', function () {
    $w = $this->world;
    $w->applications->add('link', '', acceptFellowshipSessions: true);
    $token = $w->linkToken();
    $params = ['application' => 'link', 'public_key' => FreedomWorld::keypair()[1]];

    $first = $w->signIn->session($w->request($params, $token));
    $second = $w->signIn->session($w->request($params, $token));

    expect($first->get_status())->toBe(201);
    expect($second->get_status())->toBe(200);
});

test('an application that has not opted in refuses a Link session', function () {
    $w = $this->world;

    $response = $w->signIn->session($w->request(['application' => 'register', 'public_key' => FreedomWorld::keypair()[1]], $w->linkToken()));

    expect($response->get_error_code())->toBe('freedom_sessions_not_accepted');
});

test('anything Link itself would refuse is refused', function (string $case) {
    $w = $this->world;
    $w->applications->add('link', '', acceptFellowshipSessions: true);
    $token = $w->linkToken();

    $presented = match ($case) {
        'no token'      => '',
        'a Freedom one' => 'frt_' . str_repeat('ab', 32),
        'an invented'   => 'fdt_' . str_repeat('ab', 32),
        default         => $token,
    };

    if ($case === 'a revoked one') {
        foreach ($w->linkDevices->findAllLive() as $device) {
            $w->linkDevices->revoke($device->id, time());
        }
    }

    $response = $w->signIn->session($w->request(['application' => 'link', 'public_key' => FreedomWorld::keypair()[1]], $presented));

    expect($response->get_error_code())->toBe('freedom_unauthenticated');
})->with(['no token', 'a Freedom one', 'an invented', 'a revoked one']);
