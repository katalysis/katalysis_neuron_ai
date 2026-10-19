<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Package\PackageService;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;

/**
 * Read-only: proves that Concrete boots, the database answers and the package
 * is installed.
 */
final class ConcreteBootTest extends ConcreteTestCase
{
    public function testConcreteBoots(): void
    {
        $this->assertTrue(defined('APP_VERSION'));
        $this->assertStringStartsWith('9.', (string) APP_VERSION);
    }

    public function testDatabaseIsReachable(): void
    {
        $count = $this->app()->make(\Concrete\Core\Database\Connection\Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM Pages');
        $this->assertGreaterThan(0, (int) $count);
    }

    public function testPackageIsInstalled(): void
    {
        $package = $this->app()->make(PackageService::class)->getByHandle('katalysis_neuron_ai');
        $this->assertNotNull($package);
    }
}
