<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tests\Support\ToolListChecks;
use Katalysis\NeuronAi\Tools\FilteredToolkit;
use Katalysis\NeuronAi\Tools\ToolNameResolver;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\TestCase;

/**
 * P1.6 name-collision rules, with synthetic toolkits. No Concrete, no database.
 */
final class ToolNameResolverTest extends TestCase
{
    /** @var array<int, array{string, string}> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->log = [];
    }

    private function logger(): callable
    {
        return function (string $level, string $message): void {
            $this->log[] = [$level, $message];
        };
    }

    /**
     * @param string[] $names
     */
    private static function kit(array $names, ?string $guidelines = null): ToolkitInterface
    {
        return new class ($names, $guidelines) extends AbstractToolkit {
            public function __construct(private array $names, private ?string $g)
            {
            }

            public function guidelines(): ?string
            {
                return $this->g;
            }

            public function provide(): array
            {
                return array_map(fn (string $n): ToolInterface => new Tool($n, 'from ' . spl_object_id($this)), $this->names);
            }
        };
    }

    /**
     * @param array<int, mixed> $items
     * @return ToolInterface[]
     */
    private static function flatten(array $items): array
    {
        $tools = [];
        foreach ($items as $item) {
            foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @param ToolInterface[] $tools
     * @return string[]
     */
    private static function names(array $tools): array
    {
        $names = array_map(static fn (ToolInterface $t): string => $t->getName(), $tools);
        sort($names);

        return $names;
    }

    public function testExternalToolBeatsCoreToolOfTheSameName(): void
    {
        $core = self::kit(['list_people', 'get_person_details']);
        $external = self::kit(['list_people', 'add_person']);

        $result = ToolNameResolver::resolve([$core], [$external], $this->logger());
        $tools = self::flatten($result);

        $this->assertSame(['add_person', 'get_person_details', 'list_people'], self::names($tools));
        $this->assertSame([], ToolListChecks::duplicateNames($tools));

        // The surviving list_people is the external one.
        $survivor = array_values(array_filter($tools, static fn (ToolInterface $t): bool => $t->getName() === 'list_people'))[0];
        $this->assertSame('from ' . spl_object_id($external), $survivor->getDescription());
    }

    public function testADroppedCoreToolIsLoggedAsAWarning(): void
    {
        ToolNameResolver::resolve([self::kit(['list_people'])], [self::kit(['list_people'])], $this->logger());

        $this->assertCount(1, $this->log);
        $this->assertSame('warning', $this->log[0][0]);
        $this->assertStringContainsString('"list_people"', $this->log[0][1]);
    }

    public function testNonClashingToolsAreKeptAndNothingIsLogged(): void
    {
        $core = self::kit(['get_page', 'create_page']);
        $external = self::kit(['list_places']);

        $result = ToolNameResolver::resolve([$core], [$external], $this->logger());

        $this->assertSame(['create_page', 'get_page', 'list_places'], self::names(self::flatten($result)));
        $this->assertSame([], $this->log);
        // Toolkits with nothing dropped are returned as they are.
        $this->assertSame([$core, $external], $result);
    }

    public function testNoExternalToolkitsLeavesTheCoreListUnchanged(): void
    {
        $core = [self::kit(['a']), self::kit(['b'])];

        $this->assertSame($core, ToolNameResolver::resolve($core, [], $this->logger()));
        $this->assertSame([], $this->log);
    }

    public function testTheFirstRegisteredExternalToolkitWinsAClash(): void
    {
        $first = self::kit(['list_things', 'only_first']);
        $second = self::kit(['list_things', 'only_second']);

        $result = ToolNameResolver::resolve([], [$first, $second], $this->logger());
        $tools = self::flatten($result);

        $this->assertSame(['list_things', 'only_first', 'only_second'], self::names($tools));
        $this->assertSame([], ToolListChecks::duplicateNames($tools));

        $survivor = array_values(array_filter($tools, static fn (ToolInterface $t): bool => $t->getName() === 'list_things'))[0];
        $this->assertSame('from ' . spl_object_id($first), $survivor->getDescription());

        $this->assertCount(1, $this->log);
        $this->assertSame('warning', $this->log[0][0]);
        $this->assertStringContainsString('first registered wins', $this->log[0][1]);
    }

    public function testACoreToolClashingWithTheLoserIsStillResolvedAgainstTheWinner(): void
    {
        $result = ToolNameResolver::resolve(
            [self::kit(['x'])],
            [self::kit(['x']), self::kit(['x'])],
            $this->logger()
        );

        $this->assertSame(['x'], self::names(self::flatten($result)));
        $this->assertCount(2, $this->log);
    }

    public function testAFilteredToolkitKeepsTheOriginalGuidelines(): void
    {
        $core = self::kit(['list_people', 'keep_me'], 'Use people tools carefully.');

        $result = ToolNameResolver::resolve([$core], [self::kit(['list_people'])], $this->logger());

        $this->assertInstanceOf(FilteredToolkit::class, $result[0]);
        $this->assertSame('Use people tools carefully.', $result[0]->guidelines());
        $this->assertSame(['keep_me'], self::names($result[0]->tools()));
    }

    public function testASingleToolIsDroppedByName(): void
    {
        $result = ToolNameResolver::resolve([new Tool('list_people', 'core'), new Tool('keep', 'core')], [new Tool('list_people', 'ext')], $this->logger());

        $this->assertSame(['keep', 'list_people'], self::names(self::flatten($result)));
        $this->assertSame('ext', self::flatten($result)[1]->getDescription());
    }
}
