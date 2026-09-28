<?php

declare(strict_types=1);

namespace Freedom\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Freedom\Logger\HasLogger;
use Freedom\Tablets\CurrentTablet;
use Freedom\Tablets\TabletAudit;
use Freedom\Tablets\TabletRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function register_rest_route;

/**
 * What a tablet says about itself after enrolment: that it cannot open a
 * secret it was sent, or that it is signing out.
 */
final class TabletController
{
    use HasLogger;
    use RequiresSecureTransport;

    protected static function logChannel(): string
    {
        return 'freedom';
    }

    public function __construct(
        private readonly CurrentTablet $currentTablet,
        private readonly TabletRepository $tablets,
        private readonly TabletAudit $audit,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(SignInController::NAMESPACE, '/tablet/key-fault', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'keyFault'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(SignInController::NAMESPACE, '/tablet', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'signOut'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * "I was sent a secret and cannot open it."
     *
     * The tablet keeps the value it had and says so; this puts the tablet
     * in red on the admin list, because it looks healthy and is running
     * on configuration it can no longer refresh. Signing in again — a new
     * keypair — is the cure.
     */
    public function keyFault(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($insecure = $this->insecureTransport()) {
            return $insecure;
        }

        $resolved = $this->currentTablet->resolve($request);
        if ($resolved->tablet === null) {
            return $resolved->error();
        }

        $this->tablets->markKeyFault($resolved->tablet->id, time());
        self::logWarning('A tablet reported that it cannot open its secrets', ['tablet' => $resolved->tablet->id]);

        return new WP_REST_Response(['recorded' => true], 200);
    }

    public function signOut(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($insecure = $this->insecureTransport()) {
            return $insecure;
        }

        $resolved = $this->currentTablet->resolve($request);
        if ($resolved->tablet === null) {
            return $resolved->error();
        }

        if ($this->tablets->revoke($resolved->tablet->id, time())) {
            $this->audit->revoked($resolved->tablet, 'tablet');
        }

        return new WP_REST_Response(['revoked' => true], 200);
    }
}
