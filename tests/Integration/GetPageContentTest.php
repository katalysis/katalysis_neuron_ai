<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Area\ContainerArea;
use Concrete\Core\Block\Block;
use Concrete\Core\Block\BlockType\BlockType;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Entity\Page\Container;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Container\ContainerBlockInstance;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\User\UserInfoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Tools\ToolInterface;

/**
 * P1.10: `get_page_content` reads the blocks of the page. It no longer tries to render the page, which
 * crashed with "Call to a member function getCollectionPath() on false" when there is no current page
 * (the CLI, scenarios, the chat panel's own request).
 *
 * Every test builds a throwaway page under /neuron-test as the superuser, reads it as `neuron_chat_editor`
 * (the browser test user), and deletes the page again in tearDown(). Nothing here calls the AI provider.
 * Needs the seeded dev site.
 */
final class GetPageContentTest extends ConcreteTestCase
{
    private const READER = 'neuron_chat_editor';
    private const DUMMY_KEY = 'sk-dummy-PROVIDER-0123456789-abcdefghij-ZQ9X';

    /** @var int[] */
    private array $copies = [];

    /** @var array<int, array{cID: int, cvID: int, bID: int, area: string}> block permission overrides to revert */
    private array $blockOverrides = [];

    protected function tearDown(): void
    {
        $this->actAs('superuser');
        foreach ($this->blockOverrides as $override) {
            $page = Page::getByID($override['cID'], $override['cvID']);
            $block = Block::getByID($override['bID'], $page, $override['area']);
            if ($block) {
                $block->revertToAreaPermissions();
            }
        }
        $this->blockOverrides = [];
        foreach ($this->copies as $cID) {
            $page = Page::getByID($cID);
            if ($page && !$page->isError()) {
                $page->delete();
            }
        }
        $this->copies = [];
        Request::getInstance()->setCustomRequestUser(-1);
    }

    // ---- Tests -------------------------------------------------------------

    public function testReadsAContainerPageWithNoCurrentPage(): void
    {
        $this->assertNotInstanceOf(Page::class, Page::getCurrentPage(), 'This test must run with no current page, as the CLI does');

        $cID = $this->newColumnsPage();
        $result = $this->readContent($cID);

        $this->assertSame('blocks', $result['renderMethod']);
        $this->assertArrayNotHasKey('renderErrors', $result);
        $this->assertSame(0, $result['hiddenBlocks']);

        // Text from both columns, column 1 before column 2, with no area names added as headings.
        $text = $result['text'];
        $this->assertStringContainsString('Column One Bold Heading A', $text);
        $this->assertStringContainsString('Body text in column one', $text);
        $this->assertStringContainsString('Column Two Heading', $text);
        $this->assertStringContainsString('More text in column two.', $text);
        $this->assertLessThan(strpos($text, 'Column Two Heading'), strpos($text, 'Column One Bold Heading A'));
        $this->assertStringNotContainsString('Main', $text);

        // The container has no text of its own; the two content blocks sit in the sub-areas.
        $byType = [];
        foreach ($result['blocks'] as $block) {
            $byType[$block['blockType']][] = $block;
        }
        $this->assertCount(1, $byType['core_container']);
        $this->assertFalse($byType['core_container'][0]['hasText']);
        $this->assertSame('Main', $byType['core_container'][0]['area']);
        $this->assertCount(2, $byType['content']);
        foreach ($byType['content'] as $block) {
            $this->assertTrue($block['hasText']);
            $this->assertStringStartsWith('Main : ', $block['area']);
            $this->assertStringContainsString('data-block-id="' . $block['blockID'] . '"', $result['html']);
        }
    }

