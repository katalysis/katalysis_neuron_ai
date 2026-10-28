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
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tools\PagesToolkit;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use NeuronAI\Tools\ToolInterface;

/**
 * BF-8: the block write tools pass a block's fields through as given (plans/BF-8.md). Only EPRA blocks kept the EPRA
 * field renaming, and P3.4 deleted it with the rest of the EPRA code.
 *
 * Before BF-8, add_block_to_page_area, update_block_in_page_area and batch_update_page_content renamed `title`, `name`
 * and `heading` to `Title`, `description` to `precis`, `linkTitle` to `link_Title` and `entries` to `slide` for every
 * block type, so a core block's title (or a katalysis_pro list's entries) was dropped and the tool still reported
 * success.
 *
 * Each test builds a throwaway page under /neuron-test and runs the tools as neuron_editor, as the scenarios do.
 */
final class BlockFieldNamesTest extends ConcreteTestCase
{
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

    // ---- Core blocks: the field is saved under its own name -------------------

    /** As the superuser: the seed lets neuron_editor add only some block types, and this isn't a permission test. */
    public function testAddSetsAHeroImageTitle(): void
    {
        $cID = (int) $this->newPage()->getCollectionID();

        $this->json($this->tryTool(new ContentToolkit(), 'add_block_to_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'hero_image',
            'value' => ['title' => 'Welcome aboard', 'body' => '<p>A short welcome.</p>'],
        ]));

