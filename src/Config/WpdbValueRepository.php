<?php

declare(strict_types=1);

namespace Freedom\Config;

if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use wpdb;

use function dbDelta;

final class WpdbValueRepository implements ValueRepository
{
    public const TABLE_SUFFIX = 'freedom_values';

    private const COLUMNS = 'id, application_id, tablet_id, config_key, value, is_secret, version, updated_at, updated_by';

    /** @return literal-string */
    public static function tableName(wpdb $wpdb): string
    {
        /** @var literal-string $prefix */
        $prefix = $wpdb->prefix;

        return $prefix . self::TABLE_SUFFIX;
    }

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public static function install(wpdb $wpdb): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table   = self::tableName($wpdb);
        $charset = $wpdb->get_charset_collate();

        // tablet_id 0 is the application default, and 0 rather than NULL
        // because a UNIQUE key treats every NULL as distinct: with NULL,
        // an application could hold two defaults for one key. config_key
        // is VARCHAR(100), which ConfigKey caps it at anyway.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id BIGINT UNSIGNED NOT NULL,
            tablet_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            config_key VARCHAR(100) NOT NULL,
            value LONGTEXT NOT NULL,
            is_secret TINYINT(1) NOT NULL DEFAULT 0,
            version BIGINT UNSIGNED NOT NULL,
            updated_at BIGINT UNSIGNED NOT NULL,
            updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY application_tablet_key (application_id,tablet_id,config_key),
            KEY tablet_id (tablet_id)
        ) {$charset};";

        dbDelta($sql);
    }

    public function defaults(int $applicationId): array
    {
        return $this->select($applicationId, 0);
    }

    public function overrides(int $applicationId, int $tabletId): array
    {
        return $tabletId <= 0 ? [] : $this->select($applicationId, $tabletId);
    }

    public function find(int $applicationId, int $tabletId, string $key): ?ConfigValue
    {
        $table = self::tableName($this->wpdb);

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE application_id = %d AND tablet_id = %d AND config_key = %s LIMIT 1",
            $applicationId,
            $tabletId,
            $key,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function upsert(
        int $applicationId,
        int $tabletId,
        string $key,
        string $storedValue,
        bool $isSecret,
        int $version,
        int $updatedBy,
        int $now,
    ): void {
        $table = self::tableName($this->wpdb);

        $written = $this->execute($this->wpdb->prepare(
            "INSERT INTO {$table} (application_id, tablet_id, config_key, value, is_secret, version, updated_at, updated_by)
             VALUES (%d, %d, %s, %s, %d, %d, %d, %d)
             ON DUPLICATE KEY UPDATE value = VALUES(value), is_secret = VALUES(is_secret), version = VALUES(version),
                                     updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)",
            $applicationId,
            $tabletId,
            $key,
            $storedValue,
            $isSecret ? 1 : 0,
            $version,
            $now,
            $updatedBy,
        ));

        if ($written === false) {
            throw new RuntimeException('The value could not be saved: the write to ' . $table . ' failed.');
        }
    }

    public function delete(int $applicationId, int $tabletId, string $key): bool
    {
        return (bool) $this->wpdb->delete(
            self::tableName($this->wpdb),
            ['application_id' => $applicationId, 'tablet_id' => $tabletId, 'config_key' => $key],
            ['%d', '%d', '%s'],
        );
    }

    public function removeForTablet(int $tabletId): int
    {
        if ($tabletId <= 0) {
            // Zero is every application's defaults. Never reachable from a
            // real tablet, and never to be deleted by accident as one.
            return 0;
        }

        return (int) $this->wpdb->delete(self::tableName($this->wpdb), ['tablet_id' => $tabletId], ['%d']);
    }

    public function removeForApplication(int $applicationId): int
    {
        return (int) $this->wpdb->delete(self::tableName($this->wpdb), ['application_id' => $applicationId], ['%d']);
    }

    /** @return list<ConfigValue> */
    private function select(int $applicationId, int $tabletId): array
    {
        $table = self::tableName($this->wpdb);

        $this->clearError();
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE application_id = %d AND tablet_id = %d ORDER BY config_key ASC",
            $applicationId,
            $tabletId,
        ), ARRAY_A);

        // get_results() answers null — or, on some drivers, an empty array —
        // when the query failed. last_error is the only reliable signal, and
        // the difference between "failed" and "nothing set" is the
        // difference between a tablet keeping its configuration and a
        // tablet deleting it.
        if (!is_array($rows) || $this->failed()) {
            throw new RuntimeException('Values could not be read from ' . $table . '.');
        }

        return array_values(array_map(fn(array $row): ConfigValue => $this->hydrate($row), $rows));
    }

    /**
     * Run a prepared statement. prepare() answers null for a malformed
     * query, and that is handled here as a failed write rather than
     * becoming a TypeError on a path that only runs in production.
     */
    private function execute(?string $sql): int|false
    {
        if (!is_string($sql)) {
            return false;
        }

        $affected = $this->wpdb->query($sql);

        return is_int($affected) ? $affected : ($affected === true ? 1 : false);
    }

    private function clearError(): void
    {
        $this->wpdb->last_error = '';
    }

    private function failed(): bool
    {
        return $this->wpdb->last_error !== '';
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ConfigValue
    {
        return new ConfigValue(
            (int) ($row['id'] ?? 0),
            (int) ($row['application_id'] ?? 0),
            (int) ($row['tablet_id'] ?? 0),
            (string) ($row['config_key'] ?? ''),
            (string) ($row['value'] ?? ''),
            (bool) (int) ($row['is_secret'] ?? 0),
            (int) ($row['version'] ?? 0),
            (int) ($row['updated_at'] ?? 0),
            (int) ($row['updated_by'] ?? 0),
        );
    }
}
