<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\TestCase;

/**
 * P2.2 (P2.1 findings 5.4): a failing external toolkit is skipped and logged, permanently, so one package can't break
 * every chat turn. Each toolkit's tools are built once per build(), inside the try. No Concrete, no database.
 */
final class ExternalToolkitRegistryTest extends TestCase
{
    /** @var array<string, callable> */
    private array $savedFactories = [];

    /** @var list<array{string, string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories');
        $this->savedFactories = $property->getValue();
        $property->setValue(null, []);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories'))->setValue(null, $this->savedFactories);
    }

    private function logger(): \Closure
    {
        return function (string $level, string $message): void {
            $this->logged[] = [$level, $message];
        };
    }

    /**
     * A toolkit that counts how often it builds its tools.
     */
    private static function goodToolkit(\stdClass $counter): ToolkitInterface
    {
        return new class ($counter) extends AbstractToolkit {
            public function __construct(private \stdClass $counter)
            {
            }

            public function guidelines(): ?string
            {
                return 'Use get_review for reviews.';
            }

            public function provide(): array
            {
                $this->counter->builds++;

                return [new CallableTool('get_review', 'A review.', static fn (): array => ['ok' => true])];
            }
        };
    }

    private static function throwingToolkit(): ToolkitInterface
    {
        return new class () extends AbstractToolkit {
            public function provide(): array
            {
                throw new \Error('Cannot instantiate abstract class NeuronAI\Tools\Tool; key sk-proj-abcdefghijklmnop');
            }
        };
    }

    public function testAToolkitThatThrowsIsSkippedAndTheOthersAreOffered(): void
    {
        $counter = (object) ['builds' => 0];
        ExternalToolkitRegistry::registerToolkitFactory('broken_package', static fn (): array => [self::throwingToolkit()]);
        ExternalToolkitRegistry::registerToolkitFactory('good_package', static fn (): array => [self::goodToolkit($counter)]);

        $build = ExternalToolkitRegistry::build($this->logger());

        $this->assertCount(1, $build->toolkits());
        $this->assertSame(['get_review'], array_map(static fn ($tool): string => $tool->getName(), $build->toolkits()[0]->tools()));
        $this->assertSame('Use get_review for reviews.', $build->toolkits()[0]->guidelines(), 'the guidelines are unchanged');

        $this->assertCount(1, $build->skipped());
        $skip = $build->skipped()[0];
        $this->assertSame('broken_package', $skip['provider']);
        $this->assertNotNull($skip['toolkit']);
        $this->assertStringContainsString('Cannot instantiate abstract class', $skip['reason']);

        $this->assertCount(1, $this->logged);
        $this->assertSame('warning', $this->logged[0][0]);
        $this->assertStringContainsString('"broken_package" skipped', $this->logged[0][1]);
    }

    public function testAFactoryThatThrowsIsSkipped(): void
    {
        ExternalToolkitRegistry::registerToolkitFactory('broken_factory', static function (): array {
            throw new \RuntimeException('factory failed');
        });

        $build = ExternalToolkitRegistry::build($this->logger());

        $this->assertSame([], $build->toolkits());
        $this->assertSame([['provider' => 'broken_factory', 'toolkit' => null, 'reason' => 'factory failed']], $build->skipped());
        $this->assertStringContainsString('provider "broken_factory" failed', $this->logged[0][1]);
    }

    public function testTheReasonAndTheLogLineAreRedacted(): void
    {
        ExternalToolkitRegistry::registerToolkitFactory('broken_package', static fn (): array => [self::throwingToolkit()]);

        $build = ExternalToolkitRegistry::build($this->logger());

        $this->assertStringNotContainsString('sk-proj-abcdefghijklmnop', $build->skipped()[0]['reason']);
        $this->assertStringNotContainsString('sk-proj-abcdefghijklmnop', $this->logged[0][1]);
    }

    public function testEachToolkitBuildsItsToolsOncePerBuild(): void
    {
        $counter = (object) ['builds' => 0];
        ExternalToolkitRegistry::registerToolkitFactory('good_package', static fn (): array => [self::goodToolkit($counter)]);

        $build = ExternalToolkitRegistry::build($this->logger());
        $first = $build->toolkits()[0]->tools();
        $again = $build->toolkits()[0]->tools();

        $this->assertSame(1, $counter->builds, 'tools() of the original toolkit is called once');
        $this->assertSame($first[0], $again[0], 'the same tool objects are offered every time');

        ExternalToolkitRegistry::build($this->logger());
        $this->assertSame(2, $counter->builds, 'a new build (a new agent) builds again: nothing is kept between agents');
    }

    public function testAFactoryThatReturnsNoArrayOffersNothingAndIsNotASkip(): void
    {
        ExternalToolkitRegistry::registerToolkitFactory('odd_package', static fn () => 'not an array');

        $build = ExternalToolkitRegistry::build($this->logger());

        $this->assertSame([], $build->toolkits());
        $this->assertSame([], $build->skipped());
    }
}
