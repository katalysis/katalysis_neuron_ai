<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Application\Application;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need Concrete. Concrete is booted once in
 * tests/bootstrap.php, which also enforces the dev-host guard.
 */
abstract class ConcreteTestCase extends TestCase
{
    protected function app(): Application
    {
        return $GLOBALS['neuron_test_app'];
    }
}
