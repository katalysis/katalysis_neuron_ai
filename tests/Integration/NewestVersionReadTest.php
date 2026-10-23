<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Area\Area;
use Concrete\Core\Area\ContainerArea;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Block\Traits\GetBlockToEditTrait;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Entity\Page\Container;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Container\ContainerBlockInstance;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Security\InstructionDetector;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\PromptPreview;
use Katalysis\NeuronAi\Tests\Support\TaskKeyFixture;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;

/**
 * BF-10: the assistant sees what the editor sees. The write tools change a page's newest version, and Concrete shows
 * editors that version; the read tools now read it by default too, say which version they show, and say when the
 * published version differs. BF-7's browser check 6 (a hand edit in edit mode in one tab, the chat in another) now works
 * on the first write: the read gives the block ID the newest version holds. Throwaway pages; no AI provider call.
 */
final class NewestVersionReadTest extends ConcreteTestCase
{
    private const HAND_EDIT = "<h2>Hand edited heading</h2>\r\n\r\n<p>Block one edited by hand.&nbsp; In edit mode.</p>\r\n";

    private ThrowawayPages $pages;

    private ?TaskKeyFixture $fixture = null;

    /** @var int[] */
    private array $chatIds = [];

    private bool $startedSession = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $service = ConcreteBoot::boot()->make(PackageService::class);
        $service->bootPackageEntityManager($service->getClass('katalysis_neuron_ai'));
    }

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
    }

    protected function tearDown(): void
    {
        if ($this->startedSession) {
            session_destroy();
            $this->startedSession = false;
        }
        $this->pages->restore();
        $this->fixture?->restore();
        $this->fixture = null;
        foreach ($this->chatIds as $id) {
            $this->db()->executeStatement('DELETE FROM KatalysisNeuronAiChats WHERE id = ?', [$id]);
        }
        $this->chatIds = [];
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * A published page with two Content blocks, then tab 1: the superuser hand-edits block 1 in edit mode (the block
     * edit dialog's path), which copies it into a new, unpublished version under a new ID.
     *
     * @return array{int, int, int, int} page ID, the old block 1 ID, its new ID, block 2's ID
     */
    private function handEditedPage(string $label): array
    {
        $cID = (int) $this->pages->create($label)->getCollectionID();
        [$b1, $b2] = $this->pages->blockIDs($cID);
        CacheLocal::flush();
        $editor = new class () {
            use GetBlockToEditTrait;
        };
        $page = Page::getByID($cID);
        $block = $editor->getBlockToEdit($page, Area::get($page, 'Main'), 'Main', $b1);
        $block->update(['content' => self::HAND_EDIT]);
        CacheLocal::flush();
        $newID = (int) $block->getBlockID();
        $this->assertNotSame($b1, $newID, 'the hand edit copied the block');

        return [$cID, $b1, $newID, $b2];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $output): array
    {
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, $output);

        return isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
    }

    private function versions(int $cID): array
    {
        return array_map('intval', $this->db()->fetchFirstColumn('SELECT cvID FROM CollectionVersions WHERE cID = ? ORDER BY cvID', [$cID]));
    }

    /**
     * @return int[] the block IDs a get_page_content result lists
     */
    private static function contentBlockIDs(array $result): array
    {
        return array_map(static fn (array $b): int => (int) $b['blockID'], $result['blocks']);
    }

    /**
     * @return int[] every blockID in an area snapshot (get_page, get_page_blocks, get_current_page_context)
     */
    private static function snapshotBlockIDs(array $areas): array
    {
        $ids = [];
        array_walk_recursive($areas, static function ($value, $key) use (&$ids): void {
            if ($key === 'blockID') {
                $ids[] = (int) $value;
            }
        });

        return $ids;
    }

    // ---- the two-tab case (BF-7 check 6) ------------------------------------------------------------------

    public function testTheTwoTabReadThenWriteWorksFirstTime(): void
    {
        [$cID, $old, $new] = $this->handEditedPage('Two tabs read');
        $published = $this->pages->state($cID)['blocks'];

        // Tab 2, the same user: read with no version, then write with the first block ID the read gave.
        $read = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $cID]));
        $this->assertContains($new, self::contentBlockIDs($read), 'the read shows the newest version');
        $this->assertNotContains($old, self::contentBlockIDs($read));
        $this->assertStringContainsString('Block one edited by hand.', $read['text']);

        $first = self::contentBlockIDs($read)[0];
        $write = ThrowawayPages::runTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $first,
            'value' => ['content' => "<h2>Hand edited heading</h2>\r\n\r\n<p><strong>Block one edited by hand.</strong>&nbsp; In edit mode.</p>\r\n"],
            'changeMode' => 'formatting_only',
        ]);

        $this->assertTrue(ToolKind::succeeded($write), $write);
        $this->assertCount(2, $this->versions($cID), 'the assistant wrote into the hand edit\'s draft');
        $live = $this->versions($cID)[0];
        $inLive = static fn (array $blocks): array => array_values(array_filter($blocks, static fn (array $b): bool => (int) $b['cvID'] === $live));
        $this->assertSame($inLive($published), $inLive($this->pages->state($cID)['blocks']), 'the published version is unchanged');
    }

    public function testTheTwoTabTurnThroughTheAgentHasOneWriteAndItSucceeds(): void
    {
        [$cID, , $new] = $this->handEditedPage('Two tabs agent');

        // The model reads with no version, then writes with the first block ID in what it read.
        $provider = new class (new ToolCallMessage(null, [new ToolCall('get_page_content', 'call_read', ['pageID' => $cID])])) extends FakeAIProvider {
            public int $pageID = 0;

            public function chat(Message ...$messages): ProviderResponse
            {
                if ($this->getCallCount() === 1) {
                    $read = null;
                    foreach ($messages as $message) {
                        if ($message instanceof ToolResultMessage) {
                            $result = $message->getToolCalls()[0]->getResult();
                            $read = json_decode(is_string($result) ? $result : $result->getText(), true);
                        }
                    }
                    $read = isset($read['data']) ? $read['data'] : $read;
                    $this->addResponses(
                        new ToolCallMessage(null, [new ToolCall('update_block_in_page_area', 'call_write', [
                            'pageID' => $this->pageID, 'areaHandle' => 'Main', 'blockID' => (int) ($read['blocks'][0]['blockID'] ?? 0),
                            'value' => ['content' => "<h2>Hand edited heading</h2>\r\n\r\n<p><strong>Block one edited by hand.</strong>&nbsp; In edit mode.</p>\r\n"],
                            'changeMode' => 'formatting_only',
                        ])]),
                        new AssistantMessage('Done.')
                    );
                }

                return parent::chat(...$messages);
            }
        };
        $provider->pageID = $cID;

        $chat = new Chat();
        $chat->setSessionId('neuron-test-bf10-tabs-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(USER_SUPER_ID);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        $agent = (new ConcreteCmsAgent())->setChatId((int) $chat->getId())->setAiProvider($provider);
        $this->assertSame('Done.', $agent->handleUserMessage('Make the first paragraph bold.'));

        $writes = [];
        foreach ($provider->getRecorded() as $record) {
            foreach ($record->messages as $message) {
                if ($message instanceof ToolResultMessage) {
                    foreach ($message->getToolCalls() as $call) {
                        if ($call->getName() === 'update_block_in_page_area') {
                            $result = $call->getResult();
                            $writes[(string) $call->getCallId()] = is_string($result) ? $result : $result->getText();
                        }
                    }
                }
            }
        }
        $this->assertCount(1, $writes, 'one write call');
        $this->assertTrue(ToolKind::succeeded((string) reset($writes)), (string) reset($writes));
        $this->assertStringContainsString('<strong>Block one edited by hand.</strong>', (string) $this->db()->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$new]));
        $this->assertCount(2, $this->versions($cID), 'the hand edit\'s draft, not a second one');
    }

    // ---- page reads -----------------------------------------------------------------------------------------

    public function testGetPageGetPageBlocksAndTheCurrentPageContextShowTheNewestVersion(): void
    {
        [$cID, $old, $new] = $this->handEditedPage('Page reads');

        $page = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page', ['pageID' => $cID]));
        $this->assertContains($new, self::snapshotBlockIDs($page['areas']), 'get_page');
        $this->assertNotContains($old, self::snapshotBlockIDs($page['areas']));

        $blocks = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page_blocks', ['pageID' => $cID]));
        $this->assertContains($new, self::snapshotBlockIDs($blocks['areas']), 'get_page_blocks');
        $this->assertSame('newest', $blocks['version']['shows'] ?? null);

        // The page the user is on, as the chat endpoint passes it (ScenarioRunner::setPageContext()).
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start(['use_cookies' => 0, 'use_only_cookies' => 0, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
            $this->startedSession = true;
        }
        $_SESSION['katalysis_neuron_ai_page_context'] = ['id' => $cID];
        $context = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_current_page_context', []));
        $this->assertContains($new, self::snapshotBlockIDs($context['areas']), 'get_current_page_context');
        $this->assertNotContains($old, self::snapshotBlockIDs($context['areas']));
        $this->assertSame('newest', $context['version']['shows'] ?? null);
    }

    public function testTheVersionBlockSaysWhichVersionAndWhetherThePublishedOneDiffers(): void
    {
        $same = (int) $this->pages->create('One version')->getCollectionID();
        $one = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $same]))['version'];
        [$published] = $this->versions($same);
        $this->assertSame('newest', $one['shows']);
        $this->assertSame($published, $one['versionID']);
        $this->assertSame('published', $one['status']);
        $this->assertSame($published, $one['publishedVersionID']);
        $this->assertStringContainsString('also the published version', $one['summary']);

        [$cID] = $this->handEditedPage('Two versions');
        [$live, $draft] = $this->versions($cID);
        $newest = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $cID]))['version'];
        $this->assertSame('newest', $newest['shows']);
        $this->assertSame($draft, $newest['versionID']);
        $this->assertSame('unpublished', $newest['status']);
        $this->assertSame($live, $newest['publishedVersionID']);
        $this->assertStringContainsString("Newest version ({$draft}, unpublished", $newest['summary']);
        $this->assertStringContainsString("Visitors see version {$live}", $newest['summary']);
        $this->assertSame('RECENT', $newest['versionHandle'], 'the existing key stays');

        $old = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $cID, 'version' => 'published']));
        $this->assertSame('published', $old['version']['shows']);
        $this->assertSame($live, $old['version']['versionID']);
        $this->assertSame($draft, $old['version']['newestVersionID']);
        $this->assertStringContainsString("The newest version ({$draft}, unpublished) has changes not shown here", $old['version']['summary']);
        $this->assertStringNotContainsString('Block one edited by hand.', $old['text']);

        foreach ([$one, $newest, $old['version']] as $version) {
            $this->assertSame([], (new InstructionDetector('katalysis-pro.test'))->scan($version), 'the wording never trips the injection detector');
        }
    }

    public function testThePublishedValueTheOldNamesAndAnUnknownValue(): void
    {
        [$cID, $old, $new] = $this->handEditedPage('Values');

        foreach (['published' => $old, 'active' => $old, 'newest' => $new, 'recent' => $new] as $value => $expected) {
            $read = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $cID, 'version' => $value]));
            $this->assertContains($expected, self::contentBlockIDs($read), $value);
        }

        $bad = ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $cID, 'version' => 'draft']);
        $this->assertFalse(ToolKind::succeeded($bad), $bad);
        $this->assertStringContainsString('"newest"', $bad);
        $this->assertStringContainsString('"published"', $bad);
    }

    public function testAUserWhoCannotViewVersionsGetsThePublishedVersionByDefault(): void
    {
        [$cID, $old] = $this->handEditedPage('No versions permission');
        $this->fixture = new TaskKeyFixture($this->app());
        $reader = $this->fixture->createUser('bf10-reader');
        ThrowawayPages::actAs($reader->getUserName());
        $checker = new \Concrete\Core\Permission\Checker(Page::getByID($cID, 'ACTIVE'));
        if (!$checker->canViewPage() || $checker->canViewPageVersions()) {
            $this->markTestSkipped('A plain registered user is expected to view /neuron-test pages but not their versions.');
        }

        $read = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $cID]));
        $this->assertContains($old, self::contentBlockIDs($read));
        $this->assertSame('published', $read['version']['shows']);
        $this->assertStringContainsString("can't view unpublished versions", $read['version']['summary']);

        $refused = ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $cID, 'version' => 'newest']);
        $this->assertFalse(ToolKind::succeeded($refused), $refused);
        $this->assertStringContainsString('Permission denied', $refused);
    }

    public function testAPageNeverPublishedIsReadByDefault(): void
    {
        ThrowawayPages::actAs('superuser');
        $template = PageTemplate::getByHandle('full');
        $page = Page::getByPath('/neuron-test')->add(PageType::getByHandle('page'), [
            'name' => 'Never published ' . bin2hex(random_bytes(3)),
            'cvIsApproved' => 0,
            'pTemplateID' => $template->getPageTemplateID(),
        ], $template);
        $cID = (int) $page->getCollectionID();
        $this->pages->adopt($cID);
        Page::getByID($cID, 'RECENT')->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => '<p>Not live yet.</p>']);
        CacheLocal::flush();

        $read = $this->json(ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $cID]));
        $this->assertStringContainsString('Not live yet.', $read['text']);
        $this->assertSame('unpublished', $read['version']['status']);
        $this->assertNull($read['version']['publishedVersionID']);

        $none = ThrowawayPages::runTool(new PagesToolkit(), 'get_page_content', ['pageID' => $cID, 'version' => 'published']);
        $this->assertFalse(ToolKind::succeeded($none), $none);
        $this->assertStringContainsString('no published version', $none);
    }

    // ---- the prompt's page snapshot -------------------------------------------------------------------------

    public function testThePromptSnapshotShowsTheNewestVersion(): void
    {
        [$cID] = $this->handEditedPage('Snapshot');
        // The snapshot shows block counts and attributes: a third block, in the newest version only.
        Page::getByID($cID, 'RECENT')->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => '<p>Block three, in the draft.</p>']);
        CacheLocal::flush();
        $prompt = PromptPreview::turn(Page::getByID($cID, 'ACTIVE'), 'Hello')['system'];
        $this->assertStringContainsString('Area "Main" has 3 block(s)', $prompt);
    }

    // ---- block reads ----------------------------------------------------------------------------------------

    public function testBlockReadsSayWhetherTheBlockIsInTheNewestVersion(): void
    {
        [$cID, $old, $new] = $this->handEditedPage('Block reads');
        [, $draft] = $this->versions($cID);

        foreach (['get_block_data' => ['pageID' => $cID], 'get_block_controller_data' => ['pageID' => $cID, 'areaHandle' => 'Main']] as $tool => $inputs) {
            $stale = ThrowawayPages::runTool(new BlocksToolkit(), $tool, ['blockID' => $old] + $inputs);
            $this->assertStringContainsString("Block {$old} is in the published version of this page, not in its newest version", $stale, $tool);
            $this->assertStringContainsString("use block {$new}", $stale, $tool);

            $current = ThrowawayPages::runTool(new BlocksToolkit(), $tool, ['blockID' => $new] + $inputs);
            $this->assertStringContainsString("in the newest version ({$draft}, unpublished)", $current, $tool);
            $this->assertStringContainsString('Hand edited heading', $current, $tool);
        }
    }

    public function testBlockListsShowTheNewestVersionByDefault(): void
    {
        [$cID, $old, $new] = $this->handEditedPage('Block lists');

        $byType = ThrowawayPages::runTool(new BlocksToolkit(), 'list_page_blocks_by_type', ['pageID' => $cID, 'blockTypeHandle' => 'content']);
        $this->assertMatchesRegularExpression("/\\| {$new} \\|/", $byType);
        $this->assertDoesNotMatchRegularExpression("/\\| {$old} \\|/", $byType);
        $published = ThrowawayPages::runTool(new BlocksToolkit(), 'list_page_blocks_by_type', ['pageID' => $cID, 'blockTypeHandle' => 'content', 'version' => 'published']);
        $this->assertMatchesRegularExpression("/\\| {$old} \\|/", $published);
        $this->assertDoesNotMatchRegularExpression("/\\| {$new} \\|/", $published);

        $search = ThrowawayPages::runTool(new BlocksToolkit(), 'search_blocks_by_type', ['blockTypeHandle' => 'content', 'limit' => 500]);
        $this->assertMatchesRegularExpression("/\\| {$new} \\|/", $search);
        $this->assertDoesNotMatchRegularExpression("/\\| {$old} \\|/", $search);
        $searchPublished = ThrowawayPages::runTool(new BlocksToolkit(), 'search_blocks_by_type', ['blockTypeHandle' => 'content', 'limit' => 500, 'version' => 'published']);
        $this->assertDoesNotMatchRegularExpression("/\\| {$new} \\|/", $searchPublished);
    }

    public function testContainerBlocksShowTheNewestVersionByDefault(): void
    {
        ThrowawayPages::actAs('superuser');
        $cID = (int) $this->pages->create('Container', [])->getCollectionID();
        $page = Page::getByID($cID);
        $container = $this->app()->make(EntityManagerInterface::class)->getRepository(Container::class)->findOneBy(['containerHandle' => 'row_two_column']);
        $this->assertNotNull($container, 'The row_two_column container does not exist');
        $containerBlock = $page->addBlock(BlockType::getByHandle('core_container'), 'Main', ['containerID' => $container->getContainerID()]);
        $containerBlock = Block::getByID($containerBlock->getBlockID(), $page, 'Main');
        $instance = $containerBlock->getController()->getContainerInstanceObject();
        $blockInstance = $this->app()->make(ContainerBlockInstance::class, ['block' => $containerBlock, 'instance' => $instance]);
        $column = (new ContainerArea($blockInstance, 'Column 1'))->getSubAreaObject($page);
        $nested = (int) $page->addBlock(BlockType::getByHandle('content'), $column, ['content' => '<p>Nested original.</p>'])->getBlockID();
        $containerID = (int) $containerBlock->getBlockID();

        // A hand edit of the nested block in edit mode.
        CacheLocal::flush();
        $page = Page::getByID($cID);
        $editor = new class () {
            use GetBlockToEditTrait;
        };
        $handle = (string) $this->db()->fetchOne('SELECT arHandle FROM CollectionVersionBlocks WHERE cID = ? AND bID = ?', [$cID, $nested]);
        $edited = $editor->getBlockToEdit($page, Area::get($page, $handle), $handle, $nested);
        $edited->update(['content' => '<p>Nested edited by hand.</p>']);
        CacheLocal::flush();
        $newNested = (int) $edited->getBlockID();
        $this->assertNotSame($nested, $newNested);

        $list = ThrowawayPages::runTool(new BlocksToolkit(), 'list_container_blocks', ['containerBlockID' => $containerID]);
        $this->assertMatchesRegularExpression("/\\| {$newNested} \\|/", $list);
        $this->assertDoesNotMatchRegularExpression("/\\| {$nested} \\|/", $list);
        $published = ThrowawayPages::runTool(new BlocksToolkit(), 'list_container_blocks', ['containerBlockID' => $containerID, 'version' => 'published']);
        $this->assertMatchesRegularExpression("/\\| {$nested} \\|/", $published);
        $this->assertDoesNotMatchRegularExpression("/\\| {$newNested} \\|/", $published);
    }
}
