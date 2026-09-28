<?php

declare(strict_types=1);

namespace Freedom\Tests;

use function Brain\Monkey\Functions\when;
use Freedom\Config\ValueSealer;
use Freedom\Rest\ConfigController;
use Freedom\Rest\TabletController;
use Freedom\Tablets\CurrentTablet;
use Freedom\Tablets\TabletResolution;
use Freedom\Tests\Support\FreedomWorld;
use WP_Error;
use WP_REST_Response;

/**
 * What a signed-in tablet is told, on every start and after.
 *
 * <b>The refusals are the half that matters.</b> The library clears a
 * tablet's configuration on a refusal and keeps it on anything else, so
 * the difference between a 401, a 403 and a 500 here is the difference
 * between a tablet that stops working on purpose and one that stops
 * working because the database had a bad minute.
 */

covers(ConfigController::class, TabletController::class, CurrentTablet::class, TabletResolution::class, ValueSealer::class);

beforeEach(function () {
    when('is_ssl')->justReturn(true);
    when('rest_url')->alias(static fn(string $path = ''): string => 'https://aa-bristol.org/wp-json/' . ltrim($path, '/'));

    $this->world = new FreedomWorld();
    $w = $this->world;
    $w->editor->set($w->app(), 0, 'smtp.host', 'mail.example.org', false, 1);
    $w->editor->set($w->app(), 0, 'smtp.password', 'correct horse battery staple', true, 1);
    $this->token = $w->enrolledToken();
});

// ── The manifest ──────────────────────────────────────────────────

test('the manifest lists every key with its version, and an etag', function () {
    $w = $this->world;

    $response = $w->config->manifest($w->request([], $this->token));

    expect($response)->toBeInstanceOf(WP_REST_Response::class);
    $data = (array) $response->get_data();
    expect($data['application'])->toBe('register');
    expect($data['keys'])->toBe([
        ['key' => 'smtp.host', 'version' => 1, 'secret' => false],
        ['key' => 'smtp.password', 'version' => 2, 'secret' => true],
    ]);
    expect($response->get_headers()['ETag'])->toBe('"' . $data['etag'] . '"');
    expect(json_encode($data))->not->toContain('correct horse')->not->toContain('mail.example.org');
});

test('nothing changed is a 304 with no body', function () {
    $w = $this->world;
    $etag = ((array) $w->config->manifest($w->request([], $this->token))->get_data())['etag'];

    $again = $w->config->manifest($w->request([], $this->token, ['if_none_match' => '"' . $etag . '"']));

    expect($again->get_status())->toBe(304);
    expect($again->get_data())->toBeNull();
});

test('the etag in the query is honoured too, for a proxy that drops If-None-Match', function () {
    $w = $this->world;
    $etag = ((array) $w->config->manifest($w->request([], $this->token))->get_data())['etag'];

    $again = $w->config->manifest($w->request(['etag' => $etag], $this->token));
    $stale = $w->config->manifest($w->request(['etag' => 'not-the-etag'], $this->token));

    expect($again->get_status())->toBe(304);
    expect($stale->get_status())->toBe(200);
});

test('any change to a value moves the etag', function () {
    $w = $this->world;
    $etag = ((array) $w->config->manifest($w->request([], $this->token))->get_data())['etag'];

    $w->editor->set($w->app(), 0, 'smtp.host', 'smtp.example.org', false, 1);

    $again = $w->config->manifest($w->request([], $this->token, ['if_none_match' => '"' . $etag . '"']));
    expect($again->get_status())->toBe(200);
});

test('the poll is recorded for the admin list', function () {
    $w = $this->world;
    $w->config->manifest($w->request([], $this->token));

    expect(array_values($w->tablets->rows)[0]['tablet']->lastManifestAt)->toBeGreaterThan(0);
});

test('a database error is a 500, never an empty manifest', function () {
    // An empty manifest tells every tablet to delete everything it holds.
    $w = $this->world;
    $w->values->failReads = true;

    $manifest = $w->config->manifest($w->request([], $this->token));
    $values = $w->config->values($w->request(['keys' => ['smtp.host']], $this->token));

    expect($manifest)->toBeInstanceOf(WP_Error::class);
    expect($manifest->get_error_data()['status'])->toBe(500);
    expect($values->get_error_data()['status'])->toBe(500);
});

// ── Values ────────────────────────────────────────────────────────

test('a plain value comes back as it is, and a secret sealed to this tablet', function () {
    $w = $this->world;

    $response = $w->config->values($w->request(['keys' => ['smtp.host', 'smtp.password']], $this->token));

    $data = (array) $response->get_data();
    expect($data['values'][0])->toBe(['key' => 'smtp.host', 'version' => 1, 'secret' => false, 'value' => 'mail.example.org']);

    $sealed = $data['values'][1];
    expect($sealed['secret'])->toBeTrue();
    expect($sealed)->not->toHaveKey('value');
    expect(json_encode($data))->not->toContain('correct horse');

    // Opened the way the C# library opens it.
    $opened = FreedomWorld::open($sealed['k'], $sealed['p'], FreedomWorld::keypair()[0]);
    expect($opened)->toBe(['key' => 'smtp.password', 'version' => 2, 'value' => 'correct horse battery staple']);
});

