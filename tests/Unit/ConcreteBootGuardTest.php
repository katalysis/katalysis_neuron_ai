<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ConcreteBootGuardTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function hosts(): array
    {
        return [
            'herd .test' => ['http://katalysis-pro.test/', true],
            'nested .test' => ['https://a.b.test', true],
            '.localhost' => ['http://site.localhost/', true],
            'localhost' => ['http://localhost:8080/', true],
            'uppercase' => ['http://KATALYSIS-PRO.TEST/', true],
            'live domain' => ['https://www.example.com/', false],
            'looks like test but is not' => ['https://mytest.com/', false],
            'test as a subdomain only' => ['https://test.example.com/', false],
            'ip address' => ['http://192.168.1.10/', false],
            'empty' => ['', false],
            'no host' => ['/just/a/path', false],
        ];
    }

    #[DataProvider('hosts')]
    public function testIsDevHost(string $url, bool $expected): void
    {
        $this->assertSame($expected, ConcreteBoot::isDevHost($url));
    }

    // Unit tests must not need the dev site. Checked in a clean process so that
    // an earlier integration test can't have booted Concrete.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnitTestsDoNotBootConcrete(): void
    {
        $this->assertTrue(class_exists(\Katalysis\NeuronAi\ConcreteCmsAgent::class));
        $this->assertFalse(defined('C5_EXECUTE'));
        $this->assertFalse(defined('DIR_BASE'));
    }
}
