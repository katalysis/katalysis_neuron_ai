<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tools\AttributeSetsToolkit;
use Katalysis\NeuronAi\Tools\AttributesToolkit;
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
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BF-1, B-WRT-5: every write tool that takes a page refuses a Concrete system page, even for the superuser, whose
 * permissions would allow the write. One case per tool and page input, and a registry-level check that every write
 * tool (ToolKind) with a page input is in that list, so a future tool can't skip the refusal. Disabled tools are
 * included: they get the refusal before they are turned back on.
 *
 * The system page is a throwaway page under /neuron-test, marked as a system page for the test, so a tool that
 * doesn't refuse damages only that page (it matters for the red run: delete_page and move_page are real). Every page
 * the test makes is deleted in tearDown(); the leak check confirms nothing is left.
 */
final class SystemPageWriteRefusalTest extends ConcreteTestCase
{
    /** The inputs that name a page. A write tool with any of them must refuse a system page. */
    private const PAGE_INPUTS = ['pageID', 'pagePath', 'pageName', 'parentID', 'newParentID'];

    /** Inputs that look like page inputs but aren't. */
    private const NOT_PAGE_INPUTS = ['parentFolderID', 'parent_topic_id', 'pageType', 'createParents'];

    /** @var int[] pages to delete in tearDown(), children first */
    private array $pages = [];

    protected function tearDown(): void
    {
        $this->actAs('superuser');
        foreach (array_reverse($this->pages) as $cID) {
            $this->db()->executeStatement('UPDATE Pages SET cIsSystemPage = 0 WHERE cID = ?', [$cID]);
            CacheLocal::flush();
            $page = Page::getByID($cID);
            if ($page && !$page->isError()) {
                $page->delete();
            }
        }
        $this->pages = [];
        Request::getInstance()->setCustomRequestUser(-1);
    }

    /**
     * Every write tool with a page input, and the input under test. Disabled tools are listed too.
     *
     * @return array<string, array{class-string<ToolkitInterface>, string, string}> case => [toolkit, tool, page input]
     */
    public static function writeToolProvider(): array
    {
        return [
            'batch_update_page_content' => [PagesToolkit::class, 'batch_update_page_content', 'pageID'],
            'batch_update_content_headings' => [PagesToolkit::class, 'batch_update_content_headings', 'pageID'],
            'sync_multi_links_to_epra_resources_list' => [PagesToolkit::class, 'sync_multi_links_to_epra_resources_list', 'pageID'],
            'create_page (parent)' => [PagesToolkit::class, 'create_page', 'parentID'],
            'update_page' => [PagesToolkit::class, 'update_page', 'pageID'],
            'move_page (page)' => [PagesToolkit::class, 'move_page', 'pageID'],
            'move_page (new parent)' => [PagesToolkit::class, 'move_page', 'newParentID'],
            'delete_page' => [PagesToolkit::class, 'delete_page', 'pageID'],
            'add_block_to_page_area' => [PagesToolkit::class, 'add_block_to_page_area', 'pageID'],
            'update_block_in_page_area' => [PagesToolkit::class, 'update_block_in_page_area', 'pageID'],
            'delete_block_from_page_area' => [PagesToolkit::class, 'delete_block_from_page_area', 'pageID'],
            'move_block_between_areas' => [PagesToolkit::class, 'move_block_between_areas', 'pageID'],
            'set_page_attribute_value, PageAttributesToolkit (pageID)' => [PageAttributesToolkit::class, 'set_page_attribute_value', 'pageID'],
            'set_page_attribute_value, PageAttributesToolkit (pagePath)' => [PageAttributesToolkit::class, 'set_page_attribute_value', 'pagePath'],
            'set_page_attribute_value, PageAttributesToolkit (pageName)' => [PageAttributesToolkit::class, 'set_page_attribute_value', 'pageName'],
            'set_page_attribute_value, AttributesToolkit (pageID)' => [AttributesToolkit::class, 'set_page_attribute_value', 'pageID'],
            'set_page_attribute_value, AttributesToolkit (pagePath)' => [AttributesToolkit::class, 'set_page_attribute_value', 'pagePath'],
            'set_page_attribute_value, AttributesToolkit (pageName)' => [AttributesToolkit::class, 'set_page_attribute_value', 'pageName'],
            'approve_page_version' => [VersionsToolkit::class, 'approve_page_version', 'pageID'],
            'create_page_draft' => [VersionsToolkit::class, 'create_page_draft', 'pageID'],
            'discard_draft_version' => [VersionsToolkit::class, 'discard_draft_version', 'pageID'],
        ];
    }

