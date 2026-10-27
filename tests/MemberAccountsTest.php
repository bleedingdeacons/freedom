<?php

declare(strict_types=1);

namespace Freedom\Tests;

use function Brain\Monkey\Functions\when;
use Freedom\Tablets\TabletGate;
use Freedom\Tests\Support\FreedomWorld;
use WP_REST_Response;

/**
 * Whether a member's own Google account may sign a tablet in.
 *
 * A tablet receives every value its application holds, secrets included,
 * and a member can sign in from any device of their own. So an application
 * admits members only when an admin has said so, and its common accounts
 * always. The switch is asked at every moment the gate is, so turning it
 * off reaches tablets members have already signed in.
 */

covers(TabletGate::class);

beforeEach(function () {
    when('is_ssl')->justReturn(true);
    when('rest_url')->alias(static fn(string $path = ''): string => 'https://aa-bristol.org/wp-json/' . ltrim($path, '/'));

    $this->world = new FreedomWorld();
});

afterEach(function () {
    unset($_SERVER['REMOTE_ADDR']);
});

test('a new application does not admit members', function () {
    $app = $this->world->applications->add('fresh');

    expect($app->allowMemberAccounts)->toBeFalse();
});

test('a member cannot sign a tablet in where members are not allowed', function () {
    $w = $this->world;
    $w->applications->allowMembers($w->app->id, false);

    $response = $w->signInThroughBrowser(FreedomWorld::MEMBER);

    expect($response->get_error_code())->toBe('freedom_not_authorised');
    expect($w->tablets->forApplication($w->app->id))->toBe([]);
});

test('a common account still signs a tablet in where members are not allowed', function () {
    $w = $this->world;
    $w->applications->allowMembers($w->app->id, false);

    $response = $w->signInThroughBrowser(FreedomWorld::TABLET_ACCOUNT, 'android-common');

    expect($response)->toBeInstanceOf(WP_REST_Response::class);
    expect($response->get_status())->toBe(201);
});

test('a member signs a tablet in where members are allowed', function () {
    $w = $this->world;

    $response = $w->signInThroughBrowser(FreedomWorld::MEMBER);

    expect($response)->toBeInstanceOf(WP_REST_Response::class);
    expect($response->get_status())->toBe(201);
});

test('turning members off cuts off a member\'s tablet from its next request', function () {
    $w = $this->world;
    $token = $w->enrolledToken(FreedomWorld::MEMBER);
    expect($w->config->manifest($w->request([], $token)))->toBeInstanceOf(WP_REST_Response::class);

    $w->applications->allowMembers($w->app->id, false);

    expect($w->config->manifest($w->request([], $token))->get_error_code())->toBe('freedom_not_authorised');
    expect($w->config->values($w->request(['keys' => ['smtp.password']], $token))->get_error_code())->toBe('freedom_not_authorised');
});

test('turning members off leaves a common account\'s tablet alone', function () {
    $w = $this->world;
    $token = $w->enrolledToken(FreedomWorld::TABLET_ACCOUNT, 'android-common');

    $w->applications->allowMembers($w->app->id, false);

    expect($w->config->manifest($w->request([], $token)))->toBeInstanceOf(WP_REST_Response::class);
});

test('a Link session is a member\'s, and is refused where members are not allowed', function () {
    $w = $this->world;
    $w->applications->add('link', '', acceptFellowshipSessions: true, allowMemberAccounts: false);

    $response = $w->signIn->session($w->request(['application' => 'link', 'public_key' => FreedomWorld::keypair()[1]], $w->linkToken()));

    expect($response->get_error_code())->toBe('freedom_not_authorised');
});

test('a member whose address is also a common account comes in as the common account', function () {
    $w = $this->world;
    $w->accounts->add($w->app->id, FreedomWorld::MEMBER, 'Shared with a member', 1, 1_700_000_000);
    $w->applications->allowMembers($w->app->id, false);

    $response = $w->signInThroughBrowser(FreedomWorld::MEMBER);

    expect($response)->toBeInstanceOf(WP_REST_Response::class);
    $tablet = $w->tablets->findById((int) ((array) $response->get_data())['tablet']['id']);
    expect($tablet->accountKind)->toBe('common');
});

test('the browser is told before the code is issued, not only at the exchange', function () {
    $w = $this->world;
    $w->applications->allowMembers($w->app->id, false);

    expect($w->gate->admit($w->app(), FreedomWorld::MEMBER))->toBeNull();
    expect($w->gate->admit($w->app(), FreedomWorld::TABLET_ACCOUNT))->not->toBeNull();
});
