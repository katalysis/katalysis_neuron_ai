<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Cache\CacheLocal;
use Concrete\Core\Page\Page;

/**
 * The one way write tools get the version of a page to edit.
 *
 * Concrete keeps the Page::getByID($id, 'RECENT') object for the rest of the request, so after
 * a draft is made the cached page is still the old version. A second write that used the cached
 * page (or called getVersionToModify() on it, as Concrete's own AddBlockToPageCommand does)
 * would clone the old version again: a second draft, without the first write's edits.
 * Every write tool must call PageDraft::of() before it edits, and use the page it returns.
 *
 * Drafts belong to the page, not to a user: if the newest version is already a draft, it is
 * reused whoever made it.
 */
final class PageDraft
{
    /**
     * The version of the page to edit: the newest version if it is already a draft, otherwise a
     * new draft cloned from it. The result is also what Page::getByID($id, 'RECENT') returns
     * for the rest of the request.
     *
     * @param bool|null $created set to true when a new draft was made, false when one was reused
     */
    public static function of(Page $page, ?bool &$created = null): Page
    {
        $latest = Page::getByID($page->getCollectionID(), 'RECENT');
        $draft = $latest->getVersionToModify();
        $created = $draft !== $latest;
        if ($created) {
            self::cacheAsLatest($draft);
        }

        return $draft;
    }

    /**
     * Makes a draft that was made some other way (for example cloned from an older version) the
     * page that Page::getByID($id, 'RECENT') returns for the rest of the request.
     */
    public static function cacheAsLatest(Page $draft): void
    {
        CacheLocal::set('page', $draft->getCollectionID() . '/RECENT/' . Page::class, $draft);
    }
}
