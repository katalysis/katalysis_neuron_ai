<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Area\Area;
use Concrete\Core\Block\Traits\GetBlockToEditTrait;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Page;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\ConcreteCmsAgent;
use Katalysis\NeuronAi\Entity\Chat;
use Katalysis\NeuronAi\Tests\Support\ConcreteBoot;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BF-7, Glyn's browser check 6: the same user in two tabs. Edit mode in tab 1, where a hand edit copied block X into
 * the new draft under a new ID; the chat in tab 2 then read the published version (block X) and asked to edit X. The
 * write tools edit the newest version, where X no longer is, and answered only "Block X not found", so the model
 * guessed at a lock or a permissions problem. A block ID the newest version doesn't hold now gets the exact reason
 * and the ID to use, nothing is changed, and the retry with that ID works. Throwaway pages; no AI provider call.
 */
final class StaleBlockIDTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;

    /** @var int[] */
    private array $chatIds = [];

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
        $this->pages->restore();
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
     * Tab 1: the superuser enters edit mode and edits block $bID by hand, as the block edit dialog does (a new draft,
     * the block copied into it under a new ID). Returns the new ID.
     */
    private function handEditInEditMode(int $cID, int $bID, string $html): int
    {
        $this->pages->checkOut($cID, ThrowawayPages::actAs('superuser'));
        CacheLocal::flush();
        $editor = new class () {
            use GetBlockToEditTrait;
        };
        $page = Page::getByID($cID);
        $block = $editor->getBlockToEdit($page, Area::get($page, 'Main'), 'Main', $bID);
        $block->update(['content' => $html]);
        CacheLocal::flush();

        return (int) $block->getBlockID();
    }

    private function html(int $bID): string
    {
        return trim((string) $this->db()->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$bID]));
    }

    /**
     * @return array<string, array{string, callable(int, int, string): array<string, mixed>}>
     */
    public static function blockToolProvider(): array
    {
        $bold = static fn (string $html): string => (string) preg_replace('/^<p>(.*)<\/p>$/s', '<p><strong>$1</strong></p>', $html);

        return [
            'update_block_in_page_area' => ['update_block_in_page_area', static fn (int $cID, int $bID, string $html): array => [
                'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['content' => $bold($html)], 'changeMode' => 'formatting_only',
            ]],
            'delete_block_from_page_area' => ['delete_block_from_page_area', static fn (int $cID, int $bID, string $html): array => [
                'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            ]],
            'move_block_between_areas' => ['move_block_between_areas', static fn (int $cID, int $bID, string $html): array => [
                'pageID' => $cID, 'fromAreaHandle' => 'Main', 'toAreaHandle' => 'Sidebar', 'blockID' => $bID,
            ]],
            'batch_update_page_content' => ['batch_update_page_content', static fn (int $cID, int $bID, string $html): array => [
                'pageID' => $cID, 'changeMode' => 'formatting_only', 'blocks' => [['areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['content' => $bold($html)]]],
            ]],
        ];
    }

    #[DataProvider('blockToolProvider')]
    public function testABlockIDTheNewestVersionReplacedGetsTheReasonAndTheIDToUse(string $tool, callable $inputs): void
    {
        $cID = (int) $this->pages->create('Two tabs')->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        $newID = $this->handEditInEditMode($cID, $b1, '<p>Block one edited by hand.</p>');
        $this->assertNotSame($b1, $newID);
        $before = $this->pages->state($cID);

        // Tab 2, the same user: the model read the published version, so it names the old ID.
        $output = ThrowawayPages::runTool(new PagesToolkit(), $tool, $inputs($cID, $b1, '<p>Block one edited by hand.</p>'));

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertSame($before, $this->pages->state($cID), 'nothing changed');
        $this->assertStringContainsString("Block {$b1} is in the published version of this page, not in its newest version", $output);
        $this->assertStringContainsString("block {$newID}", $output, 'the ID to use instead');
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertStringNotContainsString('is editing this page', $output, 'the same user is not refused');

        // The retry with the new ID works, and keeps the hand edit.
        $retry = ThrowawayPages::runTool(new PagesToolkit(), $tool, $inputs($cID, $newID, '<p>Block one edited by hand.</p>'));
        $this->assertTrue(ToolKind::succeeded($retry), $retry);
        if (in_array($tool, ['update_block_in_page_area', 'batch_update_page_content'], true)) {
            $this->assertSame('<p><strong>Block one edited by hand.</strong></p>', $this->html($this->pages->blockIDs($cID)[0]));
        }
        $this->assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ?', [$cID]), 'one draft, shared with edit mode');
    }

    public function testABlockInAnotherAreaIsNamedWithItsArea(): void
    {
        $cID = (int) $this->pages->create('Wrong area')->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        ThrowawayPages::actAs('superuser');

        $output = ThrowawayPages::runTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Sidebar', 'blockID' => $b1, 'value' => ['content' => '<p><strong>Block one original text.</strong></p>'], 'changeMode' => 'formatting_only',
        ]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString("Block {$b1} is in area 'Main' of this page, not 'Sidebar'", $output);
        $this->assertStringContainsString('Nothing was changed', $output);
    }

    public function testABlockThatIsNotOnThePageSaysSo(): void
    {
        $cID = (int) $this->pages->create('Not on page')->getCollectionID();
        $other = (int) $this->pages->create('Other page')->getCollectionID();
        [$elsewhere] = $this->pages->blockIDs($other);
        ThrowawayPages::actAs('superuser');

        $output = ThrowawayPages::runTool(new PagesToolkit(), 'delete_block_from_page_area', ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $elsewhere]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString("Block {$elsewhere} is not on page {$cID}", $output);
        $this->assertStringContainsString('get_page_blocks', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
    }

    /**
     * Check 6 through the agent: the stale ID first, then the ID the error gave. The edit lands in the draft that holds
     * the hand edit, and the turn reports it.
     */
    public function testTheTwoTabTurnSucceedsOnTheRetry(): void
    {
        $cID = (int) $this->pages->create('Two tabs, agent')->getCollectionID();
        [$b1] = $this->pages->blockIDs($cID);
        $newID = $this->handEditInEditMode($cID, $b1, '<p>Block one edited by hand.</p>');
        $call = static fn (int $bID): ToolCallMessage => new ToolCallMessage(null, [new ToolCall('update_block_in_page_area', 'call_' . bin2hex(random_bytes(3)), [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => '<p><strong>Block one edited by hand.</strong></p>'], 'changeMode' => 'formatting_only',
        ])]);

        $chat = new Chat();
        $chat->setSessionId('neuron-test-bf7-tabs-' . bin2hex(random_bytes(4)));
        $chat->setStarted(new \DateTime());
        $chat->setCreatedDate(new \DateTime());
        $chat->setCreatedBy(USER_SUPER_ID);
        $em = $this->app()->make(EntityManagerInterface::class);
        $em->persist($chat);
        $em->flush();
        $this->chatIds[] = (int) $chat->getId();

        $agent = (new ConcreteCmsAgent())->setChatId((int) $chat->getId())
            ->setAiProvider(new FakeAIProvider($call($b1), $call($newID), new AssistantMessage('Done.')));
        $this->assertSame('Done.', $agent->handleUserMessage('Make the first paragraph bold.'));

        $this->assertSame('<p><strong>Block one edited by hand.</strong></p>', $this->html($this->pages->blockIDs($cID)[0]));
        $versions = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ?', [$cID]);
        $this->assertSame(2, $versions, 'the assistant edited the draft the hand edit made, not a second one');
        $this->assertSame($cID, $agent->getTurnChanges()[0]['pageID'] ?? null);
    }
}
