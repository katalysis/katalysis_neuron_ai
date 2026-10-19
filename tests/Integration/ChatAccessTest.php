<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\KatalysisNeuronAi\Controller\Chat;
use Concrete\Package\KatalysisNeuronAi\Controller\Diagnostics;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P1.7: the chat endpoints refuse users who can't use the assistant (B-ACC-1) and
 * refuse POSTs without a valid CSRF token.
 *
 * Every refusal here happens before any work, so no test reaches the AI provider
 * or needs an API key. The "allowed" path is checked through ChatAccess only,
 * because calling send_message as an allowed user would call the provider.
 *
 * Needs the seeded dev site: the non-admin user `neuron_editor` (seed_test_content.php).
 */
final class ChatAccessTest extends ConcreteTestCase
{
    private const POST_ACTIONS = ['send_message', 'new_chat', 'delete_chat', 'delete_current_chat'];
    private const GET_ACTIONS = ['chat_list', 'load', 'current'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Concrete's router finds package controllers by path; here they are loaded directly.
        $packageDir = dirname(__DIR__, 2);
        require_once $packageDir . '/controllers/chat.php';
        require_once $packageDir . '/controllers/diagnostics.php';
    }

    protected function tearDown(): void
    {
        $request = Request::getInstance();
        $request->headers->remove(ChatAccess::TOKEN_HEADER);
        $request->setCustomRequestUser(-1);
    }

    private function actAs(string $who): void
    {
        if ($who === 'guest') {
            Request::getInstance()->setCustomRequestUser(-1);

            return;
        }

        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
    }

    private function sendToken(?string $token): void
    {
        $headers = Request::getInstance()->headers;
        if ($token === null) {
            $headers->remove(ChatAccess::TOKEN_HEADER);
        } else {
            $headers->set(ChatAccess::TOKEN_HEADER, $token);
        }
    }

    /**
     * @return array{int, array<string, mixed>} status code and decoded JSON body
     */
    private function call(object $controller, string $action): array
    {
        $response = $controller->$action();
        $this->assertNotNull($response, "{$action} returned nothing; a refusal must be a response.");

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
    }

    public function testGuestAndUserWithoutAccessAreRefusedByTheAccessCheck(): void
    {
        $this->actAs('guest');
        $this->assertFalse(ChatAccess::canUse());

        $this->actAs('neuron_editor');
        $this->assertFalse(ChatAccess::canUse(), 'neuron_editor must not be able to use the assistant.');

        $this->actAs('superuser');
        $this->assertTrue(ChatAccess::canUse());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function noAccessProvider(): array
    {
        $cases = [];
        foreach (['guest', 'neuron_editor'] as $who) {
            foreach (array_merge(self::POST_ACTIONS, self::GET_ACTIONS) as $action) {
                $cases["{$who}: {$action}"] = [$who, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('noAccessProvider')]
    public function testEveryChatActionRefusesAUserWithoutAccess(string $who, string $action): void
    {
        $this->actAs($who);
        // Even a token that would be valid for this user must not help.
        $this->sendToken(ChatAccess::generateToken());

        [$status, $body] = $this->call(new Chat(), $action);

        $this->assertSame(403, $status);
        $this->assertFalse($body['success'] ?? null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function whoProvider(): array
    {
        return ['guest' => ['guest'], 'neuron_editor' => ['neuron_editor']];
    }

    #[DataProvider('whoProvider')]
    public function testDiagnosticsRefusesAUserWithoutAccess(string $who): void
    {
        $this->actAs($who);

        [$status, $body] = $this->call(new Diagnostics(), 'status');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success'] ?? null);
    }

    public function testSendMessageRefusesALoggedInUserWithoutAccess(): void
    {
        // The P1.7 item 5 check, spelled out: a logged-in user who can't use the assistant is refused.
        $this->actAs('neuron_editor');

        [$status, $body] = $this->call(new Chat(), 'send_message');

        $this->assertSame(403, $status);
        $this->assertFalse($body['success']);
        $this->assertSame('Access denied.', $body['error']);
        $this->assertSame('access_denied', $body['code'], 'A missing right is not an expired token.');
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function badTokenProvider(): array
    {
        return [
            'no token' => ['no_token', null],
            'empty token' => ['empty_token', ''],
            'garbage' => ['garbage', 'not-a-token'],
            'right shape, wrong hash' => ['wrong_hash', time() . ':' . str_repeat('0', 32)],
            'token for another action' => ['other_action', null],
            'expired token' => ['expired', null],
        ];
    }

    private function tokenFor(string $kind, ?string $given): ?string
    {
        $service = $this->app()->make('token');

        return match ($kind) {
            'other_action' => $service->generate('some_other_action'),
            'expired' => $service->generate(ChatAccess::TOKEN_ACTION, time() - 90000),
            default => $given,
        };
    }

    #[DataProvider('badTokenProvider')]
    public function testEveryPostActionRefusesAMissingOrInvalidTokenEvenForASuperuser(string $kind, ?string $given): void
    {
        $this->actAs('superuser');

        foreach (self::POST_ACTIONS as $action) {
            $this->sendToken($this->tokenFor($kind, $given));

            [$status, $body] = $this->call(new Chat(), $action);

            $this->assertSame(403, $status, "{$action} ({$kind})");
            $this->assertFalse($body['success'] ?? null, "{$action} ({$kind})");
            $this->assertStringContainsString('token', (string) ($body['error'] ?? ''), "{$action} ({$kind})");
            // The panel keys its "session expired" message on this code.
            $this->assertSame(ChatAccess::TOKEN_ERROR_CODE, $body['code'] ?? null, "{$action} ({$kind})");
        }
    }

    public function testAValidTokenIsAcceptedForAUserWithAccess(): void
    {
        $this->actAs('superuser');

        $token = ChatAccess::generateToken();

        $this->assertNull(ChatAccess::refusalReason(true, $token));
        $this->assertNull(ChatAccess::refusalReason(false, null), 'GET endpoints need no token.');
    }

    public function testATokenIssuedToAnotherUserIsNotAccepted(): void
    {
        $this->actAs('neuron_editor');
        $editorToken = ChatAccess::generateToken();

        $this->actAs('superuser');

        $this->assertNotNull(ChatAccess::refusalReason(true, $editorToken));
    }
}
