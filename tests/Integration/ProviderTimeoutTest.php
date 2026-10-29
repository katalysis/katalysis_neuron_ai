<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Support\Facade\Config;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Chat\ChatMessageStore;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;

/**
 * BF-22: the OpenAI request times out after katalysis.ai.provider_timeout seconds (90 by default; one scenario run
 * waited 842 s on Neuron's 300 s default). A timeout fails the turn through the failed-turn path, and the reply says the
 * AI service didn't answer in time. No provider is called: the provider is only built, and the timeout is a fake one.
 */
final class ProviderTimeoutTest extends ConcreteTestCase
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
        Config::set(ConcreteCmsAgent::PROVIDER_TIMEOUT_KEY, null);
        foreach ($this->chatIds as $id) {
            $this->app()->make(Connection::class)->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
        }
        $this->chatIds = [];
        parent::tearDown();
    }

    /** The timeout on the HTTP client of the provider the agent builds (built, never called). */
    private static function clientTimeout(): float
    {
        $provider = (new \ReflectionMethod(ConcreteCmsAgent::class, 'provider'))->invoke(new ConcreteCmsAgent());
        $client = (new \ReflectionProperty($provider, 'httpClient'))->getValue($provider);

        return (float) (new \ReflectionProperty($client, 'timeout'))->getValue($client);
    }

    public function testTheProviderRequestTimesOutAfterTheConfiguredSeconds(): void
    {
        $this->assertSame(90.0, self::clientTimeout(), 'the default');

        Config::set(ConcreteCmsAgent::PROVIDER_TIMEOUT_KEY, 30);
        $this->assertSame(30.0, self::clientTimeout());

        Config::set(ConcreteCmsAgent::PROVIDER_TIMEOUT_KEY, 'no');
        $this->assertSame(90.0, self::clientTimeout(), 'a value that is not a positive number gets the default');
    }

    public function testATimeoutFailsTheTurnWithItsOwnReplyStoredLikeAnyFailedTurn(): void
    {
        $chat = new Chat();
        $chat->setSessionId('neuron-test-bf22-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(USER_SUPER_ID);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        $provider = new class () extends FakeAIProvider {
            public function chat(Message ...$messages): ProviderResponse
            {
                throw HttpException::networkError(
                    HttpRequest::post(uri: 'https://api.openai.com/v1/chat/completions', body: []),
                    'Operation timed out after 90001 milliseconds with 0 bytes received'
                );
            }
        };
        $agent = (new ConcreteCmsAgent())->setChatId((int) $chat->getId())->setAiProvider($provider);

        $this->assertSame(ConcreteCmsAgent::TIMEOUT_REPLY, $agent->handleUserMessage('Hello?'));

        $history = json_decode((new ChatMessageStore($this->app()->make(Connection::class)))->conversationJson(ChatMessageStore::threadFor((int) $chat->getId())), true);
        $last = end($history);
        $this->assertSame(ConcreteCmsAgent::TIMEOUT_REPLY, $last['content'][0]['content'] ?? $last['content'][0]['text'] ?? null, 'stored, so a reload shows it');
    }

    public function testAnyOtherFailureKeepsTheGenericReply(): void
    {
        $this->assertSame(ConcreteCmsAgent::ERROR_REPLY, ConcreteCmsAgent::failureReply(new \RuntimeException('The provider went away.')));
        $this->assertSame(ConcreteCmsAgent::TIMEOUT_REPLY, ConcreteCmsAgent::failureReply(new \RuntimeException('wrapped', 0, HttpException::networkError(
            HttpRequest::post(uri: 'https://api.openai.com/v1/chat/completions', body: []),
            'Operation timed out after 90001 milliseconds'
        ))));
    }
}
