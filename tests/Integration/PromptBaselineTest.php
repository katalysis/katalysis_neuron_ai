<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\PromptBaseline;
use Katalysis\NeuronAi\Tests\Support\PromptPreview;
use Katalysis\NeuronAi\Tools\ExternalToolkitRegistry;

/**
 * P2.4: the system prompt and the tagged user message the model receives on Neuron 4 are exactly what it received on
 * Neuron 3 (tests/Support/prompt-baseline-v3.json, captured on `development` before P2.4).
 *
 * A real turn of the agent (handleUserMessage) with Neuron's FakeAIProvider as the model and an in-memory history: no
 * provider call, no API key, nothing written. Core toolkits only; the prompt with katalysis_pro is P2.8's comparison.
 *
 * This is also the guard on ConcreteCmsAgent::resolveTools(), which overrides a Neuron internal to keep v3's
 * guidelines rendering (plan P2.4 §11.1): if Neuron changes or renames that method, its own headings come back and
 * this test fails. The JSON is never edited to make the test pass. If the seed's fixture page changes, the baseline is
 * recaptured on purpose, in its own commit with the reason.
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

    public function testTheCorePromptIsExactlyTheV3Prompt(): void
    {
        $baseline = json_decode((string) file_get_contents(PromptBaseline::FILE), true);
        $this->assertIsArray($baseline, 'prompt-baseline-v3.json is missing or not JSON');
        $this->assertSame('3.15.26', $baseline['captured']['neuron'], 'the baseline must come from Neuron 3');

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
            'the system prompt must be byte-identical to v3 (Current Page lines differ? the seed fixture changed: recapture on purpose)'
        );
    }
}
