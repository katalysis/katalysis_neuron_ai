<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval\Preview;

use Concrete\Core\Page\Page;
use Concrete\Core\Support\Facade\Config;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use Katalysis\NeuronAi\Tools\Utilities\PageTree;

/**
 * The approval cards of move_page and delete_page (P3.6-3, plans/P3.6-3.md §1): every page the change takes with it,
 * by title and path (B-T1-8, B-CON-3), and the state it depends on (the pages below, the target's children, the trash
 * can, the permissions). Their refusals come from the tools' own checks (CallCheck's dry run). Reads only.
 */
final class PagePreviews
{
    public static function move(array $inputs): array
    {
        $page = self::page(PreviewSupport::int($inputs, 'pageID'));
        $target = self::page(PreviewSupport::int($inputs, 'newParentID'));
        if ($page === null || $target === null) {
            return [PreviewSupport::entry('Move a page', 'a page that was not found', '', [], ''), ['missing' => [$inputs['pageID'] ?? null, $inputs['newParentID'] ?? null]]];
        }

        $pages = PageTree::summary($page);
        $oldRoot = (string) $page->getCollectionPath();
        $newRoot = rtrim((string) $target->getCollectionPath(), '/') . '/' . $page->getCollectionHandle();
        $details = [];
        foreach ($pages as $p) {
            $details[] = $p['name'] . ': ' . $p['path'] . ' → ' . $newRoot . substr($p['path'], strlen($oldRoot));
        }
        $below = count($pages) - 1;
        $warning = count($pages) > 10 ? ['This moves ' . count($pages) . ' pages.'] : [];
        $children = array_map(static fn (Page $c): string => (string) $c->getCollectionHandle(), $target->getCollectionChildren());
        sort($children);

        return [
            PreviewSupport::entry(
                'Move ' . PreviewSupport::name($page) . ' and the ' . $below . ' ' . ($below === 1 ? 'page' : 'pages') . ' below it under',
                PreviewSupport::name($target),
                PreviewSupport::path($target),
                $details,
                'The old addresses keep working: they redirect to the new ones. A site workflow may hold the move for approval.',
                $warning
            ),
            [
                'pages' => $pages,
                'parent' => (int) $page->getCollectionParentID(),
                'target' => (int) $target->getCollectionID(),
                'targetChildren' => $children,
                'canMove' => ObjectPermissions::moveOrCopyPage($page) === null,
                'canAdd' => ObjectPermissions::addSubpage($target) === null,
            ],
        ];
    }

    public static function delete(array $inputs): array
    {
        $page = self::page(PreviewSupport::int($inputs, 'pageID'));
        if ($page === null) {
            return [PreviewSupport::entry('Move a page to the trash', 'a page that was not found', '', [], ''), ['missing' => $inputs['pageID'] ?? null]];
        }

        $pages = PageTree::summary($page);
        $below = count($pages) - 1;
        $warning = count($pages) > 10 ? ['This moves ' . count($pages) . ' pages to the trash.'] : [];

        return [
            PreviewSupport::entry(
                'Move ' . PreviewSupport::name($page) . ' and the ' . $below . ' ' . ($below === 1 ? 'page' : 'pages') . ' below it to the trash:',
                '',
                '',
                array_map(static fn (array $p): string => $p['name'] . ' (' . $p['path'] . ')', $pages),
                'Nothing is deleted for good: a person can restore them from Dashboard > Sitemap > Trash. A site workflow may hold the delete for approval.',
                $warning
            ),
            [
                'pages' => $pages,
                'inTrash' => $page->isInTrash(),
                'trashCan' => (bool) Config::get('concrete.misc.enable_trash_can'),
                'canDelete' => array_map(static fn (array $p): bool => ($q = Page::getByID($p['id'])) && ObjectPermissions::deletePage($q) === null, $pages),
            ],
        ];
    }

    private static function page(int $cID): ?Page
    {
        if ($cID <= 0) {
            return null;
        }
        $page = Page::getByID($cID);

        return $page && !$page->isError() ? $page : null;
    }
}
