<?php

declare(strict_types=1);

namespace Freedom;

if (!defined('ABSPATH')) {
    exit;
}

use Fellowship\Auth\IdentityBroker;
use Freedom\Admin\ApplicationPage;
use Freedom\Admin\ApplicationsPage;
use Freedom\Admin\TabletPage;
use Freedom\Auth\FreedomAudience;
use Freedom\Core\Capabilities;
use Freedom\Core\FreedomServiceProvider;
use Freedom\Core\Schema;
use Freedom\Logger\HasLogger;
use Freedom\Rest\ConfigController;
use Freedom\Rest\SignInController;
use Freedom\Rest\TabletController;
use Freedom\Tablets\TabletRepository;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Unity\Core\Interfaces\Container;

use function add_action;
use function add_filter;
use function is_admin;

/**
 * Wires Freedom together, once Fellowship has.
 *
 * Registers Freedom's services into Unity's container, registers its
 * audience with Fellowship's broker so Fellowship's browser sign-in will
 * serve it, and registers the REST routes and the admin screens.
 */
final class Plugin
{
    use HasLogger;

    protected static function logChannel(): string
    {
        return 'freedom';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    public static function init(Container $container): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $container;

        Schema::ensureInstalled();
        Capabilities::ensureAssigned();

        (new FreedomServiceProvider())->register($container);

        self::$initialized = true;

        // Without this, Fellowship's callback refuses every Freedom
        // sign-in as a state for an audience nobody registered.
        $container->get(IdentityBroker::class)->registerAudience($container->get(FreedomAudience::class));

        $container->get(SignInController::class)->register();
        $container->get(ConfigController::class)->register();
        $container->get(TabletController::class)->register();

        // Bearer-token routes that shared caches do not recognise, one of
        // which answers secrets. WordPress sends no-cache only for
        // logged-in users, so force no-store across the namespace — the
        // same filter Fellowship applies to its own.
        add_filter('rest_post_dispatch', function ($response, $server, $request) {
            if (
                $response instanceof \WP_REST_Response
                && $request instanceof \WP_REST_Request
                && str_starts_with(ltrim((string) $request->get_route(), '/'), SignInController::NAMESPACE)
            ) {
                $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0, private');
            }

            return $response;
        }, 10, 3);

        self::registerErasure($container);

        if (is_admin()) {
            // ApplicationsPage owns the menu and routes to the other two
            // screens by query argument; those register only their form
            // handlers.
            $container->get(ApplicationsPage::class)->register();
            $container->get(ApplicationPage::class)->register();
            $container->get(TabletPage::class)->register();
        }

        self::logDebug('Initialised', [
            'version' => defined('FREEDOM_VERSION') ? FREEDOM_VERSION : 'unknown',
        ]);
    }

    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Freedom Plugin not initialized');
        }

        return self::$container;
    }

    /**
     * GDPR erasure: a tablet a member enrolled with their own account goes
     * with them.
     *
     * It would be refused at its next request anyway — CurrentTablet
     * re-runs the gate every time — but a live row naming somebody who has
     * been erased is exactly what erasure is for. Tablets enrolled with a
     * common account are nobody's personal data and are left alone, which
     * is why shared tablets should be enrolled that way.
     */
    private static function registerErasure(ContainerInterface $container): void
    {
        $tablets = $container->get(TabletRepository::class);

        add_action('unity/member_deleted', function ($postId, $member = null) use ($tablets): void {
            if ($member === null) {
                return;
            }

            $email = strtolower(trim((string) $member->getPersonalEmail()));
            if ($email === '') {
                return;
            }

            $revoked = $tablets->revokeAllForMember($email, time());

            if ($revoked > 0) {
                self::logInfo('Member deleted: their tablets were revoked', ['tablets' => $revoked]);
            }
        }, 10, 2);
    }
}
