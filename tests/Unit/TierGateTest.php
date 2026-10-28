<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Access\TierGate;
use Katalysis\NeuronAi\Extension\Tier;
use Katalysis\NeuronAi\Tools\FilteredToolkit;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\TestCase;

/**
 * P3.2-1 (B-ACC-2, B-ACC-9): a tool is offered only if the user's tier allows it, and tiers are cumulative. A removed
 * tool leaves the prompt's tool list too (FilteredToolkit), and a toolkit left with no tools is dropped with its
 * guidelines. No Concrete, no database.
 */
final class TierGateTest extends TestCase
{
    private const TIERS = [
        'get_page' => Tier::CONTENT,
        'set_value' => Tier::CONTENT,
        'create_attribute' => Tier::CONFIGURE,
        'create_topic_tree' => Tier::CONFIGURE,
        'generate_package' => Tier::GENERATE,
    ];

    private static function kit(array $names, ?string $guidelines = 'Use these.'): ToolkitInterface
    {
        return new class ($names, $guidelines) extends AbstractToolkit {
            public function __construct(private array $names, private ?string $text)
            {
            }

            public function guidelines(): ?string
            {
                return $this->text;
            }

            public function provide(): array
            {
                return array_map(static fn (string $n): ToolInterface => new CallableTool($n, 'd'), $this->names);
            }
        };
    }

    /**
     * @return array<int, mixed>
     */
    private static function items(): array
    {
        return [
            self::kit(['get_page', 'create_attribute']),
            self::kit(['create_topic_tree']),
            new CallableTool('set_value', 'd'),
            new CallableTool('generate_package', 'd'),
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return string[]
     */
    private static function names(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                $names[] = $tool->getName();
            }
        }
        sort($names);

        return $names;
    }

    public function testATierOneUserIsOfferedOnlyTierOneTools(): void
    {
        $this->assertSame(['get_page', 'set_value'], self::names(TierGate::apply(self::items(), 1, self::TIERS)));
    }

    public function testTiersAreCumulative(): void
    {
        $this->assertSame(
            ['create_attribute', 'create_topic_tree', 'get_page', 'set_value'],
            self::names(TierGate::apply(self::items(), 2, self::TIERS))
        );
        $this->assertSame(
            ['create_attribute', 'create_topic_tree', 'generate_package', 'get_page', 'set_value'],
            self::names(TierGate::apply(self::items(), 3, self::TIERS))
        );
    }

    public function testAUserWithNoTierIsOfferedNothing(): void
    {
        $this->assertSame([], TierGate::apply(self::items(), 0, self::TIERS));
    }

    public function testAToolWithNoKnownTierIsOfferedOnlyAtTierThree(): void
    {
        $items = [new CallableTool('get_unknown', 'd')];

        $this->assertSame([], TierGate::apply($items, 2, self::TIERS));
        $this->assertSame(['get_unknown'], self::names(TierGate::apply($items, 3, self::TIERS)));
    }

    public function testAToolkitLeftWithNoToolsIsDroppedWithItsGuidelines(): void
    {
        $result = TierGate::apply([self::kit(['create_topic_tree'], 'Create topic trees with create_topic_tree.')], 1, self::TIERS);

        $this->assertSame([], $result);
    }

    public function testAToolkitWithNothingRemovedIsReturnedAsItIs(): void
    {
        $kit = self::kit(['get_page', 'set_value']);

        $this->assertSame([$kit], TierGate::apply([$kit], 1, self::TIERS));
    }

    public function testAToolkitWithAToolRemovedKeepsItsGuidelinesAndListsOnlyWhatIsOffered(): void
    {
        $result = TierGate::apply([self::kit(['get_page', 'create_attribute'])], 1, self::TIERS);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(FilteredToolkit::class, $result[0]);
        $this->assertSame(['get_page'], self::names($result));
        $this->assertStringContainsString('Use these.', (string) $result[0]->guidelines());
    }

    public function testItemsThatAreNeitherToolsNorToolkitsPassThrough(): void
    {
        $other = new \stdClass();

        $this->assertSame([$other], TierGate::apply([$other], 1, self::TIERS));
    }
}
