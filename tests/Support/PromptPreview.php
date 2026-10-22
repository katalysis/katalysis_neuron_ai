<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Security\SecretRedactor;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

/**
 * Shows what the model would receive for one user message on a given page, as a given user (P2.4, show_prompt.php).
 *
 * It runs one real turn of the agent with Neuron's FakeAIProvider in place of the model, and an in-memory history: no
 * provider call, no API key read (the real provider is never built), nothing written to the database. PromptBaselineTest
 * uses the same turn, so the script prints exactly what that test checks.
 *
 * Dev tooling under tests/, not part of the shipped package. It refuses to run unless the site is a local dev site,
 * and everything it prints goes through SecretRedactor.
 */
final class PromptPreview
{
    public const REPLY = '(prompt preview: no model was called)';

    /**
     * Why the script must not run on this site, or null when it may.
     */
    public static function refusal(string $canonicalUrl): ?string
    {
        if (ConcreteBoot::isDevHost($canonicalUrl)) {
            return null;
        }

        return 'Refusing to run: the canonical URL "' . $canonicalUrl . '" is not a .test/.localhost/localhost dev host.';
    }

    /**
     * The options after "--": --path=, --user=, --message=. Returns an error message for anything else.
     *
     * @param list<string> $args
     * @return array{path: string, user: string, message: string}|string
     */
    public static function options(array $args): array|string
    {
        $options = ['path' => PromptBaseline::PAGE_PATH, 'user' => PromptBaseline::USER_NAME, 'message' => PromptBaseline::MESSAGE];
        foreach ($args as $arg) {
            if (preg_match('/^--(path|user|message)=(.+)$/s', $arg, $m) === 1) {
                $options[$m[1]] = $m[2];
            } else {
                return 'Unknown or invalid option "' . $arg . '". Options (after "--"): --path=/page/path  --user=username  --message="text"';
            }
        }

        return $options;
    }

    /**
     * Makes $userName the request's user, in memory (no login, no session). Undo with Request::setCustomRequestUser(-1).
     */
    public static function actAs(string $userName): UserInfo
    {
        $app = Application::getFacadeApplication();
        $info = $app->make(UserInfoRepository::class)->getByUserName($userName);
        if ($info === null) {
            throw new \InvalidArgumentException("There is no user \"{$userName}\".");
        }
        Request::getInstance()->setCustomRequestUser($info);
        // Permission answers are cached for the request.
        $app->make('cache/request')->flush();

        return $info;
    }

    /**
     * One turn of the real agent, as the chat endpoint runs it (handleUserMessage), with the fake model.
     *
     * @return array{system: string, systemBlocks: int, messages: list<\NeuronAI\Chat\Messages\Message>, tools: list<string>}
     */
    public static function turn(Page $page, string $message): array
    {
        $provider = new FakeAIProvider(new AssistantMessage(self::REPLY));
        $agent = (new ConcreteCmsAgent())
            ->setMessageStore(new InMemoryMessageStore())
            ->setThreadId('prompt-preview-' . bin2hex(random_bytes(4)))
            ->setAiProvider($provider);
        $agent->setRuntimePageContext(PromptBaseline::context($page));

        $agent->handleUserMessage($message);

        $request = $provider->getRecorded()[0];

        return [
            'system' => (string) $request->systemPrompt?->getContent(),
            'systemBlocks' => count($request->systemPrompt?->getContentBlocks() ?? []),
            'messages' => array_values($request->messages),
            'tools' => array_values(array_map(static fn ($tool): string => $tool->getName(), $request->tools)),
        ];
    }

    /**
     * The turn as text for a person to read, with every secret removed (configured API keys and key-shaped strings).
     *
     * @param array{system: string, messages: list<\NeuronAI\Chat\Messages\Message>, tools: list<string>} $turn
     * @param array<int, string>|null $secrets for tests; null reads the configured API keys
     */
    public static function render(array $turn, ?array $secrets = null): string
    {
        $out = "===== SYSTEM PROMPT =====\n" . $turn['system'] . "\n";
        foreach ($turn['messages'] as $message) {
            $out .= "\n===== " . strtoupper((string) $message->getRole()) . " MESSAGE =====\n" . (string) $message->getContent() . "\n";
        }
        $out .= "\n===== TOOLS OFFERED (" . count($turn['tools']) . ") =====\n" . implode(', ', $turn['tools']) . "\n";

        return SecretRedactor::redact($out, $secrets);
    }
}
