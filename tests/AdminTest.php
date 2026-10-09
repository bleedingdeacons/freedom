<?php

declare(strict_types=1);

namespace Freedom\Tests;

use function Brain\Monkey\Functions\when;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Freedom\Admin\ApplicationPage;
use Freedom\Admin\ApplicationsPage;
use Freedom\Admin\TabletPage;
use Freedom\Core\Capabilities;
use Freedom\Tests\Support\FreedomWorld;
use Scrutiny\Privacy\PersonalDataPolicy;

/**
 * The admin screens: that each form does what it says, refuses who it
 * should, and never puts a secret back on the page.
 */

covers(ApplicationsPage::class, ApplicationPage::class, TabletPage::class);

beforeEach(function () {
    $_POST = [];
    $_GET = [];
    WpState::$userCan = true;
    WpState::$deniedCaps = [];

    when('get_current_user_id')->justReturn(3);
    when('check_admin_referer')->justReturn(true);
    when('is_ssl')->justReturn(true);
    when('rest_url')->alias(static fn(string $p = ''): string => 'https://example.org/wp-json/' . $p);
    // wp-admin/includes/template.php, which no stub group loads.
    when('submit_button')->alias(static function (string $text = 'Save'): void {
        echo '<button type="submit">' . esc_html($text) . '</button>';
    });

    $this->world = new FreedomWorld();
    $w = $this->world;
    $this->tabletPage = new TabletPage($w->applications, $w->tablets, $w->values, $w->editor);
    $this->appPage = new ApplicationPage($w->applications, $w->accounts, $w->tablets, $w->values, $w->editor, $w->audit, $w->members, $w->secrets);
    $this->list = new ApplicationsPage($w->applications, $w->tablets, $this->appPage, $this->tabletPage);
});

// ── Applications ──────────────────────────────────────────────────

test('an application is added with a custom-scheme callback', function () {
    $_POST = ['slug' => 'hand', 'name' => 'Hand', 'callback_uri' => 'org.example.hand.freedom://auth', 'accept_fellowship_sessions' => '1'];

    $result = $this->list->addFromRequest();

    expect($result['code'])->toBe('app_added');
    $app = $this->world->applications->findById($result['app']);
    expect($app->slug)->toBe('hand');
    expect($app->acceptFellowshipSessions)->toBeTrue();
    expect($app->allowLoopback)->toBeFalse();
});

test('an application is refused a bad slug, a taken slug or a web callback', function (array $post, string $code) {
    $_POST = $post + ['slug' => 'hand', 'name' => 'Hand', 'callback_uri' => 'org.example.hand://auth'];

    expect($this->list->addFromRequest()['code'])->toBe($code);
})->with([
    'a bad slug'     => [['slug' => 'Hand App'], 'bad_slug'],
    'a taken slug'   => [['slug' => 'register'], 'slug_taken'],
    'an https one'   => [['callback_uri' => 'https://example.org/cb'], 'bad_callback'],
    'Link\'s scheme' => [['callback_uri' => 'link://auth'], 'bad_callback'],
    'no name'        => [['name' => ''], 'bad_name'],
]);

test('nobody without the capability gets past the check', function () {
    WpState::$deniedCaps = [Capabilities::MANAGE_APPLICATIONS];
    $_POST = ['slug' => 'hand', 'name' => 'Hand'];

    expect(fn() => $this->list->addFromRequest())->toThrow(WpDieException::class);
    expect(fn() => captureOutput(fn() => $this->list->render()))->not->toThrow(WpDieException::class);
});

test('details are saved, including disabling', function () {
    $w = $this->world;
    $_POST = ['app' => (string) $w->app->id, 'name' => 'Register (hall)', 'callback_uri' => FreedomWorld::CALLBACK];

    expect($this->appPage->saveFromRequest())->toBe('app_saved');
    expect($w->app()->name)->toBe('Register (hall)');
    expect($w->app()->enabled)->toBeFalse();
});

