<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tests\Support\ToolByName;
use Katalysis\NeuronAi\Tools\AttributeSetsToolkit;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\FileAttributesToolkit;
use Katalysis\NeuronAi\Tools\FilesToolkit;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\PeopleToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;
use Katalysis\NeuronAi\Tools\TopicsToolkit;
use Katalysis\NeuronAi\Tools\UserAttributesToolkit;
use Katalysis\NeuronAi\Tools\UsersToolkit;
use Katalysis\NeuronAi\Tools\Utilities\EditModeGuard;
use Katalysis\NeuronAi\Tools\VersionsToolkit;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BF-7: a write is refused while another user has the page in edit mode (checked out within Concrete's checkout
 * timeout), as Concrete itself refuses them edit mode. The same user, in any tab, is allowed. And a block that the
 * user has open in an editor (the panel sends its ID) is never changed under them.
 *
 * One case per write tool and page input, with a registry-level check that every write tool with a page input is
 * covered or exempt with a reason, as SystemPageWriteRefusalTest does for system pages. The tools run as the
 * superuser: Concrete refuses edit mode to them too while someone else edits. Throwaway pages, deleted afterwards.
 */
final class EditModeWriteRefusalTest extends ConcreteTestCase
{
    /** The inputs that name a page (as SystemPageWriteRefusalTest). */
    private const PAGE_INPUTS = ['pageID', 'pagePath', 'pageName', 'parentID', 'newParentID'];

    private const NOT_PAGE_INPUTS = ['parentFolderID', 'parent_topic_id', 'pageType', 'createParents'];

