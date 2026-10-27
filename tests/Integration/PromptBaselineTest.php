<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\KatalysisPro;
use Katalysis\NeuronAi\Tests\Support\PromptBaseline;
use Katalysis\NeuronAi\Tests\Support\PromptPreview;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;

/**
 * The system prompt, the tagged user message and the tools the model receives are exactly the recorded baseline
 * (tests/Support/prompt-baseline.json), so every prompt change is deliberate. P2.4 compared Neuron 4 with a Neuron 3
 * capture; P3.7-1, the first deliberate prompt change, replaced that capture with one taken after it, which takes in
 * the differences approved since (BF-5's snapshot heading, P2.8's katalysis_pro guidelines line).
 *
 * A real turn of the agent (handleUserMessage) with Neuron's FakeAIProvider as the model and an in-memory history: no
 * provider call, no API key, nothing written. The core toolkits alone, and with katalysis_pro.
 *
 * This is also the guard on ConcreteCmsAgent::resolveTools(), which overrides a Neuron internal to keep v3's
 * guidelines rendering (plan P2.4 §11.1): if Neuron changes or renames that method, its own headings come back and
 * this test fails. The JSON is never edited by hand to make the test pass. A task that changes the prompt, or the
 * seed's fixture page, recaptures it on purpose (capture_prompt_baseline.php), in its own commit with the reason.
 *
 * Needs the seeded dev site (/neuron-test/headings and neuron_chat_editor).
 */
final class PromptBaselineTest extends ConcreteTestCase
{
    /** @var array<string, callable> */
    private array $savedFactories = [];

    protected function setUp(): void
    {
        $factories = new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories');
        $this->savedFactories = $factories->getValue();
        $factories->setValue(null, []);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ExternalToolkitRegistry::class, 'factories'))->setValue(null, $this->savedFactories);
        Request::getInstance()->setCustomRequestUser(-1);
    }

    public function testTheCorePromptIsExactlyTheBaseline(): void
    {
        $baseline = json_decode((string) file_get_contents(PromptBaseline::FILE), true);
        $this->assertIsArray($baseline, 'prompt-baseline.json is missing or not JSON');

        $page = Page::getByPath(PromptBaseline::PAGE_PATH);
        if (!$page || $page->isError()) {
            $this->markTestSkipped('The dev site has no ' . PromptBaseline::PAGE_PATH . ' fixture. Run documentation/fixtures/seed_test_content.php first.');
        }
        $user = PromptPreview::actAs(PromptBaseline::USER_NAME);

        $turn = PromptPreview::turn($page, PromptBaseline::MESSAGE);

        $this->assertCount(1, $turn['messages'], 'a new chat: the provider gets the one user message');
        $actual = PromptBaseline::part(
            $turn['system'],
            (string) $turn['messages'][0]->getContent(),
            $turn['tools'],
            (int) $page->getCollectionID(),
            (int) $user->getUserID()
        );
        $expected = $baseline['core'];

        $this->assertSame($expected['tools'], $actual['tools'], 'the tools offered, in order');
        $this->assertSame($expected['user'], $actual['user'], 'the user message with its page tag');
        $this->assertSame($expected['systemMessage'], $actual['systemMessage'], 'the system message\'s shape');
        // Line by line, so a failure shows which lines differ. If only the page lines differ, the fixture page changed.
        $this->assertSame(
            implode("\n", $expected['system']),
            implode("\n", $actual['system']),
            'the system prompt must be byte-identical to the baseline (Current Page lines differ? the seed fixture changed; a prompt change? recapture on purpose)'
        );
    }

    /**
     * P2.8: the prompt with katalysis_pro's tools (registered through its ToolDefinitions, as its controller does) is the
     * baseline's withExternal part.
     */
    public function testThePromptWithKatalysisProIsExactlyTheBaseline(): void
    {
        $baseline = json_decode((string) file_get_contents(PromptBaseline::FILE), true);
        $this->assertIsArray($baseline, 'prompt-baseline.json is missing or not JSON');
        $page = Page::getByPath(PromptBaseline::PAGE_PATH);
        if (!$page || $page->isError()) {
            $this->markTestSkipped('The dev site has no ' . PromptBaseline::PAGE_PATH . ' fixture. Run documentation/fixtures/seed_test_content.php first.');
        }
        if (!KatalysisPro::isInstalled()) {
            $this->markTestSkipped('katalysis_pro is not installed.');
        }
        KatalysisPro::register();
        $user = PromptPreview::actAs(PromptBaseline::USER_NAME);

        $turn = PromptPreview::turn($page, PromptBaseline::MESSAGE);

        $actual = PromptBaseline::part(
            $turn['system'],
            (string) $turn['messages'][0]->getContent(),
            $turn['tools'],
            (int) $page->getCollectionID(),
            (int) $user->getUserID()
        );
        $expected = $baseline['withExternal'];

        $this->assertSame($expected['tools'], $actual['tools'], 'the tools offered, in order');
        $this->assertSame($expected['user'], $actual['user'], 'the user message with its page tag');
        $this->assertSame($expected['systemMessage'], $actual['systemMessage'], 'the system message\'s shape');

        $this->assertSame(
            implode("\n", $expected['system']),
            implode("\n", $actual['system']),
            'the system prompt with katalysis_pro must be byte-identical to the baseline (a prompt change? recapture on purpose)'
        );
    }
}
