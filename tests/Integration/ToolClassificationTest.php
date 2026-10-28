<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Eval\ToolCall;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\DisabledTools;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * Lists every tool the agent offers with how the scenario runner classifies it, read or write
 * (see ToolCall::isWrite: by name prefix, and anything unrecognised counts as a write).
 *
 * The expected list is committed in tests/Support/tool-classification.txt. A new, removed or renamed tool
 * fails this test until the file is updated, so the change shows up in the pull request diff and a reviewer
 * can check that a new tool was classified correctly. Update the file with:
 *
 *   UPDATE_TOOL_CLASSIFICATION=1 php tools/phpunit/vendor/bin/phpunit --filter ToolClassificationTest
 *
 * Read-only: builds the tool list through the agent and never calls a tool, the AI provider or an API key.
 */
final class ToolClassificationTest extends ConcreteTestCase
{
    private const FILE = __DIR__ . '/../Support/tool-classification.txt';

    // P3.2-1: the agent offers each user only their tier's tools. The super user is Tier 3, so it is offered every tool.
    protected function setUp(): void
    {
        parent::setUp();
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    /**
     * @return list<string> "read name" or "write name", sorted by name
     */
    private function current(): array
    {
        $names = [];
        foreach ((new ConcreteCmsAgent())->getTools() as $item) {
            foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                if ($tool instanceof ToolInterface) {
                    $names[] = $tool->getName();
                }
            }
        }
        sort($names);

        // A disabled tool must never be offered (src/Tools/DisabledTools.php).
        $this->assertSame([], array_values(array_intersect($names, DisabledTools::names())), 'A disabled tool reached the final tool list.');

        $lines = array_map(static fn (string $name): string => (ToolCall::isWrite($name) ? 'write ' : 'read  ') . $name, $names);

        // The disabled list is part of the committed file, so adding or removing an entry shows in the PR diff.
        foreach (DisabledTools::names() as $name) {
            $lines[] = 'off   ' . $name;
        }

        return $lines;
    }

    public function testEveryToolHasAReviewedClassification(): void
    {
        $current = $this->current();

        if (getenv('UPDATE_TOOL_CLASSIFICATION') === '1') {
            file_put_contents(self::FILE, implode("\n", $current) . "\n");
            $this->markTestSkipped('Wrote ' . count($current) . ' tools to tests/Support/tool-classification.txt. Review the diff.');
        }

        $this->assertFileExists(self::FILE, 'Run with UPDATE_TOOL_CLASSIFICATION=1 to create it.');
        $expected = array_values(array_filter(array_map('rtrim', file(self::FILE) ?: []), static fn (string $l): bool => $l !== ''));

        $this->assertSame(
            $expected,
            $current,
            'The agent\'s tools or their read/write classification changed. Check each changed line, then update '
            . 'tests/Support/tool-classification.txt (UPDATE_TOOL_CLASSIFICATION=1). If a tool that writes is shown as '
            . '"read", rename it so its name starts with a write verb.'
        );
    }
}
