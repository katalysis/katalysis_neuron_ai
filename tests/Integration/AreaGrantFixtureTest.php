<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Tests\Support\AreaGrantFixture;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;

/**
 * P1.12: granting a user the add-block permission on a throwaway page's area, deleting the page and restoring
 * leaves no permission rows behind. Page::delete() leaves AreaPermissionAssignments rows, and overriding the area's
 * permissions duplicates an access that nothing removes: this test found that leak in DraftIsolationTest.
 */
final class AreaGrantFixtureTest extends ConcreteTestCase
{
    private const TABLES = [
        'Areas', 'AreaPermissionAssignments', 'PagePermissionAssignments', 'PermissionAssignments', 'PermissionAccess',
        'PermissionAccessList', 'PermissionAccessEntities', 'PermissionAccessEntityUsers',
        'AreaPermissionBlockTypeAccessList', 'AreaPermissionBlockTypeAccessListCustom',
    ];

    /** @var int[] */
    private array $pages = [];

    /** @var AreaGrantFixture[] */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        // Also when an assertion failed halfway: delete the page, then take back the grants (as DraftIsolationTest does).
        Request::getInstance()->setCustomRequestUser($this->app()->make(UserInfoRepository::class)->getByID(USER_SUPER_ID));
        $db = $this->app()->make(Connection::class);
        foreach ($this->pages as $cID) {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM Collections WHERE cID = ?', [$cID]) > 0) {
                Page::getByID($cID)->delete();
            }
        }
        foreach ($this->fixtures as $fixture) {
            $fixture->restore();
        }
        $this->pages = [];
        $this->fixtures = [];
        Request::getInstance()->setCustomRequestUser(-1);
    }

    private function newFixture(): AreaGrantFixture
    {
        return $this->fixtures[] = new AreaGrantFixture($this->app());
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $db = $this->app()->make(Connection::class);
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = (int) $db->fetchOne('SELECT COUNT(*) FROM `' . $table . '`');
        }

        return $counts;
    }

    private function newThrowawayPage(): int
    {
        Request::getInstance()->setCustomRequestUser($this->app()->make(UserInfoRepository::class)->getByID(USER_SUPER_ID));
        $parent = Page::getByPath('/neuron-test');
        $this->assertFalse($parent->isError(), '/neuron-test does not exist. Run the seed script first.');
        $template = PageTemplate::getByHandle('full');
        $unique = bin2hex(random_bytes(3));
        $page = $parent->add(PageType::getByHandle('page'), [
            'cName' => 'Area grant fixture ' . $unique,
            'cHandle' => 'area-grant-fixture-' . $unique,
            'pTemplateID' => $template->getPageTemplateID(),
        ], $template);
        $this->assertFalse($page->isError());
        $this->pages[] = (int) $page->getCollectionID();

        return (int) $page->getCollectionID();
    }

    public function testGrantThenDeleteThePageThenRestoreLeavesNoRowsBehind(): void
    {
        $before = $this->counts();
        $fixture = $this->newFixture();

        $cID = $this->newThrowawayPage();
        $fixture->allowUserToAddBlocks($cID, 'neuron_editor');
        $this->assertGreaterThan($before['AreaPermissionAssignments'], $this->counts()['AreaPermissionAssignments'], 'The grant should have written rows');

        Page::getByID($cID)->delete();
        $fixture->restore();

        $this->assertSame($before, $this->counts());
    }

    public function testRestoreWithoutDeletingThePageAlsoLeavesNoGrantRows(): void
    {
        $fixture = $this->newFixture();
        $cID = $this->newThrowawayPage();
        $afterPage = $this->counts();

        $fixture->allowUserToAddBlocks($cID, 'neuron_editor');
        $fixture->restore();

        $db = $this->app()->make(Connection::class);
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM AreaPermissionAssignments WHERE cID = ?', [$cID]));
        $this->assertSame($afterPage['PermissionAccess'], $this->counts()['PermissionAccess']);
        $this->assertSame($afterPage['PermissionAccessList'], $this->counts()['PermissionAccessList']);

        Page::getByID($cID)->delete();
    }

    public function testAccessesThatOtherContentSharesAreNeverDeleted(): void
    {
        $db = $this->app()->make(Connection::class);
        $sharedBefore = $db->fetchFirstColumn(
            'SELECT DISTINCT paID FROM PagePermissionAssignments UNION SELECT DISTINCT paID FROM PermissionAssignments ORDER BY paID'
        );
        $fixture = $this->newFixture();

        $cID = $this->newThrowawayPage();
        $fixture->allowUserToAddBlocks($cID, 'neuron_editor');
        Page::getByID($cID)->delete();
        $fixture->restore();

        $existing = array_map('intval', $db->fetchFirstColumn('SELECT paID FROM PermissionAccess'));
        foreach ($sharedBefore as $paID) {
            $this->assertContains((int) $paID, $existing, "The shared access {$paID} must survive");
        }
    }
}