test('a key that is not set is reported missing rather than sent empty', function () {
    $w = $this->world;

    $data = (array) $w->config->values($w->request(['keys' => ['smtp.host', 'nothing.here']], $this->token))->get_data();

    expect($data['missing'])->toBe(['nothing.here']);
    expect($data['values'])->toHaveCount(1);
});

test('a value that no longer decrypts is reported unreadable rather than sent empty', function () {
    $w = $this->world;
    $w->values->upsert($w->app->id, 0, 'broken.value', 'garbage', false, 99, 1, 1);

    $data = (array) $w->config->values($w->request(['keys' => ['broken.value']], $this->token))->get_data();

    expect($data['unreadable'])->toBe(['broken.value']);
    expect($data['values'])->toBe([]);
});

test('an override reaches only its own tablet', function () {
    $w = $this->world;
    $other = $w->enrolledToken(FreedomWorld::MEMBER, 'android-0002');
    $mine = array_values($w->tablets->rows)[0]['tablet'];
    $w->editor->set($w->app(), $mine->id, 'smtp.host', 'mine.example.org', false, 1);

    $forMe = (array) $w->config->values($w->request(['keys' => ['smtp.host']], $this->token))->get_data();
    $forOther = (array) $w->config->values($w->request(['keys' => ['smtp.host']], $other))->get_data();

    expect($forMe['values'][0]['value'])->toBe('mine.example.org');
    expect($forOther['values'][0]['value'])->toBe('mail.example.org');
});

test('a malformed request for values is refused', function (mixed $keys) {
    $w = $this->world;

    expect($w->config->values($w->request(['keys' => $keys], $this->token))->get_error_code())->toBe('freedom_bad_keys');
})->with([
    'none'          => [[]],
    'not a list'    => ['smtp.host'],
    'a bad key'     => [['SMTP HOST']],
    'too many'      => [array_map(static fn(int $i): string => 'key.' . $i, range(1, 101))],
]);

// ── Refusals ──────────────────────────────────────────────────────

test('no token, an invented one and a revoked one are the same 401', function (string $case) {
    $w = $this->world;

    $token = match ($case) {
        'none'     => '',
        'invented' => 'frt_' . str_repeat('ab', 32),
        default    => $this->token,
    };

    if ($case === 'revoked') {
        $w->tablet->signOut($w->request([], $this->token));
    }

    $response = $w->config->manifest($w->request([], $token));
    expect($response->get_error_code())->toBe('freedom_unauthenticated');
    expect($response->get_error_data()['status'])->toBe(401);
})->with(['none', 'invented', 'revoked']);

test('a blocked tablet is refused', function () {
    $w = $this->world;
    $w->tablets->block(array_key_first($w->tablets->rows), time());

    expect($w->config->manifest($w->request([], $this->token))->get_error_data()['status'])->toBe(401);
});

test('a common account taken off the list is refused, and put back is admitted again', function () {
    $w = $this->world;
    $token = $w->enrolledToken(FreedomWorld::TABLET_ACCOUNT, 'android-common');
    $account = $w->accounts->find($w->app->id, FreedomWorld::TABLET_ACCOUNT);

    $w->accounts->remove($account->id);
    expect($w->config->manifest($w->request([], $token))->get_error_code())->toBe('freedom_not_authorised');

    $w->accounts->add($w->app->id, FreedomWorld::TABLET_ACCOUNT, 'Back again', 1, 1);
    expect($w->config->manifest($w->request([], $token)))->toBeInstanceOf(WP_REST_Response::class);
});

test('a member removed from Unity is refused at the next request', function () {
    $w = $this->world;
    $w->members->delete(7);

    expect($w->config->manifest($w->request([], $this->token))->get_error_code())->toBe('freedom_not_authorised');
});

test('a disabled application is a suspension, not a refusal', function () {
    $w = $this->world;
    $w->applications->update($w->app->id, 'Register', FreedomWorld::CALLBACK, true, false, false, 1);

    $response = $w->config->manifest($w->request([], $this->token));

    expect($response->get_error_code())->toBe('freedom_application_disabled');
    expect($response->get_error_data()['status'])->toBe(403);
});

