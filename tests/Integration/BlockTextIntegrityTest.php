<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Tests\Eval\Html;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use NeuronAI\Tools\ToolInterface;

/**
 * BF-5: block text is not lost in a draft (CLAUDE.md rule 7, B-T1-2, B-TRU-7; plans/BF-5.md).
 *
 * Scenario 05 lost body text in P2.9 (3 of 5 runs) and in BF-5's "before" run (1 of 5): the model read the block with
 * get_block_controller_data, which cut it to 97 bytes plus "...", and wrote it back whole with update_block_in_page_area,
 * which had no text check. The first tests replay that without an AI call: the read, the edit the model made, the write.
 * Page text is compared the way the scenario's all_text_unchanged check does (the eval's Html::text, block by block).
 *
 * Each test builds a throwaway page under /neuron-test and runs the tools as neuron_editor, as the scenarios do.
 */
final class BlockTextIntegrityTest extends ConcreteTestCase
{
    private const BLOCK_ONE = '<h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p>'
        . '<h3>Seed Heading Three A</h3><p>Text under the first h3 heading.</p>'
        . '<h3>Seed Heading Three B</h3><p>Text under the second h3 heading.</p>';

    /** @var int[] */
    private array $pages = [];

    protected function tearDown(): void
    {
        $this->actAs('superuser');
        foreach ($this->pages as $cID) {
            $page = Page::getByID($cID);
            if ($page && !$page->isError()) {
                $page->delete();
            }
        }
        $this->pages = [];
        Request::getInstance()->setCustomRequestUser(-1);
    }

    // ---- Scenario 05, replayed ----------------------------------------------

    public function testTheBlockReadReturnsTheWholeContent(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();

        $this->assertSame(self::BLOCK_ONE, $this->contentFromControllerData($cID, $bID));
    }

