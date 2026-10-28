<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\PageGrantFixture;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\ToolKind;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P3.5-1 (B-ACC-4, B-ACC-6), the Pages, Blocks and Versions half: every write tool checks Concrete's permission on the
 * object it changes, as the user, and refuses with the permission and who can grant it, changing nothing. The 12 Blocks
 * and Versions tools that never went through guarded() refuse a guest and report a refusal as {ok: false}.
 *
 * Tools are called by name (ToolByName), so the tests survive P3.3-4's split. neuron_editor holds the seed's
 * /neuron-test rights; PageGrantFixture takes one away on a throwaway page. Throwaway pages, deleted again.
 */
final class PagesBlocksVersionsPermissionTest extends ConcreteTestCase
{
    private ThrowawayPages $pages;
    private PageGrantFixture $grants;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->db());
        $this->grants = new PageGrantFixture($this->app());
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
        $this->grants->restore();
        $this->app()->make('cache/request')->flush();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function asGuest(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
        $this->app()->make('cache/request')->flush();
        CacheLocal::flush();
    }

    private function assertRefusedFor(string $output, string $permission, string $tool): void
    {
        $this->assertFalse(ToolKind::succeeded($output), "{$tool} did not refuse: {$output}");
        $this->assertStringContainsString('Permission denied', $output, $tool);
        $this->assertStringContainsString($permission, $output, "{$tool} does not name the permission: {$output}");
        $this->assertStringContainsString('site administrator', $output, "{$tool} does not say who can grant it: {$output}");
    }

    // ---- Writes: one case per tool --------------------------------------------------------------------------------

    /**
     * @return array<string, array{string, ?string, string}> case => [tool, the key taken away (null: the editor never
     *         has it), the permission's display name]
     */
    public static function writeProvider(): array
    {
        return [
            'batch_update_page_content' => ['batch_update_page_content', 'edit_page_contents', 'Edit Contents'],
            'batch_update_content_headings' => ['batch_update_content_headings', 'edit_page_contents', 'Edit Contents'],
            'sync_multi_links_to_epra_resources_list' => ['sync_multi_links_to_epra_resources_list', 'edit_page_contents', 'Edit Contents'],
            'add_block_to_page_area' => ['add_block_to_page_area', 'edit_page_contents', 'Edit Contents'],
            'update_block_in_page_area' => ['update_block_in_page_area', 'edit_page_contents', 'Edit Contents'],
            'delete_block_from_page_area' => ['delete_block_from_page_area', 'edit_page_contents', 'Edit Contents'],
            'move_block_between_areas' => ['move_block_between_areas', 'edit_page_contents', 'Edit Contents'],
            'create_page_draft' => ['create_page_draft', 'edit_page_contents', 'Edit Contents'],
            'update_page (name)' => ['update_page', 'edit_page_properties', 'Edit Properties'],
            'update_page (template)' => ['update_page', null, 'Change Page Template'],
            'set_page_attribute_value' => ['set_page_attribute_value', 'edit_page_properties', 'Edit Properties'],
            'create_page' => ['create_page', 'add_subpage', 'Add Sub-Page'],
            'move_page' => ['move_page', null, 'Move or Copy Page'],
            'delete_page' => ['delete_page', null, '"Delete"'],
            'approve_page_version' => ['approve_page_version', 'approve_page_versions', 'Approve Changes'],
            'discard_draft_version (another user\'s draft)' => ['discard_draft_version', null, 'Delete Versions'],
        ];
    }

    #[DataProvider('writeProvider')]
    public function testTheWriteIsRefusedWithThePermissionAndChangesNothing(string $tool, ?string $takeAway, string $permission): void
    {
        $page = $this->pages->create('Permission ' . $tool);
        $cID = (int) $page->getCollectionID();
        $other = (int) $this->pages->create('Permission other')->getCollectionID();
        if ($tool === 'discard_draft_version') {
            // A draft the super user made: the editor may discard their own, not someone else's.
            Page::getByID($cID)->getVersionToModify();
            CacheLocal::flush();
        }
        if ($takeAway !== null) {
            $this->grants->takeAway($cID, $takeAway);
        }
        $before = $this->pages->state($cID);

        ThrowawayPages::actAs('neuron_editor');
        $output = ToolByName::run($tool, $this->inputs($tool, $cID, $other, $permission));

        $this->assertRefusedFor($output, $permission, $tool);
        $this->assertSame($before, $this->pages->state($cID), "{$tool} changed the page it was refused.");
    }

    public function testTheEditorMayDiscardTheirOwnDraft(): void
    {
        $cID = (int) $this->pages->create('Own draft')->getCollectionID();
        ThrowawayPages::actAs('neuron_editor');
        $draft = Page::getByID($cID)->getVersionToModify();
        CacheLocal::flush();

        $output = ToolByName::run('discard_draft_version', ['pageID' => $cID, 'versionID' => (int) $draft->getVersionID()]);

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertStringContainsString('Draft Version Discarded', $output);
    }

    // ---- B-ACC-6: no other route ----------------------------------------------------------------------------------

    public function testARefusedBlockEditIsNotReachedByAnotherBlockTool(): void
    {
        $cID = (int) $this->pages->create('No other route')->getCollectionID();
        $this->grants->takeAway($cID, 'edit_page_contents');
        $before = $this->pages->state($cID);
        ThrowawayPages::actAs('neuron_editor');

        foreach (['update_block_in_page_area', 'batch_update_page_content', 'move_block_between_areas', 'add_block_to_page_area', 'delete_block_from_page_area', 'create_page_draft'] as $tool) {
            $this->assertRefusedFor(ToolByName::run($tool, $this->inputs($tool, $cID, 0, 'Edit Contents')), 'Edit Contents', $tool);
        }
        $this->assertSame($before, $this->pages->state($cID));
    }

    public function testARefusedAttributeIsNotReachedThroughUpdatePage(): void
    {
        $cID = (int) $this->pages->create('No attribute route')->getCollectionID();
        $this->grants->takeAway($cID, 'edit_page_properties');
        $before = $this->pages->state($cID);
        ThrowawayPages::actAs('neuron_editor');

        $this->assertRefusedFor(ToolByName::run('set_page_attribute_value', ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'X']), 'Edit Properties', 'set_page_attribute_value');
        $this->assertRefusedFor(ToolByName::run('update_page', ['pageID' => $cID, 'attributes' => ['meta_title' => 'X']]), 'Edit Properties', 'update_page');
        $this->assertSame($before, $this->pages->state($cID));
    }

    public function testMovingAPageToTheTrashIsRefusedEvenForTheSuperUser(): void
    {
        $cID = (int) $this->pages->create('No trash route')->getCollectionID();
        $trash = Page::getByPath('/!trash');
        $before = $this->pages->state($cID);

        $output = ToolByName::run('move_page', ['pageID' => $cID, 'newParentID' => (int) $trash->getCollectionID()]);

        $this->assertFalse(ToolKind::succeeded($output), $output);
        $this->assertSame($before, $this->pages->state($cID));
    }

    // ---- The 12 Blocks and Versions tools: login, envelope, object checks -----------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function unguardedProvider(): array
    {
        $tools = ['count_blocks_by_type', 'get_block_controller_data', 'get_block_data', 'get_doc_download_files_on_page',
            'list_container_blocks', 'list_page_blocks_by_type', 'search_blocks_by_type', 'approve_page_version',
            'create_page_draft', 'discard_draft_version', 'get_version_content', 'list_page_versions'];

        return array_combine($tools, array_map(static fn (string $t): array => [$t], $tools));
    }

    #[DataProvider('unguardedProvider')]
    public function testAGuestIsRefused(string $tool): void
    {
        $cID = (int) $this->pages->create('Guest ' . $tool)->getCollectionID();
        $bID = $this->pages->blockIDs($cID)[0];
        $this->asGuest();

        $output = ToolByName::run($tool, $this->readInputs($tool, $cID, $bID));

        $this->assertFalse(ToolKind::succeeded($output), "{$tool} ran for a guest: {$output}");
        $this->assertStringContainsString('Authentication required', $output);
    }

    public function testVersionReadsNeedViewVersionsAndSayOkFalse(): void
    {
        $cID = (int) $this->pages->create('No versions')->getCollectionID();
        $this->grants->takeAway($cID, 'view_page_versions');
        ThrowawayPages::actAs('neuron_editor');

        foreach (['list_page_versions' => ['pageID' => $cID], 'get_version_content' => ['pageID' => $cID, 'versionID' => 1]] as $tool => $inputs) {
            $output = ToolByName::run($tool, $inputs);
            $this->assertRefusedFor($output, 'View Versions', $tool);
            $this->assertSame(false, json_decode($output, true)['ok'] ?? null, "{$tool}: {$output}");
        }
    }

    public function testBlockReadsRefuseABlockOnAPageTheUserCannotView(): void
    {
        $cID = (int) $this->pages->create('Hidden page')->getCollectionID();
        $bID = $this->pages->blockIDs($cID)[0];
        $this->grants->takeAway($cID, 'view_page');
        ThrowawayPages::actAs('neuron_editor');

        foreach (['get_block_data', 'get_block_controller_data', 'list_page_blocks_by_type', 'get_doc_download_files_on_page'] as $tool) {
            $output = ToolByName::run($tool, $this->readInputs($tool, $cID, $bID));
            $this->assertFalse(ToolKind::succeeded($output), "{$tool} read a page the user cannot view: {$output}");
            $this->assertStringNotContainsString('Block one original text', $output, $tool);
        }
    }

    public function testSearchShowsThePublishedVersionToAUserWhoCannotViewVersions(): void
    {
        $cID = (int) $this->pages->create('Search versions')->getCollectionID();
        // A newer, unpublished version with a block only it has.
        $draft = Page::getByID($cID)->getVersionToModify();
        $draftBlock = $draft->addBlock(BlockType::getByHandle('html'), 'Main', ['content' => '<p>Draft only</p>']);
        $draftBID = (int) $draftBlock->getBlockID();
        CacheLocal::flush();

        ThrowawayPages::actAs('superuser');
        $this->assertStringContainsString("| {$draftBID} |", ToolByName::run('search_blocks_by_type', ['blockTypeHandle' => 'html', 'limit' => 500]));

        $this->grants->takeAway($cID, 'view_page_versions');
        ThrowawayPages::actAs('neuron_editor');
        $output = ToolByName::run('search_blocks_by_type', ['blockTypeHandle' => 'html', 'limit' => 500]);
        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertStringNotContainsString("| {$draftBID} |", $output, 'the newest, unpublished version leaked to a user who cannot view versions (BF-6)');
    }

    public function testCountsLeaveOutPagesTheUserCannotView(): void
    {
        $cID = (int) $this->pages->create('Count hidden')->getCollectionID();
        $this->grants->takeAway($cID, 'view_page');
        ThrowawayPages::actAs('neuron_editor');
        $editorBefore = ToolByName::run('count_blocks_by_type', ['blockTypeHandle' => 'html']);

        ThrowawayPages::actAs('superuser');
        $superBefore = ToolByName::run('count_blocks_by_type', ['blockTypeHandle' => 'html']);
        Page::getByID($cID)->addBlock(BlockType::getByHandle('html'), 'Main', ['content' => '<p>Hidden html</p>']);
        CacheLocal::flush();
        $this->assertNotSame($superBefore, ToolByName::run('count_blocks_by_type', ['blockTypeHandle' => 'html']), 'the super user sees the new block');

        ThrowawayPages::actAs('neuron_editor');
        $this->assertSame($editorBefore, ToolByName::run('count_blocks_by_type', ['blockTypeHandle' => 'html']), 'a block on a page the editor cannot view was counted');
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /**
     * Inputs that get each write tool to its permission check on page $cID (block one of Main).
     *
     * @return array<string, mixed>
     */
    private function inputs(string $tool, int $cID, int $other, string $permission): array
    {
        $bID = $this->pages->blockIDs($cID)[0];
        $heading = ['content' => '<h2>Block one original text.</h2>'];

        return match ($tool) {
            'batch_update_page_content' => ['pageID' => $cID, 'changeMode' => 'formatting_only', 'blocks' => [['areaHandle' => 'Main', 'blockID' => $bID, 'value' => $heading]]],
            'batch_update_content_headings' => ['pageID' => $cID, 'headingTexts' => ['Block one original text.'], 'targetTag' => 'h2'],
            'sync_multi_links_to_epra_resources_list' => ['pageID' => $cID],
            'add_block_to_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'content', 'value' => ['content' => '<p>New block.</p>']],
            'update_block_in_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID, 'value' => $heading, 'changeMode' => 'formatting_only'],
            'delete_block_from_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID],
            'move_block_between_areas' => ['pageID' => $cID, 'fromAreaHandle' => 'Main', 'toAreaHandle' => 'Sidebar', 'blockID' => $bID],
            'create_page_draft' => ['pageID' => $cID],
            'update_page' => $permission === 'Change Page Template' ? ['pageID' => $cID, 'template' => 'full'] : ['pageID' => $cID, 'name' => 'Renamed by the assistant'],
            'set_page_attribute_value' => ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Changed by the assistant'],
            'create_page' => ['parentID' => $cID, 'name' => 'Child by the assistant'],
            'move_page' => ['pageID' => $cID, 'newParentID' => $other],
            'delete_page' => ['pageID' => $cID],
            'approve_page_version' => ['pageID' => $cID, 'versionID' => 1],
            'discard_draft_version' => ['pageID' => $cID, 'versionID' => (int) Page::getByID($cID, 'RECENT')->getVersionID()],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function readInputs(string $tool, int $cID, int $bID): array
    {
        return match ($tool) {
            'count_blocks_by_type' => ['blockTypeHandle' => 'content'],
            'get_block_controller_data', 'get_block_data' => ['blockID' => $bID, 'pageID' => $cID],
            'get_doc_download_files_on_page' => ['pageID' => $cID],
            'list_container_blocks' => ['containerBlockID' => $bID],
            'list_page_blocks_by_type' => ['pageID' => $cID, 'blockTypeHandle' => 'content'],
            'search_blocks_by_type' => ['blockTypeHandle' => 'content'],
            'approve_page_version', 'discard_draft_version', 'get_version_content' => ['pageID' => $cID, 'versionID' => 1],
            'create_page_draft', 'list_page_versions' => ['pageID' => $cID],
        };
    }
}
