<?php

declare(strict_types=1);

namespace Freedom\Applications;

if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use wpdb;

use function dbDelta;

final class WpdbApplicationRepository implements ApplicationRepository
{
    public const TABLE_SUFFIX = 'freedom_applications';

    private const COLUMNS = 'id, slug, name, callback_uri, allow_loopback, accept_fellowship_sessions, enabled, revision, created_at, updated_at';

    /**
     * @return literal-string
     *
     * Asserted on the prefix rather than the concatenation, for the reason
     * Fellowship's WpdbDeviceRepository gives: wpdb::prepare() accepts only
     * a literal-string, and PHPStan will not accept a @var on the join.
     */
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

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(64) NOT NULL,
            name VARCHAR(200) NOT NULL DEFAULT '',
            callback_uri VARCHAR(255) NOT NULL DEFAULT '',
            allow_loopback TINYINT(1) NOT NULL DEFAULT 0,
            accept_fellowship_sessions TINYINT(1) NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at BIGINT UNSIGNED NOT NULL,
            updated_at BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) {$charset};";

        dbDelta($sql);
    }

    public function create(
        string $slug,
        string $name,
        string $callbackUri,
        bool $allowLoopback,
        bool $acceptFellowshipSessions,
        int $now,
    ): Application {
        $table = self::tableName($this->wpdb);

        $inserted = $this->wpdb->insert(
            $table,
            [
                'slug'                       => $slug,
                'name'                       => $name,
                'callback_uri'               => $callbackUri,
                'allow_loopback'             => $allowLoopback ? 1 : 0,
                'accept_fellowship_sessions' => $acceptFellowshipSessions ? 1 : 0,
                'enabled'                    => 1,
                'revision'                   => 0,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ],
            ['%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d'],
        );

        if ($inserted === false || (int) $this->wpdb->insert_id <= 0) {
            throw new RuntimeException('The application could not be created: the write to ' . $table . ' failed.');
        }

        return new Application(
            (int) $this->wpdb->insert_id,
            $slug,
            $name,
            $callbackUri,
            $allowLoopback,
            $acceptFellowshipSessions,
            true,
            0,
            $now,
            $now,
        );
    }

    public function update(
        int $id,
        string $name,
        string $callbackUri,
        bool $allowLoopback,
        bool $acceptFellowshipSessions,
        bool $enabled,
        int $now,
    ): bool {
        $updated = $this->wpdb->update(
            self::tableName($this->wpdb),
            [
                'name'                       => $name,
                'callback_uri'               => $callbackUri,
                'allow_loopback'             => $allowLoopback ? 1 : 0,
                'accept_fellowship_sessions' => $acceptFellowshipSessions ? 1 : 0,
                'enabled'                    => $enabled ? 1 : 0,
                'updated_at'                 => $now,
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%d', '%d', '%d'],
            ['%d'],
        );

        return $updated !== false;
    }

    public function findById(int $id): ?Application
    {
        $table = self::tableName($this->wpdb);

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d LIMIT 1",
            $id,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Application
    {
        $table = self::tableName($this->wpdb);

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE slug = %s LIMIT 1",
            $slug,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function all(): array
    {
        $table = self::tableName($this->wpdb);

        $rows = $this->wpdb->get_results('SELECT ' . self::COLUMNS . " FROM {$table} ORDER BY slug ASC", ARRAY_A);

        return is_array($rows) ? array_values(array_map(fn(array $row): Application => $this->hydrate($row), $rows)) : [];
    }

    public function nextRevision(int $id): int
    {
        $table = self::tableName($this->wpdb);

        // LAST_INSERT_ID(expr) both sets the column and records the value
        // for this connection, so the number read back on the next line is
        // the one this UPDATE wrote — not one another request wrote in
        // between. A SELECT then an UPDATE would hand two concurrent saves
        // the same version, and a tablet holding the first would never
        // notice the second.
        $affected = $this->execute($this->wpdb->prepare(
            "UPDATE {$table} SET revision = LAST_INSERT_ID(revision + 1) WHERE id = %d",
            $id,
        ));

        if ($affected === false || (int) $affected < 1) {
            throw new RuntimeException('The revision of application ' . $id . ' could not be advanced.');
        }

        $revision = (int) $this->wpdb->get_var('SELECT LAST_INSERT_ID()');
        if ($revision <= 0) {
            throw new RuntimeException('The revision of application ' . $id . ' could not be read back.');
        }

        return $revision;
    }

    public function remove(int $id): bool
    {
        return (bool) $this->wpdb->delete(self::tableName($this->wpdb), ['id' => $id], ['%d']);
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

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Application
    {
        return new Application(
            (int) ($row['id'] ?? 0),
            (string) ($row['slug'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['callback_uri'] ?? ''),
            (bool) (int) ($row['allow_loopback'] ?? 0),
            (bool) (int) ($row['accept_fellowship_sessions'] ?? 0),
            (bool) (int) ($row['enabled'] ?? 0),
            (int) ($row['revision'] ?? 0),
            (int) ($row['created_at'] ?? 0),
            (int) ($row['updated_at'] ?? 0),
        );
    }
}
