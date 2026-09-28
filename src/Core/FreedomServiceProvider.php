<?php

declare(strict_types=1);

namespace Freedom\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Fellowship\Auth\IdentityBroker;
use Fellowship\Core\RateLimiter;
use Fellowship\Crypto\MessageSealer;
use Fellowship\Devices\MemberGate;
use Freedom\Accounts\CommonAccountRepository;
use Freedom\Accounts\WpdbCommonAccountRepository;
use Freedom\Admin\ApplicationPage;
use Freedom\Admin\ApplicationsPage;
use Freedom\Admin\TabletPage;
use Freedom\Applications\ApplicationRepository;
use Freedom\Applications\WpdbApplicationRepository;
use Freedom\Auth\FreedomAudience;
use Freedom\Config\ConfigEditor;
use Freedom\Config\ValueRepository;
use Freedom\Config\ValueSealer;
use Freedom\Config\WpdbValueRepository;
use Freedom\Rest\ConfigController;
use Freedom\Rest\SignInController;
use Freedom\Rest\TabletController;
use Freedom\Tablets\CurrentTablet;
use Freedom\Tablets\DeviceIdHasher;
use Freedom\Tablets\Enrolment;
use Freedom\Tablets\TabletAudit;
use Freedom\Tablets\TabletGate;
use Freedom\Tablets\TabletRepository;
use Freedom\Tablets\TabletTokenMinter;
use Freedom\Tablets\WpdbTabletRepository;
use Psr\Container\ContainerInterface;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Core\Interfaces\Container;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Registers Freedom's services into Unity's container — the one Fellowship
 * registers into too, which is how Freedom reaches Fellowship's broker,
 * member gate, sealer and rate limiter without any of them being global.
 */
final class FreedomServiceProvider
{
    public function register(Container $container): void
    {
        // ── Core ──
        $container->register(Cipher::class, fn() => new Cipher('freedom-values'));

        // ── Repositories ──
        $container->register(ApplicationRepository::class, function () {
            global $wpdb;
            return new WpdbApplicationRepository($wpdb);
        });
        $container->register(CommonAccountRepository::class, function () {
            global $wpdb;
            return new WpdbCommonAccountRepository($wpdb);
        });
        $container->register(TabletRepository::class, function () {
            global $wpdb;
            return new WpdbTabletRepository($wpdb);
        });
        $container->register(ValueRepository::class, function () {
            global $wpdb;
            return new WpdbValueRepository($wpdb);
        });

        // ── Tablets ──
        $container->register(TabletTokenMinter::class, fn() => new TabletTokenMinter());
        $container->register(DeviceIdHasher::class, fn() => new DeviceIdHasher());
        $container->register(TabletAudit::class, fn(ContainerInterface $c) => new TabletAudit($c->get(AuditLogger::class)));
        $container->register(TabletGate::class, fn(ContainerInterface $c) => new TabletGate(
            $c->get(MemberGate::class),
            $c->get(CommonAccountRepository::class),
        ));
        $container->register(CurrentTablet::class, fn(ContainerInterface $c) => new CurrentTablet(
            $c->get(TabletRepository::class),
            $c->get(ApplicationRepository::class),
            $c->get(TabletTokenMinter::class),
            $c->get(TabletGate::class),
            $c->get(IdentityBroker::class),
        ));
        $container->register(Enrolment::class, fn(ContainerInterface $c) => new Enrolment(
            $c->get(TabletRepository::class),
            $c->get(TabletTokenMinter::class),
            $c->get(TabletAudit::class),
        ));

        // ── Configuration ──
        $container->register(ConfigEditor::class, fn(ContainerInterface $c) => new ConfigEditor(
            $c->get(ApplicationRepository::class),
            $c->get(ValueRepository::class),
            $c->get(Cipher::class),
        ));
        $container->register(ValueSealer::class, fn(ContainerInterface $c) => new ValueSealer($c->get(MessageSealer::class)));

        // ── Sign-in ──
        $container->register(FreedomAudience::class, fn(ContainerInterface $c) => new FreedomAudience(
            $c->get(ApplicationRepository::class),
            $c->get(TabletGate::class),
        ));

        // ── REST ──
        $container->register(SignInController::class, fn(ContainerInterface $c) => new SignInController(
            $c->get(ApplicationRepository::class),
            $c->get(IdentityBroker::class),
            $c->get(TabletGate::class),
            $c->get(Enrolment::class),
            $c->get(DeviceIdHasher::class),
            $c->get(TabletTokenMinter::class),
            $c->get(RateLimiter::class),
        ));
        $container->register(ConfigController::class, fn(ContainerInterface $c) => new ConfigController(
            $c->get(CurrentTablet::class),
            $c->get(ValueRepository::class),
            $c->get(ConfigEditor::class),
            $c->get(ValueSealer::class),
            $c->get(RateLimiter::class),
        ));
        $container->register(TabletController::class, fn(ContainerInterface $c) => new TabletController(
            $c->get(CurrentTablet::class),
            $c->get(TabletRepository::class),
            $c->get(TabletAudit::class),
        ));

        // ── Admin ──
        $container->register(ApplicationsPage::class, fn(ContainerInterface $c) => new ApplicationsPage(
            $c->get(ApplicationRepository::class),
            $c->get(TabletRepository::class),
            $c->get(ApplicationPage::class),
            $c->get(TabletPage::class),
        ));
        $container->register(ApplicationPage::class, fn(ContainerInterface $c) => new ApplicationPage(
            $c->get(ApplicationRepository::class),
            $c->get(CommonAccountRepository::class),
            $c->get(TabletRepository::class),
            $c->get(ValueRepository::class),
            $c->get(ConfigEditor::class),
            $c->get(TabletAudit::class),
            $c->get(MemberRepository::class),
        ));
        $container->register(TabletPage::class, fn(ContainerInterface $c) => new TabletPage(
            $c->get(ApplicationRepository::class),
            $c->get(TabletRepository::class),
            $c->get(ValueRepository::class),
            $c->get(ConfigEditor::class),
        ));
    }
}
