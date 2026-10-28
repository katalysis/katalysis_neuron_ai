<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Application;

/**
 * The pages below a page (P3.6-3): what a move or a delete takes with it. delete_page and move_page name every one of
 * them on their approval card and in their result (B-T1-8, B-CON-3), and delete_page checks each one's permission.
 * Trashed pages are left out. Reads only.
 */
final class PageTree
{
    /**
     * Every page below $page, depth first in display order.
     *
     * @return list<Page>
     */
    public static function descendants(Page $page): array
    {
        $db = Application::getFacadeApplication()->make(Connection::class);
        $found = [];
        $walk = static function (int $parentID) use (&$walk, &$found, $db): void {
            foreach ($db->fetchFirstColumn('SELECT cID FROM Pages WHERE cParentID = ? ORDER BY cDisplayOrder, cID', [$parentID]) as $cID) {
                $child = Page::getByID((int) $cID);
                if (!$child || $child->isError() || $child->isInTrash()) {
                    continue;
                }
                $found[] = $child;
                $walk((int) $cID);
            }
        };
        $walk((int) $page->getCollectionID());

        return $found;
    }

    /**
     * The page and its descendants as {id, name, path}, for a card, a result and a fingerprint.
     *
     * @return list<array{id: int, name: string, path: string}>
     */
    public static function summary(Page $page): array
    {
        return array_map(
            static fn (Page $p): array => ['id' => (int) $p->getCollectionID(), 'name' => (string) $p->getCollectionName(), 'path' => (string) $p->getCollectionPath()],
            [$page, ...self::descendants($page)]
        );
    }

    /** Whether $candidate is $page or one of the pages below it. */
    public static function isWithin(Page $candidate, Page $page): bool
    {
        $root = (int) $page->getCollectionID();
        $db = Application::getFacadeApplication()->make(Connection::class);
        $id = (int) $candidate->getCollectionID();
        for ($guard = 0; $id > 0 && $guard < 200; $guard++) {
            if ($id === $root) {
                return true;
            }
            $id = (int) $db->fetchOne('SELECT cParentID FROM Pages WHERE cID = ?', [$id]);
        }

        return false;
    }
}
