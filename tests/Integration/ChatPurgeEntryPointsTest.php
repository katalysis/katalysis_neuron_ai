<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Http\Request;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\KatalysisNeuronAi\Controller\Chat;
use Concrete\Package\KatalysisNeuronAi\Controller\SinglePage\Dashboard\System\Ai\NeuronAi;
use Katalysis\NeuronAi\Access\ChatAccess;
use Katalysis\NeuronAi\Chat\ChatPurge;
use Katalysis\NeuronAi\Chat\PurgeChatsCommand;
use Katalysis\NeuronAi\Chat\PurgeChatsCommandHandler;
use Katalysis\NeuronAi\Settings\ApiKeySettings;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\InMemoryConfig;

/**
 * P3.9-2 (plans/P3.9-2.md): the three ways in to ChatPurge, each with a recording purge so no real chat is touched.
 *
 * - The settings page (Configure Neuron AI and its token): the first post only counts and shows the confirmation; the
 *   confirming post deletes up to the counted cutoff, no further.
 * - The panel's "Delete all my chats": the same two steps, for the user's own chats only.
 * - The automated task: purges by the retention setting.
 */
final class ChatPurgeEntryPointsTest extends ConcreteTestCase
{
    /** @var list<array{string, ?string, ?int}> the calls the recording purge got: method, cutoff, owner */
    private array $calls = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 2) . '/controllers/chat.php';
        require_once dirname(__DIR__, 2) . '/controllers/single_page/dashboard/system/ai/neuron_ai.php';
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function tearDown(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        parent::tearDown();
    }

    private function recordingPurge(): ChatPurge
    {
        $calls = &$this->calls;

        return new class ($calls) extends ChatPurge {
            /** @param list<array{string, ?string, ?int}> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function count(\DateTimeInterface $cutoff, ?int $ownerId = null): array
            {
                $this->calls[] = ['count', $cutoff->format('Y-m-d H:i:s'), $ownerId];

                return ['chats' => 3, 'messages' => 12, 'users' => 2, 'waiting' => 1];
            }

            public function purge(\DateTimeInterface $cutoff, ?int $ownerId = null, int $limit = self::BATCH): array
            {
                $this->calls[] = ['purge', $cutoff->format('Y-m-d H:i:s'), $ownerId];

                return ['chats' => 3, 'messages' => 12, 'approvals' => 1, 'runs' => 0, 'logs' => 5];
            }
        };
    }

    private function actAsSuperUser(): void
    {
        Request::getInstance()->setCustomRequestUser($this->app()->make(UserInfoRepository::class)->getByID(USER_SUPER_ID));
        $this->app()->make('cache/request')->flush();
    }

    private function settingsPage(): NeuronAi
    {
        InMemoryConfig::reset();
        $purge = $this->recordingPurge();
        $controller = new class (Page::getByPath('/dashboard/system/ai/neuron_ai'), new ApiKeySettings(InMemoryConfig::repository()), $purge) extends NeuronAi {
            public function __construct(Page $page, private readonly ApiKeySettings $testSettings, private readonly ChatPurge $testPurge)
            {
                parent::__construct($page);
            }

            protected function settings(): ApiKeySettings
            {
                return $this->testSettings;
            }

            protected function chatPurge(): ChatPurge
            {
                return $this->testPurge;
            }
        };
        $controller->setApplication($this->app());
        $controller->on_start();

        return $controller;
    }

    /**
     * @param array<string, string> $fields
     */
    private function postToSettings(string $action, array $fields): NeuronAi
    {
        Request::getInstance()->request->replace($fields + ['ccm_token' => $this->app()->make('token')->generate('ai.chat_purge')]);
        $controller = $this->settingsPage();
        $controller->$action();

        return $controller;
    }

    public function testTheSettingsPageCountsFirstAndDeletesOnlyWhenConfirmedUpToTheCountedCutoff(): void
    {
        $this->actAsSuperUser();

        $counted = $this->postToSettings('purge_chats', ['scope' => 'older']);
        $this->assertSame('count', $this->calls[0][0] ?? null);
        $this->assertCount(1, $this->calls, 'the first post deletes nothing');
        $confirmation = $counted->getSets()['purge_confirmation'] ?? null;
        $this->assertIsArray($confirmation);
        $this->assertSame(3, $confirmation['counts']['chats']);
        $this->assertSame(1, $confirmation['counts']['waiting'], 'it says a change is waiting');

        $this->calls = [];
        $this->postToSettings('purge_chats', ['scope' => 'older', 'confirm_cutoff' => $confirmation['cutoff']]);
        $this->assertSame([['purge', $confirmation['cutoff'], null]], $this->calls);
    }

    public function testTheSettingsPageRefusesWithoutConfigureNeuronAi(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);

        $this->postToSettings('purge_chats', ['scope' => 'all', 'confirm_cutoff' => '2026-10-30 12:00:00']);

        $this->assertSame([], $this->calls);
    }

    private function panel(): Chat
    {
        $purge = $this->recordingPurge();
        $controller = new class ($purge) extends Chat {
            public function __construct(private readonly ChatPurge $testPurge)
            {
                parent::__construct();
            }

            protected function chatPurge(): ChatPurge
            {
                return $this->testPurge;
            }
        };

        return $controller;
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function postToPanel(array $json): array
    {
        $request = Request::create('/ccm/system/katalysis_neuron_ai/chat/delete_all_mine', 'POST', [], [], [], [], (string) json_encode($json));
        $request->headers->set(ChatAccess::TOKEN_HEADER, ChatAccess::generateToken());
        $controller = $this->panel();
        $controller->setRequest($request);

        return json_decode((string) $controller->delete_all_mine()->getContent(), true) ?? [];
    }

    public function testDeleteAllMyChatsCountsFirstThenDeletesOnlyTheUsersOwn(): void
    {
        $this->actAsSuperUser();

        $first = $this->postToPanel([]);
        $this->assertSame(3, $first['count']['chats'] ?? null);
        $this->assertSame([['count', $first['cutoff'], USER_SUPER_ID]], $this->calls, 'counted, nothing deleted, the user\'s own chats only');

        $this->calls = [];
        $second = $this->postToPanel(['confirm_cutoff' => $first['cutoff']]);
        $this->assertTrue($second['deleted'] ?? false);
        $this->assertSame([['purge', $first['cutoff'], USER_SUPER_ID]], $this->calls);
    }

    public function testTheTaskPurgesByTheRetentionSetting(): void
    {
        $handler = new PurgeChatsCommandHandler($this->recordingPurge(), 30, new \DateTimeImmutable('2026-10-30 12:00:00'));

        $handler(new PurgeChatsCommand());

        $this->assertSame([['purge', '2026-09-30 12:00:00', null]], $this->calls);
    }
}
