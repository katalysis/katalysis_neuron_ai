<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolBaseline;
use Katalysis\NeuronAi\Tools\DisabledTools;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use Katalysis\NeuronAi\Tools\ToolNameResolver;

/**
 * P2.3: the core tools on Neuron 4 tell the model exactly what they told it on Neuron 3.
 *
 * tests/Support/tool-baseline-v3.json was captured on `development` (Neuron 3.15.26) before the conversion, by
 * capture_tool_baseline.php. Each tool's name, description, properties and provider payload, each toolkit's guidelines,
 * and what the agent offers from the core toolkits must be the same, in the same order. A difference is a question for
 * review, never a reason to edit the file (P2.3 plan section 3). A tool changed on purpose since then is listed, with
 * its task and reason, in ToolBaseline::DELIBERATE_CHANGES (BF-5 decision 5); the capture itself is never edited.
 *
 * Read-only: builds tools and never calls one, the AI provider or an API key.
 */
final class ToolBaselineTest extends ConcreteTestCase
{
    /** @var array<string, callable> */
    private array $savedFactories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $property = new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories');
        $this->savedFactories = $property->getValue();
        // Core tools only: external toolkits (katalysis_pro) are not part of the baseline.
        $property->setValue(null, []);
        // P3.2-1: the agent offers each user only their tier's tools. The super user is Tier 3, so it is offered every tool.
        ThrowawayPages::actAs('superuser');
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        (new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories'))->setValue(null, $this->savedFactories);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function baseline(): array
    {
        $this->assertFileExists(ToolBaseline::FILE);

        return json_decode((string) file_get_contents(ToolBaseline::FILE), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheBaselineWasCapturedOnNeuron3(): void
    {
        $captured = $this->baseline()['captured'];

        $this->assertSame('development', $captured['branch']);
        $this->assertSame('3.15.26', $captured['neuron']);
    }

    public function testEveryCoreToolIsDefinedAsOnNeuron3(): void
    {
        // The capture, without the tools and toolkits removed on purpose since (ToolBaseline::REMOVED and
        // REMOVED_TOOLKITS, each with its task and reason).
        $expected = ToolBaseline::capturedDefinitions();
        $actual = ToolBaseline::definitions(ToolBaseline::coreToolkits());

        // Names first, so a missing or extra tool reads clearly before the full comparison.
        $names = static fn (array $defs): array => array_merge(...array_map(
            static fn (array $t): array => array_map(static fn (array $tool): string => $t['toolkit'] . '::' . $tool['name'], $t['tools']),
            $defs
        ));
        $this->assertSame($names($expected), $names($actual));

        foreach ($expected as $i => $toolkit) {
            $this->assertSame($toolkit['guidelines'], $actual[$i]['guidelines'], "Guidelines of {$toolkit['toolkit']}");
            foreach ($toolkit['tools'] as $j => $tool) {
                $key = "{$toolkit['toolkit']}::{$tool['name']}";
                if (isset(ToolBaseline::DELIBERATE_CHANGES[$key])) {
                    // Changed on purpose (the reason is in the list): it must really differ, or the entry is stale.
                    $this->assertNotSame($tool, $actual[$i]['tools'][$j], "{$key} is listed as changed but matches the v3 capture");
                    continue;
                }
                $this->assertSame($tool, $actual[$i]['tools'][$j], $key);
            }
        }
    }

    public function testEveryDeliberateChangeNamesATool(): void
    {
        $names = [];
        foreach (ToolBaseline::definitions(ToolBaseline::coreToolkits()) as $toolkit) {
            foreach ($toolkit['tools'] as $tool) {
                $names[] = $toolkit['toolkit'] . '::' . $tool['name'];
            }
        }

        foreach (ToolBaseline::DELIBERATE_CHANGES as $key => $reason) {
            $this->assertContains($key, $names);
            $this->assertNotSame('', trim($reason));
        }
        // BF-16: a tool disabled since the capture is still defined, and is now in DisabledTools.
        foreach (ToolBaseline::DISABLED_SINCE as $key => $reason) {
            $this->assertContains($key, $names);
            $this->assertNotSame('', trim($reason));
            $this->assertContains(substr($key, (int) strpos($key, '::') + 2), DisabledTools::names());
        }
    }

    public function testTheCoreToolkitsAreOfferedAsOnNeuron3(): void
    {
        // P3.3-3: every core toolkit is registered now (AttributesToolkit, which wasn't, is deleted).
        $registered = ToolBaseline::coreToolkits();
        $offered = DisabledTools::apply(ToolNameResolver::resolve($registered, [], static function (): void {}), static function (): void {});

        // The capture, without the tools disabled on purpose since (ToolBaseline::DISABLED_SINCE).
        $this->assertSame(ToolBaseline::capturedOffered($this->baseline()['offered']), ToolBaseline::offered($offered));
    }

    public function testTheAgentOffersTheSameCoreTools(): void
    {
        // The agent's real list: name resolution, disabled tools, then the result scanner's wrapping.
        $this->assertSame(ToolBaseline::capturedOffered($this->baseline()['offered']), ToolBaseline::offered((new ConcreteCmsAgent())->getTools()));
    }
}
