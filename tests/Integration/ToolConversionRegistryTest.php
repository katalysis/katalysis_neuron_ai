<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolBaseline;
use Katalysis\NeuronAi\Tests\Support\ToolListChecks;
use Katalysis\NeuronAi\Tools\DisabledTools;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use NeuronAI\Providers\OpenAI\ToolMapper;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P2.3's registry test for the mechanical conversion (CLAUDE.md rule 4): every tool the core toolkits define, and
 * every tool the agent offers, is a well-formed CallableTool.
 *
 * Unknown inputs are refused on every tool, before its handler runs (P2.3 plan §12.2: a deliberate exception to "no
 * behaviour change", checked by P2.9's scenarios). The refusal names the unknown input and lists the allowed ones.
 * A spy handler shows the tool never ran, so nothing can have been written; no tool is executed for real.
 *
 * Read-only: never calls the AI provider or an API key.
 */
final class ToolConversionRegistryTest extends ConcreteTestCase
{
    private const UNKNOWN = 'p23_not_an_input';

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
     * @return list<ToolInterface>
     */
    private static function definedTools(): array
    {
        $tools = [];
        foreach (ToolBaseline::coreToolkits() as $toolkit) {
            foreach ($toolkit->tools() as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @return list<ToolInterface>
     */
    private static function offeredTools(): array
    {
        $tools = [];
        foreach ((new ConcreteCmsAgent())->getTools() as $item) {
            foreach ($item instanceof ToolkitInterface ? $item->tools() : [$item] as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * The cases come from the baseline file, so the provider needs no Concrete; each test builds its tool afresh.
     *
     * @return iterable<string, array{string, int, int}>
     */
    public static function definedToolCases(): iterable
    {
        // The capture without the tools removed on purpose since (ToolBaseline::REMOVED; P3.5-2).
        foreach (ToolBaseline::capturedDefinitions() as $t => $toolkit) {
            foreach ($toolkit['tools'] as $i => $tool) {
                yield $toolkit['toolkit'] . '::' . $tool['name'] => [$tool['name'], $t, $i];
            }
        }
    }

    private static function definedTool(string $name, int $toolkit, int $index): ToolInterface
    {
        $tool = array_values(ToolBaseline::coreToolkits()[$toolkit]->tools())[$index];
        self::assertSame($name, $tool->getName());

        return $tool;
    }

    public function testThereAre87CoreToolDefinitionsLessTheRemovedOnes(): void
    {
        // P2.3 converted 87; P3.5-2 removed set_user_attribute_value (ToolBaseline::REMOVED); P3.3-3 deleted
        // AttributesToolkit and its tools (ToolBaseline::REMOVED_TOOLKITS); P3.2-2 added get_tool_group
        // (ToolBaseline::ADDED_TOOLKITS).
        $added = array_sum(array_map(static fn (array $toolkit): int => count($toolkit['tools']), ToolBaseline::ADDED_TOOLKITS));
        $this->assertCount(87 - count(ToolBaseline::REMOVED) - ToolBaseline::removedToolkitToolCount() + $added, self::definedTools());
    }

    #[DataProvider('definedToolCases')]
    public function testTheToolIsAWellFormedCallableTool(string $name, int $toolkit, int $index): void
    {
        $tool = self::definedTool($name, $toolkit, $index);

        $this->assertInstanceOf(CallableTool::class, $tool);
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/', $name);
        $this->assertNotSame('', trim((string) $tool->getDescription()), "{$name} has no description");

        foreach ($tool->getProperties() as $property) {
            $this->assertInstanceOf(ToolPropertyInterface::class, $property);
            $this->assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9_]*$/', $property->getName(), "{$name}: property name");
            $this->assertNotSame('', trim((string) $property->getDescription()), "{$name}.{$property->getName()} has no description");
        }

        // The provider payload builds. Arrays without "items" have their own known list (ToolRegistryTest).
        $payload = (new ToolMapper())->map([$tool])[0];
        $this->assertSame($name, $payload['function']['name']);
        foreach ($tool->getProperties() as $property) {
            $this->assertSame($property->getType()->value, $property->getJsonSchema()['type'] ?? null, "{$name}.{$property->getName()}");
        }
    }

    #[DataProvider('definedToolCases')]
    public function testAnUnknownInputIsRefusedBeforeTheToolRunsAndTheMessageListsTheAllowedOnes(string $name, int $toolkit, int $index): void
    {
        $tool = self::definedTool($name, $toolkit, $index);
        $this->assertInstanceOf(CallableTool::class, $tool);
        $ran = false;
        $tool->wrapHandler(static fn (\Closure $original): \Closure => static function () use (&$ran): string {
            $ran = true;

            return '{"ok":true}';
        });

        $declared = array_map(static fn (ToolPropertyInterface $p): string => $p->getName(), $tool->getProperties());
        $tool->setInputs([self::UNKNOWN => 'x']);

        try {
            $tool->execute();
            $this->fail("{$name} accepted an input it does not declare");
        } catch (\RuntimeException $e) {
            $this->assertSame(CallableTool::unknownInputsMessage($name, [self::UNKNOWN], $declared), $e->getMessage());
            $this->assertStringContainsString($name . ' does not accept: ' . self::UNKNOWN . '.', $e->getMessage());
            $this->assertStringContainsString('Nothing was changed.', $e->getMessage());
            if ($declared === []) {
                $this->assertStringEndsWith('It takes no inputs.', $e->getMessage());
            } else {
                $this->assertStringEndsWith('It accepts only: ' . implode(', ', $declared) . '.', $e->getMessage());
            }
        }

        $this->assertFalse($ran, "{$name}'s handler ran");
    }

    public function testUpdatePageRefusesAMoveKeyWithTheMoveMessage(): void
    {
        $tool = null;
        foreach ((new PagesToolkit())->tools() as $candidate) {
            if ($candidate->getName() === 'update_page') {
                $tool = $candidate;
            }
        }
        $this->assertInstanceOf(CallableTool::class, $tool);

        $ran = false;
        $tool->wrapHandler(static fn (\Closure $original): \Closure => static function () use (&$ran): string {
            $ran = true;

            return '{"ok":true}';
        });
        $tool->setInputs(['pageID' => 1, 'parentPath' => '/elsewhere']);

        try {
            $tool->execute();
            $this->fail('update_page accepted parentPath');
        } catch (\RuntimeException $e) {
            $this->assertSame(PagesToolkit::MOVE_UNAVAILABLE_MESSAGE, $e->getMessage());
        }
        $this->assertFalse($ran);
    }

    public function testEveryOfferedToolIsACallableToolWithAUniqueNameAndNoneIsDisabled(): void
    {
        $tools = self::offeredTools();
        $names = array_map(static fn (ToolInterface $t): string => $t->getName(), $tools);

        $this->assertNotEmpty($tools);
        foreach ($tools as $tool) {
            $this->assertInstanceOf(CallableTool::class, $tool, $tool->getName());
        }
        $this->assertSame([], ToolListChecks::duplicateNames($tools));
        $this->assertSame([], array_values(array_intersect($names, DisabledTools::names())));
    }
}
