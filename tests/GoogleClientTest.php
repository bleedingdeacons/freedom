<?php

declare(strict_types=1);

namespace Freedom\Tests;

use function Brain\Monkey\Functions\when;
use Freedom\Auth\ClientSecrets;
use Freedom\Auth\FreedomAudience;
use Freedom\Auth\Pkce;
use Freedom\Auth\SignInContext;
use Freedom\Tests\Support\FreedomWorld;
use WP_REST_Response;

/**
 * An application signing its tablets in with its own Google client.
 *
 * Register's tablets were shown Link's consent screen, because Fellowship
 * had one Google client for everything. An application can now carry its
 * own; Fellowship asks Freedom for it at the start and again at the
 * callback. The broker here is Fellowship's own, so a pass means
 * Fellowship would really build the provider around this client.
 */

covers(FreedomAudience::class, ClientSecrets::class);

const GOOGLE_CLIENT_ID = 'register-tablets.apps.googleusercontent.com';

beforeEach(function () {
    when('is_ssl')->justReturn(true);
    when('rest_url')->alias(static fn(string $path = ''): string => 'https://aa-bristol.org/wp-json/' . ltrim($path, '/'));

    $this->world = new FreedomWorld();
});

test('an application with its own client starts its sign-in with that client', function () {
    $w = $this->world;
    $w->applications->setGoogleClient($w->app->id, GOOGLE_CLIENT_ID, $w->secrets->encrypt('register-secret'), 1);

    $response = googleClientStart($w);

    expect($response)->toBeInstanceOf(WP_REST_Response::class);
    expect($w->ownClientsBuilt)->toBe([GOOGLE_CLIENT_ID]);
});

test('the client handed to Fellowship carries the decrypted secret', function () {
    $w = $this->world;
    $w->applications->setGoogleClient($w->app->id, GOOGLE_CLIENT_ID, $w->secrets->encrypt('register-secret'), 1);

    $client = new FreedomAudience($w->applications, $w->gate, $w->secrets)
        ->clientFor('google', googleClientContext($w));

    expect($client?->clientId)->toBe(GOOGLE_CLIENT_ID);
    expect($client?->clientSecret)->toBe('register-secret');
});

test('an application without its own client signs in with Fellowship\'s', function () {
    $w = $this->world;

    expect(googleClientStart($w))->toBeInstanceOf(WP_REST_Response::class);
    expect($w->ownClientsBuilt)->toBe([]);
});

test('a secret that will not decrypt falls back to Fellowship\'s client', function () {
    // An id sent to Google with no secret would fail at the exchange, after
    // the tablet's user had already chosen an account. Fellowship's client
    // still works, so the sign-in does.
    $w = $this->world;
    $w->applications->setGoogleClient($w->app->id, GOOGLE_CLIENT_ID, 'not-ciphertext', 1);

    expect(googleClientStart($w))->toBeInstanceOf(WP_REST_Response::class);
    expect($w->ownClientsBuilt)->toBe([]);
});

test('only Google can be brought', function () {
    $w = $this->world;
    $w->applications->setGoogleClient($w->app->id, GOOGLE_CLIENT_ID, $w->secrets->encrypt('register-secret'), 1);

    expect(new FreedomAudience($w->applications, $w->gate, $w->secrets)->clientFor('microsoft', googleClientContext($w)))
        ->toBeNull();
});

test('a context that names no application brings no client', function () {
    $w = $this->world;

    expect(new FreedomAudience($w->applications, $w->gate, $w->secrets)->clientFor('google', 'not a context'))->toBeNull();
    expect(new FreedomAudience($w->applications, $w->gate, $w->secrets)->clientFor('google', (new SignInContext('nobody', 'x'))->encode()))
        ->toBeNull();
});

test('client secrets use their own key, not the configuration values\'', function () {
    $w = $this->world;
    $stored = $w->secrets->encrypt('register-secret');

    expect($w->secrets->decrypt($stored))->toBe('register-secret');
    expect($w->cipher->decrypt($stored))->toBeNull();
    expect($w->secrets->decrypt(null))->toBeNull();
    expect($w->secrets->decrypt(''))->toBeNull();
});

function googleClientContext(FreedomWorld $w): string
{
    return (new SignInContext($w->app->slug, Pkce::challengeFor(str_repeat('v', 64))))->encode();
}

function googleClientStart(FreedomWorld $w): mixed
{
    return $w->signIn->start($w->request([
        'application'    => $w->app->slug,
        'redirect_uri'   => FreedomWorld::CALLBACK,
        'code_challenge' => Pkce::challengeFor(str_repeat('v', 64)),
    ]));
}
