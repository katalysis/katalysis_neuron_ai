<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\FilteredToolkit;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\TestCase;

/**
 * P2.2: FilteredToolkit::built() offers tools that were built once (by the result scanner or ExternalToolkitRegistry)
 * without changing the prompt: the guidelines stay exactly the original's, and a later filter (ToolNameResolver,
 * DisabledTools) still names the original toolkit, as it did before P2.2. Uses CallableTool, so it runs on Neuron 4
 * before P2.3 (FilteredToolkitTest builds v3 tools).
 */
final class FilteredToolkitBuiltTest extends TestCase
{
    private static function peopleToolkit(): ToolkitInterface
    {
        return new PeopleDirectoryToolkit();
    }

    public function testBuiltKeepsTheToolsAndTheGuidelinesExactly(): void
    {
        $original = self::peopleToolkit();
        $tools = $original->tools();

        $built = FilteredToolkit::built($original, $tools);

        $this->assertSame($tools, $built->tools());
        $this->assertSame('Use list_people to find people.', $built->guidelines());
    }

    public function testFilteringABuiltToolkitNamesTheOriginalToolkit(): void
    {
        $original = self::peopleToolkit();
        $built = FilteredToolkit::built($original, $original->tools());

        $filtered = FilteredToolkit::from($built, [$built->tools()[0]]);

        $this->assertSame('Toolkit: PeopleDirectoryToolkit' . PHP_EOL . 'Use list_people to find people.', $filtered->guidelines());
        $this->assertSame(FilteredToolkit::from($original, [$original->tools()[0]])->guidelines(), $filtered->guidelines(), 'as if the original had been filtered');
    }

    public function testBuildingAFilteredToolkitKeepsItsNameLine(): void
    {
        $original = self::peopleToolkit();
        $filtered = FilteredToolkit::from($original, [$original->tools()[0]]);

        $built = FilteredToolkit::built($filtered, $filtered->tools());

        $this->assertSame($filtered->guidelines(), $built->guidelines());
    }
}

final class PeopleDirectoryToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return 'Use list_people to find people.';
    }

    public function provide(): array
    {
        return [
            new CallableTool('list_people', 'Lists people.', static fn (): array => ['ok' => true]),
            new CallableTool('get_person', 'One person.', static fn (): array => ['ok' => true]),
        ];
    }
}
