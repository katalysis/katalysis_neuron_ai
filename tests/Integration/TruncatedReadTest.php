<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Area\ContainerArea;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Entity\Page\Container;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Container\ContainerBlockInstance;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\User\UserInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tools\BlocksToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\VersionsToolkit;
use NeuronAI\Tools\ToolInterface;

/**
 * BF-5: a read tool never hands the model shortened text without saying so and naming the tool that returns it in
 * full (B-TRU-7; plans/BF-5.md §3, R1–R11). Each test reads a throwaway page under /neuron-test as the superuser.
 */
final class TruncatedReadTest extends ConcreteTestCase
{
    /** 260 characters of text, more than every preview length. */
    private const LONG = '<h2>A long heading for the preview checks</h2><p>' . 'Sentence number one is here. Sentence number two is here. '
        . 'Sentence number three is here. Sentence number four is here. Sentence number five is here, at the very end of the block.</p>';

    private const SHORT = '<p>Short block.</p>';

    /** @var int[] */
    private array $pages = [];

    protected function setUp(): void
    {
        $this->actAs();
    }

    protected function tearDown(): void
    {
        $this->actAs();
        foreach ($this->pages as $cID) {
            $page = Page::getByID($cID);
            if ($page && !$page->isError()) {
                $page->delete();
            }
        }
        $this->pages = [];
        Request::getInstance()->setCustomRequestUser(-1);
    }

    // ---- R1, R1b: get_block_controller_data -------------------------------------

    public function testBlockControllerDataPrintsLongTextInFullOnce(): void
    {
        [$cID, $long] = $this->newPageWithBlocks();

        $output = $this->runTool(new BlocksToolkit(), 'get_block_controller_data', ['blockID' => $long, 'pageID' => $cID, 'areaHandle' => 'Main']);

        $this->assertStringContainsString(self::LONG, $output, 'The stored HTML must be returned whole');
        $this->assertSame(1, substr_count($output, self::LONG), 'The same long text is shown once, then referred to');
        $this->assertStringContainsString('same as raw field `content`', $output);
        $this->assertStringNotContainsString('heading for the preview checks</h2><p>Sentence number one is here. Sentence number two is h...', $output);
    }

    public function testBlockControllerDataSaysWhatItDoesNotShow(): void
    {
        [$cID, $long] = $this->newPageWithBlocks();

        $output = $this->runTool(new BlocksToolkit(), 'get_block_controller_data', ['blockID' => $long, 'pageID' => $cID, 'areaHandle' => 'Main']);

        $this->assertDoesNotMatchRegularExpression('/Array with \d+ items\n/', $output, 'An array line must say it is not shown');
        $this->assertDoesNotMatchRegularExpression('/ object\n/', $output, 'An object line must say it is not shown');
    }

    // ---- R2: get_page_blocks ------------------------------------------------------

    public function testPageBlocksMarksAShortenedPreviewAndNamesTheFullTextTool(): void
    {
        [$cID, $long, $short] = $this->newPageWithBlocks();

        $result = $this->json($this->runTool(new ContentToolkit(), 'get_page_blocks', ['pageID' => $cID]));
        $blocks = $this->blocksByID($result['areas']);

        $this->assertSame(['fullLength' => mb_strlen($this->plain(self::LONG)), 'fullTextTool' => 'get_block_data'], $blocks[$long]['contentPreviewShortened']);
        $this->assertArrayNotHasKey('contentPreviewShortened', $blocks[$short]);
        $this->assertStringContainsString('get_block_data', $result['note']);
        $this->assertStringContainsString('get_page_content', $result['note']);
    }

    // ---- R3–R7: the shared block summary (get_page, get_current_page_context, write results) --

    public function testGetPageMarksAShortenedPreview(): void
    {
        [$cID, $long, $short] = $this->newPageWithBlocks();

        $result = $this->json($this->runTool(new PagesToolkit(), 'get_page', ['pageID' => $cID]));
        $blocks = $this->blocksByID($result['areas'] ?? $result['page']['areas'] ?? []);

        $this->assertSame('get_block_data', $blocks[$long]['contentPreviewShortened']['fullTextTool'] ?? null);
        $this->assertArrayNotHasKey('contentPreviewShortened', $blocks[$short]);
    }