    public function testRecentShowsTheDraftEvenAfterTheActiveVersionWasReadInTheSameRequest(): void
    {
        $cID = $this->newHeadingsPage();

        $active = $this->readContent($cID);
        $this->assertStringContainsString('Seed Heading Two', $active['text']);
        $this->assertStringNotContainsString('Draft only edit', $active['text']);

        $this->actAs('neuron_editor');
        $first = $active['blocks'][0];
        $this->callTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID,
            'areaHandle' => 'Main',
            'blockID' => $first['blockID'],
            'value' => ['content' => '<p>Draft only edit</p>'],
            // BF-5: replacing a block's whole text is an intended rewrite, so it names the text it replaces.
            'changeMode' => 'scoped_text',
            'textFields' => ['content'],
            'replacedText' => ['Seed Heading Two', 'This paragraph sits under the h2 heading.'],
        ]);

        $recent = $this->readContent($cID, 'recent');
        $this->assertStringContainsString('Draft only edit', $recent['text']);
        $this->assertSame('RECENT', $recent['version']['versionHandle']);
        $this->assertCount(3, $recent['blocks']);

        $activeAgain = $this->readContent($cID);
        $this->assertStringNotContainsString('Draft only edit', $activeAgain['text'], 'The published version must not show the draft');
        $this->assertStringContainsString('Seed Heading Two', $activeAgain['text']);
        $this->assertSame($active['text'], $activeAgain['text']);
    }

    public function testABlockThatThrowsIsReportedAndTheOthersAreStillReturned(): void
    {
        $cID = $this->newHeadingsPage();
        $blocks = $this->readContent($cID)['blocks'];
        $this->assertCount(3, $blocks);
        $failing = $blocks[1];

        $toolkit = new class extends PagesToolkit {
            public int $failingBlockID = 0;
            public string $failureMessage = '';

            protected function extractBlockText(Block $block): ?string
            {
                if ($block->getBlockID() === $this->failingBlockID) {
                    // An \Error, not an \Exception: the old code let this kind escape.
                    throw new \Error($this->failureMessage);
                }

                return parent::extractBlockText($block);
            }
        };
        $toolkit->failingBlockID = $failing['blockID'];
        $toolkit->failureMessage = 'The block controller failed with key ' . self::DUMMY_KEY;

        $result = $this->readContent($cID, 'active', $toolkit);

        // The other two blocks are still there.
        $this->assertStringContainsString('Seed Heading Two', $result['text']);
        $this->assertStringContainsString('Seed Closing Heading', $result['text']);
        $this->assertStringNotContainsString('Seed Bold Heading One', $result['text']);

        // The failure names the block and its type.
        $this->assertCount(1, $result['renderErrors']);
        $this->assertStringContainsString('Block ' . $failing['blockID'] . ' (content)', $result['renderErrors'][0]);
        $this->assertStringContainsString('The block controller failed', $result['renderErrors'][0]);

        // No key, no stack trace, no file path.
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::DUMMY_KEY, $encoded);
        $this->assertStringNotContainsString('sk-dummy', $encoded);
        $this->assertStringNotContainsString('Stack trace', $encoded);
        $this->assertStringNotContainsString('#0 ', $encoded);
        $this->assertStringNotContainsString('.php', $encoded);

        // The block is still listed, without text.
        $listed = array_values(array_filter($result['blocks'], fn (array $b) => $b['blockID'] === $failing['blockID']));
        $this->assertCount(1, $listed);
        $this->assertFalse($listed[0]['hasText']);
    }

    public function testABlockTheUserCannotViewIsCountedAndNeverRead(): void
    {
        $cID = $this->newHeadingsPage();
        $blocks = $this->readContent($cID)['blocks'];
        $hidden = $blocks[1];
        $page = Page::getByID($cID, 'ACTIVE');
        $db = $this->db();
        $before = $this->permissionRowCounts();

        // As the superuser: the block gets its own permissions, and nobody gets "view" on it.
        $this->actAs('superuser');
        $block = Block::getByID($hidden['blockID'], $page, 'Main');
        $block->doOverrideAreaPermissions();
        $this->blockOverrides[] = ['cID' => $cID, 'cvID' => (int) $page->getVersionID(), 'bID' => $hidden['blockID'], 'area' => 'Main'];
        $viewKey = (int) $db->fetchOne("SELECT pkID FROM PermissionKeys WHERE pkHandle = 'view_block'");
        $this->assertGreaterThan(0, $viewKey);
        $db->executeStatement(
            'DELETE FROM BlockPermissionAssignments WHERE cID = ? AND cvID = ? AND bID = ? AND pkID = ?',
            [$cID, $page->getVersionID(), $hidden['blockID'], $viewKey]
        );

        $result = $this->readContent($cID);

        $this->assertSame(1, $result['hiddenBlocks']);
        $this->assertCount(2, $result['blocks'], 'The hidden block is not listed');
        $this->assertNotContains($hidden['blockID'], array_column($result['blocks'], 'blockID'));
        $this->assertStringNotContainsString('Seed Bold Heading One', $result['text']);
        $this->assertStringContainsString('Seed Heading Two', $result['text']);
        $this->assertStringContainsString('Seed Closing Heading', $result['text']);

        // The superuser still sees all three, so the count is about the reader, not the page.
        $this->actAs('superuser');
        $asSuperuser = $this->readContent($cID, 'active', null, 'superuser');
        $this->assertSame(0, $asSuperuser['hiddenBlocks']);
        $this->assertStringContainsString('Seed Bold Heading One', $asSuperuser['text']);

        // Put the permissions back the way they were.
        $this->actAs('superuser');
        Block::getByID($hidden['blockID'], Page::getByID($cID, 'ACTIVE'), 'Main')->revertToAreaPermissions();
        $this->blockOverrides = [];
        $this->assertSame($before, $this->permissionRowCounts(), 'The test must leave no permission rows behind');
    }

    // ---- Helpers -----------------------------------------------------------

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
        // Permission answers are cached for the request.
        $this->app()->make('cache/request')->flush();
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * Reads the page with get_page_content, as $as (default: the chat editor).
     *
     * @return array<string, mixed>
     */
    private function readContent(int $cID, string $version = 'active', ?PagesToolkit $toolkit = null, string $as = self::READER): array
    {
        $this->actAs($as);
        $json = $this->callTool($toolkit ?? new PagesToolkit(), 'get_page_content', ['pageID' => $cID, 'version' => $version]);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'get_page_content did not return JSON: ' . $json);

        return $decoded;
    }

    private function callTool(ToolkitInterface $toolkit, string $name, array $inputs): string
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

    /**
     * @return array<string, int> row counts of the tables a block permission override writes to
     */
    private function permissionRowCounts(): array
    {
        $db = $this->db();
        $counts = [];
        foreach (['BlockPermissionAssignments', 'PermissionAccess', 'PermissionAccessEntities', 'PermissionAccessList'] as $table) {
            $counts[$table] = (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");
        }

        return $counts;
    }

    private function newHeadingsPage(): int
    {
        $page = $this->newPage();
        $content = BlockType::getByHandle('content');
        $page->addBlock($content, 'Main', ['content' =>
            '<h2>Seed Heading Two</h2><p>This paragraph sits under the h2 heading.</p>']);
        $page->addBlock($content, 'Main', ['content' =>
            '<p><strong>Seed Bold Heading One</strong></p><p>Body text under the first bold-paragraph heading.</p>']);
        $page->addBlock($content, 'Main', ['content' => '<h2>Seed Closing Heading</h2><p>A short closing paragraph.</p>']);

        return (int) $page->getCollectionID();
    }

    private function newColumnsPage(): int
    {
        $app = $this->app();
        $page = $this->newPage();
        $container = $app->make(EntityManagerInterface::class)->getRepository(Container::class)->findOneBy(['containerHandle' => 'row_two_column']);
        $this->assertNotNull($container, 'The row_two_column container does not exist');
        $containerBlock = $page->addBlock(BlockType::getByHandle('core_container'), 'Main', ['containerID' => $container->getContainerID()]);
        $containerBlock = Block::getByID($containerBlock->getBlockID(), $page, 'Main');
        $instance = $containerBlock->getController()->getContainerInstanceObject();
        $blockInstance = $app->make(ContainerBlockInstance::class, ['block' => $containerBlock, 'instance' => $instance]);
        $column1 = (new ContainerArea($blockInstance, 'Column 1'))->getSubAreaObject($page);
        $column2 = (new ContainerArea($blockInstance, 'Column 2'))->getSubAreaObject($page);
        $content = BlockType::getByHandle('content');
        $page->addBlock($content, $column1, ['content' =>
            '<p><strong>Column One Bold Heading A</strong></p><p>Body text in column one, under bold heading A.</p>']);
        $page->addBlock($content, $column2, ['content' =>
            '<h2>Column Two Heading</h2><p>Text in column two.</p><h3>Column Two Sub Heading</h3><p>More text in column two.</p>']);

        return (int) $page->getCollectionID();
    }

    private function newPage(): Page
    {
        $this->actAs('superuser');
        $parent = Page::getByPath('/neuron-test');
        $this->assertFalse($parent->isError(), '/neuron-test does not exist. Run the seed script first.');
        $template = PageTemplate::getByHandle('full');
        $unique = bin2hex(random_bytes(3));
        $page = $parent->add(PageType::getByHandle('page'), [
            'cName' => 'Get page content ' . $unique,
            'cHandle' => 'get-page-content-' . $unique,
            'pTemplateID' => $template->getPageTemplateID(),
        ], $template);
        $this->assertFalse($page->isError());
        $this->copies[] = (int) $page->getCollectionID();

        return $page;
    }
}
