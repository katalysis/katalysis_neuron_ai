<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Application\Application;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need Concrete. setUpBeforeClass() boots Concrete
 * through ConcreteBoot, which enforces the dev-host guard. Booting happens
 * before PHPUnit's per-test handler checks, because Concrete installs its own
 * error and exception handlers. A subclass that overrides setUpBeforeClass()
 * must call parent::setUpBeforeClass() first.
 */
abstract class ConcreteTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ConcreteBoot::boot();
    }

    protected function app(): Application
    {
        return ConcreteBoot::boot();
    }
}
