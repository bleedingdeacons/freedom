<?php

declare(strict_types=1);

namespace Freedom\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;

use function is_ssl;

/**
 * Every Freedom route refuses plain HTTP: the routes carry bearer tokens
 * and configuration, some of it secret even before it is sealed.
 *
 * `FREEDOM_ALLOW_INSECURE_TRANSPORT` in wp-config.php lifts that for a
 * local development site, as Fellowship's constant does for its routes. A
 * local browser sign-in needs both, because the callback is Fellowship's.
 */
trait RequiresSecureTransport
{
    private function insecureTransport(): ?WP_Error
    {
        if (is_ssl()) {
            return null;
        }

        if (defined('FREEDOM_ALLOW_INSECURE_TRANSPORT') && FREEDOM_ALLOW_INSECURE_TRANSPORT) {
            return null;
        }

        return new WP_Error('freedom_insecure_transport', 'This endpoint requires HTTPS.', ['status' => 403]);
    }
}
