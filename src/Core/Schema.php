<?php

declare(strict_types=1);

namespace Freedom\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Freedom\Accounts\WpdbCommonAccountRepository;
use Freedom\Applications\WpdbApplicationRepository;
use Freedom\Config\WpdbValueRepository;
use Freedom\Logger\HasLogger;
use Freedom\Tablets\WpdbTabletRepository;
use wpdb;

/**
 * Owns every table Freedom creates, and makes sure they exist.
 *
 * Fellowship's arrangement, for Fellowship's reason: the activation hook
 * runs once, and an update over an active plugin — which is how these
 * sites take new versions — never fires it. So a schema version is kept
 * in an option and compared on load, and dbDelta runs when it has moved.
 *
 * <b>Bump {@see VERSION} whenever a table is added or a column
 * changes.</b> Nothing detects that for you.
 */
final class Schema
{
    use HasLogger;

    /**
     * Schema version. Bump on any change to a CREATE TABLE.
     *
     * 1 — applications, common accounts, tablets and values.
     */
    public const VERSION = 1;

    public const OPTION = 'freedom_schema_version';

    /** @var list<string> Every table suffix, for uninstall. */
    public const TABLES = [
        WpdbApplicationRepository::TABLE_SUFFIX,
        WpdbCommonAccountRepository::TABLE_SUFFIX,
        WpdbTabletRepository::TABLE_SUFFIX,
        WpdbValueRepository::TABLE_SUFFIX,
    ];

    protected static function logChannel(): string
    {
        return 'freedom';
    }

    public static function ensureInstalled(): void
    {
        $installed = (int) get_option(self::OPTION, 0);
        if ($installed >= self::VERSION) {
            return;
        }

        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            self::logWarning('Schema install skipped: $wpdb is not available yet');
            return;
        }

        self::install($wpdb);

        update_option(self::OPTION, self::VERSION, true);

        self::logInfo('Schema installed or upgraded', ['from' => $installed, 'to' => self::VERSION]);
    }

    public static function markInstalled(): void
    {
        update_option(self::OPTION, self::VERSION, true);
    }

    public static function install(wpdb $wpdb): void
    {
        WpdbApplicationRepository::install($wpdb);
        WpdbCommonAccountRepository::install($wpdb);
        WpdbTabletRepository::install($wpdb);
        WpdbValueRepository::install($wpdb);
    }
}
