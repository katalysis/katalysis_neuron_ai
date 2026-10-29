<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Area\Area;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Stack\Stack;

/**
 * Writes to system pages are refused (B-WRT-5, BF-1, BF-2, P3.6a).
 *
 * - **System single pages and page type defaults** are refused permanently: the dashboard, account and login pages,
 *   error pages, the trash and drafts containers (pages Concrete marks as system pages), and page type defaults
 *   (master collections, which Concrete doesn't mark as system pages, and which are edited live, without a draft).
 * - **Stacks and global areas** (a global area's content lives on a stack) are edited only through the block tools,
 *   as an editor edits them (P3.6a, SharedArea): in an unapproved stack version, with the stack's own permissions, and
 *   with a notice that the change shows on every page with the area. Every other tool refuses a stack: the assistant
 *   doesn't rename, move, delete, publish or change the properties of one. A stack is whatever Stack::getByID()
 *   finds, so multilingual copies count. batch_update_page_content still refuses a global area (refuseGlobalArea()).
 *
 * The assistant may read all of them and use them as the current page. Every write tool that takes a page calls
 * refuse() right after it has found the page. SystemPageWriteRefusalTest and GlobalAreaWriteTest check that every such
 * tool does.
 */
final class SystemPageGuard
{
    /**
     * Whether the block tools may edit stacks and global areas (P3.6a: draft-only stack versions, the user's stack
     * permissions, a notice that the change shows on every page with the area). Off before P3.6a; false refuses
     * stacks to the block tools too.
     */
    public static function stackEditingEnabled(): bool
    {
        return true;
    }

    /**
     * @param string $role what the page is to the tool, for the message ("page", "parent page", "new parent page")
     * @param bool $allowStack true only for the block tools, which edit a stack's blocks in a stack draft (P3.6a)
     */
    public static function refuse(Page $page, string $role = 'page', bool $allowStack = false): void
    {
        if (self::isStack($page)) {
            if (!$allowStack || !self::stackEditingEnabled()) {
                throw new \RuntimeException(self::message($page, $role));
            }

            return;
        }
        if ($page->isSystemPage() || $page->isMasterCollection()) {
            throw new \RuntimeException(self::message($page, $role));
        }
    }

    /**
     * batch_update_page_content: refuses an area of an ordinary page that is a global area (its content lives on a
     * stack). Each change to a shared area goes through update_block_in_page_area, with its own approval card and the
     * notice that it shows on every page (Glyn, P3.6a).
     */
    public static function refuseGlobalArea(Page $page, string $areaHandle): void
    {
        $area = Area::get($page, $areaHandle);
        if ($area instanceof Area && $area->isGlobalArea()) {
            throw new \RuntimeException(sprintf(
                'The area "%s" on this page is a global area: its content is shared by every page that shows it and is kept '
                . 'in a stack. batch_update_page_content doesn\'t change global areas. Nothing was changed. Change its blocks '
                . 'one at a time with update_block_in_page_area, so the user sees each shared change before it is made.',
                $areaHandle
            ));
        }
    }

    public static function message(Page $page, string $role = 'page'): string
    {
        $path = (string) $page->getCollectionPath();
        $what = sprintf('The %s "%s"%s', $role, (string) $page->getCollectionName(), $path !== '' ? " ({$path})" : '');

        if (self::isStack($page)) {
            return $what . ' is a stack or global area: its content can be shown on many pages. The assistant can change '
                . 'only the blocks in it, with the block tools; it doesn\'t rename, move, delete, publish or change the '
                . 'properties of a stack. Nothing was changed. Tell the user they can do that themselves under '
                . 'Dashboard > Stacks & Global Areas.';
        }

        if ($page->isMasterCollection()) {
            return $what . ' holds page type defaults: what new pages of that type start with. Concrete changes them '
                . 'directly, without a draft, so the assistant does not change page type defaults. Nothing was changed. '
                . 'Tell the user they can change them in the Concrete dashboard themselves.';
        }

        return $what . ' is a Concrete system page (part of the dashboard, account pages, or another page Concrete '
            . 'manages itself). The assistant does not change system pages. Nothing was changed. Tell the user they can '
            . 'make this change in the Concrete dashboard themselves.';
    }

    public static function isStack(Page $page): bool
    {
        return Stack::getByID((int) $page->getCollectionID()) !== null;
    }
}
