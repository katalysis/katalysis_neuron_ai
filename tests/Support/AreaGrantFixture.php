<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Application\Application;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Access\Entity\UserEntity;
use Concrete\Core\Permission\Key\Key as PermissionKey;
use Concrete\Core\Area\Area;
use Concrete\Core\Http\Request;
use Concrete\Core\User\UserInfoRepository;

/**
 * P1.12: lets one user add blocks to an area of a throwaway page, as the seed does for /neuron-test/headings
 * (adding a block is governed by the area's add-block key, which a page grant does not cover), and puts
 * everything back.
 *
 * Concrete's Page::delete() does not remove a page's AreaPermissionAssignments rows, and nothing removes the
 * access that overriding the area's permissions duplicates. restore() does both:
 *  - the area's assignment rows, for every page granted through this fixture;
 *  - the access this fixture created, unless something else still uses it.
 * The accesses the area copies from its page are shared with other content and are never touched.
 */
final class AreaGrantFixture
{
    private Connection $db;

    /** @var list<int> */
    private array $pages = [];

    /** @var list<int> accesses created here */
    private array $accesses = [];

    public function __construct(private readonly Application $app)
    {
        $this->db = $app->make(Connection::class);
    }

    /**
     * Runs as the superuser (the caller switches back to the user it wants to test).
     */
    public function allowUserToAddBlocks(int $cID, string $userName, string $areaHandle = 'Main'): void
    {
        Request::getInstance()->setCustomRequestUser($this->app->make(UserInfoRepository::class)->getByID(USER_SUPER_ID));
        $user = $this->app->make(UserInfoRepository::class)->getByUserName($userName);
        if ($user === null) {
            throw new \RuntimeException("The user \"{$userName}\" does not exist. Run the seed script first.");
        }
        $area = Area::getOrCreate(Page::getByID($cID), $areaHandle);
        $area->overridePagePermissions();
        $this->pages[] = $cID;

        $key = PermissionKey::getByHandle('add_block_to_area');
        $key->setPermissionObject($area);
        $access = $key->getPermissionAccessObject()->duplicate();
        $this->accesses[] = (int) $access->getPermissionAccessID();
        $access->addListItem(UserEntity::getOrCreate($user));
        $key->getPermissionAssignmentObject()->assignPermissionAccess($access);
    }

    public function restore(): void
    {
        foreach (array_unique($this->pages) as $cID) {
            $this->db->executeStatement('DELETE FROM AreaPermissionAssignments WHERE cID = ?', [$cID]);
        }
        // The access this fixture created. Deleted only once no assignment uses it.
        foreach (array_unique($this->accesses) as $paID) {
            PermissionCleanup::deleteAccessIfUnused($this->db, $paID);
        }
        $this->pages = [];
        $this->accesses = [];
        $this->app->make('cache/request')->flush();
    }
}
