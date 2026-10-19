<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\ConcreteCmsAgent;
use PHPUnit\Framework\TestCase;

final class BootstrapSmokeTest extends TestCase
{
    public function testPackageClassesAutoload(): void
    {
        $this->assertTrue(class_exists(ConcreteCmsAgent::class));
    }

    public function testTestClassesAutoload(): void
    {
        $this->assertTrue(class_exists(\Katalysis\NeuronAi\Tests\Support\ConcreteTestCase::class));
    }
}
