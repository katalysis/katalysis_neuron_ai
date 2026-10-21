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

    /**
     * Page::add() loads the page type's default theme controller, which lives in another package. Concrete only
     * registers package autoloaders itself for web requests and in its CLI runner, so ConcreteBoot has to do it
     * (P1.13). Before that, every test class that made a page had to call setupPackageAutoloaders() itself.
     */
    public function testAnotherPackagesClassesAreAutoloadableAfterABareBoot(): void
    {
        $theme = $this->app()->make(PackageService::class)->getByHandle('katalysis_pro_theme');
        if ($theme === null) {
            $this->markTestSkipped('katalysis_pro_theme is not installed on this site.');
        }

        $this->assertTrue(
            class_exists('Concrete\\Package\\KatalysisProTheme\\Theme\\KatalysisProTheme\\PageTheme'),
            'A class from an installed package cannot be autoloaded. ConcreteBoot::boot() should call setupPackageAutoloaders().'
        );
    }
}
