<?php

/**
 * Removes everything Freedom created.
 *
 * <b>This destroys every stored value, every secret included.</b> That is
 * the intent: an uninstalled Freedom should leave no SMTP password or API
 * key behind in a table nothing reads any more. Tablets already hold what
 * they were sent, and their next poll finds no route and keeps it.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

foreach (['freedom_values', 'freedom_tablets', 'freedom_accounts', 'freedom_applications'] as $suffix) {
    $table = $wpdb->prefix . $suffix;
    // Table names cannot be placeholders; the suffixes are this file's own
    // literals and the prefix is WordPress's.
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore
}

delete_option('freedom_schema_version');

foreach (array_keys(wp_roles()->roles) as $name) {
    $role = get_role((string) $name);
    if ($role === null) {
        continue;
    }

    $role->remove_cap('freedom_manage_applications');
    $role->remove_cap('freedom_manage_tablets');
}
