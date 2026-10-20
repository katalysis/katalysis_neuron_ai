<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;

/**
 * P1.13 spike: a page made with no approved version (cvIsApproved => 0) is not
 * live until a person approves it (B-WRT-3). Every test builds its own throwaway
 * page under /neuron-test and deletes it in tearDown(). Nothing here calls the
 * AI provider.
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

    private function createUnpublished(): Page
    {
        $this->actAs('superuser');
        $parent = Page::getByPath('/neuron-test');
        $this->assertFalse($parent->isError(), '/neuron-test does not exist. Run the seed script first.');
        $template = PageTemplate::getByHandle('full');
        $unique = bin2hex(random_bytes(3));
        $page = $parent->add(PageType::getByHandle('page'), [
            'cName' => 'Unpublished ' . $unique,
            'cHandle' => 'unpublished-' . $unique,
            'cvIsApproved' => 0,
        ], $template);
        $this->assertFalse($page->isError());
        $this->pages[] = (int) $page->getCollectionID();

        return $page;
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
