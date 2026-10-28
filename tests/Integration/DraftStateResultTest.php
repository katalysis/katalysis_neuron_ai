<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Katalysis\NeuronAi\Security\InstructionDetector;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tests\Support\ThrowawayPages;
use Katalysis\NeuronAi\Tools\AttributeSetsToolkit;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\FileAttributesToolkit;
use Katalysis\NeuronAi\Tools\FilesToolkit;
use Katalysis\NeuronAi\Tools\PageAttributesToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\PeopleToolkit;
use Katalysis\NeuronAi\Tools\ToolKind;
use Katalysis\NeuronAi\Tools\TopicsToolkit;
use Katalysis\NeuronAi\Tools\UserAttributesToolkit;
use Katalysis\NeuronAi\Tools\UsersToolkit;
use Katalysis\NeuronAi\Tools\VersionsToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BF-7 (B-WRT-3, B-REP-1 (c)): every tool that writes to a page draft says so in its result: `versionState` with the
 * page, the version, that it is unpublished, how it goes live (Concrete 9's own labels: "Publish Changes" in edit
 * mode, "Approve Version" outside it), how to undo it, and the one short line the reply should be. By rule 5 this is
 * in the tool result, not the prompt. Throwaway pages; the tools run as the superuser.
 */
final class DraftStateResultTest extends ConcreteTestCase
{
    /** Write tools with a page input that don't write to a page draft, and why. */
    private const EXEMPT = [
        'sync_multi_links_to_epra_resources_list' => 'disabled; writes live; moves out in P3.4',
        'move_page' => 'disabled; a move is not part of a page version',
        'delete_page' => 'disabled; a delete is not part of a page version',
        'approve_page_version' => 'disabled; publishes',
        'discard_draft_version' => 'removes a draft rather than writing to one',
    ];

    private ThrowawayPages $pages;

    protected function setUp(): void
    {
        $this->pages = new ThrowawayPages($this->app()->make(Connection::class));
    }

    protected function tearDown(): void
    {
        $this->pages->restore();
    }

    /**
     * @return array<string, array{class-string<ToolkitInterface>, string}>
     */
    public static function draftWriteProvider(): array
    {
        return [
            'batch_update_page_content' => [PagesToolkit::class, 'batch_update_page_content'],
            'batch_update_content_headings' => [PagesToolkit::class, 'batch_update_content_headings'],
            'update_page' => [PagesToolkit::class, 'update_page'],
            'add_block_to_page_area' => [PagesToolkit::class, 'add_block_to_page_area'],
            'update_block_in_page_area' => [PagesToolkit::class, 'update_block_in_page_area'],
            'delete_block_from_page_area' => [PagesToolkit::class, 'delete_block_from_page_area'],
            'move_block_between_areas' => [PagesToolkit::class, 'move_block_between_areas'],
            'set_page_attribute_value, PageAttributesToolkit' => [PageAttributesToolkit::class, 'set_page_attribute_value'],
        ];
    }

    /**
     * @param class-string<ToolkitInterface> $toolkit
     */
    #[DataProvider('draftWriteProvider')]
    public function testTheResultSaysTheChangeIsUnpublishedAndHowItGoesLive(string $toolkit, string $toolName): void
    {
        $page = $this->pages->create('Draft state', ['<h3>Draft State Heading</h3><p>Body text.</p>', '<p>Block two original text.</p>']);
        $cID = (int) $page->getCollectionID();

        $output = ThrowawayPages::runTool(new $toolkit(), $toolName, $this->inputs($toolName, $cID));

        $this->assertTrue(ToolKind::succeeded($output), "{$toolName} failed: {$output}");
        $result = json_decode($output, true);
        $this->assertIsArray($result, $output);
        $this->assertArrayHasKey('versionState', $result, "{$toolName} does not say the change is unpublished: {$output}");
        $state = $result['versionState'];
        CacheLocal::flush();
        $recent = Page::getByID($cID, 'RECENT');
        $this->assertSame('unpublished', $state['status']);
        $this->assertSame($cID, $state['pageID']);
        $this->assertSame((int) $recent->getVersionID(), $state['versionID']);
        $this->assertFalse((bool) $recent->getVersionObject()->isApproved(), 'the version is really unpublished');
        $this->assertStringContainsString("version {$state['versionID']}", $state['summary']);
        $this->assertStringContainsString('unpublished', $state['summary']);
        $this->assertStringContainsString('"Publish Changes"', $state['summary']);
        $this->assertStringContainsString('"Approve Version"', $state['summary']);
        $this->assertStringContainsString('Page Versions', $state['summary']);
        // Glyn, after BF-7's scenario run: say what was actually done first, then the one line about publishing.
        $this->assertStringContainsString('Describe what was actually done', $state['reply']);
        $this->assertStringContainsString('the original block was kept', $state['reply']);
        $this->assertStringContainsString('You can see it on the page now; it goes live when you publish.', $state['reply']);
    }

