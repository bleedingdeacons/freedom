<?php

declare(strict_types=1);

namespace Freedom\Tests;

use function Brain\Monkey\Functions\when;
use Fellowship\Auth\AudienceRegistry;
use Fellowship\Core\FellowshipServiceProvider;
use Freedom\Admin\ApplicationPage;
use Freedom\Admin\ApplicationsPage;
use Freedom\Admin\TabletPage;
use Freedom\Auth\FreedomAudience;
use Freedom\Config\ConfigEditor;
use Freedom\Core\FreedomServiceProvider;
use Freedom\Plugin;
use Freedom\Rest\ConfigController;
use Freedom\Rest\SignInController;
use Freedom\Rest\TabletController;
use Freedom\Tablets\CurrentTablet;
use Freedom\Tablets\TabletRepository;
use Freedom\Tests\Support\RecordingWpdb;
use Scrutiny\Testing\Doubles\SpyAuditLogger;
use Unity\Auth\Interfaces\PasswordCredentialRepository;
use Unity\Testing\Doubles\FakeContainer;
use Unity\Testing\Doubles\InMemoryCommitteeRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;

/**
 * Every entry point, built for real from the container — over Fellowship's
 * registrations, as on a site.
 *
 * Built, not merely registered: a factory that throws when called is the
 * failure this exists to catch, and it would sail past a has() check. A
 * Fellowship registration Freedom depends on being renamed or removed
 * fails here too, rather than at the first request on a live site.
 */

covers(FreedomServiceProvider::class, Plugin::class);

beforeEach(function () {
    $GLOBALS['wpdb'] = new RecordingWpdb();
    when('rest_url')->alias(static fn(string $p = ''): string => 'https://example.org/wp-json/' . $p);

    $this->container = new FakeContainer([
        'Unity\\Members\\Interfaces\\MemberRepository' => new InMemoryMemberRepository(),
        'Unity\\Committees\\Interfaces\\CommitteeRepository' => new InMemoryCommitteeRepository(),
        'Scrutiny\\Audit\\Interfaces\\AuditLogger' => new SpyAuditLogger(),
        PasswordCredentialRepository::class => new InMemoryPasswordCredentialRepository(),
    ]);

    (new FellowshipServiceProvider())->register($this->container);
    (new FreedomServiceProvider())->register($this->container);
});

/** @param class-string $service */
test('every service can be built', function (string $service) {
    expect($this->container->get($service))->toBeInstanceOf($service);
})->with([
    [SignInController::class],
    [ConfigController::class],
    [TabletController::class],
    [ApplicationsPage::class],
    [ApplicationPage::class],
    [TabletPage::class],
    [CurrentTablet::class],
    [ConfigEditor::class],
    [FreedomAudience::class],
    [TabletRepository::class],
]);

test('initialising registers Freedom\'s audience with Fellowship', function () {
    // Without it, Fellowship's callback refuses every Freedom sign-in as a
    // state for an audience nobody registered.
    when('get_option')->justReturn(1);
    when('is_admin')->justReturn(true);

    Plugin::init($this->container);

    expect($this->container->get(AudienceRegistry::class)->get('freedom'))->toBeInstanceOf(FreedomAudience::class);
    expect(Plugin::getContainer())->toBe($this->container);
});