test('an application\'s own Google client is saved with its secret encrypted', function () {
    $w = $this->world;
    $_POST = ['app' => (string) $w->app->id, 'name' => 'Register', 'callback_uri' => FreedomWorld::CALLBACK, 'enabled' => '1',
        'google_client_id' => 'register-tablets.apps.googleusercontent.com', 'google_client_secret' => ' register-secret '];

    expect($this->appPage->saveFromRequest())->toBe('app_saved');
    expect($w->app()->googleClientId)->toBe('register-tablets.apps.googleusercontent.com');
    expect($w->app()->googleClientSecret)->not->toBe('register-secret');
    expect($w->secrets->decrypt($w->app()->googleClientSecret))->toBe('register-secret');
});

test('a blank secret keeps the stored one', function () {
    $w = $this->world;
    $w->applications->setGoogleClient($w->app->id, 'old.apps.googleusercontent.com', $w->secrets->encrypt('kept'), 1);
    $_POST = ['app' => (string) $w->app->id, 'name' => 'Register', 'callback_uri' => FreedomWorld::CALLBACK, 'enabled' => '1',
        'google_client_id' => 'new.apps.googleusercontent.com', 'google_client_secret' => ''];

    expect($this->appPage->saveFromRequest())->toBe('app_saved');
    expect($w->app()->googleClientId)->toBe('new.apps.googleusercontent.com');
    expect($w->secrets->decrypt($w->app()->googleClientSecret))->toBe('kept');
});

test('clearing the client id goes back to Fellowship\'s client and drops the secret', function () {
    $w = $this->world;
    $w->applications->setGoogleClient($w->app->id, 'old.apps.googleusercontent.com', $w->secrets->encrypt('gone'), 1);
    $_POST = ['app' => (string) $w->app->id, 'name' => 'Register', 'callback_uri' => FreedomWorld::CALLBACK, 'enabled' => '1',
        'google_client_id' => ''];

    expect($this->appPage->saveFromRequest())->toBe('app_saved');
    expect($w->app()->hasOwnGoogleClient())->toBeFalse();
    expect($w->secrets->decrypt($w->app()->googleClientSecret))->toBeNull();
});

test('a Google client is refused before anything is saved', function (array $client, string $code) {
    $w = $this->world;
    $_POST = ['app' => (string) $w->app->id, 'name' => 'Renamed', 'callback_uri' => FreedomWorld::CALLBACK, 'enabled' => '1'] + $client;

    expect($this->appPage->saveFromRequest())->toBe($code);
    expect($w->app()->name)->toBe('Register');
    expect($w->app()->hasOwnGoogleClient())->toBeFalse();
})->with([
    'a secret pasted into the id' => [['google_client_id' => 'GOCSPX-abc123', 'google_client_secret' => 'x'], 'bad_google_client'],
    'an id with no secret at all' => [['google_client_id' => 'register.apps.googleusercontent.com'], 'google_secret_needed'],
]);

test('the details tab shows the client id, never the secret, and the redirect URI to register', function () {
    $w = $this->world;
    $w->applications->setGoogleClient($w->app->id, 'register-tablets.apps.googleusercontent.com', $w->secrets->encrypt('never-shown'), 1);

    $html = captureOutput(fn() => $this->appPage->render($w->app->id, 'details'));

    expect($html)->toContain('register-tablets.apps.googleusercontent.com')
        ->toContain('fellowship/v1/auth/callback')
        ->toContain('Stored. Leave blank to keep it.')
        ->not->toContain('never-shown');
});

test('deleting an application takes everything under it', function () {
    $w = $this->world;
    $w->enrolledToken();
    $w->editor->set($w->app(), 0, 'smtp.host', 'x', false, 1);
    $_POST = ['app' => (string) $w->app->id];

    expect($this->appPage->deleteFromRequest())->toBe('app_deleted');
    expect($w->applications->rows)->toBe([]);
    expect($w->values->rows)->toBe([]);
    expect($w->tablets->rows)->toBe([]);
    expect($w->accounts->rows)->toBe([]);
});

// ── Values ────────────────────────────────────────────────────────

