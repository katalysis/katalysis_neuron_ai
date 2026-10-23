<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Application\Application;
use Concrete\Core\Database\Connection\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need Concrete. setUpBeforeClass() boots Concrete
 * through ConcreteBoot, which enforces the dev-host guard. Booting happens
 * before PHPUnit's per-test handler checks, because Concrete installs its own
 * error and exception handlers. A subclass that overrides setUpBeforeClass()
 * must call parent::setUpBeforeClass() first.
 *
 * P1.12 leak check: setUpBeforeClass() also snapshots the dev database and
 * tearDownAfterClass() compares. A class that leaves rows behind fails, naming
 * the tables (see DatabaseSnapshot for the allowance). A subclass that
 * overrides tearDownAfterClass() must call parent::tearDownAfterClass().
 *
 * WP-2 drift check: setUpBeforeClass() fails the class with "fixture drift: run the seed reset" when a fixture page
 * under /neuron-test is not as the seed left it (FixtureDrift), naming the pages. The leak check keeps the state the
 * same from one class to the next, so in a clean run this only fails when something outside the run changed a page.
 */
abstract class ConcreteTestCase extends TestCase
{
    private static ?DatabaseSnapshot $snapshotBeforeClass = null;

    public static function setUpBeforeClass(): void
    {
        $app = ConcreteBoot::boot();
        $db = $app->make(Connection::class);
        self::$snapshotBeforeClass = DatabaseSnapshot::take($db);

        $drift = FixtureDrift::problem($db);
        if ($drift !== null) {
            self::fail($drift);
        }
    }

    public static function tearDownAfterClass(): void
    {
        $before = self::$snapshotBeforeClass;
        self::$snapshotBeforeClass = null;
        if ($before === null) {
            return;
        }

        $after = DatabaseSnapshot::take(ConcreteBoot::boot()->make(Connection::class));
        $differences = DatabaseSnapshot::differences($before, $after);
        if ($differences !== []) {
            self::fail(DatabaseSnapshot::message(static::class, $differences));
        }
    }

    protected function app(): Application
    {
        return ConcreteBoot::boot();
    }
}
