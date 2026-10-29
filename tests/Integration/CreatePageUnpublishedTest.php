<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tools\BatchEditToolkit;
use Katalysis\NeuronAi\Tools\ContentToolkit;
use Katalysis\NeuronAi\Tools\PagesToolkit;

/**
 * P1.13: create_page makes a page with no approved version, so it is not live until a person approves it from
 * Page Versions (B-WRT-3). The tool runs as the non-admin neuron_editor, as in scenario 08, and says so in its
 * description and result. Every test builds its own throwaway page under /neuron-test and deletes it in
 * tearDown(). Nothing here calls the AI provider.
 */
final class CreatePageUnpublishedTest extends ConcreteTestCase
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
            // Approving a page adds a PageSummaryTemplates row, and Page::delete() does not remove it.
            $this->db()->executeStatement('DELETE FROM PageSummaryTemplates WHERE cID = ?', [$cID]);
        }
        $this->pages = [];
        Request::getInstance()->setCustomRequestUser(-1);
    }

    private function actAs(string $who): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        $info = $who === 'superuser' ? $repository->getByID(USER_SUPER_ID) : $repository->getByUserName($who);
        $this->assertNotNull($info, "The user \"{$who}\" does not exist. Run the seed script first.");
        Request::getInstance()->setCustomRequestUser($info);
    }

    private function asGuest(): void
    {
        Request::getInstance()->setCustomRequestUser(-1);
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    /**
     * Runs a Pages toolkit tool as the current user and returns the decoded result. Since P3.3-4 that is any of the
     * three toolkits PagesToolkit was split into (add_block_to_page_area is ContentToolkit's).
     */
    private function callTool(string $name, array $inputs): array
    {
        foreach (array_merge((new PagesToolkit())->tools(), (new ContentToolkit())->tools(), (new BatchEditToolkit())->tools()) as $tool) {
            if ($tool->getName() === $name) {
                $tool = clone $tool;
                $tool->setInputs($inputs)->execute();
                $decoded = json_decode((string) $tool->getResult(), true);
                $this->assertIsArray($decoded, 'The tool result is not JSON: ' . $tool->getResult());

                return $decoded;
            }
        }
        $this->fail("No tool named {$name}");
    }

    /**
     * Creates a page under /neuron-test with the create_page tool, as neuron_editor, and returns the tool's result.
     */
    private function createWithTheTool(): array
    {
        $this->actAs('superuser');
        $parent = Page::getByPath('/neuron-test');
        $this->assertFalse($parent->isError(), '/neuron-test does not exist. Run the seed script first.');
        $this->actAs('neuron_editor');

        $result = $this->callTool('create_page', [
            'parentID' => (int) $parent->getCollectionID(),
            'name' => 'Unpublished ' . bin2hex(random_bytes(3)),
            'pageType' => 'page',
        ]);
        $this->pages[] = (int) $result['id'];

        return $result;
    }

    private function createUnpublished(): Page
    {
        $result = $this->createWithTheTool();
        $this->actAs('superuser');

        return Page::getByID((int) $result['id'], 'RECENT');
    }

    /**
     * @return int[] IDs of the pages under /neuron-test the current user gets from a list
     */
    private function listedIDs(int $versions): array
    {
        $list = new PageList();
        $list->filterByPath('/neuron-test');
        $list->setPageVersionToRetrieve($versions);
        $ids = [];
        foreach ($list->getResults() as $page) {
            $ids[] = (int) $page->getCollectionID();
        }

        return $ids;
    }

    public function testTheDescriptionAndTheResultSayThePageIsUnpublishedAndAPersonPublishesIt(): void
    {
        foreach ((new PagesToolkit())->tools() as $tool) {
            if ($tool->getName() === 'create_page') {
                $this->assertStringContainsString('unpublished', $tool->getDescription());
                $this->assertStringContainsString('Approve Version', $tool->getDescription());
            }
        }

        $result = $this->createWithTheTool();

        $this->assertFalse($result['published']);
        $this->assertStringContainsString('unpublished', $result['note']);
        $this->assertStringContainsString('Approve Version', $result['note']);
        $this->assertSame(1, $result['versionID']);
    }

    public function testALaterBlockAddStaysInVersionOne(): void
    {
        $cID = (int) $this->createUnpublished()->getCollectionID();

        $this->actAs('superuser');
        $this->callTool('add_block_to_page_area', [
            'pageID' => $cID,
            'areaHandle' => 'Main',
            'blockType' => 'content',
            'value' => ['content' => '<p>Added after creation</p>'],
        ]);

        $versions = $this->db()->fetchAllAssociative('SELECT cvID, cvIsApproved FROM CollectionVersions WHERE cID = ?', [$cID]);
        $this->assertCount(1, $versions, 'The edit goes into version 1 and makes no second version');
        $this->assertSame(0, (int) $versions[0]['cvIsApproved']);
        $text = (string) $this->db()->fetchOne(
            'SELECT GROUP_CONCAT(c.content) FROM CollectionVersionBlocks cvb JOIN btContentLocal c ON c.bID = cvb.bID WHERE cvb.cID = ? AND cvb.cvID = 1',
            [$cID]
        );
        $this->assertStringContainsString('Added after creation', $text);
    }

    public function testARefusedCreateLeavesNoPageBehind(): void
    {
        $this->actAs('neuron_editor');
        $count = fn (): int => (int) $this->db()->fetchOne('SELECT COUNT(*) FROM Pages');
        $before = $count();

        try {
            $this->callTool('create_page', ['parentID' => 1, 'name' => 'Neuron Denied', 'pageType' => 'page']);
            $this->fail('The editor has no add-subpage right on the home page');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Permission denied', $e->getMessage());
        }

        $this->assertSame($before, $count());
    }

    public function testTheNewPageHasNoApprovedVersion(): void
    {
        $cID = (int) $this->createUnpublished()->getCollectionID();

        $versions = $this->db()->fetchAllAssociative('SELECT cvID, cvIsApproved, cvIsNew FROM CollectionVersions WHERE cID = ?', [$cID]);
        $this->assertCount(1, $versions);
        $this->assertSame(1, (int) $versions[0]['cvID']);
        $this->assertSame(0, (int) $versions[0]['cvIsApproved'], 'Nothing is approved, so nothing is live');
        $this->assertSame(1, (int) $versions[0]['cvIsNew'], 'A new version is edited in place, so later edits stay in version 1');
    }

    public function testTheNewPageHasACanonicalPath(): void
    {
        $page = $this->createUnpublished();

        $path = (string) $this->db()->fetchOne('SELECT cPath FROM PagePaths WHERE cID = ? AND ppIsCanonical = 1', [$page->getCollectionID()]);
        $this->assertStringStartsWith('/neuron-test/unpublished-', $path);
        $this->assertSame((int) $page->getCollectionID(), (int) Page::getByPath($path, 'RECENT')->getCollectionID(), 'The most recent version is found by its path');
    }

    public function testGuestsAndDefaultListsDoNotSeeThePage(): void
    {
        $cID = (int) $this->createUnpublished()->getCollectionID();

        // The default list (what autonav and search use) shows approved versions only.
        $this->actAs('superuser');
        $this->assertNotContains($cID, $this->listedIDs(PageList::PAGE_VERSION_ACTIVE), 'Not in the default list, even for the superuser');
        $this->asGuest();
        $this->assertNotContains($cID, $this->listedIDs(PageList::PAGE_VERSION_ACTIVE), 'Not in the guest list');
    }

    public function testAnEditorSeesThePageInTheMostRecentVersionList(): void
    {
        $cID = (int) $this->createUnpublished()->getCollectionID();

        $this->actAs('neuron_editor');
        $this->assertContains($cID, $this->listedIDs(PageList::PAGE_VERSION_RECENT));
    }

    public function testAPersonCanApproveItFromPageVersionsAndThenGuestsSeeIt(): void
    {
        $page = $this->createUnpublished();
        $cID = (int) $page->getCollectionID();

        $this->actAs('superuser');
        Page::getByID($cID, 'RECENT')->getVersionObject()->approve();

        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT cvIsApproved FROM CollectionVersions WHERE cID = ? AND cvID = 1', [$cID]));
        $this->asGuest();
        $this->assertContains($cID, $this->listedIDs(PageList::PAGE_VERSION_ACTIVE), 'Once approved, the same guest list shows it');
    }
}