test('a value is set and deleted from the configuration tab', function () {
    $w = $this->world;
    $_POST = ['app' => (string) $w->app->id, 'key' => 'smtp.host', 'value' => 'mail.example.org'];

    expect($this->appPage->setValueFromRequest())->toBe('saved');
    expect($w->editor->reveal($w->values->find($w->app->id, 0, 'smtp.host')))->toBe('mail.example.org');

    expect($this->appPage->deleteValueFromRequest())->toBe('deleted');
    expect($w->values->find($w->app->id, 0, 'smtp.host'))->toBeNull();
});

test('a value is taken exactly as typed', function () {
    // A password is an arbitrary string; a text sanitiser would quietly
    // turn one into something that no longer works.
    $w = $this->world;
    $_POST = ['app' => (string) $w->app->id, 'key' => 'smtp.password', 'value' => "  p<a>ss%20wörd\t", 'secret' => '1'];

    $this->appPage->setValueFromRequest();

    expect($w->editor->reveal($w->values->find($w->app->id, 0, 'smtp.password')))->toBe("  p<a>ss%20wörd\t");
});

test('saving a secret row left blank keeps the secret', function () {
    $w = $this->world;
    $w->editor->set($w->app(), 0, 'smtp.password', 'hunter2', true, 1);
    $_POST = ['app' => (string) $w->app->id, 'key' => 'smtp.password', 'value' => '', 'secret' => '1', 'existing' => '1'];

    expect($this->appPage->setValueFromRequest())->toBe('unchanged');
    expect($w->editor->reveal($w->values->find($w->app->id, 0, 'smtp.password')))->toBe('hunter2');
});

test('a secret is never written back into the page', function () {
    $w = $this->world;
    $w->editor->set($w->app(), 0, 'smtp.password', 'hunter2', true, 1);
    $w->editor->set($w->app(), 0, 'smtp.host', 'mail.example.org', false, 1);
    $w->enrolledToken();
    $tabletId = array_key_first($w->tablets->rows);
    $w->editor->set($w->app(), $tabletId, 'unity.api_key', 'override-secret', true, 1);

    $config = captureOutput(fn() => $this->appPage->render($w->app->id, 'configuration'));
    $tablet = captureOutput(fn() => $this->tabletPage->render($tabletId));

    expect($config)->toContain('mail.example.org')->toContain('smtp.password')->not->toContain('hunter2');
    expect($tablet)->toContain('unity.api_key')->not->toContain('override-secret')->not->toContain('hunter2');
});

// ── Common accounts ──────────────────────────────────────────────

test('a common account is added, audited without its address, and removed', function () {
    $w = $this->world;
    $_POST = ['app' => (string) $w->app->id, 'email' => 'Hall.Tablets@Example.org', 'label' => 'Hall'];

    expect($this->appPage->addAccountFromRequest())->toBe('account_added');
    $account = $w->accounts->find($w->app->id, 'hall.tablets@example.org');
    expect($account)->not->toBeNull();
    expect(json_encode($w->auditLogger->entries))->not->toContain('@');

    $_POST = ['app' => (string) $w->app->id, 'account' => (string) $account->id];
    expect($this->appPage->removeAccountFromRequest())->toBe('account_removed');
    expect($w->accounts->find($w->app->id, 'hall.tablets@example.org'))->toBeNull();
});

test('an account is refused a bad or repeated address', function () {
    $w = $this->world;

    $_POST = ['app' => (string) $w->app->id, 'email' => 'not an address'];
    expect($this->appPage->addAccountFromRequest())->toBe('bad_address');

    $_POST = ['app' => (string) $w->app->id, 'email' => FreedomWorld::TABLET_ACCOUNT];
    expect($this->appPage->addAccountFromRequest())->toBe('account_exists');
});

test('an account belonging to another application cannot be removed from this one', function () {
    $w = $this->world;
    $other = $w->applications->add('other', 'org.example.other://auth');
    $theirs = $w->accounts->add($other->id, 'theirs@example.org', '', 1, 1);
    $_POST = ['app' => (string) $w->app->id, 'account' => (string) $theirs->id];

    expect($this->appPage->removeAccountFromRequest())->toBe('not_found');
    expect($w->accounts->findById($theirs->id))->not->toBeNull();
});

