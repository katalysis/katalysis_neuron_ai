<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit;

use Katalysis\NeuronAi\Tools\FilteredToolkit;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use PHPUnit\Framework\TestCase;

/**
 * A filtered toolkit must keep the original's short name, because Neuron uses
 * it to title the toolkit's guidelines in the system prompt. No Concrete, no
 * database.
 */
final class FilteredToolkitTest extends TestCase
{
    public function testItKeepsTheOriginalToolkitShortName(): void
    {
        $original = new FilteredToolkitTestPagesToolkit();

        $filtered = FilteredToolkit::from($original, [new Tool('keep', 'd')]);

        $this->assertInstanceOf(FilteredToolkit::class, $filtered);
        // What HandleTools::bootstrapTools() uses for the heading.
        $this->assertSame('FilteredToolkitTestPagesToolkit', (new \ReflectionClass($filtered))->getShortName());
        $this->assertSame('Pages guidance.', $filtered->guidelines());
        $this->assertSame(['keep'], array_map(static fn ($t): string => $t->getName(), $filtered->tools()));
    }

    public function testTheSameNameReusesTheGeneratedClass(): void
    {
        $original = new FilteredToolkitTestPagesToolkit();

        $a = FilteredToolkit::from($original, []);
        $b = FilteredToolkit::from($original, []);

        $this->assertSame($a::class, $b::class);
    }

    public function testAnAnonymousToolkitFallsBackToTheDefaultName(): void
    {
        $original = new class () extends AbstractToolkit {
            public function provide(): array
            {
                return [];
            }
        };

        $filtered = FilteredToolkit::from($original, []);

        $this->assertSame(FilteredToolkit::class, $filtered::class);
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
        return [new Tool('a', 'd'), new Tool('keep', 'd')];
    }
}
