<?php

declare(strict_types=1);

namespace Freedom\Tests;

use Freedom\Applications\Application;
use Freedom\Applications\CallbackUri;
use Freedom\Auth\Pkce;
use Freedom\Auth\SignInContext;
use Freedom\Tablets\DeviceIdHasher;
use Freedom\Tablets\TabletTokenMinter;

/**
 * The small allow-lists everything else trusts: which callbacks a code may
 * be sent to, what a PKCE challenge and verifier look like, and how a
 * device's identifier becomes the key its row is found by.
 */

covers(CallbackUri::class, Pkce::class, SignInContext::class, DeviceIdHasher::class, TabletTokenMinter::class, Application::class);

test('an application callback must be a custom scheme with a host and nothing else', function (string $uri, bool $ok) {
    expect(CallbackUri::isAcceptable($uri))->toBe($ok);
})->with([
    'reverse-DNS scheme'  => ['org.example.register.freedom://auth', true],
    'short custom scheme' => ['register://auth', true],
    'https'               => ['https://example.org/auth', false],
    'http'                => ['http://example.org/auth', false],
    'Link\'s own scheme'  => ['link://auth', false],
    'intent'              => ['intent://auth', false],
    'javascript'          => ['javascript://auth', false],
    'no host'             => ['register:auth', false],
    'a port'              => ['register://auth:99', false],
    'a query'             => ['register://auth?x=1', false],
    'a fragment'          => ['register://auth#x', false],
    'credentials'         => ['register://me@auth', false],
    'upper-case scheme'   => ['Register://auth', false],
    'empty'               => ['', false],
]);

test('a redirect must be the application\'s callback, or loopback when allowed', function (string $candidate, bool $loopback, bool $ok) {
    expect(CallbackUri::allows($candidate, 'org.example.app://auth', $loopback))->toBe($ok);
})->with([
    'its callback'                    => ['org.example.app://auth', false, true],
    'a near miss'                     => ['org.example.app://auth/', false, false],
    'loopback, allowed'               => ['http://127.0.0.1:53682/', true, true],
    'IPv6 loopback, allowed'          => ['http://[::1]:53682/', true, true],
    'loopback, not allowed'           => ['http://127.0.0.1:53682/', false, false],
    'a privileged port'               => ['http://127.0.0.1:80/', true, false],
    'loopback with a query'           => ['http://127.0.0.1:53682/?x=1', true, false],
    'https to loopback'               => ['https://127.0.0.1:53682/', true, false],
    'somewhere else, even if allowed' => ['http://10.0.0.1:53682/', true, false],
]);

test('PKCE is S256 and nothing else', function () {
    $verifier = str_repeat('a', 43);
    $challenge = Pkce::challengeFor($verifier);

    expect(Pkce::isChallenge($challenge))->toBeTrue();
    expect(Pkce::verifies($verifier, $challenge))->toBeTrue();
    expect(Pkce::verifies(str_repeat('b', 43), $challenge))->toBeFalse();
    // "plain" PKCE — the verifier sent as its own challenge — is refused.
    expect(Pkce::verifies($verifier, $verifier))->toBeFalse();
    expect(Pkce::verifies('short', Pkce::challengeFor('short')))->toBeFalse();
});

test('the RFC 7636 example verifier produces the RFC\'s challenge', function () {
    // Appendix B, so the C# side and this one agree on the encoding.
    expect(Pkce::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

test('the sign-in context survives a round trip and refuses anything else', function () {
    $context = new SignInContext('register', 'abc');

    expect(SignInContext::decode($context->encode()))->toEqual($context);
    expect(SignInContext::decode('not json'))->toBeNull();
    expect(SignInContext::decode('{"app":1}'))->toBeNull();
});

test('a device hash is keyed, and scoped to the application', function () {
    $hasher = new DeviceIdHasher();

    $register = $hasher->hash('register', 'android-0001');

    expect($register)->toMatch('/^[0-9a-f]{64}$/');
    expect($register)->not->toBe(hash('sha256', 'register|android-0001'));
    expect($hasher->hash('link', 'android-0001'))->not->toBe($register);
    expect($hasher->forFellowshipDevice('link', 12))->toBe($hasher->hash('link', 'fellowship:12'));
});

test('a device identifier is refused outside its shape', function (string $id, bool $ok) {
    expect((new DeviceIdHasher())->isValid($id))->toBe($ok);
})->with([
    'an ANDROID_ID'              => ['9774d56d682e549c', true],
    'too short'                  => ['abc', false],
    'a space'                    => ['android 0001', false],
    'Link\'s reserved prefix'    => ['fellowship:12345678', false],
    'too long'                   => [str_repeat('a', 129), false],
]);

test('tablet tokens are prefixed, recognised and never stored as sent', function () {
    $minter = new TabletTokenMinter();
    $token = $minter->mint();

    expect($token)->toStartWith('frt_');
    expect($minter->looksLikeToken($token))->toBeTrue();
    expect($minter->looksLikeToken('fdt_' . substr($token, 4)))->toBeFalse();
    expect($minter->hash($token))->not->toContain(substr($token, 4));
    expect($minter->bearerFrom('Bearer ' . $token))->toBe($token);
    expect($minter->bearerFrom('Basic abc'))->toBe('');
});

test('an application slug is lower case, digits and hyphens', function (string $slug, bool $ok) {
    expect(Application::isValidSlug($slug))->toBe($ok);
})->with([
    ['register', true],
    ['link-2', true],
    ['Register', false],
    ['-leading', false],
    ['with space', false],
    ['', false],
]);