// ── Tablets ───────────────────────────────────────────────────────

test('a tablet is revoked, blocked, unblocked and removed from the devices tab', function () {
    $w = $this->world;
    $w->enrolledToken();
    $id = array_key_first($w->tablets->rows);
    $w->editor->set($w->app(), $id, 'smtp.host', 'mine', false, 1);
    $post = fn(string $op): array => ['app' => (string) $w->app->id, 'tablet' => (string) $id, 'operation' => $op];

    $_POST = $post('revoke');
    expect($this->appPage->tabletActionFromRequest())->toBe('revoked');
    $_POST = $post('block');
    expect($this->appPage->tabletActionFromRequest())->toBe('blocked');
    $_POST = $post('unblock');
    expect($this->appPage->tabletActionFromRequest())->toBe('unblocked');
    $_POST = $post('remove');
    expect($this->appPage->tabletActionFromRequest())->toBe('removed');

    expect($w->tablets->rows)->toBe([]);
    expect($w->values->overrides($w->app->id, $id))->toBe([]);
});

test('tablet actions need the tablet capability, not the application one', function () {
    $w = $this->world;
    $w->enrolledToken();
    WpState::$deniedCaps = [Capabilities::MANAGE_TABLETS];
    $_POST = ['app' => (string) $w->app->id, 'tablet' => (string) array_key_first($w->tablets->rows), 'operation' => 'revoke'];

    expect(fn() => $this->appPage->tabletActionFromRequest())->toThrow(WpDieException::class);
});

test('the devices tab names a member only for somebody who may see personal data', function () {
    $w = $this->world;
    $w->enrolledToken();

    $shown = captureOutput(fn() => $this->appPage->render($w->app->id, 'devices'));
    WpState::$deniedCaps = [PersonalDataPolicy::VIEW_CAPABILITY];
    $hidden = captureOutput(fn() => $this->appPage->render($w->app->id, 'devices'));

    expect($shown)->toContain('Dave P');
    expect($hidden)->not->toContain('Dave P')->toContain('A member');
});

test('an override is set and removed from the tablet page', function () {
    $w = $this->world;
    $w->enrolledToken();
    $id = array_key_first($w->tablets->rows);
    $_POST = ['tablet' => (string) $id, 'key' => 'device.label', 'value' => 'Front desk'];

    expect($this->tabletPage->setFromRequest())->toBe('saved');
    expect($w->values->overrides($w->app->id, $id))->toHaveCount(1);

    expect($this->tabletPage->deleteFromRequest())->toBe('deleted');
    expect($w->values->overrides($w->app->id, $id))->toBe([]);
});

test('every screen renders', function () {
    $w = $this->world;
    $w->enrolledToken();
    $w->editor->set($w->app(), 0, 'smtp.host', 'mail.example.org', false, 1);

    expect(captureOutput(fn() => $this->list->render()))->toContain('Register');
    foreach (['configuration', 'accounts', 'devices', 'details'] as $tab) {
        expect(captureOutput(fn() => $this->appPage->render($w->app->id, $tab)))->toContain('nav-tab-active');
    }
    expect(captureOutput(fn() => $this->tabletPage->render((int) array_key_first($w->tablets->rows))))->toContain('smtp.host');
    expect(captureOutput(fn() => $this->appPage->render(999, '')))->toContain('no longer exists');
});

test('the list page routes to an application and to a tablet', function () {
    $w = $this->world;
    $w->enrolledToken();

    $_GET = ['app' => (string) $w->app->id, 'tab' => 'details'];
    expect(captureOutput(fn() => $this->list->render()))->toContain('Callback URI');

    $_GET = ['tablet' => (string) array_key_first($w->tablets->rows)];
    expect(captureOutput(fn() => $this->list->render()))->toContain('Override a value');
});