        $row = $this->newestRow($cID, 'btHeroImage');
        $this->assertSame('Welcome aboard', (string) $row['title']);
        $this->assertStringContainsString('A short welcome.', (string) $row['body']);
    }

    /**
     * The test BF-5 couldn't write (BlockTextIntegrityTest uses buttonText instead). Replacing the title's stored text
     * needs scoped_text, as for any text field: the text check now sees the title too.
     */
    public function testUpdateSetsASearchTitleAndKeepsTheButtonText(): void
    {
        $page = $this->newPage();
        $block = $page->addBlock(BlockType::getByHandle('search'), 'Main', ['title' => 'Search the site', 'buttonText' => 'Find it']);
        $cID = $this->startAsEditor($page);
        $bID = (int) $block->getBlockID();

        $refused = $this->tryTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['title' => 'Search this site'],
        ]);
        $this->assertStringContainsString('Search the site', $refused);
        $this->assertStringContainsString('Nothing was changed', $refused);

        $this->json($this->tryTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => $bID,
            'value' => ['title' => 'Search this site'],
            'changeMode' => 'scoped_text',
            'textFields' => ['title'],
            'replacedText' => ['Search the site'],
        ]));

        $row = $this->newestRow($cID, 'btSearch');
        $this->assertSame('Search this site', (string) $row['title']);
        $this->assertSame('Find it', (string) $row['buttonText']);
    }

    public function testUpdateSetsATestimonialName(): void
    {
        $page = $this->newPage();
        $block = $page->addBlock(BlockType::getByHandle('testimonial'), 'Main', ['name' => 'Sam Smith', 'paragraph' => 'Very helpful.']);
        $cID = $this->startAsEditor($page);

        $this->json($this->tryTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => (int) $block->getBlockID(),
            'value' => ['name' => 'Sam Smith-Jones', 'paragraph' => 'Very helpful.'],
        ]));

        $this->assertSame('Sam Smith-Jones', (string) $this->newestRow($cID, 'btTestimonial')['name']);
    }

    public function testBatchUpdateSetsAFeatureTitle(): void
    {
        $page = $this->newPage();
        $block = $page->addBlock(BlockType::getByHandle('feature'), 'Main', ['title' => 'Fast', 'paragraph' => 'It is quick.', 'link__which' => 'none']);
        $cID = $this->startAsEditor($page);

        $result = $this->json($this->tryTool(new BatchEditToolkit(), 'batch_update_page_content', [
            'pageID' => $cID,
            'blocks' => [['areaHandle' => 'Main', 'blockID' => (int) $block->getBlockID(), 'value' => ['title' => 'Fast and simple', 'paragraph' => 'It is quick.', 'link__which' => 'none']]],
        ]));

        $this->assertTrue($result['blocks'][0]['ok'], $result['blocks'][0]['error'] ?? '');
        $this->assertSame('Fast and simple', (string) $this->newestRow($cID, 'btFeature')['title']);
    }

    /**
     * The FAQ block's form save() takes its entries as parallel arrays: title[], linkTitle[], description[], sortOrder[].
     * P3.3-7 (Glyn): the tools write them as the rows of the table the block's export declares (btFaqEntries), each
     * stored row by its id; replacing an entry's text names it (scoped_text).
     */
    public function testUpdateSetsAnFaqEntry(): void
    {
        $page = $this->newPage();
        $block = $page->addBlock(BlockType::getByHandle('faq'), 'Main', [
            'blockTitle' => 'Questions',
            'title' => ['Old question'], 'linkTitle' => ['old'], 'description' => ['<p>Old answer.</p>'], 'sortOrder' => [0],
        ]);
        $entryID = (int) $this->db()->fetchOne('SELECT id FROM btFaqEntries WHERE bID = ?', [$block->getBlockID()]);
        $cID = $this->startAsEditor($page);

        $this->json($this->tryTool(new ContentToolkit(), 'update_block_in_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockID' => (int) $block->getBlockID(),
            'value' => [
                'btFaqEntries' => [['id' => $entryID, 'title' => 'New question', 'linkTitle' => 'new', 'description' => '<p>New answer.</p>']],
            ],
            'changeMode' => 'scoped_text',
            'textFields' => ['btFaqEntries'],
            'replacedText' => ['Old question', 'Old answer.'],
        ]));

        $entries = $this->newestEntries($cID, 'btFaq', 'btFaqEntries');
        $this->assertCount(1, $entries);
        $this->assertSame('New question', (string) $entries[0]['title']);
        $this->assertSame('new', (string) $entries[0]['linkTitle']);
        $this->assertStringContainsString('New answer.', (string) $entries[0]['description']);
    }

    // ---- katalysis_pro: a list block's entries are saved ----------------------

    /**
     * Its save() reads `entries`, which the tools used to move to `slide`. P3.3-7 (Glyn): the tools write the entries as
     * the rows of the table the block's export declares (btKatalysisFeatureListEntries).
     */
    public function testAddSavesAFeatureListsEntries(): void
    {
        if (!BlockType::getByHandle('katalysis_feature_list')) {
            $this->markTestSkipped('katalysis_pro is not installed.');
        }
        // As the superuser, as for the hero image.
        $cID = (int) $this->newPage()->getCollectionID();

        $this->json($this->tryTool(new ContentToolkit(), 'add_block_to_page_area', [
            'pageID' => $cID, 'areaHandle' => 'Main', 'blockType' => 'katalysis_feature_list',
            'value' => ['btKatalysisFeatureListEntries' => [
                ['linkTitle' => 'First feature', 'linkDescription' => 'One', 'sortOrder' => 0],
                ['linkTitle' => 'Second feature', 'linkDescription' => 'Two', 'sortOrder' => 1],
            ]],
        ]));

        $entries = $this->newestEntries($cID, 'btKatalysisFeatureList', 'btKatalysisFeatureListEntries');
        $this->assertSame(['First feature', 'Second feature'], array_map(static fn (array $e): string => (string) $e['linkTitle'], $entries));
    }

    // ---- Helpers ------------------------------------------------------------

    private function newPage(): Page
    {
        $this->actAs('superuser');
        $parent = Page::getByPath('/neuron-test');
        $this->assertFalse($parent->isError(), '/neuron-test does not exist. Run the seed script first.');
        $template = PageTemplate::getByHandle('full');
        $unique = bin2hex(random_bytes(3));
        $page = $parent->add(PageType::getByHandle('page'), [
            'cName' => 'Block fields ' . $unique,
            'cHandle' => 'block-fields-' . $unique,
            'pTemplateID' => $template->getPageTemplateID(),
        ], $template);
        $this->assertFalse($page->isError());
        $this->pages[] = (int) $page->getCollectionID();

        return $page;
    }

    private function startAsEditor(Page $page): int
    {
        $this->actAs('neuron_editor');

        return (int) $page->getCollectionID();
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

    private function newestVersion(int $cID): int
    {
        return (int) $this->db()->fetchOne('SELECT MAX(cvID) FROM CollectionVersions WHERE cID = ?', [$cID]);
    }

    /**
     * The block's row in its table, in the page's newest version.
     *
     * @return array<string, mixed>
     */
    private function newestRow(int $cID, string $table): array
    {
        $row = $this->db()->fetchAssociative(
            "SELECT t.* FROM CollectionVersionBlocks cvb JOIN {$table} t ON t.bID = cvb.bID WHERE cvb.cID = ? AND cvb.cvID = ?",
            [$cID, $this->newestVersion($cID)]
        );
        $this->assertIsArray($row, "No {$table} block in the newest version of page {$cID}");

        return $row;
    }

    /** @return list<array<string, mixed>> the block's entry rows, in order */
    private function newestEntries(int $cID, string $table, string $entriesTable): array
    {
        return $this->db()->fetchAllAssociative(
            "SELECT * FROM {$entriesTable} WHERE bID = ? ORDER BY sortOrder",
            [(int) $this->newestRow($cID, $table)['bID']]
        );
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
