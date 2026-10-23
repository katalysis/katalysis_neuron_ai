<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Application\Application;
use Katalysis\NeuronAi\Tests\Eval\DatabaseGuard;

/**
 * The only place in the test code that boots Concrete.
 *
 * Booting reads the dev site's database, so the dev-host guard is part of the
 * boot itself: boot() does not return an application unless the site's
 * canonical URL is a dev host, and ends the process if it isn't. Nothing can
 * use Concrete in a test without passing the guard.
 *
 * Unit tests never call this, so they don't need the dev site.
 */
final class ConcreteBoot
{
    private static ?Application $app = null;

    /** Held until the PHPUnit process ends (WP-2). */
    private static ?FixtureLock $fixtureLock = null;

    /**
     * Same rule as documentation/fixtures/seed_test_content.php.
     */
    public static function isDevHost(string $canonicalUrl): bool
    {
        $host = strtolower((string) parse_url($canonicalUrl, PHP_URL_HOST));

        return $host !== ''
            && ($host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.localhost'));
    }

    public static function boot(): Application
    {
        if (self::$app !== null) {
            return self::$app;
        }

        // .../httpdocs/packages/katalysis_neuron_ai/tests/Support -> .../httpdocs
        $siteDir = dirname(__DIR__, 4);
        if (!is_file($siteDir . '/concrete/bootstrap/configure.php')) {
            fwrite(STDERR, "Cannot find the Concrete web root at {$siteDir}. The package must be installed in httpdocs/packages/.\n");
            exit(1);
        }

        // The pre-boot guard: read the database config files (no connection) and refuse a non-local host
        // before Concrete boots and connects.
        $dbProblem = DatabaseGuard::findProblem($siteDir . '/application/config');
        if ($dbProblem !== null) {
            fwrite(STDERR, "Refusing to run tests: {$dbProblem}\n");
            exit(1);
        }

        // WP-2: the shared fixture lock, so the seed and the scenario runner can't reset /neuron-test under the tests.
        try {
            self::$fixtureLock = FixtureLock::acquire('the PHPUnit integration tests');
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }

        // Concrete's own CLI entry point works these values out the same way.
        define('DIR_BASE', str_replace(DIRECTORY_SEPARATOR, '/', $siteDir));
        $_SERVER['SCRIPT_FILENAME'] = $siteDir . '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        require $siteDir . '/concrete/bootstrap/configure.php';
        require $siteDir . '/concrete/bootstrap/autoload.php';

        /** @var Application $app */
        $app = require $siteDir . '/concrete/bootstrap/start.php';

        // The guard. It runs before boot() hands the application to anything.
        $canonical = (string) $app->make('site')->getSite()->getConfigRepository()->get('seo.canonical_url');
        if (!self::isDevHost($canonical)) {
            fwrite(STDERR, "Refusing to run tests: the canonical URL \"{$canonical}\" is not a .test/.localhost/localhost dev host.\n");
            exit(1);
        }

        // As the package controller does in on_start(), after Concrete's own autoloaders.
        self::loadPackageAutoload();

        // Concrete's booter registers every installed package's autoloaders only for web requests
        // (DefaultBooter::bootHttpSapi), and its CLI runner does it itself (CLIRunner::run()). A PHPUnit
        // run is CLI but goes through neither, so without this a class from another package, such as a
        // theme's PageTheme controller that Page::add() loads, does not exist. setupPackages() is not
        // called: that is each package's on_start(), which tests don't need.
        if ($app->isInstalled()) {
            $app->setupPackageAutoloaders();
        }

        return self::$app = $app;
    }

    public static function loadPackageAutoload(): void
    {
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    }
}
