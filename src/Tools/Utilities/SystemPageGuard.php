<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Page\Page;

/**
 * Writes to system pages are refused (B-WRT-5, BF-1).
 *
 * Concrete marks its own pages as system pages: the dashboard, account pages, login and error pages, the trash and
 * drafts containers, and stacks and global areas. The assistant may read them and use them as the current page, but
 * no write tool changes one, even when Concrete's permissions would allow it. Every write tool that takes a page
 * calls refuse() right after it has found the page, before any other check and before anything is written.
 * ToolRegistrySystemPageTest checks that every such tool does.
 */
final class SystemPageGuard
{
    /**
     * @param string $role what the page is to the tool, for the message ("page", "parent page", "new parent page")
     */
    public static function refuse(Page $page, string $role = 'page'): void
    {
        if (!$page->isSystemPage()) {
            return;
        }

        throw new \RuntimeException(self::message($page, $role));
    }

    public static function message(Page $page, string $role = 'page'): string
    {
        $path = (string) $page->getCollectionPath();

        return sprintf(
            'The %s "%s"%s is a Concrete system page (part of the dashboard, account pages, stacks or global areas, '
            . 'or another page Concrete manages itself). The assistant does not change system pages. Nothing was changed. '
            . 'Tell the user they can make this change in the Concrete dashboard themselves.',
            $role,
            (string) $page->getCollectionName(),
            $path !== '' ? " ({$path})" : ''
        );
    }
}
