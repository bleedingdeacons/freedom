<?php

declare(strict_types=1);

namespace Freedom\Tablets;

if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use wpdb;

use function dbDelta;

/**
 * Enrolled tablets.
 *
 * Nothing secret is stored: a token HMAC rather than the token, a device
 * hash rather than the device's identifier, and a *public* key.
 */
final class WpdbTabletRepository implements TabletRepository
{
    public const TABLE_SUFFIX = 'freedom_tablets';

    private const COLUMNS = 'id, application_id, device_hash, account_kind, account_email, member_id, account_id, '
        . 'fellowship_device_id, label, platform, model, app_version, public_key, key_fault_at, created_at, '
        . 'reattached_at, last_seen_at, last_manifest_at, revoked_at, blocked_at';

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

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id BIGINT UNSIGNED NOT NULL,
            device_hash CHAR(64) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            account_kind VARCHAR(16) NOT NULL DEFAULT '',
            account_email VARCHAR(254) NOT NULL DEFAULT '',
            member_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            fellowship_device_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            label VARCHAR(200) NOT NULL DEFAULT '',
            platform VARCHAR(32) NOT NULL DEFAULT '',
            model VARCHAR(100) NOT NULL DEFAULT '',
            app_version VARCHAR(32) NOT NULL DEFAULT '',
            public_key TEXT NULL,
            key_fault_at BIGINT UNSIGNED NULL,
            created_at BIGINT UNSIGNED NOT NULL,
            reattached_at BIGINT UNSIGNED NULL,
            last_seen_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_manifest_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            revoked_at BIGINT UNSIGNED NULL,
            blocked_at BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY application_device (application_id,device_hash),
            UNIQUE KEY token_hash (token_hash),
            KEY account_email (account_email)
        ) {$charset};";

        dbDelta($sql);
    }

    public function create(
        int $applicationId,
        string $deviceHash,
        string $tokenHash,
        Admission $admission,
        int $fellowshipDeviceId,
        TabletProfile $profile,
        int $now,
    ): Tablet {
        $table = self::tableName($this->wpdb);

        $inserted = $this->wpdb->insert(
            $table,
            [
                'application_id'       => $applicationId,
                'device_hash'          => $deviceHash,
                'token_hash'           => $tokenHash,
                'account_kind'         => $admission->kind,
                'account_email'        => $admission->email,
                'member_id'            => $admission->memberId,
                'account_id'           => $admission->accountId,
                'fellowship_device_id' => $fellowshipDeviceId,
                'label'                => $profile->label,
                'platform'             => $profile->platform,
                'model'                => $profile->model,
                'app_version'          => $profile->appVersion,
                'public_key'           => $profile->publicKey,
                'created_at'           => $now,
                'last_seen_at'         => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d'],
        );

        // As in Fellowship's device table: an unchecked failed insert would
        // mint a token for a row that does not exist, and the tablet would
        // be refused on its very next request with nothing saying why.
        if ($inserted === false || (int) $this->wpdb->insert_id <= 0) {
            throw new RuntimeException(
                'The tablet could not be enrolled: the write to ' . $table . ' failed. '
                . 'If the table is missing, Freedom\Core\Schema installs it on the next load.'
            );
        }

        return new Tablet(
            (int) $this->wpdb->insert_id,
            $applicationId,
            $deviceHash,
            $admission->kind,
            $admission->email,
            $admission->memberId,
            $admission->accountId,
            $fellowshipDeviceId,
            $profile->label,
            $profile->platform,
            $profile->model,
            $profile->appVersion,
            $profile->publicKey,
            null,
            $now,
            null,
            $now,
            0,
            null,
            null,
        );
    }

    public function reattach(
        int $id,
        string $tokenHash,
        Admission $admission,
        int $fellowshipDeviceId,
        TabletProfile $profile,
        int $now,
    ): bool {
        $table = self::tableName($this->wpdb);

        // One statement, so a re-attachment is never half applied: a new
        // token on the old key would be a tablet that authenticates and
        // cannot open a single secret it is sent.
        $affected = $this->execute($this->wpdb->prepare(
            "UPDATE {$table}
                SET token_hash = %s, account_kind = %s, account_email = %s, member_id = %d, account_id = %d,
                    fellowship_device_id = %d, label = %s, platform = %s, model = %s, app_version = %s,
                    public_key = %s, key_fault_at = NULL, revoked_at = NULL, reattached_at = %d, last_seen_at = %d
              WHERE id = %d AND blocked_at IS NULL",
            $tokenHash,
            $admission->kind,
            $admission->email,
            $admission->memberId,
            $admission->accountId,
            $fellowshipDeviceId,
            $profile->label,
            $profile->platform,
            $profile->model,
            $profile->appVersion,
            $profile->publicKey,
            $now,
            $now,
            $id,
        ));

        return $affected !== false && (int) $affected > 0;
    }

    public function findByTokenHash(string $tokenHash): ?Tablet
    {
        $table = self::tableName($this->wpdb);

        // revoked_at IS NULL is part of the lookup, as in Fellowship: a
        // revoked token must be indistinguishable from an unknown one.
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE token_hash = %s AND revoked_at IS NULL LIMIT 1",
            $tokenHash,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?Tablet
    {
        $table = self::tableName($this->wpdb);

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d LIMIT 1",
            $id,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByDeviceHash(int $applicationId, string $deviceHash): ?Tablet
    {
        $table = self::tableName($this->wpdb);

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE application_id = %d AND device_hash = %s LIMIT 1",
            $applicationId,
            $deviceHash,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function forApplication(int $applicationId): array
    {
        $table = self::tableName($this->wpdb);

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE application_id = %d ORDER BY created_at DESC, id DESC",
            $applicationId,
        ), ARRAY_A);

        return is_array($rows) ? array_values(array_map(fn(array $row): Tablet => $this->hydrate($row), $rows)) : [];
    }

    public function countForApplication(int $applicationId): int
    {
        $table = self::tableName($this->wpdb);

        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE application_id = %d",
            $applicationId,
        ));
    }

    public function touch(int $id, int $now, bool $manifest): void
    {
        $data = ['last_seen_at' => $now];
        if ($manifest) {
            $data['last_manifest_at'] = $now;
        }

        $this->wpdb->update(self::tableName($this->wpdb), $data, ['id' => $id], array_fill(0, count($data), '%d'), ['%d']);
    }

    public function markKeyFault(int $id, int $now): void
    {
        $this->wpdb->update(self::tableName($this->wpdb), ['key_fault_at' => $now], ['id' => $id], ['%d'], ['%d']);
    }

    public function revoke(int $id, int $now): bool
    {
        $table = self::tableName($this->wpdb);

        $affected = $this->execute($this->wpdb->prepare(
            "UPDATE {$table} SET revoked_at = %d WHERE id = %d AND revoked_at IS NULL",
            $now,
            $id,
        ));

        return $affected !== false && (int) $affected > 0;
    }

    public function block(int $id, int $now): bool
    {
        $table = self::tableName($this->wpdb);

        // Blocking revokes as well: a blocked tablet that could still use
        // the token it holds would be blocked in name only.
        $affected = $this->execute($this->wpdb->prepare(
            "UPDATE {$table} SET blocked_at = %d, revoked_at = COALESCE(revoked_at, %d) WHERE id = %d",
            $now,
            $now,
            $id,
        ));

        return $affected !== false && (int) $affected > 0;
    }

    public function unblock(int $id): bool
    {
        $table = self::tableName($this->wpdb);

        // Stays revoked. Unblocking allows the device to sign in again; it
        // does not hand back the token it held.
        $affected = $this->execute($this->wpdb->prepare(
            "UPDATE {$table} SET blocked_at = NULL WHERE id = %d",
            $id,
        ));

        return $affected !== false && (int) $affected > 0;
    }

    public function remove(int $id): bool
    {
        return (bool) $this->wpdb->delete(self::tableName($this->wpdb), ['id' => $id], ['%d']);
    }

    public function revokeAllForMember(string $email, int $now): int
    {
        $table = self::tableName($this->wpdb);

        $affected = $this->execute($this->wpdb->prepare(
            "UPDATE {$table} SET revoked_at = %d WHERE account_kind = %s AND account_email = %s AND revoked_at IS NULL",
            $now,
            Admission::MEMBER,
            strtolower(trim($email)),
        ));

        return $affected === false ? 0 : (int) $affected;
    }

    public function removeForApplication(int $applicationId): int
    {
        return (int) $this->wpdb->delete(self::tableName($this->wpdb), ['application_id' => $applicationId], ['%d']);
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
    private function hydrate(array $row): Tablet
    {
        $nullable = static fn(mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;

        return new Tablet(
            (int) ($row['id'] ?? 0),
            (int) ($row['application_id'] ?? 0),
            (string) ($row['device_hash'] ?? ''),
            (string) ($row['account_kind'] ?? ''),
            (string) ($row['account_email'] ?? ''),
            (int) ($row['member_id'] ?? 0),
            (int) ($row['account_id'] ?? 0),
            (int) ($row['fellowship_device_id'] ?? 0),
            (string) ($row['label'] ?? ''),
            (string) ($row['platform'] ?? ''),
            (string) ($row['model'] ?? ''),
            (string) ($row['app_version'] ?? ''),
            (string) ($row['public_key'] ?? ''),
            $nullable($row['key_fault_at'] ?? null),
            (int) ($row['created_at'] ?? 0),
            $nullable($row['reattached_at'] ?? null),
            (int) ($row['last_seen_at'] ?? 0),
            (int) ($row['last_manifest_at'] ?? 0),
            $nullable($row['revoked_at'] ?? null),
            $nullable($row['blocked_at'] ?? null),
        );
    }
}
