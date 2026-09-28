<?php

declare(strict_types=1);

namespace Freedom\Tests;

use Freedom\Accounts\WpdbCommonAccountRepository;
use Freedom\Applications\WpdbApplicationRepository;
use Freedom\Config\WpdbValueRepository;
use Freedom\Core\Schema;
use Freedom\Tablets\Admission;
use Freedom\Tablets\TabletProfile;
use Freedom\Tablets\WpdbTabletRepository;
use Freedom\Tests\Support\RecordingWpdb;
use RuntimeException;

/**
 * The SQL, where the SQL is the rule.
 *
 * Three behaviours here cannot be seen any other way: that a version is
 * taken with LAST_INSERT_ID() so two saves never share one, that a revoked
 * token is filtered in the lookup rather than by a caller, and that a read
 * which failed throws instead of answering empty.
 */

covers(
    WpdbApplicationRepository::class,
    WpdbCommonAccountRepository::class,
    WpdbTabletRepository::class,
    WpdbValueRepository::class,
    Schema::class,
);

beforeEach(function () {
    $this->wpdb = new RecordingWpdb();
});

test('a revision is taken atomically and read back on the same connection', function () {
    $this->wpdb->queryResult = 1;
    $this->wpdb->var = 42;

    $revision = (new WpdbApplicationRepository($this->wpdb))->nextRevision(3);

    expect($revision)->toBe(42);
    expect($this->wpdb->queries[0])->toContain('SET revision = LAST_INSERT_ID(revision + 1)')->toContain('WHERE id = 3');
    expect($this->wpdb->queries[1])->toBe('SELECT LAST_INSERT_ID()');
});

test('a revision that could not be taken throws rather than reusing one', function () {
    $this->wpdb->queryResult = 0;

    expect(fn() => (new WpdbApplicationRepository($this->wpdb))->nextRevision(3))->toThrow(RuntimeException::class);
});

test('a revoked tablet is filtered in the lookup itself', function () {
    (new WpdbTabletRepository($this->wpdb))->findByTokenHash(str_repeat('a', 64));

    expect($this->wpdb->lastQuery())->toContain('revoked_at IS NULL');
});

test('a re-attachment cannot land on a blocked tablet', function () {
    $this->wpdb->queryResult = 0;

    $ok = (new WpdbTabletRepository($this->wpdb))->reattach(
        5,
        str_repeat('b', 64),
        Admission::member('m@example.org', 7),
        0,
        new TabletProfile('l', 'android', 'm', '1', 'k'),
        100,
    );

    expect($ok)->toBeFalse();
    expect($this->wpdb->lastQuery())->toContain('blocked_at IS NULL')->toContain('revoked_at = NULL');
});

test('erasing a member revokes only tablets their own account enrolled', function () {
    (new WpdbTabletRepository($this->wpdb))->revokeAllForMember('M@Example.org', 100);

    expect($this->wpdb->lastQuery())->toContain("account_kind = 'member'")->toContain("account_email = 'm@example.org'");
});

test('a read of values that failed throws rather than answering empty', function () {
    $repository = new WpdbValueRepository($this->wpdb);
    $failing = new class extends \wpdb {
        public function get_results(string $query, mixed $output = null): array
        {
            $this->last_error = 'Table is marked as crashed';

            return [];
        }
    };

    expect($repository->defaults(1))->toBe([]);
    expect(fn() => (new WpdbValueRepository($failing))->defaults(1))->toThrow(RuntimeException::class);
});

test('a value is written once per application, tablet and key', function () {
    $this->wpdb->queryResult = 1;

    (new WpdbValueRepository($this->wpdb))->upsert(1, 0, 'smtp.host', 'cipher', false, 9, 1, 100);

    expect($this->wpdb->lastQuery())->toContain('ON DUPLICATE KEY UPDATE');
});

test('deleting a tablet\'s overrides never touches the defaults', function () {
    expect((new WpdbValueRepository($this->wpdb))->removeForTablet(0))->toBe(0);
    expect($this->wpdb->deletes)->toBe([]);
});

test('a failed insert is an exception, not a row that does not exist', function () {
    $this->wpdb->insertResult = false;

    expect(fn() => (new WpdbTabletRepository($this->wpdb))->create(
        1,
        str_repeat('a', 64),
        str_repeat('b', 64),
        Admission::common('t@example.org', 1),
        0,
        new TabletProfile('l', 'android', 'm', '1', 'k'),
        100,
    ))->toThrow(RuntimeException::class);
});

test('the schema installs every table, with the uniques the rules need', function () {
    $GLOBALS['__freedom_dbdelta'] = [];

    Schema::install($this->wpdb);

    $sql = implode("\n", $GLOBALS['__freedom_dbdelta']);
    expect($GLOBALS['__freedom_dbdelta'])->toHaveCount(4);
    expect($sql)->toContain('UNIQUE KEY slug')
        ->toContain('UNIQUE KEY application_email')
        ->toContain('UNIQUE KEY application_device')
        ->toContain('UNIQUE KEY application_tablet_key');
});