    /**
     * @param class-string<ToolkitInterface> $toolkit
     */
    #[DataProvider('writeToolProvider')]
    public function testTheToolRefusesASystemPage(string $toolkit, string $toolName, string $pageInput): void
    {
        $system = $this->newPage('System page ');
        $this->markAsSystemPage($system);
        $before = $this->state($system);

        $output = $this->runTool(new $toolkit(), $toolName, $this->inputs($toolName, $pageInput, $system));

        $this->assertSame($before, $this->state($system), "{$toolName} changed the system page.");
        if ($pageInput === 'pageName') {
            // A page found by name comes from a PageList, which leaves out system pages (cIsSystemPage = 0), so the
            // tool can't select one by name at all; it fails before the guard. (Its case-insensitive fallback query
            // is broken today, a pre-existing bug noted in BF-1's PR.)
            $this->assertFalse(ToolKind::succeeded($output), "{$toolName} reported success for a system page found by name: {$output}");

            return;
        }
        $this->assertStringContainsString('is a Concrete system page', $output, "{$toolName} did not refuse a system page. It returned: {$output}");
        $this->assertStringContainsString('Nothing was changed', $output);
    }

    public function testEveryWriteToolThatTakesAPageIsCovered(): void
    {
        $covered = [];
        foreach (self::writeToolProvider() as [$toolkit, $tool, $input]) {
            $covered[$toolkit . '::' . $tool][] = $input;
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

        ksort($covered);
        ksort($found);
        $this->assertSame($found, $covered, 'A write tool takes a page but is not in writeToolProvider(), so nothing checks that it refuses '
            . 'a system page. Add a case for it (and make it call SystemPageGuard::refuse()), or list a non-page input in NOT_PAGE_INPUTS.');
    }

    public function testTheDashboardAStackAndAGlobalAreaAreSystemPagesAndTheHomePageIsNot(): void
    {
        $this->assertTrue(Page::getByPath('/dashboard/system')->isSystemPage());
        $this->assertTrue(Page::getByPath('/!stacks')->isSystemPage());
        $stack = $this->db()->fetchOne('SELECT s.cID FROM Stacks s LIMIT 1');
        if ($stack !== false) {
            $this->assertTrue(Page::getByID((int) $stack)->isSystemPage(), 'stacks and global areas are system pages, so they are refused too');
        }
        $this->assertFalse(Page::getByID((int) $this->app()->make('site')->getSite()->getSiteHomePageID())->isSystemPage());
        $this->assertFalse(Page::getByPath('/neuron-test/headings')->isSystemPage());
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /**
     * The tool's inputs: the page input under test set to the system page, and dummy values for the rest, chosen so
     * that the tool gets as far as finding the page.
     *
     * @return array<string, mixed>
     */
    private function inputs(string $tool, string $pageInput, Page $system): array
    {
        $other = $this->newPage('Other page ');
        $inputs = match ($tool) {
            'batch_update_page_content' => ['blocks' => [['areaHandle' => 'Main', 'blockID' => 1, 'value' => ['content' => '<p>x</p>']]]],
            'batch_update_content_headings' => ['headingTexts' => ['x'], 'targetTag' => 'h2'],
            'create_page' => ['name' => 'Should not exist'],
            'update_page' => ['name' => 'Changed by the assistant'],
            'move_page' => ['pageID' => $other->getCollectionID(), 'newParentID' => $other->getCollectionID()],
            'add_block_to_page_area' => ['areaHandle' => 'Main', 'blockType' => 'content', 'value' => ['content' => '<p>x</p>']],
            'update_block_in_page_area' => ['areaHandle' => 'Main', 'blockID' => 1, 'value' => ['content' => '<p>x</p>']],
            'delete_block_from_page_area' => ['areaHandle' => 'Main', 'blockID' => 1],
            'move_block_between_areas' => ['fromAreaHandle' => 'Main', 'toAreaHandle' => 'Main', 'blockID' => 1],
            'set_page_attribute_value' => ['handle' => 'meta_title', 'value' => 'Changed by the assistant'],
            'approve_page_version', 'discard_draft_version' => ['versionID' => 1],
            default => [],
        };

        $inputs[$pageInput] = match ($pageInput) {
            'pagePath' => (string) $system->getCollectionPath(),
            'pageName' => (string) $system->getCollectionName(),
            default => (int) $system->getCollectionID(),
        };

        return $inputs;
    }

    /**
     * Runs the tool as the superuser. Returns its result, or what it threw, worded as the agent's error handler
     * words it for the model.
     */
    private function runTool(ToolkitInterface $toolkit, string $name, array $inputs): string
    {
        $this->actAs('superuser');
        foreach ($toolkit->tools() as $tool) {
            if ($tool->getName() !== $name) {
                continue;
            }
            $tool = clone $tool;
            try {
                $tool->setInputs($inputs)->execute();

                return (string) $tool->getResult();
            } catch (\Throwable $e) {
                // What the agent's tool error handler gives the model (ConcreteCmsAgent).
                return 'Error executing tool: ' . $e->getMessage();
            }
        }
        $this->fail("No tool named {$name} in " . $toolkit::class);
    }

    private function newPage(string $namePrefix): Page
    {
        $this->actAs('superuser');
        $parent = Page::getByPath('/neuron-test');
        $this->assertFalse($parent->isError(), '/neuron-test does not exist. Run the seed script first.');
        $template = PageTemplate::getByHandle('full');
        $unique = bin2hex(random_bytes(3));
        $page = $parent->add(PageType::getByHandle('page'), [
            'cName' => $namePrefix . $unique,
            'cHandle' => 'bf1-' . strtolower(trim($namePrefix)) . '-' . $unique,
            'pTemplateID' => $template->getPageTemplateID(),
        ], $template);
        $this->assertFalse($page->isError());
        $this->pages[] = (int) $page->getCollectionID();

        return $page;
    }

    private function markAsSystemPage(Page $page): void
    {
        $this->db()->executeStatement('UPDATE Pages SET cIsSystemPage = 1 WHERE cID = ?', [$page->getCollectionID()]);
        CacheLocal::flush();
        $this->assertTrue(Page::getByID($page->getCollectionID())->isSystemPage());
    }

    /**
     * Everything a write could change about the page: its versions, its blocks, its name and parent, its children,
     * its attribute values, whether it is in the trash.
     *
     * @return array<string, mixed>
     */
    private function state(Page $page): array
    {
        $db = $this->db();
        $cID = (int) $page->getCollectionID();

        return [
            'page' => $db->fetchAssociative('SELECT cParentID, cIsActive, cIsSystemPage FROM Pages WHERE cID = ?', [$cID]),
            'versions' => $db->fetchAllAssociative('SELECT cvID, cvName, cvIsApproved FROM CollectionVersions WHERE cID = ? ORDER BY cvID', [$cID]),
            'blocks' => $db->fetchAllAssociative('SELECT cvID, bID, arHandle FROM CollectionVersionBlocks WHERE cID = ? ORDER BY cvID, bID', [$cID]),
            'children' => (int) $db->fetchOne('SELECT COUNT(*) FROM Pages WHERE cParentID = ?', [$cID]),
            'attributes' => (int) $db->fetchOne('SELECT COUNT(*) FROM CollectionAttributeValues WHERE cID = ?', [$cID]),
        ];
    }

    /**
     * @return list<ToolkitInterface>
     */
    private function allToolkits(): array
    {
        return [
            new PagesToolkit(), new BlocksToolkit(), new FilesToolkit(), new UsersToolkit(), new PageAttributesToolkit(),
            new UserAttributesToolkit(), new FileAttributesToolkit(), new AttributeSetsToolkit(), new TopicsToolkit(),
            new PeopleToolkit(), new VersionsToolkit(), new AttributesToolkit(),
        ];
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }
}
