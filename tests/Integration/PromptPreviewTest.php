<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Config;
use Katalysis\NeuronAi\Security\SecretRedactor;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\PromptBaseline;
use Katalysis\NeuronAi\Tests\Support\PromptPreview;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * P2.4: show_prompt.php (through PromptPreview) runs only on a dev site, never prints a secret, and prints the prompt
 * and the tagged user message of a real turn.
 *
 * The turn uses Neuron's FakeAIProvider and an in-memory history: no provider call, no API key, nothing written.
 * Needs the seeded dev site (/neuron-test/columns and neuron_chat_editor).
 */
final class PromptPreviewTest extends ConcreteTestCase
{
    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
    }

    public function testItRefusesASiteThatIsNotADevHost(): void
    {
        foreach (['https://www.katalysis.net/', 'http://example.com', '', 'https://katalysis-pro.test.example.com/'] as $url) {
            $this->assertNotNull(PromptPreview::refusal($url), "must refuse \"{$url}\"");
        }
        foreach (['http://katalysis-pro.test/', 'http://localhost:8000', 'https://site.localhost'] as $url) {
            $this->assertNull(PromptPreview::refusal($url), "may run on \"{$url}\"");
        }
    }

    public function testOptionsAreTheThreeKnownOnesOnly(): void
    {
        $this->assertSame(
            ['path' => '/neuron-test/columns', 'user' => 'admin', 'message' => 'What is here?'],
            PromptPreview::options(['--path=/neuron-test/columns', '--user=admin', '--message=What is here?'])
        );
        $this->assertSame(
            ['path' => PromptBaseline::PAGE_PATH, 'user' => PromptBaseline::USER_NAME, 'message' => PromptBaseline::MESSAGE],
            PromptPreview::options([])
        );
        $this->assertIsString(PromptPreview::options(['--key=1']), 'an unknown option is an error');
    }

    public function testWhatItPrintsHasNoSecrets(): void
    {
        $secret = 'phpunit-not-a-real-key-0123456789';
        $turn = [
            'system' => "Prompt with {$secret} and sk-proj-ABCDEF0123456789 inside.",
            'messages' => [new UserMessage('Bearer abcdef0123456789 in the message')],
            'tools' => ['get_page'],
        ];

        $printed = PromptPreview::render($turn, [$secret]);

        $this->assertStringNotContainsString($secret, $printed);
        $this->assertStringNotContainsString('sk-proj-ABCDEF', $printed);
        $this->assertStringNotContainsString('abcdef0123456789', $printed);
        $this->assertStringContainsString(SecretRedactor::REPLACEMENT, $printed);
    }

    public function testItPrintsTheRealTurnWithoutTheConfiguredKey(): void
    {
        $page = Page::getByPath('/neuron-test/columns');
        if (!$page || $page->isError()) {
            $this->markTestSkipped('The dev site has no /neuron-test/columns fixture. Run documentation/fixtures/seed_test_content.php first.');
        }
        PromptPreview::actAs(PromptBaseline::USER_NAME);

        $printed = PromptPreview::render(PromptPreview::turn($page, 'Hello'));

        $this->assertStringContainsString('Current Page Path: /neuron-test/columns', $printed);
        $this->assertStringContainsString('User: ' . PromptBaseline::USER_NAME, $printed);
        $this->assertStringContainsString('<TOOLS-GUIDELINES>', $printed);
        $this->assertStringContainsString('[Current page for this message: Columns, /neuron-test/columns, ID ' . $page->getCollectionID(), $printed);
        $this->assertStringContainsString("\n\nHello\n", $printed);
        $this->assertStringNotContainsString(PromptPreview::REPLY, $printed, 'only what the model is sent, not the fake reply');

        $key = trim((string) Config::get('katalysis.ai.open_ai_key', ''));
        if ($key !== '') {
            // assertFalse, not assertStringNotContainsString: a failure must not print the key either.
            $this->assertFalse(str_contains($printed, $key), 'the API key must never be printed');
        }
    }
}