    public function testANewPageSaysItIsUnpublished(): void
    {
        ThrowawayPages::actAs('superuser');
        $parent = Page::getByPath('/neuron-test');

        $output = ThrowawayPages::runTool(new PagesToolkit(), 'create_page', ['parentID' => (int) $parent->getCollectionID(), 'name' => 'BF-7 new page ' . bin2hex(random_bytes(3))]);
        $result = json_decode($output, true);
        $this->assertIsArray($result, $output);
        $this->pages->adopt((int) $result['id']);

        $state = $result['versionState'];
        $this->assertSame('unpublished', $state['status']);
        $this->assertSame((int) $result['id'], $state['pageID']);
        $this->assertStringContainsString('"Approve Version"', $state['summary']);
        $this->assertStringContainsString('Describe what was actually done', $state['reply']);
        $this->assertStringContainsString('not published yet', $state['reply']);
    }

    public function testCreatePageDraftSaysHowTheDraftGoesLive(): void
    {
        $page = $this->pages->create('Draft state, create draft');

        $output = ThrowawayPages::runTool(new VersionsToolkit(), 'create_page_draft', ['pageID' => (int) $page->getCollectionID()]);

        $this->assertStringContainsString('"Publish Changes"', $output);
        $this->assertStringContainsString('"Approve Version"', $output);
    }

    public function testTheWordingIsNotFlaggedAsInstructionsToTheAssistant(): void
    {
        $page = $this->pages->create('Draft state, scanner');
        $cID = (int) $page->getCollectionID();

        $output = ThrowawayPages::runTool(new PagesToolkit(), 'update_page', ['pageID' => $cID, 'description' => 'Changed.']);

        // Scenario 10 expects no injection notice, so the result's own wording must never trip the detector (P1.11).
        $this->assertSame([], (new InstructionDetector('katalysis-pro.test'))->scan(json_decode($output, true)));
    }

    public function testEveryDraftWriteToolIsCoveredOrExempt(): void
    {
        $covered = array_merge(
            array_map(static fn (array $case): string => $case[1], array_values(self::draftWriteProvider())),
            array_keys(self::EXEMPT),
            ['create_page', 'create_page_draft']
        );

        $found = [];
        $toolkits = [
            new PagesToolkit(), new BlocksToolkit(), new FilesToolkit(), new UsersToolkit(), new PageAttributesToolkit(),
            new UserAttributesToolkit(), new FileAttributesToolkit(), new AttributeSetsToolkit(), new TopicsToolkit(),
            new PeopleToolkit(), new VersionsToolkit(),
        ];
        foreach ($toolkits as $toolkit) {
            foreach ($toolkit->tools() as $tool) {
                if (!ToolKind::isWrite($tool->getName())) {
                    continue;
                }
                foreach ($tool->getProperties() as $property) {
                    if (in_array($property->getName(), ['pageID', 'pagePath', 'pageName', 'parentID'], true)) {
                        $found[] = $tool->getName();
                    }
                }
            }
        }

        $covered = array_values(array_unique($covered));
        $found = array_values(array_unique($found));
        sort($covered);
        sort($found);
        $this->assertSame($found, $covered, 'A write tool takes a page but nothing checks that its result states the version (DraftState).');
    }

    /**
     * @return array<string, mixed> inputs for a write that succeeds on the throwaway page
     */
    private function inputs(string $tool, int $cID): array
    {
        [$b1, $b2] = $this->pages->blockIDs($cID);
        ThrowawayPages::actAs('superuser');

        return match ($tool) {
            'batch_update_page_content' => ['pageID' => $cID, 'changeMode' => 'formatting_only', 'blocks' => [
                ['areaHandle' => 'Main', 'blockID' => $b2, 'value' => ['content' => '<h2>Block two original text.</h2>']],
            ]],
            'batch_update_content_headings' => ['pageID' => $cID, 'headingTexts' => ['Draft State Heading'], 'targetTag' => 'h2'],
            'update_page' => ['pageID' => $cID, 'description' => 'Changed by the assistant'],
            'add_block_to_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'content', 'value' => ['content' => '<p>Added.</p>']],
            'update_block_in_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b2, 'value' => ['content' => '<h2>Block two original text.</h2>'], 'changeMode' => 'formatting_only'],
            'delete_block_from_page_area' => ['pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $b2],
            'move_block_between_areas' => ['pageID' => $cID, 'fromAreaHandle' => 'Main', 'toAreaHandle' => 'Sidebar', 'blockID' => $b2],
            'set_page_attribute_value' => ['pageID' => $cID, 'handle' => 'meta_title', 'value' => 'Changed by the assistant'],
        };
    }
}
