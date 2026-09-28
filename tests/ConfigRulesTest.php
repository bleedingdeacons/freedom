<?php

declare(strict_types=1);

namespace Freedom\Tests;

use Freedom\Config\ConfigEditor;
use Freedom\Config\ConfigKey;
use Freedom\Config\ConfigValue;
use Freedom\Config\EffectiveConfig;
use Freedom\Config\Manifest;
use Freedom\Tests\Support\FreedomWorld;

/**
 * The rules a tablet's staleness check depends on.
 *
 * <b>Why these matter more than anything else here.</b> A tablet decides
 * what to fetch by comparing the versions it holds with the manifest, and
 * what to delete by what the manifest leaves out. If a version repeats, a
 * changed value is never fetched. If removing an override does not move
 * the version, the tablet keeps a value nobody meant it to have. Nothing
 * on screen would show either — the tablet would simply be quietly wrong.
 */

covers(EffectiveConfig::class, Manifest::class, ConfigEditor::class);

beforeEach(function () {
    $this->world = new FreedomWorld();
});

// ── Versions ──────────────────────────────────────────────────────

test('every write takes a new version, and versions never repeat within an application', function () {
    $w = $this->world;

    $w->editor->set($w->app(), 0, 'smtp.host', 'mail.example.org', false, 1);
    $w->editor->set($w->app(), 0, 'smtp.port', '587', false, 1);
    $w->editor->set($w->app(), 0, 'smtp.host', 'smtp.example.org', false, 1);

    expect($w->values->find($w->app->id, 0, 'smtp.host')->version)->toBe(3);
    expect($w->values->find($w->app->id, 0, 'smtp.port')->version)->toBe(2);
    expect($w->app()->revision)->toBe(3);
});

test('saving an unchanged value takes no version', function () {
    // Otherwise pressing Save on an untouched form sends every tablet back
    // for a value it already holds.
    $w = $this->world;
    $w->editor->set($w->app(), 0, 'smtp.host', 'mail.example.org', false, 1);

    expect($w->editor->set($w->app(), 0, 'smtp.host', 'mail.example.org', false, 1))->toBe(ConfigEditor::UNCHANGED);
    expect($w->values->find($w->app->id, 0, 'smtp.host')->version)->toBe(1);
});

test('making a value secret is a change even when the text is not', function () {
    $w = $this->world;
    $w->editor->set($w->app(), 0, 'unity.api_key', 'k', false, 1);

    expect($w->editor->set($w->app(), 0, 'unity.api_key', 'k', true, 1))->toBe(ConfigEditor::SAVED);
    expect($w->values->find($w->app->id, 0, 'unity.api_key')->isSecret)->toBeTrue();
});

test('an empty value is a value', function () {
    $w = $this->world;

    expect($w->editor->set($w->app(), 0, 'compliance.email', '', false, 1))->toBe(ConfigEditor::SAVED);
    expect($w->editor->reveal($w->values->find($w->app->id, 0, 'compliance.email')))->toBe('');
    expect($w->editor->set($w->app(), 0, 'compliance.email', '', false, 1))->toBe(ConfigEditor::UNCHANGED);
});

test('a value that no longer decrypts reads as unreadable, never as empty', function () {
    $w = $this->world;
    $w->values->upsert($w->app->id, 0, 'smtp.password', 'not-ciphertext', true, 1, 1, 1);

    expect($w->editor->reveal($w->values->find($w->app->id, 0, 'smtp.password')))->toBeNull();
});

test('values are stored encrypted, secret or not', function () {
    $w = $this->world;
    $w->editor->set($w->app(), 0, 'smtp.host', 'mail.example.org', false, 1);

    expect($w->values->find($w->app->id, 0, 'smtp.host')->storedValue)->not->toContain('mail.example.org');
});

test('keys and sizes outside the rules are refused', function (string $key, string $value, string $result) {
    $w = $this->world;

    expect($w->editor->set($w->app(), 0, $key, $value, false, 1))->toBe($result);
    expect($w->values->rows)->toBe([]);
})->with([
    'upper case'      => ['SMTP.host', 'x', ConfigEditor::BAD_KEY],
    'a space'         => ['smtp host', 'x', ConfigEditor::BAD_KEY],
    'leading dot'     => ['.smtp', 'x', ConfigEditor::BAD_KEY],
    'too long a key'  => [str_repeat('a', 101), 'x', ConfigEditor::BAD_KEY],
    'too large'       => ['smtp.host', str_repeat('x', ConfigKey::MAX_VALUE_BYTES + 1), ConfigEditor::TOO_LARGE],
]);

// ── Overrides ─────────────────────────────────────────────────────

test('overlay and version rules', function (array $defaults, array $overrides, array $expected) {
    $effective = EffectiveConfig::resolve(configRulesRows($defaults, 0), configRulesRows($overrides, 9));

    $seen = [];
    foreach ($effective as $key => $value) {
        $seen[$key] = [$value->version, $value->tabletId];
    }

    expect($seen)->toBe($expected);
})->with([
    'defaults only' => [
        ['a' => 1, 'b' => 2], [],
        ['a' => [1, 0], 'b' => [2, 0]],
    ],
    'an override wins, with its own version' => [
        ['a' => 1, 'b' => 2], ['a' => 5],
        ['a' => [5, 9], 'b' => [2, 0]],
    ],
    'an override for a key with no default is still delivered' => [
        ['a' => 1], ['z' => 4],
        ['a' => [1, 0], 'z' => [4, 9]],
    ],
    'sorted by key whatever the input order' => [
        ['c' => 3, 'a' => 1], [],
        ['a' => [1, 0], 'c' => [3, 0]],
    ],
]);

