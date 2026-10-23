<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools\Utilities;

use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\Page\Page;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\User\UserInfoRepository;

/**
 * Which version of a page the read tools show (BF-10; B-CTX-4). The write tools change the page's newest version
 * (PageDraft::of()), and Concrete shows editors that version, so the reads show it by default too. The published
 * version stays available on request. Every read says which version it shows, and says when the published one differs,
 * so a block ID the model reads is one the write tools can use (BF-7's browser check 6).
 *
 * Reading an unpublished version needs view_page_versions, as before. Without it the default read shows the published
 * version and says why; asking for "newest" is refused.
 */
final class PageVersionRead
{
    public const NEWEST = 'newest';
    public const PUBLISHED = 'published';

    /** The description of every read tool's `version` input. */
    public const DESCRIPTION = 'Which version to read: "newest" (default): the version editors see and the write tools change, which may '
        . 'have unpublished changes; or "published": what visitors see. Take block IDs for writes from the newest version.';

    /** The same, for the tools that list blocks across the site, one version per page. */
    public const SITE_DESCRIPTION = 'Which version of each page to search: "newest" (default): the version editors see and the write tools '
        . 'change, which may have unpublished changes; or "published": what visitors see. Take block IDs for writes from the newest version.';

    /** The names before BF-10, still accepted. */
    private const ALIASES = ['recent' => self::NEWEST, 'active' => self::PUBLISHED];

    /**
     * The page in the version asked for (null or "": the newest, falling back to the published version when the user
     * can't view versions), and the `version` block that describes it.
     *
     * @return array{Page, array<string, mixed>}
     *
     * @throws \RuntimeException
     */
    public static function load(int $pageID, ?string $requested): array
    {
        $wanted = self::normalize($requested);
        $newest = self::newestVersionID($pageID);
        if ($newest === 0) {
            throw new \RuntimeException("Page with ID {$pageID} not found. The page may not exist or may have been deleted.");
        }
        $published = self::publishedVersionID($pageID);

        $fallback = false;
        if ($wanted !== self::PUBLISHED) {
            $page = Page::getByID($pageID, 'RECENT');
            if (!$page || $page->isError()) {
                throw new \RuntimeException("Page with ID {$pageID} not found. The page may not exist or may have been deleted.");
            }
            if ((new Checker($page))->canViewPageVersions()) {
                return [$page, self::describe($page, self::NEWEST, false)];
            }
            if ($wanted === self::NEWEST || $published === 0) {
                throw new \RuntimeException("Permission denied: you can't view unpublished versions of page {$pageID}."
                    . ($published > 0 ? ' Read the published version (version "published").' : ''));
            }
            $fallback = true;
        }

        if ($published === 0) {
            throw new \RuntimeException("Page {$pageID} has no published version yet. Read the newest version (the default).");
        }
        $page = Page::getByID($pageID, 'ACTIVE');
        if (!$page || $page->isError()) {
            throw new \RuntimeException("Page with ID {$pageID} not found. The page may not exist or may have been deleted.");
        }
        if (!(new Checker($page))->canViewPage()) {
            throw new \RuntimeException("Permission denied: Cannot read page {$pageID}. You need view permissions for this page.");
        }

        return [$page, self::describe($page, self::PUBLISHED, $fallback)];
    }

    /**
     * The newest version of $page when the acting user can view versions, else $page as given. For the prompt's page
     * snapshot, which must never fail.
     */
    public static function newestIfAllowed(Page $page): Page
    {
        try {
            $newest = Page::getByID((int) $page->getCollectionID(), 'RECENT');
            if ($newest && !$newest->isError() && (new Checker($newest))->canViewPageVersions()) {
                return $newest;
            }
        } catch (\Throwable $e) {
            // Keep the page as given.
        }

        return $page;
    }

    /**
     * "newest", "published" or null (the default). Throws on anything else.
     */
    public static function normalize(?string $requested): ?string
    {
        $value = strtolower(trim((string) $requested));
        if ($value === '') {
            return null;
        }
        $value = self::ALIASES[$value] ?? $value;
        if ($value !== self::NEWEST && $value !== self::PUBLISHED) {
            throw new \RuntimeException("Unknown version \"{$requested}\": use \"newest\" (the default) or \"published\".");
        }

        return $value;
    }

    public static function newestVersionID(int $pageID): int
    {
        return (int) self::db()->fetchOne('SELECT MAX(cvID) FROM CollectionVersions WHERE cID = ?', [$pageID]);
    }

    public static function publishedVersionID(int $pageID): int
    {
        return (int) self::db()->fetchOne('SELECT MAX(cvID) FROM CollectionVersions WHERE cID = ? AND cvIsApproved = 1', [$pageID]);
    }

    /**
     * ", saved by <user name>" for a version with a known author, else "".
     */
    public static function savedBy(int $pageID, int $versionID): string
    {
        $author = (int) self::db()->fetchOne('SELECT cvAuthorUID FROM CollectionVersions WHERE cID = ? AND cvID = ?', [$pageID, $versionID]);
        $info = $author > 0 ? Application::getFacadeApplication()->make(UserInfoRepository::class)->getByID($author) : null;

        return $info ? ', saved by ' . $info->getUserName() : '';
    }

    /**
     * The `version` block of a read's result.
     *
     * @return array<string, mixed>
     */
    private static function describe(Page $page, string $shows, bool $fallback): array
    {
        $cID = (int) $page->getCollectionID();
        $versionID = (int) $page->getVersionID();
        $newest = self::newestVersionID($cID);
        $published = self::publishedVersionID($cID);

        $block = [
            'shows' => $shows,
            'versionID' => $versionID,
            'status' => $versionID === $published ? 'published' : 'unpublished',
            'publishedVersionID' => $published > 0 ? $published : null,
        ];

        if ($shows === self::NEWEST) {
            if ($versionID === $published) {
                $block['summary'] = "Newest version ({$versionID}), which is also the published version.";
            } else {
                $state = "Newest version ({$versionID}, unpublished" . self::savedBy($cID, $versionID) . ')';
                $block['summary'] = $published > 0
                    ? "{$state}: what editors see and what the write tools change. Visitors see version {$published} until someone "
                        . 'publishes. Use block IDs from this version.'
                    : "{$state}: the page has never been published, so visitors can't see it. This is what editors see and what "
                        . 'the write tools change.';
            }

            return $block;
        }

        if ($newest !== $versionID) {
            $block['newestVersionID'] = $newest;
        }
        if ($fallback) {
            $block['summary'] = "You can't view unpublished versions of this page, so this is the published version ({$versionID}): "
                . 'what visitors see.' . ($newest !== $versionID ? ' A newer, unpublished version exists.' : '');
        } elseif ($newest === $versionID) {
            $block['summary'] = "Published version ({$versionID}), which is also the newest version.";
        } else {
            $block['summary'] = "Published version ({$versionID}): what visitors see. The newest version ({$newest}, unpublished) has "
                . 'changes not shown here; the write tools change that version, and block IDs here may not be in it.';
        }

        return $block;
    }

    private static function db(): Connection
    {
        return Application::getFacadeApplication()->make(Connection::class);
    }
}
