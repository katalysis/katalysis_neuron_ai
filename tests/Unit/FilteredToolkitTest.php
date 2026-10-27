<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\FilteredToolkit;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use PHPUnit\Framework\TestCase;

/**
 * A filtered toolkit must keep the original's guidelines and name them, because
 * Neuron titles every filtered toolkit "FilteredToolkit". No Concrete, no
 * database.
 */
final class FilteredToolkitTest extends TestCase
{
    public function testGuidelinesStartWithTheOriginalToolkitName(): void
    {
        $filtered = FilteredToolkit::from(new FilteredToolkitTestPagesToolkit(), [new CallableTool('keep', 'd')]);

        $this->assertSame("Toolkit: FilteredToolkitTestPagesToolkit\nPages guidance.", $filtered->guidelines());
        $this->assertSame(['keep'], array_map(static fn ($t): string => $t->getName(), $filtered->tools()));
    }

    public function testFilteringAFilteredToolkitAgainNamesTheOriginalOnce(): void
    {
        // ToolNameResolver filters first, then DisabledTools filters the result again (P3.7-1).
        $once = FilteredToolkit::from(new FilteredToolkitTestPagesToolkit(), [new CallableTool('a', 'd'), new CallableTool('keep', 'd')]);
        $twice = FilteredToolkit::from($once, [new CallableTool('keep', 'd')]);

        $this->assertSame("Toolkit: FilteredToolkitTestPagesToolkit\nPages guidance.", $twice->guidelines());
        $this->assertSame(['keep'], array_map(static fn ($t): string => $t->getName(), $twice->tools()));
    }

    public function testAToolkitWithoutGuidelinesStaysWithoutThem(): void
    {
        // Neuron adds a title only when there is guideline text, so null must stay null.
        $this->assertNull(FilteredToolkit::from(new FilteredToolkitTestNoGuidelinesToolkit(), [])->guidelines());
    }

    public function testAnAnonymousToolkitKeepsItsGuidelinesWithoutAName(): void
    {
        $original = new class () extends AbstractToolkit {
            public function guidelines(): ?string
            {
                return 'Anonymous guidance.';
            }

            public function provide(): array
            {
                return [];
            }
        };

        $this->assertSame('Anonymous guidance.', FilteredToolkit::from($original, [])->guidelines());
    }
}

final class FilteredToolkitTestPagesToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return 'Pages guidance.';
    }

    public function provide(): array
    {
        return [new CallableTool('a', 'd'), new CallableTool('keep', 'd')];
    }
}

final class FilteredToolkitTestNoGuidelinesToolkit extends AbstractToolkit
{
    public function provide(): array
    {
        return [new CallableTool('a', 'd')];
    }
}
