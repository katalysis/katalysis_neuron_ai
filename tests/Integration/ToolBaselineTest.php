<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
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
 * review, never a reason to edit the file (P2.3 plan section 3).
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
    }

    protected function tearDown(): void
    {
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
        $expected = $this->baseline()['definitions'];
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
                $this->assertSame($tool, $actual[$i]['tools'][$j], "{$toolkit['toolkit']}::{$tool['name']}");
            }
        }

        $this->assertSame($expected, $actual);
    }

    public function testTheCoreToolkitsAreOfferedAsOnNeuron3(): void
    {
        $registered = array_slice(ToolBaseline::coreToolkits(), 0, -1);
        $offered = DisabledTools::apply(ToolNameResolver::resolve($registered, [], static function (): void {}), static function (): void {});

        $this->assertSame($this->baseline()['offered'], ToolBaseline::offered($offered));
    }

    public function testTheAgentOffersTheSameCoreTools(): void
    {
        // The agent's real list: name resolution, disabled tools, then the result scanner's wrapping.
        $this->assertSame($this->baseline()['offered'], ToolBaseline::offered((new ConcreteCmsAgent())->getTools()));
    }
}
