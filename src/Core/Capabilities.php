<?php

declare(strict_types=1);

namespace Freedom\Core;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Role;

/**
 * Freedom's capabilities, granted to the administrator role.
 *
 * Two, because they answer different questions. Managing applications
 * means reading and setting every value — including every secret — that
 * a tablet receives. Managing tablets means cutting a device off, or
 * giving one its own overrides. The first is the more trusted of the two.
 *
 * Granted on every load rather than only at activation, for the reason
 * Fellowship gives: an update never fires the activation hook.
 */
final class Capabilities
{
    /** Create applications, list common accounts, and set values. */
    public const MANAGE_APPLICATIONS = 'freedom_manage_applications';

    /** Revoke, block or remove a tablet, and set its overrides. */
    public const MANAGE_TABLETS = 'freedom_manage_tablets';

    public const ALL = [self::MANAGE_APPLICATIONS, self::MANAGE_TABLETS];

    public static function ensureAssigned(): void
    {
        $role = get_role('administrator');
        if (!$role instanceof WP_Role) {
            return;
        }

        foreach (self::ALL as $capability) {
            if (!$role->has_cap($capability)) {
                $role->add_cap($capability);
            }
        }
    }
}