test('a tablet handed over from Link goes when Link\'s enrolment goes', function () {
    $w = $this->world;
    $w->applications->add('link', '', acceptFellowshipSessions: true);
    $linkToken = $w->linkToken();
    $data = (array) $w->signIn->session($w->request(['application' => 'link', 'public_key' => FreedomWorld::keypair()[1]], $linkToken))->get_data();

    expect($w->config->manifest($w->request([], $data['token'])))->toBeInstanceOf(WP_REST_Response::class);

    foreach ($w->linkDevices->findAllLive() as $device) {
        $w->linkDevices->revoke($device->id, time());
    }

    expect($w->config->manifest($w->request([], $data['token']))->get_error_code())->toBe('freedom_unauthenticated');
});

test('plain HTTP is refused', function () {
    when('is_ssl')->justReturn(false);
    $w = $this->world;

    expect($w->config->manifest($w->request([], $this->token))->get_error_code())->toBe('freedom_insecure_transport');
    expect($w->config->values($w->request([], $this->token))->get_error_code())->toBe('freedom_insecure_transport');
    expect($w->tablet->keyFault($w->request([], $this->token))->get_error_code())->toBe('freedom_insecure_transport');
    expect($w->tablet->signOut($w->request([], $this->token))->get_error_code())->toBe('freedom_insecure_transport');
});

// ── The wire shape ────────────────────────────────────────────────

test('what the routes answer has the shape the committed fixtures describe', function () {
    // tests/fixtures/*.json are committed in freedom-sharp too, and its
    // deserialisation tests read them. A field renamed here fails this
    // test; the same rename left undone there fails that one.
    $w = $this->world;
    $w->editor->set($w->app(), 0, 'nothing.else', 'x', false, 1);

    $shapes = [
        'manifest.json'  => (array) $w->config->manifest($w->request([], $this->token))->get_data(),
        'values.json'    => (array) $w->config->values($w->request(['keys' => ['smtp.host', 'smtp.password', 'nothing.here']], $this->token))->get_data(),
        'enrolment.json' => (array) $w->signInThroughBrowser(FreedomWorld::MEMBER, 'android-shape')->get_data(),
    ];

    foreach ($shapes as $file => $actual) {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $file), true);
        expect(configApiShape($actual))->toBe(configApiShape($fixture), $file . ' has drifted from its fixture.');
    }
});

// ── The tablet's own routes ──────────────────────────────────────

test('a key fault is recorded against the tablet', function () {
    $w = $this->world;

    $response = $w->tablet->keyFault($w->request([], $this->token));

    expect($response->get_status())->toBe(200);
    expect(array_values($w->tablets->rows)[0]['tablet']->hasKeyFault())->toBeTrue();
});

test('signing out revokes the tablet and is audited', function () {
    $w = $this->world;

    $w->tablet->signOut($w->request([], $this->token));

    expect(array_values($w->tablets->rows)[0]['tablet']->isRevoked())->toBeTrue();
    expect(end($w->auditLogger->entries)['detail'])->toContain('revoked')->toContain('by:tablet');
});

test('a refused tablet cannot report a fault or sign out', function () {
    $w = $this->world;

    expect($w->tablet->keyFault($w->request([]))->get_error_code())->toBe('freedom_unauthenticated');
    expect($w->tablet->signOut($w->request([]))->get_error_code())->toBe('freedom_unauthenticated');
});

test('polls from one tablet are limited', function () {
    $w = $this->world;

    for ($i = 0; $i < 120; $i++) {
        $w->config->manifest($w->request([], $this->token));
    }

    expect($w->config->manifest($w->request([], $this->token))->get_error_code())->toBe('freedom_rate_limited');
});

test('a sealing failure is reported unreadable, never sent in the clear', function () {
    $w = $this->world;
    $tablet = array_values($w->tablets->rows)[0]['tablet'];
    $id = $tablet->id;
    $w->tablets->rows[$id]['tablet'] = new \Freedom\Tablets\Tablet(...array_merge(get_object_vars($tablet), ['publicKey' => 'not a key']));

    $data = (array) $w->config->values($w->request(['keys' => ['smtp.password']], $this->token))->get_data();

    expect($data['unreadable'])->toBe(['smtp.password']);
    expect(json_encode($data))->not->toContain('correct horse');
});

// ── Fixtures ──────────────────────────────────────────────────────

/**
 * A value's shape: keys and scalar types, recursively. A list is described
 * by the union of its items' shapes, so a plain value and a sealed one
 * both count.
 *
 * @return array<string, mixed>|string
 */
function configApiShape(mixed $value): array|string
{
    if (!is_array($value)) {
        return get_debug_type($value);
    }

    if (array_is_list($value)) {
        $items = [];
        foreach ($value as $item) {
            $shape = configApiShape($item);
            $items[json_encode($shape)] = $shape;
        }
        ksort($items);

        return ['list' => array_values($items)];
    }

    $shape = [];
    foreach ($value as $key => $item) {
        $shape[(string) $key] = configApiShape($item);
    }
    ksort($shape);

    return $shape;
}
