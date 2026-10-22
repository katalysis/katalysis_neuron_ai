<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\PackageService;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Chat\ChatMessageStore;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;

/**
 * P2.5 (plans/P2.5.md §6, §8.2): a stored chat with two user messages in a row is repaired as it loads, before the
 * provider is called. Real turns of the agent, with Neuron's FakeAIProvider as the model: no AI provider call, no API key.
 *
 * The control test shows why: on Neuron 4's own store the same chat reaches the provider unrepaired, and the turn then
 * fails when it is committed (P2.1 findings §6.4), after a paid call in real use.
 */
final class AgentRepairBeforeProviderTest extends ConcreteTestCase
{
    /** @var int[] */
    private array $chatIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function tearDown(): void
    {
        foreach ($this->chatIds as $id) {
            $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
        }
        $this->chatIds = [];
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function newChat(): int
    {
        $chat = new Chat();
        $chat->setSessionId('neuron-test-p25-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(0);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        return (int) $chat->getId();
    }

    /**
     * @return Message[] a stored chat whose first turn failed on v3: its question has no reply
     */
    private static function brokenChat(): array
    {
        return [new UserMessage('First question.'), new UserMessage('Second question.'), new AssistantMessage('An answer.')];
    }

    /**
     * @param Message[] $messages
     * @return string[]
     */
    private static function roles(array $messages): array
    {
        return array_map(static fn (Message $m): string => $m->getRole(), $messages);
    }

    public function testTheProviderIsGivenTheRepairedChatAndTheTurnIsStored(): void
    {
        $chatId = $this->newChat();
        $store = new ChatMessageStore($this->db());
        foreach (self::brokenChat() as $message) {
            $store->append(ChatMessageStore::threadFor($chatId), $message);
        }
        $storedBefore = $this->db()->fetchAllAssociative('SELECT * FROM KatalysisNeuronAiChatMessages WHERE chatId = ? ORDER BY id', [$chatId]);

        $provider = new FakeAIProvider(new AssistantMessage('A second answer.'));
        $agent = (new ConcreteCmsAgent())->setChatId($chatId);
        $agent->setAiProvider($provider);

        $this->assertSame('A second answer.', $agent->handleUserMessage('Third question.'));

        $provider->assertCallCount(1);
        $sent = $provider->getRecorded()[0]->messages;
        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], self::roles($sent), 'the first and only request already holds the repaired chat');
        $this->assertStringContainsString('Recovered', (string) $sent[1]->getContent());
        (new HistoryTrimmer())->trim($sent, PHP_INT_MAX);

        $stored = $this->db()->fetchAllAssociative('SELECT * FROM KatalysisNeuronAiChatMessages WHERE chatId = ? ORDER BY id', [$chatId]);
        $this->assertCount(5, $stored, 'the turn is stored: its question and its reply');
        $this->assertSame($storedBefore, array_slice($stored, 0, 3), 'the rows that were there are unchanged');
        $this->assertSame(['Third question.', 'A second answer.'], array_map(
            static fn (array $row): string => json_decode((string) $row['content'], true)[0]['content'],
            array_slice($stored, 3)
        ));
    }

    public function testControlNeuronsOwnStoreSendsTheBrokenChatAndTheTurnFails(): void
    {
        $store = new InMemoryMessageStore();
        $thread = 'p25-control-' . bin2hex(random_bytes(4));
        foreach (self::brokenChat() as $message) {
            $store->append($thread, $message);
        }

        $provider = new FakeAIProvider(new AssistantMessage('A second answer.'));
        $agent = (new ConcreteCmsAgent())->setMessageStore($store)->setThreadId($thread)->setAiProvider($provider);

        $reply = $agent->handleUserMessage('Third question.');

        $provider->assertCallCount(1);
        $this->assertSame(['user', 'user', 'assistant', 'user'], self::roles($provider->getRecorded()[0]->messages), 'the provider was called with the broken chat');
        $this->assertSame('I ran into an error processing that request. Please try again.', $reply, 'and the turn failed after the call');
        $this->assertCount(3, $store->loadAll($thread), 'nothing was stored');
    }

    public function testSetChatIdBindsTheThreadAndTheStore(): void
    {
        $chatId = $this->newChat();
        $agent = (new ConcreteCmsAgent())->setChatId($chatId);

        $this->assertSame('chat-' . $chatId, $agent->getThreadId());
        $this->assertSame('chat-' . $chatId, $agent->getChatHistory()->getThreadId());
        $agent->getChatHistory()->addMessage(new UserMessage('Hello.'));
        $this->assertCount(1, (new ChatMessageStore($this->db()))->loadAll('chat-' . $chatId), 'the agent writes to the Chat row store');

        $this->assertNull((new ConcreteCmsAgent())->setChatId(null)->getThreadId(), 'no chat, no thread');
    }
}
