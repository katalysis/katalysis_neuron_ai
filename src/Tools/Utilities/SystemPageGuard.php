<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Area\Area;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Stack\Stack;

/**
 * Writes to system pages are refused (B-WRT-5, BF-1, BF-2).
 *
 * - **System single pages and page type defaults** are refused permanently: the dashboard, account and login pages,
 *   error pages, the trash and drafts containers (pages Concrete marks as system pages), and page type defaults
 *   (master collections, which Concrete doesn't mark as system pages, and which are edited live, without a draft).
 * - **Stacks and global areas** (a global area's content lives on a stack) are refused until stack editing is enabled
 *   (P3.6a): stackEditingEnabled() is false. A stack is whatever Stack::getByID() finds, so multilingual copies count.
 *
 * The assistant may read all of them and use them as the current page. Every write tool that takes a page calls
 * refuse() right after it has found the page, and every block tool calls refuseGlobalArea() for each area it would
 * write to, before any check that could make a draft. SystemPageWriteRefusalTest and GlobalAreaWriteRefusalTest check
 * that every such tool does.
 */
final class SystemPageGuard
{
    /**
     * Whether the assistant may edit stacks and global areas. Off until P3.6a (draft-only stack versions, the user's
     * stack permissions, a notice that the change shows on every page with the area).
     */
    public static function stackEditingEnabled(): bool
    {
        return false;
    }

    /**
     * @param string $role what the page is to the tool, for the message ("page", "parent page", "new parent page")
     */
    public static function refuse(Page $page, string $role = 'page'): void
    {
        if (self::isStack($page) ? !self::stackEditingEnabled() : ($page->isSystemPage() || $page->isMasterCollection())) {
            throw new \RuntimeException(self::message($page, $role));
        }
    }

    /**
     * Refuses an area of an ordinary page that is a global area: its content lives on a stack.
     */
    public static function refuseGlobalArea(Page $page, string $areaHandle): void
    {
        if (self::stackEditingEnabled()) {
            return;
        }

        $area = Area::get($page, $areaHandle);
        if ($area instanceof Area && $area->isGlobalArea()) {
            throw new \RuntimeException(sprintf(
                'The area "%s" on this page is a global area: its content is shared by every page that shows it and is kept '
                . 'in a stack. The assistant can\'t edit stacks or global areas yet. Nothing was changed. Tell the user they '
                . 'can edit it themselves in edit mode or under Dashboard > Stacks & Global Areas.',
                $areaHandle
            ));
        }
    }

    public static function message(Page $page, string $role = 'page'): string
    {
        $path = (string) $page->getCollectionPath();
        $what = sprintf('The %s "%s"%s', $role, (string) $page->getCollectionName(), $path !== '' ? " ({$path})" : '');

        if (self::isStack($page)) {
            return $what . ' is a stack or global area: its content can be shown on many pages. The assistant can\'t edit '
                . 'stacks or global areas yet. Nothing was changed. Tell the user they can edit it themselves under '
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

    private static function isStack(Page $page): bool
    {
        return Stack::getByID((int) $page->getCollectionID()) !== null;
    }
}