test('removing an override hands the tablet a different version, so it fetches the default', function () {
    $w = $this->world;
    $w->editor->set($w->app(), 0, 'smtp.host', 'shared', false, 1);          // v1
    $w->editor->set($w->app(), 42, 'smtp.host', 'this tablet only', false, 1); // v2

    $held = EffectiveConfig::resolve($w->values->defaults($w->app->id), $w->values->overrides($w->app->id, 42))['smtp.host']->version;
    $w->editor->delete($w->app(), 42, 'smtp.host', 1);
    $now = EffectiveConfig::resolve($w->values->defaults($w->app->id), $w->values->overrides($w->app->id, 42))['smtp.host']->version;

    expect($held)->toBe(2);
    expect($now)->toBe(1);
    expect($now)->not->toBe($held);
});

test('a default changing underneath an override changes nothing for that tablet', function () {
    $w = $this->world;
    $w->editor->set($w->app(), 0, 'smtp.host', 'shared', false, 1);
    $w->editor->set($w->app(), 42, 'smtp.host', 'this tablet only', false, 1);
    $before = Manifest::for(42, EffectiveConfig::resolve($w->values->defaults($w->app->id), $w->values->overrides($w->app->id, 42)));

    $w->editor->set($w->app(), 0, 'smtp.host', 'shared, changed', false, 1);
    $after = Manifest::for(42, EffectiveConfig::resolve($w->values->defaults($w->app->id), $w->values->overrides($w->app->id, 42)));

    expect($after->etag)->toBe($before->etag);
});

// ── The manifest ──────────────────────────────────────────────────

test('the manifest lists keys, versions and secret flags, and never a value', function () {
    $w = $this->world;
    $w->editor->set($w->app(), 0, 'smtp.host', 'mail.example.org', false, 1);
    $w->editor->set($w->app(), 0, 'smtp.password', 'hunter2', true, 1);

    $manifest = Manifest::for(1, EffectiveConfig::resolve($w->values->defaults($w->app->id), []));

    expect($manifest->keys)->toBe([
        ['key' => 'smtp.host', 'version' => 1, 'secret' => false],
        ['key' => 'smtp.password', 'version' => 2, 'secret' => true],
    ]);
    expect(json_encode($manifest->keys))->not->toContain('hunter2')->not->toContain('mail.example.org');
});

test('the etag moves with any change to what the tablet should hold', function (array $changed) {
    $base = Manifest::for(1, EffectiveConfig::resolve(configRulesRows(['a' => 1, 'b' => 2], 0), []));
    $other = Manifest::for($changed['tablet'] ?? 1, EffectiveConfig::resolve(configRulesRows($changed['rows'], 0, $changed['secret'] ?? []), []));

    expect($other->etag)->not->toBe($base->etag);
})->with([
    'a version'      => [['rows' => ['a' => 1, 'b' => 3]]],
    'a key added'    => [['rows' => ['a' => 1, 'b' => 2, 'c' => 4]]],
    'a key removed'  => [['rows' => ['a' => 1]]],
    'a secret flag'  => [['rows' => ['a' => 1, 'b' => 2], 'secret' => ['b']]],
    'another tablet' => [['rows' => ['a' => 1, 'b' => 2], 'tablet' => 2]],
]);

test('the etag does not depend on the order values were read in', function () {
    $one = Manifest::for(1, configRulesKeyed(configRulesRows(['a' => 1, 'b' => 2], 0)));
    $two = Manifest::for(1, configRulesKeyed(array_reverse(configRulesRows(['a' => 1, 'b' => 2], 0))));

    expect($one->etag)->toBe($two->etag);
});

test('If-None-Match matches quoted, weak and listed etags', function () {
    $manifest = Manifest::for(1, []);

    expect($manifest->matches('"' . $manifest->etag . '"'))->toBeTrue();
    expect($manifest->matches('W/"' . $manifest->etag . '"'))->toBeTrue();
    expect($manifest->matches('"other", "' . $manifest->etag . '"'))->toBeTrue();
    expect($manifest->matches(''))->toBeFalse();
    expect($manifest->matches('"other"'))->toBeFalse();
});

// ── Fixtures ──────────────────────────────────────────────────────

/**
 * @param array<string, int> $versions
 * @param list<string> $secret
 * @return list<ConfigValue>
 */
function configRulesRows(array $versions, int $tabletId, array $secret = []): array
{
    $rows = [];
    foreach ($versions as $key => $version) {
        $rows[] = new ConfigValue($version, 1, $tabletId, (string) $key, 'x', in_array($key, $secret, true), $version, 0, 0);
    }

    return $rows;
}

/**
 * @param list<ConfigValue> $rows
 * @return array<string, ConfigValue>
 */
function configRulesKeyed(array $rows): array
{
    $keyed = [];
    foreach ($rows as $row) {
        $keyed[$row->key] = $row;
    }

    return $keyed;
}