    /** Page inputs that don't need the check, and why. */
    private const EXEMPT = [
        PagesToolkit::class . '::create_page' => ['parentID' => 'adding a child page does not change the parent\'s version'],
        PagesToolkit::class . '::move_page' => ['newParentID' => 'the new parent\'s version does not change'],
    ];

    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->app()->make(Connection::class));
    }

    protected function tearDown(): void
    {
        // The pages first, so a failure below can't leave them behind.
        $this->pages->restore();
        EditModeGuard::clearOpenBlocks();
    }

    /**
     * @return array<string, array{class-string<ToolkitInterface>, string, string}> case => [toolkit, tool, page input]
     */
    public static function writeToolProvider(): array
    {
        return [
            'batch_update_page_content' => [BatchEditToolkit::class, 'batch_update_page_content', 'pageID'],
            'batch_update_content_headings' => [BatchEditToolkit::class, 'batch_update_content_headings', 'pageID'],
            'update_page' => [PagesToolkit::class, 'update_page', 'pageID'],
            'move_page (page)' => [PagesToolkit::class, 'move_page', 'pageID'],
            'delete_page' => [PagesToolkit::class, 'delete_page', 'pageID'],
            'add_block_to_page_area' => [ContentToolkit::class, 'add_block_to_page_area', 'pageID'],
            'update_block_in_page_area' => [ContentToolkit::class, 'update_block_in_page_area', 'pageID'],
            'delete_block_from_page_area' => [ContentToolkit::class, 'delete_block_from_page_area', 'pageID'],
            'move_block_between_areas' => [ContentToolkit::class, 'move_block_between_areas', 'pageID'],
            'set_page_attribute_value, PageAttributesToolkit (pageID)' => [PageAttributesToolkit::class, 'set_page_attribute_value', 'pageID'],
            'set_page_attribute_value, PageAttributesToolkit (pagePath)' => [PageAttributesToolkit::class, 'set_page_attribute_value', 'pagePath'],
            'set_page_attribute_value, PageAttributesToolkit (pageName)' => [PageAttributesToolkit::class, 'set_page_attribute_value', 'pageName'],
            'approve_page_version' => [VersionsToolkit::class, 'approve_page_version', 'pageID'],
            'create_page_draft' => [VersionsToolkit::class, 'create_page_draft', 'pageID'],
            'discard_draft_version' => [VersionsToolkit::class, 'discard_draft_version', 'pageID'],
        ];
    }

    /**
     * @param class-string<ToolkitInterface> $toolkit
     */
    #[DataProvider('writeToolProvider')]
    public function testTheToolRefusesAPageAnotherUserIsEditing(string $toolkit, string $toolName, string $pageInput): void
    {
        $page = $this->pages->create('Edit mode');
        $cID = (int) $page->getCollectionID();
        $this->pages->checkOut($cID, ThrowawayPages::actAs('neuron_editor'));
        $before = $this->pages->state($cID);

        ThrowawayPages::actAs('superuser');
        $output = ThrowawayPages::runTool(new $toolkit(), $toolName, $this->inputs($toolName, $pageInput, $cID));

        $this->assertSame($before, $this->pages->state($cID), "{$toolName} changed a page another user is editing.");
        $this->assertStringContainsString('neuron_editor is editing this page in edit mode', $output, "{$toolName} did not refuse. It returned: {$output}");
        $this->assertStringContainsString('Nothing was changed', $output);
    }

    /**
     * The same user (in another tab, or on another page) is allowed: the tools that write to a draft succeed.
     *
     * @return array<string, array{class-string<ToolkitInterface>, string}>
     */
    public static function sameUserProvider(): array
    {
        return [
            'update_block_in_page_area' => [ContentToolkit::class, 'update_block_in_page_area'],
            'update_page' => [PagesToolkit::class, 'update_page'],
            'set_page_attribute_value' => [PageAttributesToolkit::class, 'set_page_attribute_value'],
        ];
    }

    /**
     * @param class-string<ToolkitInterface> $toolkit
     */
    #[DataProvider('sameUserProvider')]
    public function testTheSameUserInEditModeIsAllowed(string $toolkit, string $toolName): void
    {
        $page = $this->pages->create('Same user');
        $cID = (int) $page->getCollectionID();
        $this->pages->checkOut($cID, ThrowawayPages::actAs('superuser'));

        $output = ThrowawayPages::runTool(new $toolkit(), $toolName, $this->workingInputs($toolName, $cID));

        $this->assertTrue(ToolKind::succeeded($output), "{$toolName} failed for the user who is editing: {$output}");
        $this->assertStringNotContainsString('is editing this page', $output);
    }

    public function testACheckoutOlderThanTheTimeoutDoesNotCountAndIsLeftAsItWas(): void
    {
        $page = $this->pages->create('Stale checkout');
        $cID = (int) $page->getCollectionID();
        $this->pages->checkOut($cID, ThrowawayPages::actAs('neuron_editor'), CHECKOUT_TIMEOUT + 60);
        $checkout = $this->pages->checkoutRow($cID);

        ThrowawayPages::actAs('superuser');
        $output = ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', $this->workingInputs('update_block_in_page_area', $cID));

        $this->assertTrue(ToolKind::succeeded($output), $output);
        $this->assertSame($checkout, $this->pages->checkoutRow($cID), 'the check only reads the checkout; it does not check the page in');
    }

    public function testACheckoutWithNoLastEditTimeCountsAsCurrent(): void
    {
        $page = $this->pages->create('No last edit');
        $cID = (int) $page->getCollectionID();
        $this->pages->checkOut($cID, ThrowawayPages::actAs('neuron_editor'), null);

        ThrowawayPages::actAs('superuser');
        $output = ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', $this->workingInputs('update_block_in_page_area', $cID));

        $this->assertStringContainsString('is editing this page', $output);
    }

    public function testEveryWriteToolThatTakesAPageIsCoveredOrExempt(): void
    {
        $covered = [];
        foreach (self::writeToolProvider() as [$toolkit, $tool, $input]) {
            $covered[$toolkit . '::' . $tool][] = $input;
        }
        foreach (self::EXEMPT as $key => $inputs) {
            foreach (array_keys($inputs) as $input) {
                $covered[$key][] = $input;
            }
        }

        $found = [];
        foreach ($this->allToolkits() as $toolkit) {
            foreach ($toolkit->tools() as $tool) {
                if (!$tool instanceof ToolInterface || !ToolKind::isWrite($tool->getName())) {
                    continue;
                }
                foreach ($tool->getProperties() as $property) {
                    $name = $property->getName();
                    if (in_array($name, self::NOT_PAGE_INPUTS, true)) {
                        continue;
                    }
                    if (in_array($name, self::PAGE_INPUTS, true) || preg_match('/page|parent/i', $name) === 1) {
                        $found[$toolkit::class . '::' . $tool->getName()][] = $name;
                    }
                }
            }
        }

        foreach ([&$covered, &$found] as &$list) {
            ksort($list);
            foreach ($list as &$inputs) {
                sort($inputs);
            }
            unset($inputs);
        }
        unset($list);
        $this->assertSame($found, $covered, 'A write tool takes a page but nothing checks that it refuses a page another user is editing. '
            . 'Add a case to writeToolProvider() (and make the tool call EditModeGuard::refuse()), or list the input in EXEMPT with the reason.');
    }

    // ---- A block the user has open in an editor ------------------------------------------------------------------

    /**
     * Every write tool that takes a block ID refuses a block the user has open in an editor on that page.
     *
     * @return array<string, array{string}>
     */
    public static function blockToolProvider(): array
    {
        return [
            'update_block_in_page_area' => ['update_block_in_page_area'],
            'delete_block_from_page_area' => ['delete_block_from_page_area'],
            'move_block_between_areas' => ['move_block_between_areas'],
            'batch_update_page_content' => ['batch_update_page_content'],
        ];
    }

    #[DataProvider('blockToolProvider')]
    public function testTheToolRefusesABlockOpenInTheUsersEditor(string $toolName): void
    {
        $page = $this->pages->create('Open editor');
        $cID = (int) $page->getCollectionID();
        [$open] = $this->pages->blockIDs($cID);
        EditModeGuard::setOpenBlocks($cID, [$open]);
        $before = $this->pages->state($cID);

        $output = ToolByName::run($toolName, $this->workingInputs($toolName, $cID, $open));

        $this->assertSame($before, $this->pages->state($cID), "{$toolName} changed a block open in the user's editor.");
        $this->assertStringContainsString("Block {$open} on this page is open in the user's editor", $output, $output);
        $this->assertStringContainsString('Nothing was changed', $output);
    }

    public function testAnotherBlockOnThePageAndTheSameBlockIDOnAnotherPageAreNotRefused(): void
    {
        $page = $this->pages->create('Open editor, other block');
        $cID = (int) $page->getCollectionID();
        [$open, $other] = $this->pages->blockIDs($cID);
        $page2 = $this->pages->create('Open editor, page two');
        $cID2 = (int) $page2->getCollectionID();
        [$first] = $this->pages->blockIDs($cID2);

        // The editor is open on block one of the first page: block two there may change.
        EditModeGuard::setOpenBlocks($cID, [$open]);
        $output = ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', $this->workingInputs('update_block_in_page_area', $cID, $other));
        $this->assertTrue(ToolKind::succeeded($output), $output);

        // The IDs the panel sends belong to the page it is on, not to any page.
        EditModeGuard::setOpenBlocks($cID, [$first]);
        $output = ThrowawayPages::runTool(new ContentToolkit(), 'update_block_in_page_area', $this->workingInputs('update_block_in_page_area', $cID2, $first));
        $this->assertTrue(ToolKind::succeeded($output), $output);
    }

    public function testTheHeadingsToolLeavesABlockOpenInTheUsersEditorAlone(): void
    {
        $page = $this->pages->create('Open editor, headings', [
            '<h3>Heading In The Open Block</h3><p>Body one.</p>',
            '<h3>Heading In The Closed Block</h3><p>Body two.</p>',
        ]);
        $cID = (int) $page->getCollectionID();
        [$open] = $this->pages->blockIDs($cID);
        EditModeGuard::setOpenBlocks($cID, [$open]);

        $output = ThrowawayPages::runTool(new BatchEditToolkit(), 'batch_update_content_headings', [
            'pageID' => $cID,
            'headingTexts' => ['Heading In The Open Block', 'Heading In The Closed Block'],
            'targetTag' => 'h2',
            'continueOnError' => true,
        ]);

        $result = json_decode($output, true);
        $this->assertIsArray($result, $output);
        $this->assertSame(1, $result['summary']['updatedCount'], $output);
        $this->assertSame('Heading In The Closed Block', $result['updated'][0]['headingText']);
        $this->assertStringContainsString("Block {$open} on this page is open in the user's editor", (string) $result['failed'][0]['error']);
        $openHtml = (string) $this->app()->make(Connection::class)->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$open]);
        $this->assertStringContainsString('<h3>Heading In The Open Block</h3>', $openHtml);
    }

    public function testEveryWriteToolThatTakesABlockIsCovered(): void
    {
        $covered = array_map(static fn (array $case): string => $case[0], array_values(self::blockToolProvider()));
        // Picks its blocks itself: an open block is skipped and reported (testTheHeadingsToolLeavesABlockOpenInTheUsersEditorAlone).
        $covered[] = 'batch_update_content_headings';

        $found = [];
        foreach (array_merge((new PagesToolkit())->tools(), (new ContentToolkit())->tools(), (new BatchEditToolkit())->tools()) as $tool) { // P3.3-4: the split toolkits
            if (!ToolKind::isWrite($tool->getName())) {
                continue;
            }
            foreach ($tool->getProperties() as $property) {
                if (in_array($property->getName(), ['blockID', 'blocks'], true)) {
                    $found[] = $tool->getName();
                }
            }
        }
        $found[] = 'batch_update_content_headings';

        sort($covered);
        $found = array_values(array_unique($found));
        sort($found);
        $this->assertSame($found, $covered, 'A write tool takes a block but nothing checks that it refuses a block open in the user\'s editor.');
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /**
     * Dummy inputs that get the tool as far as finding the page (as SystemPageWriteRefusalTest).
     *
     * @return array<string, mixed>
     */
    private function inputs(string $tool, string $pageInput, int $cID): array
    {
        $other = (int) $this->pages->create('Other page')->getCollectionID();
        ThrowawayPages::actAs('superuser');
        $inputs = match ($tool) {
            'batch_update_page_content' => ['blocks' => [['areaHandle' => 'Main', 'blockID' => 1, 'value' => ['content' => '<p>x</p>']]]],
            'batch_update_content_headings' => ['headingTexts' => ['x'], 'targetTag' => 'h2'],
            'update_page' => ['name' => 'Changed by the assistant'],
            'move_page' => ['newParentID' => $other],
            'add_block_to_page_area' => ['areaHandle' => 'Main', 'blockType' => 'content', 'value' => ['content' => '<p>x</p>']],
            'update_block_in_page_area' => ['areaHandle' => 'Main', 'blockID' => 1, 'value' => ['content' => '<p>x</p>']],
            'delete_block_from_page_area' => ['areaHandle' => 'Main', 'blockID' => 1],
            'move_block_between_areas' => ['fromAreaHandle' => 'Main', 'toAreaHandle' => 'Sidebar', 'blockID' => 1],
            'set_page_attribute_value' => ['handle' => 'meta_title', 'value' => 'Changed by the assistant'],
            'approve_page_version', 'discard_draft_version' => ['versionID' => 1],
            default => [],
        };

        $page = \Concrete\Core\Page\Page::getByID($cID);
        $inputs[$pageInput] = match ($pageInput) {
            'pagePath' => (string) $page->getCollectionPath(),
            'pageName' => (string) $page->getCollectionName(),
            default => $cID,
        };

        return $inputs;
    }

    /**
     * Inputs for a write that succeeds on a throwaway page (block one, or $blockID).
     *
     * @return array<string, mixed>
     */
    private function workingInputs(string $tool, int $cID, ?int $blockID = null): array
    {
        $blockID ??= $this->pages->blockIDs($cID)[0];
        $html = (string) $this->app()->make(Connection::class)->fetchOne('SELECT content FROM btContentLocal WHERE bID = ?', [$blockID]);
        $asHeading = (string) preg_replace('/^<p>(.*)<\/p>$/s', '<h2>$1</h2>', trim($html));

        return match ($tool) {
            'update_block_in_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $blockID, 'value' => ['content' => $asHeading], 'changeMode' => 'formatting_only'],
            'delete_block_from_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $blockID],
            'move_block_between_areas' => ['pageID' => $cID, 'fromAreaHandle' => 'Main', 'toAreaHandle' => 'Sidebar', 'blockID' => $blockID],
            'batch_update_page_content' => ['pageID' => $cID, 'changeMode' => 'formatting_only', 'blocks' => [['areaHandle' => 'Main', 'blockID' => $blockID, 'value' => ['content' => $asHeading]]]],
            'update_page' => ['pageID' => $cID, 'description' => 'Changed by the assistant'],
            'set_page_attribute_value' => ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Changed by the assistant'],
        };
    }

    /**
     * @return list<ToolkitInterface>
     */
    private function allToolkits(): array
    {
        return [
            new PagesToolkit(), new ContentToolkit(), new BatchEditToolkit(), new BlocksToolkit(), new FilesToolkit(), new UsersToolkit(), new PageAttributesToolkit(),
            new UserAttributesToolkit(), new FileAttributesToolkit(), new AttributeSetsToolkit(), new TopicsToolkit(),
            new PeopleToolkit(), new VersionsToolkit(),
        ];
    }
}
