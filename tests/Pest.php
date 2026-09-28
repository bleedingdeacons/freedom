<?php

declare(strict_types=1);

// Pest configuration.
//
// Every test here runs on wp-mocks' TestCase, because nearly all of them
// reach a WordPress function — even the "pure" rules call wp_json_encode()
// or wp_salt() somewhere underneath — and Brain Monkey is only set up
// inside that TestCase. Listed by name, as Fellowship does, so a new file
// is a deliberate addition: a file missing from this list finds none of
// Brain Monkey's functions defined.
//
// Every test file shares the Freedom\Tests namespace, and Pest loads all of
// them before running any, so a file-level helper collides with any other
// file's of the same name. Helpers carry their file's name, and most of the
// wiring lives in Support\FreedomWorld instead.

use BleedingDeacons\WpMocks\TestCase;

pest()->extend(TestCase::class)->in(
    'AdminTest.php',
    'ConfigApiTest.php',
    'ConfigRulesTest.php',
    'ContainerWiringTest.php',
    'RepositoriesTest.php',
    'RulesTest.php',
    'SignInTest.php',
);

/**
 * Runs $render inside an output buffer and returns what it printed.
 */
function captureOutput(callable $render): string
{
    ob_start();

    try {
        $render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}
