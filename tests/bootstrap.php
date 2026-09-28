<?php

declare(strict_types=1);

/**
 * Test bootstrap for Freedom.
 *
 * WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across
 * the plugin suite. Its bootstrap loads Patchwork before anything
 * patchable, so anything below that defines WordPress functions of its
 * own — here, only dbDelta() — must stay after the Bootstrap::load()
 * call, not before it.
 *
 * Groups: `wordpress`, plus `rest` because the controller tests drive
 * route callbacks with WP_REST_Request/WP_REST_Response, plus `sentinel`
 * so HasLogger's resolution path runs rather than being skipped by its
 * function_exists('wp_log') guard. Not `acf` — Freedom does not use
 * it directly.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

$autoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoloader)) {
    require_once $autoloader;
}

Bootstrap::load(['wordpress', 'rest', 'sentinel']);

WpState::$pluginSlug = 'freedom';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Normally set by freedom.php, which is not loaded here.
if (!defined('FREEDOM_PLUGIN_DIR')) {
    define('FREEDOM_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('FREEDOM_PLUGIN_FILE')) {
    define('FREEDOM_PLUGIN_FILE', dirname(__DIR__) . '/freedom.php');
}
if (!defined('FREEDOM_VERSION')) {
    define('FREEDOM_VERSION', '9.9.9');
}

// Freedom autoloader.
spl_autoload_register(function ($class) {
    $prefix = 'Freedom\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Fellowship. Freedom signs tablets in through Fellowship's IdentityBroker,
// admits members through its MemberGate and seals values with its
// MessageSealer, so its src/ is autoloaded from the sibling checkout, as
// Unity's and Scrutiny's are below. FELLOWSHIP_PATH overrides the location.
//
// Its tests/Support is autoloaded too, for InMemoryDeviceRepository: the
// session hand-off is tested against a real IdentityBroker, which needs a
// Fellowship device store behind it. That is a dependency on another
// repository's test code, and deliberately so — a copy here would be a
// second implementation of Fellowship's contract to keep in step.
$fellowshipPath = getenv('FELLOWSHIP_PATH') ?: dirname(__DIR__, 2) . '/fellowship';

if (!is_dir($fellowshipPath . '/src')) {
    fwrite(STDERR, PHP_EOL . 'ERROR: Fellowship plugin source not found at ' . $fellowshipPath . '/src' . PHP_EOL
        . 'Freedom is built on Fellowship, so it must be checked out as a sibling' . PHP_EOL
        . 'directory (or FELLOWSHIP_PATH set) for this suite to run.' . PHP_EOL . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class) use ($fellowshipPath): void {
    $map = [
        'Fellowship' . chr(92) . 'Tests' . chr(92) . 'Support' . chr(92) => $fellowshipPath . '/tests/Support/',
        'Fellowship' . chr(92) => $fellowshipPath . '/src/',
    ];

    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $dir . str_replace(chr(92), '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});

// Unity. The resolver, the controllers and several admin classes
// type-hint Unity\Members\Interfaces\{Member, MemberRepository},
// Unity\Committees\Interfaces\CommitteeRepository and
// Unity\Core\Interfaces\Container, and the fixtures extend the test
// doubles Unity ships at Unity\Testing\Doubles. A PSR-4 autoloader over
// the sibling checkout covers all of it; UNITY_PATH overrides the
// default location.
//
// Deliberately no eval()'d fallback copy of those interfaces. A copy of
// a contract owned elsewhere, never exercised in CI (which always checks
// Unity out), is how a suite goes green against a contract that has
// since moved.
$unityPath = getenv('UNITY_PATH') ?: dirname(__DIR__, 2) . '/unity';
$unitySrc  = $unityPath . '/src';

if (!is_dir($unitySrc)) {
    fwrite(STDERR, PHP_EOL . 'ERROR: Unity plugin source not found at ' . $unitySrc . PHP_EOL
        . "Freedom is built on Unity's interfaces and test doubles, so the Unity" . PHP_EOL
        . 'plugin must be checked out as a sibling directory (or UNITY_PATH set) for' . PHP_EOL
        . 'this suite to run.' . PHP_EOL . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class) use ($unitySrc): void {
    if (!str_starts_with($class, 'Unity' . chr(92))) {
        return;
    }

    $file = $unitySrc . '/' . str_replace(chr(92), '/', substr($class, strlen('Unity' . chr(92)))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// Scrutiny. The controllers type-hint Scrutiny\Audit\Interfaces\AuditLogger
// and read constants off Scrutiny\Privacy\PersonalDataPolicy, and the
// tests audit through the spy Scrutiny ships at Scrutiny\Testing\Doubles.
$scrutinyPath = getenv('SCRUTINY_PATH') ?: dirname(__DIR__, 2) . '/scrutiny';
$scrutinySrc  = $scrutinyPath . '/src';

if (!is_dir($scrutinySrc)) {
    fwrite(STDERR, PHP_EOL . 'ERROR: Scrutiny plugin source not found at ' . $scrutinySrc . PHP_EOL
        . "Freedom is built on Scrutiny's audit contract and test doubles, so the" . PHP_EOL
        . 'Scrutiny plugin must be checked out as a sibling directory (or' . PHP_EOL
        . 'SCRUTINY_PATH set) for this suite to run.' . PHP_EOL . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class) use ($scrutinySrc): void {
    if (!str_starts_with($class, 'Scrutiny' . chr(92))) {
        return;
    }

    $file = $scrutinySrc . '/'
        . str_replace(chr(92), '/', substr($class, strlen('Scrutiny' . chr(92)))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// dbDelta() lives in wp-admin/includes rather than the loaded core, so no
// shared stub group covers it. The Wpdb repositories call it from their
// install() routines, and the tests only need to confirm install()
// reaches it without touching a database — so record the SQL and return.
$GLOBALS['__freedom_dbdelta'] = [];
if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', bool $execute = true): array
    {
        $GLOBALS['__freedom_dbdelta'][] = $queries;

        return [];
    }
}

// A minimal wpdb, because the repositories type-hint the real one.
//
// wp-mocks ships Doubles\FakeWpdb, but it is final and extends nothing,
// so it cannot satisfy `__construct(wpdb $wpdb)` — and those constructors
// are typed on purpose, since a repository handed something that is not a
// database connection is a wiring fault worth failing on. The stand-in
// therefore has to *be* a wpdb, which means a class to extend.
//
// Only the surface the repositories touch is declared. Anything else is a
// method this suite does not exercise, and inventing behaviour for it
// would be inventing a database. Tests use Support\RecordingWpdb, which
// extends this and records what it was asked.
if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';

        public int $insert_id = 0;

        public string $last_error = '';

        /** @param array<int|string, mixed> $args */
        public function prepare(string $query, ...$args): string
        {
            // Enough of the real thing for a test to assert on the SQL:
            // %s is quoted, %d and %f are not.
            $replaced = str_replace(['%s', '%d', '%f'], ['%s', '%d', '%f'], $query);

            foreach ($args as $arg) {
                $position = strcspn($replaced, '%');
                $token = substr($replaced, $position, 2);

                $value = match ($token) {
                    '%d' => (string) (int) $arg,
                    '%f' => (string) (float) $arg,
                    default => "'" . (is_scalar($arg) ? (string) $arg : '') . "'",
                };

                $replaced = substr_replace($replaced, $value, $position, 2);
            }

            return $replaced;
        }

        /** @return array<int, mixed> */
        public function get_results(string $query, mixed $output = null): array
        {
            return [];
        }

        public function get_row(string $query, mixed $output = null, int $y = 0): mixed
        {
            return null;
        }

        public function get_var(string $query, int $x = 0, int $y = 0): mixed
        {
            return null;
        }

        public function query(string $query): mixed
        {
            return 0;
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data, mixed $formats = null): mixed
        {
            return 1;
        }

        /**
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         */
        public function update(string $table, array $data, array $where, mixed $f = null, mixed $wf = null): mixed
        {
            return 1;
        }

        /** @param array<string, mixed> $where */
        public function delete(string $table, array $where, mixed $formats = null): mixed
        {
            return 1;
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }
    }
}

// A minimal WP_Role, because Capabilities::ensureAssigned() type-checks
// the real one.
//
// Without a class to satisfy that instanceof, the method returns early
// and the capability grant is untestable — worse, it would *look* tested
// while asserting only that nothing happened. Only add_cap and has_cap
// are declared; those are the two the grant uses.
if (!class_exists('WP_Role')) {
    class WP_Role
    {
        /** @var array<string, bool> */
        public array $capabilities = [];

        public function has_cap(string $cap): bool
        {
            return !empty($this->capabilities[$cap]);
        }

        public function add_cap(string $cap, bool $grant = true): void
        {
            $this->capabilities[$cap] = $grant;
        }

        public function remove_cap(string $cap): void
        {
            unset($this->capabilities[$cap]);
        }
    }
}
