<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Support;

use Concrete\Core\Application\Application;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Access\Entity\UserEntity;
use Concrete\Core\Permission\Key\Key as PermissionKey;
use Concrete\Core\User\UserInfoRepository;

/**
 * P3.5-1: takes one page permission away from some users on a throwaway page, as the seed does for /neuron-test/locked,
 * and puts everything back.
 *
 * The page gets its own copy of its parent's permissions (setPermissionsToOverride(), which shares the parent's
 * accesses), and the key's access is duplicated before the users are taken out, so no shared access is ever changed.
 * Page::delete() leaves a page's PagePermissionAssignments rows behind, so restore() removes them for every page
 * changed here (call it after the pages are deleted), and then the accesses this fixture created, once unused.
 */
final class PageGrantFixture
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
     *
     * @param list<string> $userNames
     */
    public function takeAway(int $cID, string $handle, array $userNames = ['neuron_editor', 'neuron_chat_editor']): void
    {
        $repository = $this->app->make(UserInfoRepository::class);
        Request::getInstance()->setCustomRequestUser($repository->getByID(USER_SUPER_ID));
        $page = Page::getByID($cID);
        if (!in_array($cID, $this->pages, true)) {
            $page->setPermissionsToOverride();
            $this->pages[] = $cID;
        }

        $key = PermissionKey::getByHandle($handle);
        $key->setPermissionObject($page);
        $access = $key->getPermissionAccessObject()->duplicate();
        $this->accesses[] = (int) $access->getPermissionAccessID();
        foreach ($userNames as $userName) {
            $user = $repository->getByUserName($userName);
            if ($user === null) {
                throw new \RuntimeException("The user \"{$userName}\" does not exist. Run the seed script first.");
            }
            $access->removeListItem(UserEntity::getOrCreate($user));
        }
        $key->getPermissionAssignmentObject()->assignPermissionAccess($access);
        $page->refreshCache();
        $this->app->make('cache/request')->flush();
    }

    public function restore(): void
    {
        foreach (array_unique($this->pages) as $cID) {
            if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM Pages WHERE cID = ?', [$cID]) === 0) {
                $this->db->executeStatement('DELETE FROM PagePermissionAssignments WHERE cID = ?', [$cID]);
            }
        }
        foreach (array_unique($this->accesses) as $paID) {
            PermissionCleanup::deleteAccessIfUnused($this->db, $paID);
        }
        $this->pages = [];
        $this->accesses = [];
        $this->app->make('cache/request')->flush();
    }
}
