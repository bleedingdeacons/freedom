<?php

declare(strict_types=1);

namespace Freedom\Accounts;

if (!defined('ABSPATH')) {
    exit;
}

use RuntimeException;
use wpdb;

use function dbDelta;

final class WpdbCommonAccountRepository implements CommonAccountRepository
{
    public const TABLE_SUFFIX = 'freedom_accounts';

    private const COLUMNS = 'id, application_id, email, label, created_at, created_by';

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

        // (application_id, email) is unique, and email is VARCHAR(191) rather
        // than 254 so the pair fits utf8mb4's index limit. An address longer
        // than that is refused at save.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(191) NOT NULL,
            label VARCHAR(200) NOT NULL DEFAULT '',
            created_at BIGINT UNSIGNED NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY application_email (application_id,email)
        ) {$charset};";

        dbDelta($sql);
    }

    public function add(int $applicationId, string $email, string $label, int $createdBy, int $now): CommonAccount
    {
        $table = self::tableName($this->wpdb);

        $inserted = $this->wpdb->insert(
            $table,
            [
                'application_id' => $applicationId,
                'email'          => $email,
                'label'          => $label,
                'created_at'     => $now,
                'created_by'     => $createdBy,
            ],
            ['%d', '%s', '%s', '%d', '%d'],
        );

        if ($inserted === false || (int) $this->wpdb->insert_id <= 0) {
            throw new RuntimeException('The account could not be added: the write to ' . $table . ' failed.');
        }

        return new CommonAccount((int) $this->wpdb->insert_id, $applicationId, $email, $label, $now, $createdBy);
    }

    public function remove(int $id): bool
    {
        return (bool) $this->wpdb->delete(self::tableName($this->wpdb), ['id' => $id], ['%d']);
    }

    public function findById(int $id): ?CommonAccount
    {
        $table = self::tableName($this->wpdb);

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d LIMIT 1",
            $id,
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function find(int $applicationId, string $email): ?CommonAccount
    {
        $table = self::tableName($this->wpdb);

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE application_id = %d AND email = %s LIMIT 1",
            $applicationId,
            strtolower(trim($email)),
        ), ARRAY_A);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function forApplication(int $applicationId): array
    {
        $table = self::tableName($this->wpdb);

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE application_id = %d ORDER BY email ASC",
            $applicationId,
        ), ARRAY_A);

        return is_array($rows) ? array_values(array_map(fn(array $row): CommonAccount => $this->hydrate($row), $rows)) : [];
    }

    public function removeForApplication(int $applicationId): int
    {
        return (int) $this->wpdb->delete(self::tableName($this->wpdb), ['application_id' => $applicationId], ['%d']);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CommonAccount
    {
        return new CommonAccount(
            (int) ($row['id'] ?? 0),
            (int) ($row['application_id'] ?? 0),
            (string) ($row['email'] ?? ''),
            (string) ($row['label'] ?? ''),
            (int) ($row['created_at'] ?? 0),
            (int) ($row['created_by'] ?? 0),
        );
    }
}