    public function testAWriteResultMarksAShortenedPreview(): void
    {
        [$cID, $long] = $this->newPageWithBlocks();

        $result = $this->json($this->runTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $long, 'value' => ['content' => self::LONG . '<p>Added.</p>'],
        ]));

        $this->assertSame('get_block_data', $result['contentPreviewShortened']['fullTextTool'] ?? null);
    }

    // ---- R11: blocks in container and layout columns ---------------------------------

    public function testPageToolsCountBlocksInContainerColumns(): void
    {
        $cID = $this->newPageWithAContainer();

        $blocks = $this->json($this->runTool(new ContentToolkit(), 'get_page_blocks', ['pageID' => $cID]));
        $this->assertSame(1, $blocks['subAreaBlocks']);
        $this->assertStringContainsString('get_page_content', $blocks['subAreaNote']);

        $page = $this->json($this->runTool(new PagesToolkit(), 'get_page', ['pageID' => $cID]));
        $details = isset($page['page']) ? $page['page'] : $page;
        $this->assertSame(1, $details['subAreaBlocks']);
        $this->assertStringContainsString('get_page_content', $details['subAreaNote']);
    }

    public function testNoSubAreaNoteWithoutSubAreaBlocks(): void
    {
        [$cID] = $this->newPageWithBlocks();

        $blocks = $this->json($this->runTool(new ContentToolkit(), 'get_page_blocks', ['pageID' => $cID]));

        $this->assertSame(0, $blocks['subAreaBlocks']);
        $this->assertArrayNotHasKey('subAreaNote', $blocks);
    }

    // ---- R8, R9: the versions tools -----------------------------------------------------

    public function testVersionContentMarksAShortenedPreviewByCharacters(): void
    {
        $page = $this->newPage();
        $page->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => '<p>' . str_repeat('é', 150) . '</p>']);
        $cID = (int) $page->getCollectionID();
        $cvID = (int) Page::getByID($cID, 'ACTIVE')->getVersionID();

        $output = $this->runTool(new VersionsToolkit(), 'get_version_content', ['pageID' => $cID, 'versionID' => $cvID]);

        $this->assertTrue(mb_check_encoding($output, 'UTF-8'), 'A preview must not split a character');
        $this->assertStringContainsString('shortened: 100 of 150 characters', $output);
        $this->assertStringContainsString('get_block_data', $output);
    }

    public function testVersionListShowsTheWholeComment(): void
    {
        $page = $this->newPage();
        $comment = 'A version comment that is a good deal longer than forty characters';
        Page::getByID((int) $page->getCollectionID(), 'ACTIVE')->getVersionObject()->setComment($comment);

        $output = $this->runTool(new VersionsToolkit(), 'list_page_versions', ['pageID' => (int) $page->getCollectionID()]);

        $this->assertStringContainsString($comment, $output);
    }

    // ---- Helpers --------------------------------------------------------------------------

    /** @return array{int, int, int} page ID, long block ID, short block ID */
    private function newPageWithBlocks(): array
    {
        $page = $this->newPage();
        $long = $page->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => self::LONG]);
        $short = $page->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => self::SHORT]);

        return [(int) $page->getCollectionID(), (int) $long->getBlockID(), (int) $short->getBlockID()];
    }

    /** A page with one Content block in Main and one in column 1 of a two-column container. */
    private function newPageWithAContainer(): int
    {
        $app = $this->app();
        $page = $this->newPage();
        $page->addBlock(BlockType::getByHandle('content'), 'Main', ['content' => self::SHORT]);
        $container = $app->make(EntityManagerInterface::class)->getRepository(Container::class)->findOneBy(['containerHandle' => 'row_two_column']);
        $this->assertNotNull($container, 'The row_two_column container does not exist');
        $containerBlock = $page->addBlock(BlockType::getByHandle('core_container'), 'Main', ['containerID' => $container->getContainerID()]);
        $containerBlock = Block::getByID($containerBlock->getBlockID(), $page, 'Main');
        $instance = $containerBlock->getController()->getContainerInstanceObject();
        $blockInstance = $app->make(ContainerBlockInstance::class, ['block' => $containerBlock, 'instance' => $instance]);
        $column1 = (new ContainerArea($blockInstance, 'Column 1'))->getSubAreaObject($page);
        $page->addBlock(BlockType::getByHandle('content'), $column1, ['content' => '<p>In column one.</p>']);

        return (int) $page->getCollectionID();
    }

    private function newPage(): Page
    {
        $parent = Page::getByPath('/neuron-test');
        $this->assertFalse($parent->isError(), '/neuron-test does not exist. Run the seed script first.');
        $template = PageTemplate::getByHandle('full');
        $unique = bin2hex(random_bytes(3));
        $page = $parent->add(PageType::getByHandle('page'), [
            'cName' => 'Truncated read ' . $unique,
            'cHandle' => 'truncated-read-' . $unique,
            'pTemplateID' => $template->getPageTemplateID(),
        ], $template);
        $this->assertFalse($page->isError());
        $this->pages[] = (int) $page->getCollectionID();

        return $page;
    }

    private function actAs(): void
    {
        Request::getInstance()->setCustomRequestUser($this->app()->make(UserInfoRepository::class)->getByID(USER_SUPER_ID));
    }

    private function plain(string $html): string
    {
        return \Katalysis\NeuronAi\Tools\Utilities\HtmlToText::convert($html);
    }

    /**
     * @param list<array{blocks: list<array<string, mixed>>}> $areas
     *
     * @return array<int, array<string, mixed>>
     */
    private function blocksByID(array $areas): array
    {
        $byID = [];
        foreach ($areas as $area) {
            foreach ($area['blocks'] as $block) {
                $byID[(int) $block['blockID']] = $block;
            }
        }

        return $byID;
    }

    private function runTool(object $toolkit, string $name, array $inputs): string
    {
        /** @var ToolInterface $tool */
        foreach ($toolkit->tools() as $tool) {
            if ($tool->getName() === $name) {
                $tool = clone $tool;
                $tool->setInputs($inputs)->execute();

                return (string) $tool->getResult();
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