    /** The model's steps in scenario 05: read the block, change the heading tags in what it read, write it back. */
    public function testScenario05ReplayedKeepsThePageText(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();
        $before = $this->pageText($cID);

        $payload = str_replace(['<h3>', '</h3>'], ['<h2>', '</h2>'], $this->contentFromControllerData($cID, $bID));
        if (!str_ends_with($payload, '>')) {
            $payload .= '</h2>'; // as the model closed the cut heading
        }
        $this->tryTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['content' => $payload],
        ]);

        $this->assertSame($before, $this->pageText($cID), 'The page text (newest version) must be unchanged');
    }

    /** The payload of BF-5's "before" run 4 (and P2.9's run 1), word for word. */
    public function testTheRecordedShortenedPayloadIsRefusedAndLeavesNoDraft(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();
        $versions = $this->versionCount($cID);

        $output = $this->tryTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => '<h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p><h2>Seed Heading Three A...</h2>'],
        ]);

        $this->assertStringContainsString('shortened read', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame($versions, $this->versionCount($cID), 'A refused update must not make a draft');
    }

    /** P2.9 runs 2 and 5: the paragraph under the heading replaced by invented text. */
    public function testTheRecordedInventedTextPayloadIsRefused(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();
        $versions = $this->versionCount($cID);

        $output = $this->tryTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => '<h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p>'
                . '<h2>Seed Heading Three A</h2><p>Body text continues under the updated heading level.</p>'],
        ]);

        $this->assertStringContainsString('Text under the first h3 heading.', $output);
        $this->assertStringContainsString('Nothing was changed', $output);
        $this->assertSame($versions, $this->versionCount($cID));
    }

    public function testTheRightCallIsSavedInADraftAndReportsTheTextUnchanged(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();
        $before = $this->pageText($cID);

        $result = $this->json($this->tryTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => str_replace(['<h3>', '</h3>'], ['<h2>', '</h2>'], self::BLOCK_ONE)],
        ]));

        $this->assertSame('keep_text', $result['textCheck']['mode']);
        $this->assertTrue($result['textCheck']['fields']['content']['unchanged']);
        $this->assertSame($before, $this->pageText($cID));
        $this->assertStringNotContainsString('<h3>', $this->newestContent($cID));
        $this->assertSame(2, $this->versionCount($cID), 'One draft');
    }

    public function testAReorderIsSavedAndReported(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();

        $result = $this->json($this->tryTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => '<h3>Seed Heading Three B</h3><p>Text under the second h3 heading.</p>'
                . '<h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p>'
                . '<h3>Seed Heading Three A</h3><p>Text under the first h3 heading.</p>'],
        ]));

        $this->assertTrue($result['textCheck']['fields']['content']['orderChanged']);
    }

    // ---- An intended rewrite (Glyn, on approving BF-5) -----------------------

    public function testRewriteThisParagraphWorksWhenTheReplacedTextIsNamed(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();

        $result = $this->json($this->tryTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => str_replace('Text under the first h3 heading.', 'A rewritten paragraph.', self::BLOCK_ONE)],
            'changeMode' => 'scoped_text',
            'textFields' => ['content'],
            'replacedText' => ['Text under the first h3 heading.'],
        ]));

        $this->assertSame(['Text under the first h3 heading.'], $result['textCheck']['fields']['content']['removedText']);
        $this->assertStringContainsString('A rewritten paragraph.', $this->newestContent($cID));
    }

    public function testTheSameRewriteWithoutNamingTheTextIsRefused(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();
        $versions = $this->versionCount($cID);

        $output = $this->tryTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['content' => str_replace('Text under the first h3 heading.', 'A rewritten paragraph.', self::BLOCK_ONE)],
            'changeMode' => 'scoped_text',
            'textFields' => ['content'],
        ]);

        $this->assertStringContainsString('replacedText', $output);
        $this->assertSame($versions, $this->versionCount($cID));
    }

    // ---- Fields left out are kept ---------------------------------------------

    /**
     * The search block's save() sets a field the update leaves out to '' (most core blocks do something like it), so
     * before BF-5 an update naming one field cleared the others.
     */
    public function testAFieldLeftOutIsKeptAndAnExplicitEmptyValueClearsIt(): void
    {
        $page = $this->newPage();
        $block = $page->addBlock(BlockType::getByHandle('search'), 'Main', ['title' => 'Search the site', 'buttonText' => 'Find it']);
        $cID = $this->startAsEditor($page);
        $bID = (int) $block->getBlockID();
        $this->assertSame('Search the site', (string) $this->newestSearch($cID)['title']);

        $this->json($this->tryTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['buttonText' => 'Find it now'],
        ]));
        $row = $this->newestSearch($cID);
        $this->assertSame('Search the site', (string) $row['title'], 'A field the update left out must be kept');
        $this->assertSame('Find it now', (string) $row['buttonText']);

        $this->json($this->tryTool(new PagesToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => (int) $row['bID'],
            'value' => ['title' => ''],
            'changeMode' => 'scoped_text',
            'textFields' => ['title'],
            'replacedText' => ['Search the site'],
        ]));
        $row = $this->newestSearch($cID);
        $this->assertSame('', (string) $row['title'], 'An explicit empty value clears the field');
        $this->assertSame('Find it now', (string) $row['buttonText']);
    }

    // ---- batch_update_page_content --------------------------------------------

    public function testBatchUpdateWithNoModeRefusesALostParagraph(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();
        $before = $this->pageText($cID);

        $result = $this->json($this->tryTool(new PagesToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [['areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['content' => str_replace('<p>Text under the first h3 heading.</p>', '', self::BLOCK_ONE)]]],
        ]));

        $this->assertSame('keep_text', $result['summary']['changeMode']);
        $this->assertFalse($result['blocks'][0]['ok']);
        $this->assertStringContainsString('Text under the first h3 heading.', $result['blocks'][0]['error']);
        $this->assertSame($before, $this->pageText($cID));
    }

    public function testBatchUpdateNoLongerAcceptsFreeform(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();

        $output = $this->tryTool(new PagesToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'changeMode' => 'freeform',
            'blocks' => [['areaHandle' => 'Main', 'blockID' => $bID, 'value' => ['content' => '<p>Everything replaced.</p>']]],
        ]);

        $this->assertStringContainsString('changeMode must be one of: keep_text, formatting_only, scoped_text', $output);
        $this->assertSame(1, $this->versionCount($cID));
    }

    public function testBatchUpdateScopedTextRewriteWorksWhenTheReplacedTextIsNamed(): void
    {
        [$cID, $bID] = $this->newHeadingsPage();

        $result = $this->json($this->tryTool(new PagesToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'changeMode' => 'scoped_text',
            'blocks' => [[
                'areaHandle' => 'Main', 'blockID' => $bID, 'textFields' => ['content'],
                'replacedText' => ['Text under the first h3 heading.'],
                'value' => ['content' => str_replace('Text under the first h3 heading.', 'A rewritten paragraph.', self::BLOCK_ONE)],
            ]],
        ]));

        $this->assertTrue($result['blocks'][0]['ok'], $result['blocks'][0]['error'] ?? '');
        $this->assertStringContainsString('A rewritten paragraph.', $this->newestContent($cID));
    }

    // ---- Helpers ------------------------------------------------------------

    /**
     * A page under /neuron-test with seed block 1 of the Headings page, and the editor acting.
     *
     * @return array{int, int} page ID, block ID
     */
    private function newHeadingsPage(): array
    {
        $page = $this->newPage();
        $block = $page->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => self::BLOCK_ONE]);
        $page->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => '<h2>Seed Closing Heading</h2><p>A short closing paragraph.</p>']);

        return [$this->startAsEditor($page), (int) $block->getBlockID()];
    }

    private function newPage(): Page
    {
        $this->actAs('superuser');
        $parent = Page::getByPath('/neuron-test');
        $this->assertFalse($parent->isError(), '/neuron-test does not exist. Run the seed script first.');
        $template = PageTemplate::getByHandle('full');
        $unique = bin2hex(random_bytes(3));
        $page = $parent->add(PageType::getByHandle('page'), [
            'cName' => 'Text integrity ' . $unique,
            'cHandle' => 'text-integrity-' . $unique,
            'pTemplateID' => $template->getPageTemplateID(),
        ], $template);
        $this->assertFalse($page->isError());
        $this->pages[] = (int) $page->getCollectionID();

        return $page;
    }

    private function startAsEditor(Page $page): int
    {
        $cID = (int) $page->getCollectionID();
        $this->assertSame(1, $this->versionCount($cID), 'The page should start with a single version');
        $this->actAs('neuron_editor');

        return $cID;
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
        $this->app()->make('cache/request')->flush();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function versionCount(int $cID): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM CollectionVersions WHERE cID = ?', [$cID]);
    }

    private function newestVersion(int $cID): int
    {
        return (int) $this->db()->fetchOne('SELECT MAX(cvID) FROM CollectionVersions WHERE cID = ?', [$cID]);
    }

    /** The page's text in its newest version, as the scenario check reads it: each block's Html::text, joined. */
    private function pageText(int $cID): string
    {
        $texts = [];
        foreach ($this->db()->fetchFirstColumn(
            'SELECT c.content FROM CollectionVersionBlocks cvb JOIN btContentLocal c ON c.bID = cvb.bID
             WHERE cvb.cID = ? AND cvb.cvID = ? ORDER BY cvb.arHandle, cvb.cbDisplayOrder',
            [$cID, $this->newestVersion($cID)]
        ) as $html) {
            $texts[] = Html::text((string) $html);
        }

        return Html::collapse(implode(' ', $texts));
    }

    /** The first Content block's HTML in the newest version. */
    private function newestContent(int $cID): string
    {
        return (string) $this->db()->fetchOne(
            'SELECT c.content FROM CollectionVersionBlocks cvb JOIN btContentLocal c ON c.bID = cvb.bID
             WHERE cvb.cID = ? AND cvb.cvID = ? ORDER BY cvb.cbDisplayOrder LIMIT 1',
            [$cID, $this->newestVersion($cID)]
        );
    }

    /** @return array<string, mixed> */
    private function newestSearch(int $cID): array
    {
        $row = $this->db()->fetchAssociative(
            'SELECT f.* FROM CollectionVersionBlocks cvb JOIN btSearch f ON f.bID = cvb.bID WHERE cvb.cID = ? AND cvb.cvID = ?',
            [$cID, $this->newestVersion($cID)]
        );
        $this->assertIsArray($row);

        return $row;
    }

    /** The `content` field as get_block_controller_data prints it under "Raw Block Table Data". */
    private function contentFromControllerData(int $cID, int $bID): string
    {
        $output = $this->tryTool(new BlocksToolkit(), 'get_block_controller_data', ['blockID' => $bID, 'pageID' => $cID, 'areaHandle' => 'Main']);
        $raw = strstr($output, '**Raw Block Table Data:**');
        $this->assertIsString($raw, "No raw block data in:\n{$output}");
        $this->assertSame(1, preg_match('/^- \*\*content:\*\* (.*)$/m', $raw, $m), "No content line in:\n{$raw}");

        return $m[1];
    }

    /** Runs a tool and returns its result, or "Error executing tool: <message>" as the agent would show it. */
    private function tryTool(object $toolkit, string $name, array $inputs): string
    {
        /** @var ToolInterface $tool */
        foreach ($toolkit->tools() as $tool) {
            if ($tool->getName() === $name) {
                $tool = clone $tool;
                try {
                    $tool->setInputs($inputs)->execute();

                    return (string) $tool->getResult();
                } catch (\Throwable $e) {
                    return 'Error executing tool: ' . $e->getMessage();
                }
            }
        }
        $this->fail("No tool named {$name}");
    }

    /** @return array<string, mixed> */
    private function json(string $output): array
    {
        $data = json_decode($output, true);
        $this->assertIsArray($data, "Not a JSON result:\n{$output}");

        return $data;
    }
}
