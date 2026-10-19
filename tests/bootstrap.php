<?php

/**
 * PHPUnit bootstrap for the Katalysis Neuron AI package.
 *
 * Boots Concrete against the dev site database, so every test run touches the
 * real dev database. Because of that, the dev-host guard below lives here and
 * cannot be skipped by an individual test.
 *
 * Rules for tests (see CLAUDE.md, Testing):
 * - No test may call the AI provider or use an API key. Only the scenario
 *   runner does that.
 * - Tests that write must clean up after themselves.
 */

declare(strict_types=1);

// Package root: .../httpdocs/packages/katalysis_neuron_ai
$packageDir = dirname(__DIR__);
// Site web root: .../httpdocs
$siteDir = dirname($packageDir, 2);

if (!is_file($siteDir . '/concrete/bootstrap/configure.php')) {
    fwrite(STDERR, "Cannot find the Concrete web root at {$siteDir}. The package must be installed in httpdocs/packages/.\n");
    exit(1);
}

// Concrete's own CLI entry point works out these values the same way.
define('DIR_BASE', str_replace(DIRECTORY_SEPARATOR, '/', $siteDir));
$_SERVER['SCRIPT_FILENAME'] = $siteDir . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require $siteDir . '/concrete/bootstrap/configure.php';
require $siteDir . '/concrete/bootstrap/autoload.php';

/** @var \Concrete\Core\Application\Application $app */
$app = require $siteDir . '/concrete/bootstrap/start.php';

// Dev-only guard (same rule as documentation/fixtures/seed_test_content.php).
$canonical = (string) $app->make('site')->getSite()->getConfigRepository()->get('seo.canonical_url');
$host = strtolower((string) parse_url($canonical, PHP_URL_HOST));
$isDevHost = $host !== '' && ($host === 'localhost' || substr($host, -5) === '.test' || substr($host, -10) === '.localhost');
if (!$isDevHost) {
    fwrite(STDERR, "Refusing to run tests: the canonical URL \"{$canonical}\" is not a .test/.localhost/localhost dev host.\n");
    exit(1);
}

// The package's own classes (Concrete loads this from the package controller).
require_once $packageDir . '/vendor/autoload.php';

// Test classes.
spl_autoload_register(static function (string $class) use ($packageDir): void {
    $prefix = 'Katalysis\\NeuronAi\\Tests\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $packageDir . '/tests/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

unset($packageDir, $siteDir, $canonical, $host, $isDevHost);
$GLOBALS['neuron_test_app'] = $app;
