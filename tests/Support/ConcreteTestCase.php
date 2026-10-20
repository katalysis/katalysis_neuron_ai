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
 */
abstract class ConcreteTestCase extends TestCase
{
    private static ?DatabaseSnapshot $snapshotBeforeClass = null;

    public static function setUpBeforeClass(): void
    {
        $app = ConcreteBoot::boot();
        self::$snapshotBeforeClass = DatabaseSnapshot::take($app->make(Connection::class));
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
