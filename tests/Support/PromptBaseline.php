<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Page\Page;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\MessageMapper;

/**
 * What the model receives for one user message, in a form that is the same on Neuron 3 and 4 (P2.4).
 *
 * The v3 baseline (prompt-baseline-v3.json) was written by capture_prompt_baseline.php on `development` before P2.4;
 * PromptBaselineTest builds the same arrays on v4 from a real turn and compares them exactly. Only classes and methods
 * that exist with the same meaning on both versions are used here (the OpenAI MessageMapper, Message, UserMessage).
 *
 * The page's ID and the user's ID change when the seed is reset, so they become {PAGE_ID} and {USER_ID}, in the three
 * places they appear and nowhere else. Everything else is compared as it is.
 */
final class PromptBaseline
{
    public const FILE = __DIR__ . '/prompt-baseline-v3.json';

    /** The fixed inputs: a seeded page, a seeded user, one message. */
    public const PAGE_PATH = '/neuron-test/headings';
    public const USER_NAME = 'neuron_chat_editor';
    public const MESSAGE = 'Hello';

    /**
     * The page context as the chat endpoint passes it to setRuntimePageContext().
     *
     * @return array{id: int, path: string, title: string, url: string}
     */
    public static function context(Page $page): array
    {
        return [
            'id' => (int) $page->getCollectionID(),
            'path' => (string) $page->getCollectionPath(),
            'title' => (string) $page->getCollectionName(),
            'url' => '',
        ];
    }

    /**
     * One captured prompt: the system message and the user message as the OpenAI provider maps them, and the names
     * of the tools offered, in order.
     *
     * @param list<string> $toolNames
     * @return array{system: list<string>, systemMessage: array<string, mixed>, user: array<string, mixed>, tools: list<string>}
     */
    public static function part(string $system, string $userText, array $toolNames, int $pageId, int $userId): array
    {
        // Exactly what the provider sends: the system prompt as the first message, then the user's message.
        $mapped = json_decode((string) json_encode((new MessageMapper())->map([
            new Message(MessageRole::SYSTEM, $system),
            new UserMessage($userText),
        ])), true);

        $systemMessage = $mapped[0];
        $text = self::normalise((string) $systemMessage['content'][0]['text'], $pageId, $userId);
        // The text is kept once, as lines (readable in a diff); the message keeps its shape (role, blocks).
        $systemMessage['content'][0]['text'] = '(see system)';

        $user = $mapped[1];
        $user['content'][0]['text'] = self::normalise((string) $user['content'][0]['text'], $pageId, $userId);

        return [
            'system' => explode("\n", $text),
            'systemMessage' => $systemMessage,
            'user' => $user,
            'tools' => array_values($toolNames),
        ];
    }

    public static function normalise(string $text, int $pageId, int $userId): string
    {
        $text = (string) preg_replace('/^Current Page ID: ' . $pageId . '$/m', 'Current Page ID: {PAGE_ID}', $text);
        $text = (string) preg_replace('/^User ID: ' . $userId . '$/m', 'User ID: {USER_ID}', $text);

        // The page tag (PageContextTag) at the start of a user message.
        return str_replace(', ID ' . $pageId . '. Earlier messages', ', ID {PAGE_ID}. Earlier messages', $text);
    }
}
