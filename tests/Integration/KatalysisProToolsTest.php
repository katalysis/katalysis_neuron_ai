<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Extension\ToolDefinition;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\KatalysisPro;
use Katalysis\NeuronAi\Tests\Support\ToolBaseline;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

/**
 * P2.8, plans/P2.8.md section 4.4: katalysis_pro's real tools, as ToolDefinitions, on Neuron 4.
 *
 * - each of its 11 tools tells the model exactly what it told it on Neuron 3 (katalysis-pro-tool-baseline-v3.json,
 *   captured by capture_katalysis_pro_tool_baseline.php; never edited to make this test pass)
 * - the agent offers its 7 tools in v3's order, and not the 4 that DisabledTools lists
 * - the add_person defect (make('user')) is fixed
 *
 * Needs katalysis_pro with the P2.8 conversion: an older katalysis_pro fails here with the reason (KatalysisPro).
 * Nothing is written and nothing calls the AI provider.
 */
final class KatalysisProToolsTest extends ConcreteTestCase
{
    private const BASELINE = __DIR__ . '/../Support/katalysis-pro-tool-baseline-v3.json';

    /** The guidelines after P2.8 (decision D3): the first sentence of v3's. */
    private const GUIDELINES = 'Use these tools to manage Katalysis Pro entities.';

    /**
     * Tools whose definition was changed on purpose after the v3 capture, with the task and the reason (as
     * ToolBaseline::DELIBERATE_CHANGES for the core tools). Each must really differ from the capture.
     *
     * @var array<string, string> tool name => reason
     */
    private const DELIBERATE_CHANGES = [
        'list_people' => 'BF-6: offset input; the result says total, more and how to get the next people',
        'list_places' => 'BF-6: offset input; the result says total, more and how to get the next places',
        'list_reviews' => 'BF-6: offset input; the result says total, more and how to get the next reviews',
    ];

    /** @var array<string, mixed>|null */
    private ?array $originalFactories = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!KatalysisPro::isInstalled()) {
            $this->markTestSkipped('katalysis_pro is not installed.');
        }
        $property = new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories');
        $this->originalFactories = $property->getValue();
        $property->setValue(null, []);
        KatalysisPro::register();
    }

    protected function tearDown(): void
    {
        if ($this->originalFactories !== null) {
            (new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories'))->setValue(null, $this->originalFactories);
            $this->originalFactories = null;
        }
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    /**
     * @return list<ToolDefinition>
     */
    private function definitions(): array
    {
        $class = KatalysisPro::TOOLKIT;

        return (new $class())->definitions();
    }

    /**
     * @return array<string, mixed>
     */
    private function baseline(): array
    {
        return json_decode((string) file_get_contents(self::BASELINE), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheBaselineWasCapturedOnNeuron3(): void
    {
        $captured = $this->baseline()['captured'];

        $this->assertSame('development', $captured['branch']);
        $this->assertSame('3.15.26', $captured['neuron']);
    }

    public function testEveryToolIsDefinedAsOnNeuron3(): void
    {
        $expected = $this->baseline()['tools'];
        $actual = array_map(static fn (ToolDefinition $d): array => ToolBaseline::tool($d->toNeuronTool()), $this->definitions());

        $this->assertSame(array_column($expected, 'name'), array_column($actual, 'name'), 'the same tools, in the same order');
        foreach ($expected as $i => $tool) {
            if (isset(self::DELIBERATE_CHANGES[$tool['name']])) {
                $this->assertNotSame($tool, $actual[$i], "{$tool['name']} is listed as changed but matches the v3 capture");
                continue;
            }
            $this->assertSame($tool, $actual[$i], $tool['name']);
        }
    }

    public function testEveryDeliberateChangeNamesATool(): void
    {
        $names = array_map(static fn (ToolDefinition $d): string => $d->name(), $this->definitions());

        foreach (self::DELIBERATE_CHANGES as $name => $reason) {
            $this->assertContains($name, $names);
            $this->assertNotSame('', trim($reason));
        }
    }

    public function testTheWriteToolsAreMarked(): void
    {
        $writes = array_values(array_map(
            static fn (ToolDefinition $d): string => $d->name(),
            array_filter($this->definitions(), static fn (ToolDefinition $d): bool => $d->isWrite())
        ));

        $this->assertSame(KatalysisPro::DISABLED, $writes);
    }

    public function testTheGuidelinesAreTheFirstSentenceOfV3s(): void
    {
        $class = KatalysisPro::TOOLKIT;

        $this->assertSame(self::GUIDELINES, $class::GUIDELINES);
        $this->assertStringStartsWith(self::GUIDELINES . ' ', (string) $this->baseline()['guidelines']);
        foreach (KatalysisPro::DISABLED as $name) {
            $this->assertStringNotContainsString($name, $class::GUIDELINES);
        }
    }

    public function testTheAgentOffersTheSevenToolsInV3sOrderAndNotTheDisabledOnes(): void
    {
        $agent = new ConcreteCmsAgent();
        $kit = null;
        foreach ($agent->getTools() as $item) {
            if ($item instanceof ToolkitInterface && str_contains((string) $item->guidelines(), self::GUIDELINES)) {
                $kit = $item;
            }
        }
        $this->assertNotNull($kit, "katalysis_pro's toolkit is not offered");

        $names = array_map(static fn ($tool): string => $tool->getName(), $kit->tools());
        $this->assertSame(KatalysisPro::OFFERED, $names);
        foreach ($kit->tools() as $tool) {
            $this->assertTrue($agent->toolResultScanner()->isWrapped($tool), $tool->getName() . ' is not covered by the scanner');
        }
    }

    public function testAddPersonChecksTheUserWithoutTheContainerError(): void
    {
        // A registered user who can't view the people dashboard page, so canWritePeople() reaches the user check.
        $user = $this->app()->make(UserInfoRepository::class)->getByUserName('neuron_editor');
        $this->assertNotNull($user, 'neuron_editor does not exist. Run the seed script first.');
        Request::getInstance()->setCustomRequestUser($user);
        $this->app()->make('cache/request')->flush();
        $dashboard = Page::getByPath('/dashboard/katalysis_pro/katalysis_people');
        $this->assertFalse($dashboard && !$dashboard->isError() && (new Checker($dashboard))->canViewPage(), 'neuron_editor must not manage people for this test');

        $addPerson = null;
        foreach ($this->definitions() as $definition) {
            if ($definition->name() === 'add_person') {
                $addPerson = $definition->toNeuronTool();
            }
        }
        $this->assertNotNull($addPerson);

        // An empty name fails after the user check and before anything is saved.
        $addPerson->setInputs(['name' => ''])->execute();

        $this->assertSame(['ok' => false, 'error' => 'Name is required.'], json_decode((string) $addPerson->getResult(), true));
    }
}
